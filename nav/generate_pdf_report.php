<?php
session_start();
// 1. Load all necessary files
require_once '../lib/fpdf.php';
require_once '../src/DashboardManager.php';
require_once '../src/InventoryManager.php';

// 2. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    die('Access denied.');
}

// 3. Get dates from the form submission
$date_start = $_POST['date_start'] ?? date('Y-m-d');
$date_end = $_POST['date_end'] ?? date('Y-m-d');
$date_str = ($date_start == $date_end) ? $date_start : "$date_start to $date_end";

// 4. Fetch the data
$dashboardManager = new DashboardManager();
$inventoryManager = new InventoryManager();

$salesData = $dashboardManager->getSalesSummaryByDate($date_start, $date_end);
$recallData = $inventoryManager->getRecallHistoryByDate($date_start, $date_end);

// 5. Create a new PDF class
class PDF extends FPDF
{
    // Page header
    function Header()
    {
        global $date_str;
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(80); // Center
        $this->Cell(30, 10, 'BREADLY Sales Report', 0, 0, 'C');
        $this->Ln(5);
        $this->SetFont('Arial', '', 12);
        $this->Cell(80);
        $this->Cell(30, 10, $date_str, 0, 0, 'C');
        $this->Ln(15);
    }

    // Page footer
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Section title
    function SectionTitle($label)
    {
        $this->SetFont('Arial', 'B', 12);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(0, 8, $label, 1, 1, 'L', true);
        $this->Ln(2);
    }
    
    // Table header
    function SalesHeader()
    {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 7, 'Product', 1, 0, 'L');
        $this->Cell(30, 7, 'Qty Sold', 1, 0, 'C');
        $this->Cell(50, 7, 'Total Revenue', 1, 1, 'R');
    }
    
    // Table body
    function SalesBody($data)
    {
        $this->SetFont('Arial', '', 10);
        $total_qty = 0;
        $total_revenue = 0;
        
        if (empty($data)) {
            $this->Cell(0, 7, 'No sales recorded for this period.', 'LRB', 1, 'C');
            return;
        }

        foreach ($data as $row) {
            $this->Cell(80, 7, $row['product_name'], 'LR', 0, 'L');
            $this->Cell(30, 7, $row['total_qty_sold'], 'LR', 0, 'C');
            $this->Cell(50, 7, 'P ' . number_format($row['total_revenue'], 2), 'LR', 1, 'R');
            $total_qty += $row['total_qty_sold'];
            $total_revenue += $row['total_revenue'];
        }
        
        // Totals
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 7, 'Overall Total', 'T', 0, 'R');
        $this->Cell(30, 7, $total_qty, 1, 0, 'C');
        $this->Cell(50, 7, 'P ' . number_format($total_revenue, 2), 1, 1, 'R');
    }
    
    function RecallHeader()
    {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(50, 7, 'Item Name', 1, 0, 'L');
        $this->Cell(20, 7, 'Qty', 1, 0, 'C');
        $this->Cell(100, 7, 'Reason', 1, 1, 'L');
    }
    
    function RecallBody($data)
    {
        $this->SetFont('Arial', '', 10);
        if (empty($data)) {
            $this->Cell(0, 7, 'No recall events recorded for this period.', 'LRB', 1, 'C');
            return;
        }
        
        foreach ($data as $row) {
            $this->Cell(50, 7, $row['item_name'], 'LR', 0, 'L');
            $this->Cell(20, 7, number_format($row['adjustment_qty']), 'LR', 0, 'C');
            $this->Cell(100, 7, $row['reason'], 'LR', 1, 'L');
        }
        $this->Cell(170, 0, '', 'T'); // Draw final bottom line
    }
}

// 6. Generate the PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Sales Section
$pdf->SectionTitle('Sales Summary');
$pdf->SalesHeader();
$pdf->SalesBody($salesData);

$pdf->Ln(10);

// Recall Section
$pdf->SectionTitle('Recall Log');
$pdf->RecallHeader();
$pdf->RecallBody($recallData);

// 7. Output the PDF
$pdf->Output('D', 'Breadly_Report_' . $date_start . '.pdf');
exit;
?>