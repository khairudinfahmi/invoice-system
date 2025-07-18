<?php // ongkir.php - revisi penyatuan kolom pencarian dan export Excel
session_start();
date_default_timezone_set('Asia/Jakarta');

// Include PhpSpreadsheet autoloader
$phpSpreadsheetAutoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($phpSpreadsheetAutoloadPath)) {
    require $phpSpreadsheetAutoloadPath;
    define('PHPSPREADSHEET_AVAILABLE', true);
} else {
    define('PHPSPREADSHEET_AVAILABLE', false);
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;


// ====================
// KONFIGURASI DATABASE
// ====================
$db_host = 'sql103.byethost22.com';
$db_name = 'b22_37265128_demoinvoicegenerator';
$db_user = 'b22_37265128';
$db_pass = 'YnScc89#';

$logo_header = "company_logo1.png";

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET time_zone = '+7:00'");
} catch (PDOException $e) { die("Koneksi database gagal di ongkir.php: " . $e->getMessage());}


// ====================
// PARAMETER FILTER & PAGINASI
// ====================
$s_keyword_ongkir = trim($_GET['s_keyword'] ?? '');
$s_date_range_ongkir = $_GET['s_date_range'] ?? '';
$active_s_filters_ongkir_page = array_filter(['s_keyword' => $s_keyword_ongkir, 's_date_range' => $s_date_range_ongkir]);

$items_per_page_ongkir_val = 15;
$current_page_ongkir_val = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page_ongkir_val < 1) $current_page_ongkir_val = 1;
$offset_ongkir_val = ($current_page_ongkir_val - 1) * $items_per_page_ongkir_val;

$ongkir_where_cls_arr = ["nominal_ongkir > 0"];
$ongkir_sql_prms_arr = [];

if (!empty($s_keyword_ongkir)) {
    $search_like_ongkir = "%" . $s_keyword_ongkir . "%";
    $ongkir_where_cls_arr[] = "(no_pesanan LIKE ? OR nama_penerima LIKE ? OR courier_name LIKE ? OR nama_admin LIKE ? OR alamat_penerima LIKE ? OR telepon_penerima LIKE ?)";
    for($i=0; $i<6; $i++) $ongkir_sql_prms_arr[] = $search_like_ongkir;
}
if (!empty($s_date_range_ongkir)) {
    $dates_ongk_filt = explode(' - ', $s_date_range_ongkir);
    if (count($dates_ongk_filt)==2 && trim($dates_ongk_filt[0]) && trim($dates_ongk_filt[1])) {
        $ongkir_where_cls_arr[] = "DATE(created_at) BETWEEN ? AND ?";
        $ongkir_sql_prms_arr[] = trim($dates_ongk_filt[0]); $ongkir_sql_prms_arr[] = trim($dates_ongk_filt[1]);
    } elseif (count($dates_ongk_filt)==1 && trim($dates_ongk_filt[0])) {
        $ongkir_where_cls_arr[] = "DATE(created_at) = ?"; $ongkir_sql_prms_arr[] = trim($dates_ongk_filt[0]);
    }
}
$sql_ongkir_where_cond_str = "WHERE " . implode(" AND ", $ongkir_where_cls_arr);
$ongkir_report_data_list = []; $total_ongkir_items_count = 0; $total_ongkir_pages_num = 1;
$total_nominal_ongkir_rpt_sum = 0; $summary_ongkir_rpt_data = [];

// Helper function to sanitize string for filename
function sanitizeForFilename($string) {
    $string = preg_replace('/[^a-zA-Z0-9_ \.-]/', '', $string);
    $string = preg_replace('/[\s\.]+/', '_', $string);
    $string = substr($string, 0, 50);
    return trim($string, '_');
}

// ====================
// HANDLE EXPORT EXCEL
// ====================
if (isset($_GET['action']) && $_GET['action'] === 'export_excel' && PHPSPREADSHEET_AVAILABLE) {
    try {
        $sql_export = "SELECT no_pesanan, nama_penerima, alamat_penerima, telepon_penerima, courier_name, nominal_ongkir, nama_admin, DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') as tanggal_invoice
                       FROM invoices $sql_ongkir_where_cond_str ORDER BY created_at DESC";
        $stmt_export = $pdo->prepare($sql_export);
        $stmt_export->execute($ongkir_sql_prms_arr);
        $data_to_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

        if (empty($data_to_export)) {
            $_SESSION['error_ongkir'] = "Tidak ada data untuk diexport berdasarkan filter yang dipilih.";
            header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($active_s_filters_ongkir_page) . '#data-results');
            exit();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Ongkir');

        $excel_main_header_text = "Laporan Ongkir";
        $filter_parts = [];
        if (!empty($s_keyword_ongkir)) { $filter_parts[] = "Filter: " . $s_keyword_ongkir; }
        if (!empty($s_date_range_ongkir)) { $filter_parts[] = "Periode: " . $s_date_range_ongkir; }
        $excel_main_header_text .= empty($filter_parts) ? " (Semua Data)" : " (" . implode(" | ", $filter_parts) . ")";

        $sheet->insertNewRowBefore(1, 1);
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', $excel_main_header_text);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(25);

        $headers = ['No.', 'Tgl Invoice', 'No. Pesanan', 'Nama Penerima', 'Alamat', 'Telepon', 'Ekspedisi', 'Nominal Ongkir (Rp)', 'Admin'];
        $sheet->fromArray($headers, NULL, 'A2');
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getStyle('A2:I2')->getFont()->setBold(true);
        $sheet->getStyle('A2:I2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $rowNum = 3; $idx = 1; $total_ongkir_exported = 0;
        foreach ($data_to_export as $row) {
            $sheet->setCellValueExplicit('A' . $rowNum, $idx++, DataType::TYPE_NUMERIC);
            $sheet->setCellValue('B' . $rowNum, $row['tanggal_invoice']);
            $sheet->setCellValueExplicit('C' . $rowNum, $row['no_pesanan'], DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $rowNum, $row['nama_penerima']);
            $sheet->setCellValue('E' . $rowNum, $row['alamat_penerima']);
            $sheet->setCellValueExplicit('F' . $rowNum, $row['telepon_penerima'], DataType::TYPE_STRING);
            $sheet->setCellValue('G' . $rowNum, $row['courier_name']);
            $sheet->setCellValueExplicit('H' . $rowNum, (float)$row['nominal_ongkir'], DataType::TYPE_NUMERIC);
            $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->setCellValue('I' . $rowNum, $row['nama_admin'] ?? '-');
            $total_ongkir_exported += (float)$row['nominal_ongkir'];
            $rowNum++;
        }

        $sheet->setCellValue('G' . $rowNum, 'TOTAL ONGKIR:');
        $sheet->setCellValueExplicit('H' . $rowNum, $total_ongkir_exported, DataType::TYPE_NUMERIC);
        $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('G'.$rowNum.':H'.$rowNum)->getFont()->setBold(true);

        foreach (range('A', 'I') as $columnID) { $sheet->getColumnDimension($columnID)->setAutoSize(true); }
        $styleArray = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000'],],],];
        $sheet->getStyle('A1:I' . ($rowNum))->applyFromArray($styleArray);

        $filename_base = 'Laporan_Ongkir_Yens';
        if (!empty($s_keyword_ongkir)) { $filename_base .= '_Filter_' . sanitizeForFilename($s_keyword_ongkir); }
        if (!empty($s_date_range_ongkir)) {
             $date_part = str_replace(' - ', '_sd_', $s_date_range_ongkir);
             $filename_base .= '_Periode_' . sanitizeForFilename($date_part);
        }
        if (empty($s_keyword_ongkir) && empty($s_date_range_ongkir)){ $filename_base .= '_SemuaData'; }
        $filename = $filename_base . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($spreadsheet); $writer->save('php://output'); exit();

    } catch (Exception $e) {
        $_SESSION['error_ongkir'] = "Gagal membuat file Excel: " . $e->getMessage();
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($active_s_filters_ongkir_page) . '#data-results');
        exit();
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'export_excel' && !PHPSPREADSHEET_AVAILABLE) {
    $_SESSION['error_ongkir'] = "Fitur Export Excel tidak tersedia karena library PhpSpreadsheet tidak ditemukan/dikonfigurasi dengan benar di server.";
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($active_s_filters_ongkir_page) . '#data-results');
    exit();
}


// AMBIL DATA UNTUK DISPLAY HALAMAN
try {
    $stmt_ongk_count=$pdo->prepare("SELECT COUNT(*), SUM(nominal_ongkir) FROM invoices $sql_ongkir_where_cond_str");
    $stmt_ongk_count->execute($ongkir_sql_prms_arr);
    $count_sum_ongk=$stmt_ongk_count->fetch(PDO::FETCH_NUM);
    $total_ongkir_items_count=(int)($count_sum_ongk[0]??0);
    $total_nominal_ongkir_rpt_sum=(float)($count_sum_ongk[1]??0);

    $total_ongkir_pages_num=$total_ongkir_items_count > 0 ? ceil($total_ongkir_items_count/$items_per_page_ongkir_val) : 1;
    if($current_page_ongkir_val > $total_ongkir_pages_num && $total_ongkir_items_count > 0){
        $current_page_ongkir_val = $total_ongkir_pages_num;
        $offset_ongkir_val = ($current_page_ongkir_val - 1) * $items_per_page_ongkir_val;
    }

    $sql_ongk_list_disp = "SELECT id, no_pesanan, nama_penerima, alamat_penerima, telepon_penerima, courier_name, nominal_ongkir, nama_admin, created_at
                           FROM invoices $sql_ongkir_where_cond_str
                           ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $all_display_params = $ongkir_sql_prms_arr;
    $all_display_params[] = (int)$items_per_page_ongkir_val;
    $all_display_params[] = (int)$offset_ongkir_val;
    $stmt_ongk_list_disp = $pdo->prepare($sql_ongk_list_disp);
    $stmt_ongk_list_disp->execute($all_display_params);
    $ongkir_report_data_list = $stmt_ongk_list_disp->fetchAll(PDO::FETCH_ASSOC);

    $sql_ongk_summary_disp="SELECT courier_name,COUNT(*) as total_shipments,SUM(nominal_ongkir) as total_ongkir
                            FROM invoices $sql_ongkir_where_cond_str
                            GROUP BY courier_name ORDER BY total_ongkir DESC";
    $stmt_ongk_summary_disp=$pdo->prepare($sql_ongk_summary_disp);
    $stmt_ongk_summary_disp->execute($ongkir_sql_prms_arr);
    $summary_ongkir_rpt_data=$stmt_ongk_summary_disp->fetchAll(PDO::FETCH_ASSOC);

    // Prepare data for Chart.js
    $chart_labels_json = "[]";
    $chart_data_ongkir_json = "[]";
    if (!empty($summary_ongkir_rpt_data)) {
        $chart_labels = [];
        $chart_data_ongkir = [];
        foreach ($summary_ongkir_rpt_data as $item) {
            $chart_labels[] = $item['courier_name'];
            $chart_data_ongkir[] = (float)$item['total_ongkir'];
        }
        $chart_labels_json = json_encode($chart_labels);
        $chart_data_ongkir_json = json_encode($chart_data_ongkir);
    }

} catch(PDOException $e){ $error_msg_ongkir_page = "Gagal ambil data laporan: ".$e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Ongkir</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="icon" type="image/x-icon" href="yens2.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            color: #333;
            line-height:1.6;
        }
        .page-wrapper {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        * {
            box-sizing: border-box;
        }

        .site-header, .site-footer { background: rgba(44, 62, 80, 0.95); color: white; padding: 10px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); backdrop-filter: blur(5px); }
        .site-header { position: sticky; top: 0; z-index: 1000; }
        .site-header .container, .site-footer .container { max-width:1200px; margin:0 auto; display: flex; justify-content: space-between; align-items: center; width: 100%; }
        .site-header h1 { margin: 0; font-size: 24px; display: flex; align-items: center; gap: 10px; }
        .site-header img { height: 40px; }
        .site-header h1 small { font-size: 14px; opacity: 0.8; }
        .header-actions { display: flex; gap: 10px; }
        .header-actions .refresh-btn { background: rgba(255,255,255,0.1); color: white; border: 2px solid rgba(255,255,255,0.3); padding: 10px 20px; border-radius: 30px; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 12px; text-decoration:none; }
        .header-actions .refresh-btn:hover { background: rgba(255,255,255,0.2); transform: translateY(-1px) scale(1.03); border-color: rgba(255,255,255,0.5); }
        .header-actions .refresh-btn i { transition: transform 0.3s ease; font-size:1.1em; }
        .header-actions .refresh-btn:hover i { transform: rotate(15deg); }
        .header-actions .refresh-btn span { font-weight: 600; letter-spacing: 0.5px; }

        .site-footer { padding: 20px; text-align: center; font-size: 14px; width: 100%; }
        .site-footer .container {justify-content:center; flex-direction:column;}
        .site-footer a { color: #3498db; text-decoration: none; }
        .site-footer a:hover { color: #2980b9; }
        .site-footer .version-info { font-size:0.85em; opacity:0.7; margin-top:5px;}

        .report-container {
            flex-grow: 1;
            max-width: 100%;
            padding: 30px 50px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .report-container h2.report-title { font-size: 1.6em; color: #34495e; margin-bottom: 20px; text-align:center; border-bottom: 1px solid #eee; padding-bottom:15px; }
        .ongkir-filter-form { display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 25px; padding: 15px; background-color:#f8f9fa; border-radius:5px; }
        .ongkir-filter-form .form-group { margin-bottom: 0; }
        .ongkir-filter-form .form-group label { font-size: 0.9em; display:block; margin-bottom:5px; font-weight: bold; color: #2c3e50; }
        .ongkir-filter-form input, .ongkir-filter-form select { width:100%; padding: 8px; font-size:14px; border-radius:4px; border:1px solid #ddd; }
        .ongkir-filter-form select {text-transform:none;}
        .ongkir-filter-form input:focus, .ongkir-filter-form select:focus { border-color: #3498db; outline:none; box-shadow: 0 0 0 2px rgba(52,152,219,.2); }
        .ongkir-filter-form .buttons { grid-column: 1 / -1; display: flex; gap:10px; margin-top:10px; flex-wrap:wrap; }
        .ongkir-filter-form .buttons button, .ongkir-filter-form .buttons a { background: #3498db; color: white !important; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: background 0.3s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .ongkir-filter-form .buttons button:hover, .ongkir-filter-form .buttons a:hover { background: #2980b9; }
        .ongkir-filter-form .buttons a[style*="#f39c12"] { background: #f39c12 !important;}
        .ongkir-filter-form .buttons a[style*="#f39c12"]:hover { background: #e67e22 !important; }
        .ongkir-data-table { font-size: 14px; width: 100%; border-collapse: collapse; margin-top: 20px; }
        .ongkir-data-table th { background: #3498db; color: white; padding: 12px 15px; text-align: left; }
        .ongkir-data-table td { vertical-align: top; padding: 12px 15px; border-bottom:1px solid #eee; }
        .ongkir-data-table td small { font-size: 0.8em; color: #666; display: block; margin-top: 3px; }
        .ongkir-data-table tr:nth-child(even) { background-color: #f9f9f9; }
        .ongkir-data-table tr:hover { background-color: #f1f1f1; }
        .ongkir-data-table .text-right { text-align: right; }
        .total-ongkir-filtered { font-size: 14px; color: #2c3e50; border: 1px solid #3498db; margin: 20px 0; padding: 10px; border-radius: 5px; background: #f8f9fa; text-align:center; font-weight:bold; }
        .total-ongkir-filtered span { color: #2980b9; font-size:1.1em; }

        .summary-section-container {
            margin-top: 40px;
            padding: 25px;
            background-color: #ffffff;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        .summary-title {
            font-size: 1.4em;
            text-align: center;
            margin-bottom: 25px;
            color: #34495e;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        .summary-content-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            align-items: flex-start;
        }
        .summary-table-container {
            flex: 1 1 400px;
            min-width: 300px;
        }
        .summary-ongkir-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 auto;
            border: 1px solid #ccc;
        }
        .summary-ongkir-table th {
            background-color: #4a5258;
            color: white;
            padding: 10px 12px;
            text-align: left;
        }
        .summary-ongkir-table td {
            padding: 10px 12px;
            border: 1px solid #ddd;
        }
        .summary-ongkir-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .summary-ongkir-table .text-right {
            text-align: right;
        }
        .summary-chart-container {
            flex: 1 1 500px;
            min-width: 300px;
            height: 400px;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
        }
        #summaryOngkirChart {
            width: 100% !important;
            height: 100% !important;
        }
        @media (max-width: 992px) {
            .summary-content-wrapper {
                flex-direction: column;
            }
            .summary-table-container,
            .summary-chart-container {
                flex-basis: auto;
                width: 100%;
            }
            .summary-chart-container {
                margin-top: 20px;
                height: 350px;
            }
        }
         @media (max-width: 768px) {
            .report-container { padding: 20px; }
            .ongkir-filter-form { grid-template-columns: 1fr; }
            .summary-chart-container { height: 300px; }
        }


        .pagination { margin-top: 20px; text-align: center; }
        .pagination a, .pagination span { display: inline-block; padding: 8px 14px; margin: 0 4px; border: 1px solid #dee2e6; text-decoration: none; color: #007bff; border-radius: 4px; transition: background-color 0.3s, color 0.3s; font-size:0.9em;}
        .pagination a:hover { background-color: #007bff; color: white; }
        .pagination .current-page { background-color: #007bff; color: white; border-color: #007bff; }
        .pagination .disabled { color: #6c757d; pointer-events: none; background-color: #e9ecef; }
        .alert-ongkir { padding: 15px; margin: 20px 0; border-radius: 5px; font-size: 14px; text-align:center; }
        .alert-ongkir.info { background-color:#e9f7fe; border:1px solid #bce8f1; color:#1c6480; }
        .alert-ongkir.danger { background-color:#f8d7da; border:1px solid #f5c6cb; color:#721c24; }
        .floating-notification { position:fixed; top:20px; right:20px; padding:15px 25px; border-radius:8px; color:white; font-weight:bold; display:flex; align-items:center; gap:10px; box-shadow:0 4px 6px rgba(0,0,0,0.1); opacity:0; transform:translateX(120%); transition:all .5s cubic-bezier(.68,-.55,.265,1.55); z-index:99999; max-width:300px }
        .floating-notification.show { opacity:1; transform:translateX(0) }
        .floating-notification.success { background:#2ecc71; border-left:5px solid #27ae60 }
        .floating-notification.error { background:#e74c3c; border-left:5px solid #c0392b }
        .floating-notification.warning { background:#fff3cd; border-left:5px solid #ffc107; color:#856404 }
        .floating-notification i { font-size:18px }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div id="floating-notification-ongkir" class="floating-notification"></div>
        <header class="site-header">
            <div class="container"><h1><img src="<?= htmlspecialchars($logo_header)?>" alt="Logo Header"><span>khairudinfahmi</span><small>Laporan Ongkir</small></h1><div class="header-actions"><a href="index.php#history" class="refresh-btn"><i class="fas fa-arrow-left"></i><span>Kembali ke Invoice</span></a></div></div>
        </header>

        <div class="report-container">
            <?php if(isset($error_msg_ongkir_page)):?><p class="alert-ongkir danger"><?= htmlspecialchars($error_msg_ongkir_page)?></p><?php endif;?>
            <form method="GET" action="<?=$_SERVER['PHP_SELF']?>#data-results" class="ongkir-filter-form">
                <div class="form-group"><label for="s_keyword_ongkir_input">Cari (No. Pesanan, Penerima, Alamat, Kontak, Ekspedisi, Admin)</label><input type="text" name="s_keyword" id="s_keyword_ongkir_input" value="<?=htmlspecialchars($s_keyword_ongkir)?>" placeholder="Masukkan kata kunci..."></div>
                <div class="form-group"><label for="s_date_range_ongkir_page">Rentang Tanggal</label><input type="text" name="s_date_range" id="s_date_range_ongkir_page" class="flatpickr-range-ongkir" value="<?=htmlspecialchars($s_date_range_ongkir)?>" placeholder="Pilih Rentang..."></div>
                <div class="buttons">
                    <button type="submit"><i class="fas fa-filter"></i> Terapkan Filter</button>
                    <a href="<?=strtok($_SERVER['PHP_SELF'],'?')?>#data-results" style="background:#f39c12;"><i class="fas fa-undo"></i> Reset Filter</a>
                    <?php if (PHPSPREADSHEET_AVAILABLE && $total_ongkir_items_count > 0): ?>
                        <a href="?<?=http_build_query(array_merge($active_s_filters_ongkir_page, ['action'=>'export_excel']))?>" style="background:#198754;"><i class="fas fa-file-excel"></i> Export ke Excel</a>
                    <?php elseif ($total_ongkir_items_count > 0): ?>
                        <span style="font-size:0.8em; color: #6c757d; align-self:center; padding: 10px;">(Export Excel tidak tersedia)</span>
                    <?php endif; ?>
                </div>
            </form>
            <div id="data-results">
                <?php if(empty($ongkir_report_data_list)&&$total_ongkir_items_count==0&&!empty($active_s_filters_ongkir_page)):?><p class="alert-ongkir info">Tidak ada data ongkir cocok filter.</p><?php elseif(empty($ongkir_report_data_list)&&$total_ongkir_items_count==0):?><p class="alert-ongkir info">Belum ada data ongkir.</p><?php else:?>
                <div class="total-ongkir-filtered">Total Ongkir (Sesuai Filter): <span>Rp <?=number_format($total_nominal_ongkir_rpt_sum,0,',','.')?></span> (Dari <?=number_format($total_ongkir_items_count)?> pengiriman)</div>
                <table class="ongkir-data-table"><thead><tr><th>No.</th><th>Tgl Invoice</th><th>No.Pesanan</th><th>Penerima & Alamat</th><th>Kontak</th><th>Ekspedisi</th><th class="text-right">Ongkir(Rp)</th><th>Admin</th></tr></thead><tbody>
                    <?php $ori=$offset_ongkir_val+1;foreach($ongkir_report_data_list as $itm):?>
                    <tr>
                        <td><?=$ori++?></td>
                        <td><?=htmlspecialchars(date('d/m/y H:i',strtotime($itm['created_at'])))?></td>
                        <td><?=htmlspecialchars($itm['no_pesanan'])?></td>
                        <td>
                            <strong><?=htmlspecialchars($itm['nama_penerima'])?></strong><br>
                            <small><?=nl2br(htmlspecialchars($itm['alamat_penerima']))?></small>
                        </td>
                        <td><?=htmlspecialchars($itm['telepon_penerima'])?></td>
                        <td><?=htmlspecialchars($itm['courier_name'])?></td>
                        <td class="text-right"><?=number_format($itm['nominal_ongkir'],0,',','.')?></td>
                        <td><?=htmlspecialchars($itm['nama_admin']??'-')?></td>
                    </tr>
                    <?php endforeach;?>
                </tbody></table>
                <div class="pagination">
                    <?php if($current_page_ongkir_val>1):?><a href="?<?=http_build_query(array_merge($active_s_filters_ongkir_page,['page'=>1]))?>#data-results"><i class="fas fa-angle-double-left"></i></a><a href="?<?=http_build_query(array_merge($active_s_filters_ongkir_page,['page'=>$current_page_ongkir_val-1]))?>#data-results"><i class="fas fa-angle-left"></i></a><?php endif;?>
                    <?php $nlop=2;$spgo=max(1,$current_page_ongkir_val-$nlop);$epgo=min($total_ongkir_pages_num,$current_page_ongkir_val+$nlop);if($spgo>1)echo"<span class=\"disabled\">...</span>";for($i=$spgo;$i<=$epgo;$i++):?><a href="?<?=http_build_query(array_merge($active_s_filters_ongkir_page,['page'=>$i]))?>#data-results" class="<?=($i==$current_page_ongkir_val)?'current-page':''?>"><?=$i?></a><?php endfor;if($epgo<$total_ongkir_pages_num)echo"<span class=\"disabled\">...</span>";?>
                    <?php if($current_page_ongkir_val<$total_ongkir_pages_num):?><a href="?<?=http_build_query(array_merge($active_s_filters_ongkir_page,['page'=>$current_page_ongkir_val+1]))?>#data-results"><i class="fas fa-angle-right"></i></a><a href="?<?=http_build_query(array_merge($active_s_filters_ongkir_page,['page'=>$total_ongkir_pages_num]))?>#data-results"><i class="fas fa-angle-double-right"></i></a><?php endif;?>
                </div><p style="text-align:center;margin-top:10px;font-size:0.9em;">Menampilkan <?=count($ongkir_report_data_list)?> dari <?=$total_ongkir_items_count?> data. (Hal <?=$current_page_ongkir_val?> dari <?=$total_ongkir_pages_num?>)</p><?php endif;?>
            </div>

            <?php if(!empty($summary_ongkir_rpt_data) && $total_ongkir_items_count > 0): ?>
            <div class="summary-section-container">
                <h3 class="summary-title">Ringkasan Ekspedisi</h3>
                <div class="summary-content-wrapper">
                    <div class="summary-table-container">
                        <table class="summary-ongkir-table">
                            <thead>
                                <tr>
                                    <th>Ekspedisi</th>
                                    <th class="text-right">Jumlah Kiriman</th>
                                    <th class="text-right">Total Ongkir(Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($summary_ongkir_rpt_data as $s_itm):?>
                                <tr>
                                    <td><?=htmlspecialchars($s_itm['courier_name'])?></td>
                                    <td class="text-right"><?=number_format($s_itm['total_shipments'])?></td>
                                    <td class="text-right"><?=number_format($s_itm['total_ongkir'],0,',','.')?></td>
                                </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <div class="summary-chart-container">
                        <canvas id="summaryOngkirChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif;?>
        </div>


        <footer class="site-footer">
            <div class="container">
                <p>© <?=date('Y')?> Laporan Ongkir</p>
                <p class="version-info">Developed with ❤️ by Fahmi Khairudin</p>
            </div>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script><script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script>
    <script>
        function showOngkirNotification(type,message,duration=3500){const notification=document.getElementById('floating-notification-ongkir');if(!notification)return;notification.className=`floating-notification ${type} show`;let iconClass='fas fa-info-circle';if(type==='success')iconClass='fas fa-check-circle';if(type==='error')iconClass='fas fa-exclamation-circle';if(type==='warning')iconClass='fas fa-exclamation-triangle';while(notification.firstChild)notification.removeChild(notification.firstChild);const iconEl=document.createElement('i');iconEl.className=iconClass;notification.appendChild(iconEl);notification.appendChild(document.createTextNode(" "+message));setTimeout(()=>notification.classList.remove('show'),duration);}
        
        document.addEventListener('DOMContentLoaded',function(){
            flatpickr(".flatpickr-range-ongkir",{mode:"range",dateFormat:"Y-m-d",locale:"id"});
            if(window.location.hash==='#data-results'){
                const ds=document.getElementById('data-results');
                if(ds)setTimeout(()=>ds.scrollIntoView({behavior:'smooth',block:'start'}),100);
            }
            <?php if(isset($_SESSION['error_ongkir'])): echo "showOngkirNotification('error','".addslashes(htmlspecialchars($_SESSION['error_ongkir']))."');"; unset($_SESSION['error_ongkir']); endif; ?>
            <?php if(isset($_SESSION['warning_ongkir'])): echo "showOngkirNotification('warning','".addslashes(htmlspecialchars($_SESSION['warning_ongkir']))."', 5000);"; unset($_SESSION['warning_ongkir']); endif; ?>
        
// Fixed Chart.js configuration for displaying ongkir totals
<?php if (!empty($summary_ongkir_rpt_data) && $total_ongkir_items_count > 0): ?>
const ctxSummaryOngkir = document.getElementById('summaryOngkirChart');
if (ctxSummaryOngkir) {
    const courierLabels = <?= $chart_labels_json ?>;
    const ongkirData = <?= $chart_data_ongkir_json ?>;

    const defaultColors = [
        'rgba(54, 162, 235, 0.3)', 'rgba(255, 99, 132, 0.3)',
        'rgba(255, 206, 86, 0.3)', 'rgba(75, 192, 192, 0.3)',
        'rgba(153, 102, 255, 0.3)', 'rgba(255, 159, 64, 0.3)',
        'rgba(199, 199, 199, 0.3)', 'rgba(83, 102, 255, 0.3)',
        'rgba(40, 159, 64, 0.3)', 'rgba(210, 99, 132, 0.3)'
    ];
    
    const borderColors = [
        'rgba(54, 162, 235, 0.8)', 'rgba(255, 99, 132, 0.8)',
        'rgba(255, 206, 86, 0.8)', 'rgba(75, 192, 192, 0.8)',
        'rgba(153, 102, 255, 0.8)', 'rgba(255, 159, 64, 0.8)',
        'rgba(199, 199, 199, 0.8)', 'rgba(83, 102, 255, 0.8)',
        'rgba(40, 159, 64, 0.8)', 'rgba(210, 99, 132, 0.8)'
    ];

    const backgroundColors = [];
    const borderColorsArray = [];

    for(let i = 0; i < courierLabels.length; i++) {
        backgroundColors.push(defaultColors[i % defaultColors.length]);
        borderColorsArray.push(borderColors[i % borderColors.length]);
    }

    new Chart(ctxSummaryOngkir, {
        type: 'bar',
        data: {
            labels: courierLabels,
            datasets: [{
                label: 'Total Ongkir (Rp)',
                data: ongkirData,
                backgroundColor: backgroundColors,
                borderColor: borderColorsArray,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        font: {
                            size: 12
                        },
                        // Custom legend generation to show courier names with values
                        generateLabels: function(chart) {
                            const labels = [];
                            const data = chart.data;
                            if (data.labels.length && data.datasets.length) {
                                data.labels.forEach((label, i) => {
                                    const dataset = data.datasets[0];
                                    const value = dataset.data[i];
                                    labels.push({
                                        text: `${label}: Rp ${new Intl.NumberFormat('id-ID').format(value)}`,
                                        fillStyle: dataset.backgroundColor[i],
                                        strokeStyle: dataset.borderColor[i],
                                        lineWidth: dataset.borderWidth,
                                        hidden: false,
                                        index: i
                                    });
                                });
                            }
                            return labels;
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'Total Ongkir per Ekspedisi',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: {
                        top: 10,
                        bottom: 20
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    titleFont: { size: 14 },
                    bodyFont: { size: 12 },
                    padding: 10,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            if (label) { label += ': '; }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('id-ID', { 
                                    style: 'currency', 
                                    currency: 'IDR', 
                                    minimumFractionDigits: 0 
                                }).format(context.parsed.y);
                            }
                            return label;
                        },
                        afterLabel: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed.y / total) * 100).toFixed(1);
                            return `(${percentage}% dari total)`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Total Ongkir (Rupiah)',
                        font: { 
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    ticks: {
                        callback: function(value, index, values) {
                            return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
                        },
                        font: { size: 11 }
                    },
                    grid: {
                        color: 'rgba(200, 200, 200, 0.3)',
                        drawBorder: true
                    }
                },
                x: {
                    title: {
                        display: false, // HAPUS TULISAN "EKSPEDISI" DI SINI
                        text: 'Ekspedisi',
                        font: { 
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    ticks: {
                        font: { size: 11 },
                        maxRotation: 45,
                        minRotation: 0
                    },
                    grid: {
                        display: false
                    }
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeInOutQuart'
            },
            onHover: (event, activeElements) => {
                event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
            }
        },
        plugins: [{
            // Custom plugin to display values on top of bars
            afterDatasetsDraw: function(chart) {
                const ctx = chart.ctx;
                chart.data.datasets.forEach((dataset, i) => {
                    const meta = chart.getDatasetMeta(i);
                    if (!meta.hidden) {
                        meta.data.forEach((element, index) => {
                            ctx.fillStyle = 'rgb(50, 50, 50)';
                            ctx.font = 'bold 11px Arial';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';

                            const dataString = 'Rp ' + new Intl.NumberFormat('id-ID').format(dataset.data[index]);
                            ctx.fillText(dataString, element.x, element.y - 5);
                        });
                    }
                });
            }
        }]
    });
}
<?php endif; ?>
        });
    </script>
</body>
</html>