<?php
session_start();
// 1. Load all necessary files
require_once 'fpdf.php';
require_once '../src/DashboardManager.php';
require_once '../src/InventoryManager.php'; // Keep this for recall data

// 2. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    die('Access denied.');
}

// 3. Get dates from the form submission
$date_start = $_POST['date_start'] ?? date('Y-m-d');
$date_end = $_POST['date_end'] ?? date('Y-m-d');

// --- Create friendly date range text ---
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
// ---

// 4. Fetch the data
$dashboardManager = new DashboardManager();
$inventoryManager = new InventoryManager();

// --- ::: NEW: Fetch summary data like the dashboard ::: ---
$dateRangeSummary = $dashboardManager->getSalesSummaryByDateRange($date_start, $date_end);
$grossRevenue = $dateRangeSummary['totalRevenue'] ?? 0.00;
$totalReturns = $dateRangeSummary['totalReturns'] ?? 0.00;
$netRevenue = $grossRevenue - $totalReturns;
$recalledStockValue = $dashboardManager->getRecalledStockValue($date_start, $date_end);
// --- ::: END NEW ::: ---

// --- Fetch detailed data for tables ---
$salesData = $dashboardManager->getSalesSummaryByDate($date_start, $date_end); //
$recallData = $inventoryManager->getRecallHistoryByDate($date_start, $date_end); //


// 5. Create a new PDF class
class PDF extends FPDF
{
    // --- Define some colors ---
    private $colorHeaderBg;
    private $colorHeaderText;
    private $colorRowEven;
    private $colorRowOdd;
    private $colorBorder;

    function __construct($orientation='P', $unit='mm', $size='A4') {
        parent::__construct($orientation, $unit, $size);
        // --- Set our color palette ---
        $this->colorHeaderBg = [230, 230, 230]; // Light Gray
        $this->colorHeaderText = [106, 56, 31]; // Dark Brown
        $this->colorRowEven = [245, 245, 245]; // Off-white
        $this->colorRowOdd = [255, 255, 255]; // White
        $this->colorBorder = [200, 200, 200]; // Gray border
    }

    // --- ::: NEW HEADER ::: ---
    function Header()
    {
        global $date_range_text;
        // Add Logo
        // (Assuming kzklogo.png is in the ../images/ folder relative to this script)
        $this->Image('../images/kzklogo.png', 10, 8, 25);
        
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(25); // Move right, past the logo
        $this->SetTextColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->Cell(0, 10, 'BREADLY', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 12);
        $this->Cell(25); // Move right, past the logo
        $this->SetTextColor(50, 50, 50);
        $this->Cell(0, 8, 'Sales & Recall Report', 0, 1, 'L');
        
        $this->SetFont('Arial', 'I', 10);
        $this->Cell(25); // Move right, past the logo
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Period: ' . $date_range_text, 0, 1, 'L');
        
        $this->Ln(10);
    }
    // --- ::: END NEW HEADER ::: ---

    // Page footer
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Section title
    function SectionTitle($label)
    {
        $this->SetFont('Arial', 'B', 14);
        $this->SetFillColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 10, $label, 0, 1, 'L', true);
        $this->Ln(4);
    }
    
    // --- ::: NEW: Summary Box Function ::: ---
    function SummaryBox($title, $value, $color = [0, 0, 0])
    {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(50, 50, 50);
        $this->Cell(47, 8, $title, 0, 0, 'L');
        
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor($color[0], $color[1], $color[2]);
        $this->Cell(47, 8, $value, 0, 1, 'R');
    }
    
    // --- ::: NEW: Fancy Table Function ::: ---
    function FancyTable($header, $data, $columnWidths, $aligns)
    {
        // --- Table Header ---
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor($this->colorHeaderBg[0], $this->colorHeaderBg[1], $this->colorHeaderBg[2]);
        $this->SetTextColor($this->colorHeaderText[0], $this->colorHeaderText[1], $this->colorHeaderText[2]);
        $this->SetDrawColor($this->colorBorder[0], $this->colorBorder[1], $this->colorBorder[2]);
        $this->SetLineWidth(0.3);
        
        for ($i = 0; $i < count($header); $i++) {
            $this->Cell($columnWidths[$i], 8, $header[$i], 1, 0, 'C', true);
        }
        $this->Ln();

        // --- Table Body ---
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(0);
        $fill = false;
        
        if (empty($data)) {
            $this->SetFillColor($this->colorRowOdd[0], $this->colorRowOdd[1], $this->colorRowOdd[2]);
            $this->Cell(array_sum($columnWidths), 10, 'No data available for this period.', 'LRB', 1, 'C', true);
            return;
        }

        foreach ($data as $row) {
            // Set row color
            $fillColor = $fill ? $this->colorRowEven : $this->colorRowOdd;
            $this->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
            
            $i = 0;
            foreach ($row as $col) {
                $this->Cell($columnWidths[$i], 7, $col, 'LR', 0, $aligns[$i], true);
                $i++;
            }
            $this->Ln();
            $fill = !$fill;
        }
        
        // Closing line
        $this->Cell(array_sum($columnWidths), 0, '', 'T');
    }

    // --- ::: NEW: Function for Table Totals Row ::: ---
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
// --- ::: END OF NEW PDF CLASS ::: ---


// 6. Generate the PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// --- ::: NEW: SUMMARY SECTION ::: ---
$pdf->SectionTitle('Executive Summary');

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(250, 250, 250);

// --- ::: FIX: Get Y before drawing boxes ::: ---
$startY = $pdf->GetY();
$boxHeight = 36; // Define the height of the summary boxes

$pdf->Rect(10, $startY, 95, $boxHeight, 'F');
$pdf->Rect(105, $startY, 95, $boxHeight, 'F');

// Column 1: Sales
$pdf->SetY($startY + 2); // Set Y relative to the start
$pdf->SetX(12);
$pdf->SummaryBox('Gross Revenue:', 'P ' . number_format($grossRevenue, 2), [60, 118, 61]);
$pdf->SetX(12);
$pdf->SummaryBox('Less Returns:', '(P ' . number_format($totalReturns, 2) . ')', [20, 138, 209]);
$pdf->SetX(12);
$pdf->Cell(91, 0, '', 'T'); // Divider
$pdf->Ln(1);
$pdf->SetX(12);
$pdf->SummaryBox('Net Revenue:', 'P ' . number_format($netRevenue, 2), [60, 118, 61]);

// Column 2: Recalls
$pdf->SetY($startY + 2); // Reset Y to top of boxes
$pdf->SetX(107);
$pdf->SummaryBox('Total Recalled Value:', '(P ' . number_format($recalledStockValue, 2) . ')', [217, 83, 79]);

// --- ::: FIX: Set Y based on box height, replacing Ln(15) ::: ---
$pdf->SetY($startY + $boxHeight + 6); // Set Y below the boxes + 6 margin

// --- ::: END SUMMARY SECTION ::: ---


// --- Sales Section ---
$pdf->SectionTitle('Detailed Sales Report');

// Prepare data for sales table
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

// Draw the sales table
$pdf->FancyTable($salesHeader, $salesTableData, $salesColumnWidths, $salesAligns);

// --- Use the new TableTotals function ---
$totalsCells = [
    'Overall Total',
    $total_qty,
    'P ' . number_format($total_revenue, 2)
];
$totalsAligns = ['R', 'C', 'R'];
$pdf->TableTotals($totalsCells, $salesColumnWidths, $totalsAligns);


$pdf->Ln(10);

// --- Recall Section ---
$pdf->SectionTitle('Detailed Recall Log');

// Prepare data for recall table
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

// Draw the recall table
$pdf->FancyTable($recallHeader, $recallTableData, $recallColumnWidths, $recallAligns);


// 7. Output the PDF
$pdf->Output('D', 'Breadly_Report_' . $date_start . '_to_' . $date_end . '.pdf');
exit;
?>