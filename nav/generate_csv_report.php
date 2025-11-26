<?php
session_start();
require_once '../config.php';
require_once '../db_connection.php';
require_once '../phpmailer/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'assistant_manager'])) {
    die('Access denied.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();

    $date_start = $_POST['date_start'] ?? date('Y-m-d');
    $date_end = $_POST['date_end'] ?? date('Y-m-d');
    $report_types = $_POST['report_types'] ?? []; 
    $action = $_POST['csv_action'] ?? 'download';
    $recipient_email = $_POST['recipient_email'] ?? '';

    if (empty($report_types)) {
        die("Error: No report types selected.");
    }

    if (!is_array($report_types)) {
        $report_types = [$report_types];
    }

    $db = new Database();
    $conn = $db->getConnection();
    $generatedFiles = [];

    // Generate content for each selected report
    foreach ($report_types as $type) {
        $data = [];
        $headers = [];
        $filename = "Breadly_" . ucwords($type) . "_{$date_start}_to_{$date_end}.csv";

        if ($type === 'sales') {
            $headers = [
                'Transaction ID', 'Date/Time', 'Items Purchased', 'Quantity', 
                'Price', 'Discount Amount', 'Total Sale Amount', 'User/Cashier'
            ];
            $stmt = $conn->prepare("CALL ReportGetSalesHistory(?, ?, 'date', 'DESC')");
            $stmt->execute([$date_start, $date_end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            foreach ($rows as $row) {
                $data[] = [
                    $row['sale_id'],
                    $row['date'],
                    $row['product_name'],
                    $row['qty_sold'],
                    number_format($row['subtotal'] / ($row['qty_sold'] ?: 1), 2),
                    number_format($row['discount_amount'], 2),
                    number_format($row['total_price'], 2),
                    $row['cashier_username']
                ];
            }

        } elseif ($type === 'product_inventory') {
            $headers = ['Product Name', 'Product SKU', 'Current Stock Level', 'Unit of Measure', 'Status'];
            $stmt = $conn->prepare("CALL InventoryGetProducts()");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            foreach ($rows as $r) {
                $data[] = [$r['name'], 'PROD-'.$r['product_id'], $r['stock_qty'], $r['stock_unit'], ucfirst($r['status'])];
            }
            usort($data, fn($a, $b) => strcasecmp($a[0], $b[0]));

        } elseif ($type === 'ingredient_inventory') {
            $headers = ['Ingredient Name', 'Ingredient SKU', 'Current Stock Level', 'Unit of Measure', 'Par Level'];
            $stmt = $conn->prepare("CALL InventoryGetIngredients()");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            foreach ($rows as $r) {
                $data[] = [$r['name'], 'ING-'.$r['ingredient_id'], $r['stock_qty'], $r['unit'], $r['reorder_level']];
            }
            usort($data, fn($a, $b) => strcasecmp($a[0], $b[0]));

        } elseif ($type === 'returns') {
            $headers = ['Date/Time', 'Product ID/Name', 'Quantity', 'Unit', 'Reason', 'Value', 'User'];
            
            // Price map for value calc
            $priceMap = [];
            $stmtP = $conn->prepare("CALL InventoryGetProducts()");
            $stmtP->execute();
            foreach($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) $priceMap[$p['name']] = $p['price'];
            $stmtP->closeCursor();

            // Fetch Returns
            $stmtRet = $conn->prepare("CALL ReportGetReturnHistory()");
            $stmtRet->execute();
            $allReturns = $stmtRet->fetchAll(PDO::FETCH_ASSOC);
            $stmtRet->closeCursor();

            $startTs = strtotime("$date_start 00:00:00");
            $endTs = strtotime("$date_end 23:59:59");

            foreach ($allReturns as $r) {
                if (strtotime($r['timestamp']) >= $startTs && strtotime($r['timestamp']) <= $endTs) {
                    $data[] = [$r['timestamp'], $r['product_name'], $r['qty_returned'], 'pcs', $r['reason'], number_format($r['return_value'], 2), $r['username']];
                }
            }

            // Fetch Wastage/Recalls
            $stmtAdj = $conn->prepare("CALL ReportGetStockAdjustmentHistoryByDate(?, ?)");
            $stmtAdj->execute([$date_start, $date_end]);
            $adjustments = $stmtAdj->fetchAll(PDO::FETCH_ASSOC);
            $stmtAdj->closeCursor();

            foreach ($adjustments as $row) {
                if ($row['adjustment_qty'] < 0) {
                    $reason = strtolower($row['reason']);
                    if (preg_match('/recall|spoilage|expired|damaged/', $reason)) {
                        $qty = abs($row['adjustment_qty']);
                        $val = ($row['item_type'] === 'product' && isset($priceMap[$row['item_name']])) 
                               ? $qty * $priceMap[$row['item_name']] : 0;
                        $data[] = [$row['timestamp'], $row['item_name'], $qty, 'N/A', $row['reason'], number_format($val, 2), $row['username']];
                    }
                }
            }
            usort($data, fn($a, $b) => strtotime($b[0]) - strtotime($a[0]));
        }

        // Create CSV content
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $headers);
        foreach ($data as $line) fputcsv($fp, $line);
        rewind($fp);
        $generatedFiles[$filename] = stream_get_contents($fp);
        fclose($fp);
    }

    // Bundle Logic (Single vs Zip)
    $finalContent = '';
    $finalFilename = '';
    $isZip = false;

    if (count($generatedFiles) === 1) {
        $finalFilename = array_key_first($generatedFiles);
        $finalContent = current($generatedFiles);
    } else {
        $isZip = true;
        $finalFilename = "Breadly_Reports_Bundle_{$date_start}_{$date_end}.zip";
        $zipTempFile = tempnam(sys_get_temp_dir(), 'breadly_zip');
        
        $zip = new ZipArchive();
        if ($zip->open($zipTempFile, ZipArchive::CREATE) !== TRUE) {
            die("Could not create ZIP archive.");
        }

        foreach ($generatedFiles as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $finalContent = file_get_contents($zipTempFile);
        unlink($zipTempFile);
    }

    if ($action === 'download') {
        if ($isZip) {
            header('Content-Type: application/zip');
            header('Content-Transfer-Encoding: Binary');
        } else {
            header('Content-Type: text/csv');
        }
        header("Content-Disposition: attachment; filename=\"$finalFilename\"");
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $finalContent;
        exit();

    } elseif ($action === 'email') {
        if (empty($recipient_email) || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
            die("Invalid email address.");
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($recipient_email);
            $mail->addStringAttachment($finalContent, $finalFilename);

            $mail->isHTML(true);
            $mail->Subject = 'Breadly Reports Export';
            $mail->Body    = "<h3>Breadly Reports</h3><p>Please find attached the requested reports for <strong>{$date_start} to {$date_end}</strong>.</p>";

            $mail->send();

            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Email Sent</title>';
            echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
            echo '</head><body style="font-family: sans-serif; background-color: #f8f9fa;">';
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        title: 'Email Sent!',
                        text: 'The reports have been sent to $recipient_email',
                        icon: 'success',
                        confirmButtonColor: '#d97706',
                        confirmButtonText: 'Back to Dashboard'
                    }).then((result) => {
                        if (result.isConfirmed) window.history.back();
                    });
                });
            </script>";
            echo '</body></html>';

        } catch (Exception $e) {
             echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
        exit();
    }
} else {
    header("Location: dashboard_panel.php");
    exit();
}
?>