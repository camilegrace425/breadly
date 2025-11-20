<?php
session_start();
// 1. Load all necessary files
require_once '../lib/fpdf.php';
require_once '../src/DashboardManager.php';
require_once '../src/InventoryManager.php'; 
require_once '../src/SalesManager.php'; // Added SalesManager
require_once '../config.php';
require_once '../phpmailer/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 2. Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'assistant_manager'])) {
    die('Access denied.');
}

// 3. Get dates and action from the form
$date_start = $_POST['date_start'] ?? date('Y-m-d');
$date_end = $_POST['date_end'] ?? date('Y-m-d');
$action_type = $_POST['report_action'] ?? 'download'; 
$recipient_email = $_POST['recipient_email'] ?? ''; 

// Friendly date range text
$date_range_text = date('M d, Y', strtotime($date_start));
if ($date_start != $date_end) {
    $date_range_text .= ' to ' . date('M d, Y', strtotime($date_end));
}
if (empty($_POST['date_start']) && empty($_POST['date_end'])) {
    if ($date_start == date('Y-m-d', strtotime('-29 days')) && $date_end == date('Y-m-d')) {
        $date_range_text = 'Last 30 Days';
    }
}
if ($date_start == date('Y-m-d') && $date_end == date('Y-m-d')) {
    $date_range_text = 'Today';
}

// 4. Fetch the data
$dashboardManager = new DashboardManager();
$inventoryManager = new InventoryManager();
$salesManager = new SalesManager(); // Instantiate SalesManager

$dateRangeSummary = $dashboardManager->getSalesSummaryByDateRange($date_start, $date_end);
$grossRevenue = $dateRangeSummary['totalRevenue'] ?? 0.00;
// FIX: Use correct key 'totalReturnsValue' from DashboardManager
$totalReturns = $dateRangeSummary['totalReturnsValue'] ?? 0.00; 
$netRevenue = $grossRevenue - $totalReturns;

$recalledStockValue = $dashboardManager->getRecalledStockValue($date_start, $date_end);
$salesData = $dashboardManager->getSalesSummaryByDate($date_start, $date_end); 
$recallData = $inventoryManager->getRecallHistoryByDate($date_start, $date_end);

// --- Fetch and Filter Returns Data ---
$allReturns = $salesManager->getReturnHistory();
$returnsData = [];
$startDateObj = new DateTime($date_start . ' 00:00:00');
$endDateObj = new DateTime($date_end . ' 23:59:59');

foreach ($allReturns as $ret) {
    // Ensure timestamp exists and is valid
    if (!empty($ret['timestamp'])) {
        $retDate = new DateTime($ret['timestamp']);
        if ($retDate >= $startDateObj && $retDate <= $endDateObj) {
            $returnsData[] = $ret;
        }
    }
}


// 5. PDF Class Definition
class PDF extends FPDF
{
    private $colorHeaderBg;
    private $colorHeaderText;
    private $colorRowEven;
    private $colorRowOdd;
    private $colorBorder;

    function __construct($orientation='P', $unit='mm', $size='A4') {
        parent::__construct($orientation, $unit, $size);
        $this->colorHeaderBg = [230, 230, 230]; 
        $this->colorHeaderText = [106, 56, 31]; 
        $this->colorRowEven = [245, 245, 245]; 
        $this->colorRowOdd = [255, 255, 255]; 
        $this->colorBorder = [200, 200, 200]; 
    }

    function Header()
    {
        global $date_range_text;
        $this->Image('../images/kzklogo.png', 10, 8, 25);
        
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(25); 
        $this->SetTextColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->Cell(0, 10, 'BREADLY', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 12);
        $this->Cell(25); 
        $this->SetTextColor(50, 50, 50);
        $this->Cell(0, 8, 'Sales, Returns & Recall Report', 0, 1, 'L'); // Updated Title
        
        $this->SetFont('Arial', 'I', 10);
        $this->Cell(25); 
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Period: ' . $date_range_text, 0, 1, 'L');
        
        $this->Ln(10);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function SectionTitle($label)
    {
        $this->SetFont('Arial', 'B', 14);
        $this->SetFillColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 10, $label, 0, 1, 'L', true);
        $this->Ln(4);
    }
    
    function SummaryBox($title, $value, $color = [0, 0, 0])
    {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(50, 50, 50);
        $this->Cell(47, 8, $title, 0, 0, 'L');
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor($color[0], $color[1], $color[2]);
        $this->Cell(47, 8, $value, 0, 1, 'R');
    }
    
    function FancyTable($header, $data, $columnWidths, $aligns)
    {
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor($this->colorHeaderBg[0], $this->colorHeaderBg[1], $this->colorHeaderBg[2]);
        $this->SetTextColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->SetDrawColor($this->colorBorder[0], $this->colorBorder[1], $this->colorBorder[2]);
        $this->SetLineWidth(0.3);
        
        for ($i = 0; $i < count($header); $i++) {
            $this->Cell($columnWidths[$i], 8, $header[$i], 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(0);
        $fill = false;
        
        if (empty($data)) {
            $this->SetFillColor($this->colorRowOdd[0], $this->colorRowOdd[1], $this->colorRowOdd[2]);
            $this->Cell(array_sum($columnWidths), 10, 'No data available for this period.', 'LRB', 1, 'C', true);
            return;
        }

        foreach ($data as $row) {
            $fillColor = $fill ? $this->colorRowEven : $this->colorRowOdd;
            $this->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
            
            $i = 0;
            foreach ($row as $col) {
                // Ensure text fits or is truncated if absolutely necessary, but Cell handles basic overflow by hiding
                $this->Cell($columnWidths[$i], 7, $col, 'LR', 0, $aligns[$i], true);
                $i++;
            }
            $this->Ln();
            $fill = !$fill;
        }
        $this->Cell(array_sum($columnWidths), 0, '', 'T');
    }

    function TableTotals($cells, $columnWidths, $aligns)
    {
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor($this->colorHeaderBg[0], $this->colorHeaderBg[1], $this->colorHeaderBg[2]);
        $this->SetTextColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->SetDrawColor($this->colorBorder[0], $this->colorBorder[1], $this->colorBorder[2]);
        $this->SetLineWidth(0.3);

        for ($i = 0; $i < count($cells); $i++) {
            $this->Cell($columnWidths[$i], 8, $cells[$i], 1, 0, $aligns[$i], true);
        }
        $this->Ln();
    }
}


// 6. Generate the PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// --- Executive Summary ---
$pdf->SectionTitle('Executive Summary');
$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(250, 250, 250);

$startY = $pdf->GetY();
$boxHeight = 36; 

$pdf->Rect(10, $startY, 95, $boxHeight, 'F');
$pdf->Rect(105, $startY, 95, $boxHeight, 'F');

// Column 1: Sales
$pdf->SetY($startY + 2); 
$pdf->SetX(12);
$pdf->SummaryBox('Gross Revenue:', 'P ' . number_format($grossRevenue, 2), [60, 118, 61]);
$pdf->SetX(12);
$pdf->SummaryBox('Less Returns:', '(P ' . number_format($totalReturns, 2) . ')', [217, 83, 79]); // Red for deductions
$pdf->SetX(12);
$pdf->Cell(91, 0, '', 'T'); 
$pdf->Ln(1);
$pdf->SetX(12);
$pdf->SummaryBox('Net Revenue:', 'P ' . number_format($netRevenue, 2), [60, 118, 61]);

// Column 2: Recalls & Returns Info
$pdf->SetY($startY + 2); 
$pdf->SetX(107);
$pdf->SummaryBox('Total Recalled Value:', '(P ' . number_format($recalledStockValue, 2) . ')', [217, 83, 79]);
$pdf->SetX(107);
$pdf->SummaryBox('Return Transactions:', count($returnsData), [50, 50, 50]);

$pdf->SetY($startY + $boxHeight + 6); 


// --- Sales Section ---
$pdf->SectionTitle('Detailed Sales Report');
$salesHeader = ['Product', 'Qty Sold', 'Total Revenue'];
$salesColumnWidths = [100, 40, 50];
$salesAligns = ['L', 'C', 'R'];
$salesTableData = [];
$total_qty = 0;
$total_revenue = 0;

foreach ($salesData as $row) {
    $salesTableData[] = [
        $row['product_name'],
        $row['total_qty_sold'],
        'P ' . number_format($row['total_revenue'], 2)
    ];
    $total_qty += $row['total_qty_sold'];
    $total_revenue += $row['total_revenue'];
}

$pdf->FancyTable($salesHeader, $salesTableData, $salesColumnWidths, $salesAligns);

$totalsCells = ['Overall Total', $total_qty, 'P ' . number_format($total_revenue, 2)];
$totalsAligns = ['R', 'C', 'R'];
$pdf->TableTotals($totalsCells, $salesColumnWidths, $totalsAligns);
$pdf->Ln(10);

// --- Returns Section (NEW) ---
$pdf->SectionTitle('Detailed Returns Report');
$returnsHeader = ['Date', 'Product', 'Qty', 'Value', 'Reason', 'Cashier'];
$returnsColumnWidths = [35, 50, 15, 25, 40, 25];
$returnsAligns = ['L', 'L', 'C', 'R', 'L', 'L'];
$returnsTableData = [];
$total_returned_val = 0;

foreach ($returnsData as $row) {
    $returnsTableData[] = [
        date('M d H:i', strtotime($row['timestamp'])),
        $row['product_name'],
        $row['qty_returned'],
        'P ' . number_format($row['return_value'], 2),
        $row['reason'],
        $row['username'] ?? 'N/A'
    ];
    $total_returned_val += $row['return_value'];
}

$pdf->FancyTable($returnsHeader, $returnsTableData, $returnsColumnWidths, $returnsAligns);
// Optional Total Row for Returns
if (!empty($returnsData)) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(100, 8, 'Total Returns Value', 1, 0, 'R', true);
    $pdf->Cell(90, 8, 'P ' . number_format($total_returned_val, 2), 1, 1, 'R', true);
}
$pdf->Ln(10);

// --- Recall Section ---
$pdf->SectionTitle('Detailed Recall Log');
$recallHeader = ['Timestamp', 'Product', 'Qty', 'Cashier', 'Reason'];
$recallColumnWidths = [40, 50, 15, 30, 55];
$recallAligns = ['L', 'L', 'C', 'L', 'L'];
$recallTableData = [];

foreach ($recallData as $row) {
    $recallTableData[] = [
        date('M d, Y H:i', strtotime($row['timestamp'])),
        $row['item_name'],
        number_format($row['adjustment_qty']),
        $row['username'] ?? 'N/A',
        $row['reason']
    ];
}
$pdf->FancyTable($recallHeader, $recallTableData, $recallColumnWidths, $recallAligns);


// 7. Output based on Action
$filename = 'Breadly_Report_' . $date_start . '_to_' . $date_end . '.pdf';

if ($action_type === 'email' && !empty($recipient_email)) {
    // --- EMAIL LOGIC ---
    $pdfString = $pdf->Output('S');
    
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

        $mail->addStringAttachment($pdfString, $filename);

        $mail->isHTML(true);
        $mail->Subject = 'Breadly Report: ' . $date_range_text;
        $mail->Body    = "<h3>Sales & Recall Report</h3><p>Please find attached the report for the period: <strong>$date_range_text</strong>.</p>";

        $mail->send();
        
        // --- SweetAlert Success Response ---
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Email Sent</title>';
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'; 
        echo '</head><body style="font-family: sans-serif; background-color: #f8f9fa;">';
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: 'Email Sent!',
                    text: 'The report has been successfully sent to $recipient_email',
                    icon: 'success',
                    confirmButtonColor: '#d97706', 
                    confirmButtonText: 'Back to Dashboard'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.history.back();
                    }
                });
            });
        </script>";
        echo '</body></html>';

    } catch (Exception $e) {
        // SweetAlert Error Response
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script></head><body>';
        echo "<script>
            Swal.fire({
                title: 'Email Failed',
                text: 'Message could not be sent. Mailer Error: " . addslashes($mail->ErrorInfo) . "',
                icon: 'error',
                confirmButtonText: 'OK'
            }).then(() => { window.history.back(); });
        </script>";
        echo '</body></html>';
    }

} else {
    // --- DOWNLOAD LOGIC ---
    $pdf->Output('D', $filename);
}
exit;
?>