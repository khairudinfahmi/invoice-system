<?php
session_start();

// Handle reset session request (dipakai oleh tombol Buat Baru & Refresh)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_session'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token on reset!");
    }
    unset($_SESSION['last_input']);
    unset($_SESSION['default_nama_admin']);

    $destination_url = strtok($_SERVER['PHP_SELF'], '?');
    $destination_url .= '#form-invoice';

    header("Location: " . $destination_url);
    exit();
}


date_default_timezone_set('Asia/Jakarta');

// ====================
// KONFIGURASI DATABASE
// ====================
$db_host = 'localhost';
$db_name = 'demoinvoicegenerator';
$db_user = 'root';
$db_pass = '';


try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET time_zone = '+7:00'");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id INT PRIMARY KEY AUTO_INCREMENT,
            no_pesanan VARCHAR(50) NOT NULL UNIQUE,
            nama_penerima VARCHAR(100) NOT NULL,
            alamat_penerima TEXT NOT NULL,
            telepon_penerima VARCHAR(20) NOT NULL,
            courier_name VARCHAR(50) NOT NULL,
            nominal_ongkir DECIMAL(10,2) NOT NULL DEFAULT 0,
            nama_admin VARCHAR(100) NULL,
            created_at DATETIME NOT NULL
        )
    ");

    try { $pdo->exec("ALTER TABLE invoices ADD COLUMN nominal_ongkir DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER courier_name"); }
    catch (PDOException $e) { if ($e->errorInfo[1] !== 1060) throw $e; }
    try { $pdo->exec("ALTER TABLE invoices ADD COLUMN nama_admin VARCHAR(100) NULL AFTER nominal_ongkir"); }
    catch (PDOException $e) { if ($e->errorInfo[1] !== 1060) throw $e; }

} catch (PDOException $e) { die("Koneksi atau inisialisasi database gagal: " . $e->getMessage()); }

// ====================
// KONFIGURASI SISTEM & DATA AWAL
// ====================
$logos_dir = "courier_logos/";
$default_company_logo = "company_logo.png";
$logo_header = "company_logo1.png";
$available_couriers = [
    'id1' => 'ID Express', 'id2' => 'ID Truck', 'gosend' => 'GoSend',
    'grab' => 'Grab Express', 'jtr' => 'JTR', 'jne' => 'JNE', 'spx' => 'SPX Instant',
    'pos' => 'Pos Aja', 'jnt' => 'J&T Express', 'jntcargo' => 'J&T Cargo',
    'jneyes' => 'JNE Yes', 'travel' => 'Travel'
];
// DIHAPUS: 'better' dari array ini
$couriers_with_optional_ongkir = ['gosend', 'grab', 'spx'];

$fixed_sender = [
    'nama_pengirim' => "Toko Kamu", 'alamat_pengirim' => "Jl. Suka Kamu",
    'kota_pengirim' => "Kec. Sample, Kota Sample, Jawa Barat 111111", 'telepon_pengirim' => "777777777777"
];
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$distinct_admins = [];
try {
    $stmt_admins = $pdo->query("SELECT DISTINCT nama_admin FROM invoices WHERE nama_admin IS NOT NULL AND nama_admin != '' ORDER BY nama_admin ASC");
    $distinct_admins = $stmt_admins->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { /* Abaikan */ }

// ====================
// PROSES CRUD
// ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) && !isset($_POST['reset_session'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("Invalid CSRF token!");

    $id = $_POST['id'] ?? null;
    $courier_key = $_POST['courier'] ?? '';
    $selected_courier = $available_couriers[$courier_key] ?? '';

    if (empty($selected_courier)) {
        $_SESSION['error'] = "Pilih ekspedisi terlebih dahulu!";
        $_SESSION['last_input_error'] = $_POST;
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice');
        exit();
    }

    $nama_penerima_raw = trim($_POST['nama_penerima'] ?? '');
    if (strlen($nama_penerima_raw) > 60) {
        $_SESSION['error'] = "Nama Penerima maksimal 60 karakter!";
        $_SESSION['last_input_error'] = $_POST;
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice');
        exit();
    }
    $nama_penerima = strtoupper($nama_penerima_raw);

    $alamat_penerima = strtoupper(substr(trim($_POST['alamat_penerima'] ?? ''), 0, 200));
    $telepon_penerima = preg_replace('/[^0-9]/', '', $_POST['telepon_penerima'] ?? '');
    $telepon_penerima = substr($telepon_penerima, 0, 15);

    $nama_admin_selected = $_POST['nama_admin_select'] ?? '';
    $other_nama_admin = trim($_POST['other_nama_admin'] ?? '');
    $nama_admin_final = '';

    if ($nama_admin_selected === 'other') {
        $nama_admin_final = $other_nama_admin;
    } elseif (!empty($nama_admin_selected)) {
        $nama_admin_final = $nama_admin_selected;
    }

    if (empty($nama_admin_final)) {
        $_SESSION['error'] = "Nama Admin wajib diisi!"; $_SESSION['last_input_error'] = $_POST;
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
    }
    $_SESSION['default_nama_admin'] = $nama_admin_final;

    // PERUBAHAN: Ongkir logic based on courier type
    $is_special_courier = in_array($courier_key, $couriers_with_optional_ongkir);
    $nominal_ongkir_raw = $_POST['nominal_ongkir'] ?? '0';
    $nominal_ongkir_final = 0.00;

    if ($is_special_courier) {
        $ongkir_option_selected = $_POST['ongkir_option'] ?? 'tidak';
        if ($ongkir_option_selected === 'ya') {
            if (empty($nominal_ongkir_raw) || (float)str_replace(['.', ','], ['', '.'], $nominal_ongkir_raw) <= 0) {
                $_SESSION['error'] = "Nominal Ongkir wajib diisi dan lebih besar dari 0 jika opsi 'Ya' dipilih!"; $_SESSION['last_input_error'] = $_POST;
                header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
            }
            $nominal_ongkir_final = (float)str_replace(['.', ','], ['', '.'], $nominal_ongkir_raw);
        }
        // else $nominal_ongkir_final remains 0.00
    } else { // For other couriers, ongkir is mandatory
        $ongkir_option_selected = 'ya'; // Force this
        if (empty($nominal_ongkir_raw) || (float)str_replace(['.', ','], ['', '.'], $nominal_ongkir_raw) <= 0) {
            $_SESSION['error'] = "Nominal Ongkir wajib diisi dan lebih besar dari 0 untuk ekspedisi ini!"; $_SESSION['last_input_error'] = $_POST;
            header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
        }
        $nominal_ongkir_final = (float)str_replace(['.', ','], ['', '.'], $nominal_ongkir_raw);
    }
    // END PERUBAHAN Ongkir Logic

    $no_pesanan_input_val = strtoupper(trim($_POST['no_pesanan'] ?? ''));

    if(empty($no_pesanan_input_val) && !$id) {
        try {
            $pdo->beginTransaction(); $current_date_leg = date('ymd');
            $stmt_leg_no = $pdo->prepare("SELECT MAX(no_pesanan) FROM invoices WHERE no_pesanan LIKE ? FOR UPDATE");
            $stmt_leg_no->execute(["JO-{$current_date_leg}%-S"]); $last_no_leg = $stmt_leg_no->fetchColumn();
            $seq_leg = 1;
            if ($last_no_leg && preg_match('/JO-(\d{6})(\d{4})-S/', $last_no_leg, $matches_leg)) { $seq_leg = (int)$matches_leg[2] + 1; }
            $no_pesanan_input_val = sprintf("JO-%s%04d-S", $current_date_leg, $seq_leg);
            $pdo->commit();
        } catch(Exception $e) {
            $pdo->rollBack(); $no_pesanan_input_val = 'ERR-' . substr(uniqid(), -8) . substr(time(), -8);
            $_SESSION['warning'] = "Gagal generate nomor otomatis: " . $e->getMessage() . ". Nomor sementara digunakan.";
        }
    }

    if(strlen($no_pesanan_input_val) > 20) {
        $_SESSION['error'] = "Nomor pesanan maksimal 20 karakter!"; $_SESSION['last_input_error'] = $_POST;
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
    }
    $no_pesanan_to_save = $no_pesanan_input_val;

    try {
        $stmt_chk_exist = $pdo->prepare("SELECT id FROM invoices WHERE no_pesanan = ?"); $stmt_chk_exist->execute([$no_pesanan_to_save]);
        $existing_inv = $stmt_chk_exist->fetch();
        if ($existing_inv && $existing_inv['id'] != $id) {
            $_SESSION['error'] = "Nomor pesanan '$no_pesanan_to_save' sudah digunakan!"; $_SESSION['last_input_error'] = $_POST;
            header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
        }
    } catch(PDOException $e) {
        $_SESSION['error'] = "Error checking duplicate: " . $e->getMessage(); $_SESSION['last_input_error'] = $_POST;
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
    }

    try {
        $current_datetime_val = date('Y-m-d H:i:s');
        if($id) {
            $stmt_upd = $pdo->prepare("UPDATE invoices SET no_pesanan = ?, nama_penerima = ?, alamat_penerima = ?, telepon_penerima = ?, courier_name = ?, nominal_ongkir = ?, nama_admin = ?, created_at = ? WHERE id = ?");
            $stmt_upd->execute([$no_pesanan_to_save, $nama_penerima, $alamat_penerima, $telepon_penerima, $selected_courier, $nominal_ongkir_final, $nama_admin_final, $current_datetime_val, $id]);
            $_SESSION['success'] = "Invoice berhasil diupdate lur!";
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO invoices (no_pesanan, nama_penerima, alamat_penerima, telepon_penerima, courier_name, nominal_ongkir, nama_admin, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$no_pesanan_to_save, $nama_penerima, $alamat_penerima, $telepon_penerima, $selected_courier, $nominal_ongkir_final, $nama_admin_final, $current_datetime_val]);
            $id = $pdo->lastInsertId();
            $_SESSION['success'] = "Invoice berhasil dibuat lur!";
        }
        $_SESSION['last_input'] = ['id' => $id, 'no_pesanan' => $no_pesanan_to_save, 'nama_penerima' => $nama_penerima, 'alamat_penerima' => $alamat_penerima, 'telepon_penerima' => $telepon_penerima, 'courier' => $courier_key, 'nominal_ongkir' => $nominal_ongkir_final, 'nama_admin' => $nama_admin_final, 'ongkir_option' => $ongkir_option_selected]; // Storing $ongkir_option_selected is important
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') ."#preview"); exit();
    } catch(PDOException $e) {
        if($e->errorInfo[1] === 1062) { $_SESSION['error'] = "Nomor pesanan '$no_pesanan_to_save' sudah digunakan (kemungkinan ada race condition, coba lagi)!"; }
        else { $_SESSION['error'] = "Error menyimpan invoice: " . $e->getMessage(); }
        $_SESSION['last_input_error'] = $_POST; $_SESSION['last_input_error']['nama_admin_select'] = $nama_admin_selected;
        header("Location: ".$_SERVER['PHP_SELF']. (isset($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '') . '#form-invoice'); exit();
    }
}

if(isset($_GET['edit'])) {
    try {
        $stmt_edit = $pdo->prepare("SELECT * FROM invoices WHERE id = ?"); $stmt_edit->execute([$_GET['edit']]);
        $edit_data_form = $stmt_edit->fetch(PDO::FETCH_ASSOC);
        if($edit_data_form) {
            $edited_courier_key = array_search($edit_data_form['courier_name'], $available_couriers);
            $is_special_courier_edit = in_array($edited_courier_key, $couriers_with_optional_ongkir);
            
            $_SESSION['last_input'] = [
                'id' => $edit_data_form['id'], 'no_pesanan' => $edit_data_form['no_pesanan'],
                'nama_penerima' => $edit_data_form['nama_penerima'], 'alamat_penerima' => $edit_data_form['alamat_penerima'],
                'telepon_penerima' => $edit_data_form['telepon_penerima'], 'nominal_ongkir' => (float)$edit_data_form['nominal_ongkir'],
                'courier' => $edited_courier_key,
                'nama_admin' => $edit_data_form['nama_admin'],
                // PERUBAHAN: ongkir_option logic for edit
                'ongkir_option' => $is_special_courier_edit ? (((float)$edit_data_form['nominal_ongkir'] > 0) ? 'ya' : 'tidak') : 'ya'
            ];
            $redirect_params = $_GET; unset($redirect_params['edit']);
            header("Location: " . $_SERVER['PHP_SELF'] . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : '') . '#form-invoice'); exit();
        } else {
            $_SESSION['error'] = "Invoice untuk diedit tidak ditemukan!"; header("Location: ".$_SERVER['PHP_SELF'] . '#history'); exit();
        }
    } catch(PDOException $e) { $_SESSION['error'] = "Error fetching for edit: " . $e->getMessage(); header("Location: ".$_SERVER['PHP_SELF'] . '#history'); exit(); }
}

if (isset($_GET['print'])) {
    try {
        $stmt_print = $pdo->prepare("SELECT * FROM invoices WHERE id = ?"); $stmt_print->execute([$_GET['print']]);
        $print_db_data = $stmt_print->fetch(PDO::FETCH_ASSOC);
        if (!$print_db_data) {
            $_SESSION['error'] = "Invoice untuk dicetak tidak ditemukan!"; $qs = !empty($_GET)?http_build_query(array_diff_key($_GET,['print'=>''])):'';
            header("Location: ".$_SERVER['PHP_SELF'] . ($qs ? '?'.$qs : '') . '#history'); exit();
        }
        $printed_courier_key = array_search($print_db_data['courier_name'], $available_couriers);
        $is_special_courier_print = in_array($printed_courier_key, $couriers_with_optional_ongkir);

        $_SESSION['last_input'] = [
            'id' => $print_db_data['id'], 'no_pesanan' => $print_db_data['no_pesanan'],
            'nama_penerima' => $print_db_data['nama_penerima'], 'alamat_penerima' => $print_db_data['alamat_penerima'],
            'telepon_penerima' => $print_db_data['telepon_penerima'],
            'courier' => $printed_courier_key,
            'nominal_ongkir' => (float)$print_db_data['nominal_ongkir'], 'nama_admin' => $print_db_data['nama_admin'],
             // PERUBAHAN: ongkir_option logic for print (affects preview if print is clicked)
            'ongkir_option' => $is_special_courier_print ? (((float)$print_db_data['nominal_ongkir'] > 0) ? 'ya' : 'tidak') : 'ya'
        ];
        $qs_print = !empty($_GET)?http_build_query(array_diff_key($_GET,['print'=>''])):'';
        header("Location: ".$_SERVER['PHP_SELF'] . ($qs_print ? '?'.$qs_print : '') . "#print_trigger"); exit();
    } catch(PDOException $e) { $_SESSION['error']="Error fetch print: ".$e->getMessage();$qs_pe = !empty($_GET)?http_build_query(array_diff_key($_GET,['print'=>''])):'';header("Location: ".$_SERVER['PHP_SELF'].($qs_pe?'?'.$qs_pe:'') . '#history'); exit(); }
}

$last_input_values_for_form = $_SESSION['last_input_error'] ?? $_SESSION['last_input'] ?? [];
if (isset($_SESSION['last_input_error'])) unset($_SESSION['last_input_error']);
if (isset($_SESSION['last_input'])) unset($_SESSION['last_input']);

// PERUBAHAN: Ongkir display logic for form field
$current_courier_key_form = $last_input_values_for_form['courier'] ?? '';
$is_special_courier_form = in_array($current_courier_key_form, $couriers_with_optional_ongkir);

if ($is_special_courier_form) {
    $ongkir_option_form_display = $last_input_values_for_form['ongkir_option'] ?? 'tidak';
} else {
    // For other couriers, if a courier is selected, ongkir option is always 'ya'
    // If no courier selected yet, default to 'tidak' to avoid showing input initially
    // (JS will handle it once a non-special courier is selected)
    $ongkir_option_form_display = !empty($current_courier_key_form) ? 'ya' : 'tidak';
}


$nominal_ongkir_for_form_field = 0.00;
if (isset($last_input_values_for_form['nominal_ongkir'])) {
    if((float)$last_input_values_for_form['nominal_ongkir'] > 0){
        $nominal_ongkir_for_form_field = (float)$last_input_values_for_form['nominal_ongkir'];
        if($ongkir_option_form_display === 'tidak' && ($is_special_courier_form || empty($current_courier_key_form)) ) { // only switch to 'ya' if it was 'tidak' and it's a special courier OR no courier picked yet
             $ongkir_option_form_display = 'ya';
        }
    } elseif ($ongkir_option_form_display === 'ya'){
         $nominal_ongkir_for_form_field = (float)str_replace(['.',','],['','.'], $last_input_values_for_form['nominal_ongkir'] ?? '0');
    }
}
// END PERUBAHAN Ongkir display logic for form field

$auto_gen_no_pesanan_field = $last_input_values_for_form['no_pesanan'] ?? '';
if (empty($auto_gen_no_pesanan_field) && !isset($last_input_values_for_form['id'])) {
    try {
        $pdo->beginTransaction(); $cur_dt_form = date('ymd');
        $stmt_formno = $pdo->prepare("SELECT MAX(no_pesanan) FROM invoices WHERE no_pesanan LIKE ? FOR UPDATE");
        $stmt_formno->execute(["JO-{$cur_dt_form}%-S"]); $last_no_form = $stmt_formno->fetchColumn();
        $seq_form = 1;
        if ($last_no_form && preg_match('/JO-(\d{6})(\d{4})-S/', $last_no_form, $m_form)) { $seq_form = (int)$m_form[2] + 1; }
        $auto_gen_no_pesanan_field = sprintf("JO-%s%04d-S", $cur_dt_form, $seq_form);
        if(strlen($auto_gen_no_pesanan_field) > 20) $auto_gen_no_pesanan_field = substr($auto_gen_no_pesanan_field,0,20);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack(); $auto_gen_no_pesanan_field = 'ERR-' . substr(uniqid(), -8) . substr(time(),-8);
        if(strlen($auto_gen_no_pesanan_field) > 20) $auto_gen_no_pesanan_field = substr($auto_gen_no_pesanan_field,0,20);
    }
}

$invoice_data_for_preview_a6 = [];
if (!empty($last_input_values_for_form) && !empty($last_input_values_for_form['no_pesanan'])) {
    $courier_key_a6 = $last_input_values_for_form['courier'] ?? ''; $courier_name_a6 = '';
    if(!empty($courier_key_a6) && isset($available_couriers[$courier_key_a6])) $courier_name_a6 = $available_couriers[$courier_key_a6];
    else { $courier_name_a6 = $last_input_values_for_form['courier_name'] ?? 'Tidak Diketahui'; if($courier_name_a6 !== 'Tidak Diketahui' && empty($courier_key_a6)){ $fk = array_search($courier_name_a6, $available_couriers); if($fk !== false) $courier_key_a6 = $fk;}}
    $is_express_a6 = in_array($courier_key_a6, ['grab', 'gosend', 'spx']);
    $shipping_date_a6 = date('d/m/Y', strtotime('+4 days'));
    if ($courier_key_a6 === 'jneyes') $shipping_date_a6 = date('d/m/Y',strtotime('+1 day')); elseif ($is_express_a6) $shipping_date_a6 = date('d/m/Y H:i',strtotime('+3 hours'));
    $logo_path_a6 = $logos_dir . 'default.png'; if(!empty($courier_key_a6) && file_exists($logos_dir.$courier_key_a6.'.png')) $logo_path_a6=$logos_dir.$courier_key_a6.'.png';

    $nama_penerima_preview = $last_input_values_for_form['nama_penerima'] ?? 'N/A';

    $invoice_data_for_preview_a6 = array_merge($fixed_sender, [
        'company_logo'=>$default_company_logo,
        'expedition_logo'=>$logo_path_a6,
        'courier_name'=>$courier_name_a6,
        'no_pesanan'=>$last_input_values_for_form['no_pesanan'],
        'nama_penerima'=> $nama_penerima_preview,
        'alamat_penerima'=>$last_input_values_for_form['alamat_penerima']??'N/A',
        'telepon_penerima'=>$last_input_values_for_form['telepon_penerima']??'N/A',
        'shipping_date'=>$shipping_date_a6,
    ]);
}

$search_keyword_hist = trim($_GET['search_keyword'] ?? '');
$search_date_range_hist = $_GET['search_date_range'] ?? '';
$active_search_params_hist = array_filter(['search_keyword' => $search_keyword_hist, 'search_date_range' => $search_date_range_hist]);
$items_per_pg_hist = 10; $cur_pg_hist = isset($_GET['page'])?(int)$_GET['page']:1; if($cur_pg_hist<1)$cur_pg_hist=1; $offset_pg_hist = ($cur_pg_hist-1)*$items_per_pg_hist;
$hist_where_arr = []; $hist_params_arr = [];
if (!empty($search_keyword_hist)) { $search_like = "%".$search_keyword_hist."%"; $hist_where_arr[] = "(no_pesanan LIKE ? OR nama_penerima LIKE ? OR courier_name LIKE ? OR nama_admin LIKE ?)"; for($i=0;$i<4;$i++)$hist_params_arr[]=$search_like; }
if (!empty($search_date_range_hist)) { $d_hist=explode(' - ',$search_date_range_hist); if(count($d_hist)==2&&trim($d_hist[0])&&trim($d_hist[1])){$hist_where_arr[]="DATE(created_at) BETWEEN ? AND ?";$hist_params_arr[]=trim($d_hist[0]);$hist_params_arr[]=trim($d_hist[1]);}elseif(count($d_hist)==1&&trim($d_hist[0])){$hist_where_arr[]="DATE(created_at) = ?";$hist_params_arr[]=trim($d_hist[0]);}} // FIXED: Changed ' to ' to ' - '
$sql_hist_cond = ""; if(!empty($hist_where_arr)){$sql_hist_cond="WHERE ".implode(" AND ",$hist_where_arr);}
$history_list_data=[]; $total_hist_pg_items=0; $total_hist_pgs=1; $total_today_val=0;
try {
    $stmt_hist_cnt=$pdo->prepare("SELECT COUNT(*) FROM invoices $sql_hist_cond"); $stmt_hist_cnt->execute($hist_params_arr);
    $total_hist_pg_items=(int)$stmt_hist_cnt->fetchColumn();
    $total_hist_pgs=$total_hist_pg_items>0?ceil($total_hist_pg_items/$items_per_pg_hist):1;
    if($cur_pg_hist>$total_hist_pgs&&$total_hist_pg_items>0){$cur_pg_hist=$total_hist_pgs;$offset_pg_hist=($cur_pg_hist-1)*$items_per_pg_hist;}

    $sql_hist_data="SELECT * FROM invoices $sql_hist_cond ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $all_query_params_hist = $hist_params_arr;
    $all_query_params_hist[] = (int)$items_per_pg_hist;
    $all_query_params_hist[] = (int)$offset_pg_hist;

    $stmt_hist_data=$pdo->prepare($sql_hist_data);
    $stmt_hist_data->execute($all_query_params_hist);
    $history_list_data=$stmt_hist_data->fetchAll(PDO::FETCH_ASSOC);

    $stmt_td_cnt=$pdo->query("SELECT COUNT(*) FROM invoices WHERE DATE(CONVERT_TZ(created_at,@@session.time_zone,'+07:00'))=CURDATE()");
    $total_today_val=$stmt_td_cnt->fetchColumn()?:0;
}catch(PDOException $e){$_SESSION['error_history']="Gagal ambil riwayat: ".$e->getMessage();}
$bulan_map_indo=[1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
$tanggal_sekarang_str = date('j').' '.$bulan_map_indo[date('n')].' '.date('Y');
$is_editing_current_form = isset($last_input_values_for_form['id']) && $last_input_values_for_form['id'] != '';

// PERUBAHAN: Pass special couriers to JavaScript
$js_couriers_with_optional_ongkir = json_encode($couriers_with_optional_ongkir);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="icon" type="image/x-icon" href="logo.png">
    <title>Invoice Generator</title>
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
    .main-content {
        flex-grow: 1;
    }

    * {
        box-sizing: border-box;
    }

    .invoice-container { width: 105mm; min-height: 148mm; margin: 20px auto; padding: 2mm 3mm; background: white; font-family: 'Arial', sans-serif; font-size: 12px; border: 1px solid #000 !important; position: relative; }
    .invoice-border { position: absolute; border: 1px solid #000; top: -2px; left: -2px; right: -2px; bottom: -2px; pointer-events: none; }
    .invoice-container .header, .invoice-container .order-number, .invoice-container .data-container { border-bottom: 0.5px solid #000; padding: 2mm 0; }
    .invoice-container .invoice-footer { border-top: 0.5px solid #333; margin-top: 0; padding-top: 2mm; font-size: 8px; text-align: center; }
    .invoice-container .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2mm; }
    .invoice-container .logo { width: 30mm; height: 30mm; object-fit: contain; padding: 2mm; background: white; border: 1px solid #ddd; border-radius: 3mm; }
    .invoice-container .company-info { text-align: center; flex-grow: 1; padding: 0 3mm; }
    .invoice-container .company-name { font-size: 14px; font-weight: bold; margin-bottom: 2mm; color: #333; }
    .invoice-container .company-address { font-size: 10px; line-height: 1.4; color: #666; margin-bottom: 1mm; }
    .invoice-container .company-phone { font-size: 11px; font-weight: bold; color: #444; }
    .invoice-container .order-number { text-align: center; font-size: 12px; font-weight: bold; margin: 3mm 0; padding: 1mm 0 !important; background: #f5f5f5; border-radius: 2mm; }
    .invoice-container .data-container { display: flex; justify-content: space-between; gap: 2mm; margin-top: 1mm; width: 100%; }
    .invoice-container .data-box { padding: 1mm; border: 1px solid #ddd; border-radius: 3mm; background: #f9f9f9; box-sizing: border-box; display:flex; flex-direction:column;}
    .invoice-container .data-box:first-child { width: 65%; padding: 1mm 2mm; min-height:50mm;}
    .invoice-container .data-box:last-child { width: 33%; min-height:40mm;}
    .invoice-container .data-title { font-size: 12px; font-weight: bold; margin-bottom: 1mm; color: #2c3e50; border-bottom: 1px solid #ddd; padding-bottom: 0.5mm; }
    .invoice-container .data-content { font-size: 10px; line-height: 1.3; color: #34495e; flex-grow:1;}
    .invoice-container .data-header { display: flex; align-items: baseline; margin-bottom: 0.5mm; padding-bottom: 1mm; border-bottom: 1px solid #ddd; gap: 2mm; }
    .invoice-container .data-header .data-title { font-size: 13px; flex-shrink: 0; border-bottom: none !important; }
    .invoice-container .nama-penerima { font-size: 13px; font-weight: bold; color: #2c3e50; white-space: normal; word-break: break-word; text-align: left; margin-left: 0; line-height: 1.2; flex-grow:1;}
    .invoice-container .data-box:first-child .data-content { font-size: 13px; line-height: 1.3; margin-top: -1mm; padding: 1mm 1mm 0; display: flex; flex-direction: column; justify-content: center; height: calc(100% - 30px); }
    .invoice-container .data-box:first-child .data-content > div:first-child { flex-grow: 1; display: flex; align-items: center; margin: 1mm 0; }
    .invoice-container .data-box:first-child .data-content div { margin: 1.5mm 0; }
    .invoice-container .telepon-penerima { margin-top: auto; padding-top: 1mm; font-weight: bold;}
    .invoice-container .data-box:first-child .data-content div:last-child { margin-top: 3mm; font-weight: bold; }
    .invoice-container .important-note { border-color: #e74c3c !important; background: #fdedec !important; }
    .invoice-container .important-note .data-title { color: #e74c3c !important; border-color: #e74c3c !important; }
    .invoice-container .important-note strong { color: #c0392b; font-size: 13px; }
    .invoice-container .data-box:last-child .data-content { font-size: 11px; line-height: 1.1; padding: 1mm; }
    .invoice-container .data-box:last-child .data-content br { margin-bottom: 0.3mm; }

    .form-wrapper-for-width { max-width: 100%; margin: 20px auto; padding: 0 50px; }
    .form-container { max-width:100% !important; margin:0; padding: 25px 0; background: #f8f9fa; border-radius:5px; box-shadow:none; margin-top: 0 !important; padding-top:10px !important; }
    .form-group { margin-bottom: 15px; }
    label { display: block; margin-bottom: 5px; font-weight: bold; color: #2c3e50; }
    input[type="text"], input[type="tel"], input[type="number"], textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; text-transform: uppercase; }
    input[type="text"]:focus, textarea:focus, select:focus, input[type="tel"]:focus, input[type="number"]:focus { border-color: #3498db; outline:none; box-shadow: 0 0 0 2px rgba(52,152,219,.2); }
    select { text-transform: none; }

    .courier-select { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 15px; margin: 15px 0; }
    .courier-option { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; cursor: pointer; border: 1px solid #e0e0e0; border-radius: 8px; padding: 15px 10px; transition: all 0.25s ease-in-out; background-color: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); min-height: 120px; }
    .courier-option:hover { border-color: #b3d4fc; transform: translateY(-3px) scale(1.02); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .courier-option input[type="radio"] { position: absolute; opacity: 0; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0;}
    .courier-option img { width: 60px; height: 60px; object-fit: contain; margin-bottom: 10px; transition: transform 0.2s ease-in-out;}
    .courier-option:hover img { transform: scale(1.05); }
    .courier-option div { font-size: 13px; color: #333; font-weight: 500; line-height: 1.4;}
    .courier-option.selected { border-color: #3498db; background: #e9f5ff; box-shadow: 0 3px 8px rgba(52, 152, 219, 0.2); transform: translateY(-2px) scale(1.01); }
    .courier-option.selected div { color: #2980b9; font-weight: 600;}

    .button-container button { background: #3498db; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: background 0.3s; }
    .button-container button:hover { background: #2980b9; }
    .button-container { display: flex; gap: 10px; margin: 15px 0; justify-content: flex-start; }
    .button-container.edit-mode { justify-content: center; margin-bottom: 20px; }
    #charCounterNoPesanan, #charCounterAlamat, #charCounterNamaPenerima { display: block; text-align: right; color: #666; font-size: 12px; margin-top:2px;}

    .history-container { max-width: 100%; margin: 30px 0; padding: 25px 50px; background: #fff; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .history-container h2.history-title { font-size: 1.6em; color: #34495e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom:10px;}
    .history-search-form { display:grid; grid-template-columns: 2fr 1fr; gap:15px; margin-bottom:20px; padding:15px; background-color:#f8f9fa; border-radius:5px;}
    .history-search-form .form-group { margin-bottom:0; } .history-search-form .form-group label { font-size:0.9em; font-weight:bold; color:#2c3e50; margin-bottom:5px; }
    .history-search-form input, .history-search-form select { width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; font-size:14px; text-transform:none; }
    .history-search-form .buttons { grid-column:1/-1; display:flex; gap:10px; margin-top:10px; }
    .history-search-form .buttons button, .history-search-form .buttons a { font-size:14px; background:#3498db; color:white !important; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px;}
    .history-search-form .buttons a[style*="#f39c12"] { background:#f39c12 !important;}

    .history-table { font-size: 14px; width: 100%; border-collapse: collapse; margin-top: 10px; }
    .history-table th { background: #3498db; color: white; padding: 12px 15px; text-align: left; }
    .history-table td { vertical-align: top; padding: 12px 15px; border-bottom:1px solid #eee; }
    .history-table td small { font-size: 0.8em; color: #666; display: block; margin-top: 3px; }
    .history-table tr:nth-child(even) { background-color: #f9f9f9; } .history-table tr:hover { background-color: #f1f1f1; }
    .edit-btn, .delete-btn, .print-btn-icon { padding: 8px 12px; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s; margin:2px; }
    .edit-btn:active, .delete-btn:active, .print-btn-icon:active { transform: scale(0.95); }
    .edit-btn { background: #3498db; color: white !important; } .edit-btn:hover { background: #2980b9; transform: translateY(-1px); }
    .print-btn-icon { background: #9b59b6; color: white !important;} .print-btn-icon:hover { background: #8e44ad; transform: translateY(-1px); }
    .edit-btn i, .print-btn-icon i { font-size: 14px; }
    .pagination { margin-top: 20px; text-align: center; }
    .pagination a, .pagination span { display: inline-block; padding: 8px 14px; margin: 0 4px; border: 1px solid #dee2e6; text-decoration: none; color: #007bff; border-radius: 4px; transition: background-color 0.3s, color 0.3s; font-size:0.9em;}
    .pagination a:hover { background-color: #007bff; color: white; } .pagination .current-page { background-color: #007bff; color: white; border-color: #007bff; } .pagination .disabled { color: #6c757d; pointer-events: none; background-color: #e9ecef; }

    .alert { padding: 15px; margin: 15px 0; border-radius: 5px; font-size: 14px; animation: fadeIn 0.5s; }
    .alert.success { background: #c6efce; color: #2ecc71; border: 1px solid #34c759; margin-top: 0px; margin-bottom: 10px; }
    .alert.error { background: #ffd4d4; color: #c0392b; border: 1px solid #c0392b; margin-top: 0px; margin-bottom: 10px; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .site-header, .site-footer { background: rgba(44,62,80,0.95); color: white; padding: 15px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: all 0.3s ease-in-out; backdrop-filter: blur(5px); }
    .site-header .container { display: flex; justify-content: space-between; align-items: center; width: 100%; max-width:1200px; margin:0 auto; position:relative; }
    .site-header { position: sticky; top: 0; z-index: 1000; padding: 10px 20px; }
    .site-header h1 { margin: 0; font-size: 24px; display: flex; align-items: center; gap: 10px; }
    .site-header img { height: 40px; } .site-header h1 small {font-size: 14px; opacity:0.8; margin-left:5px; transition:opacity .3s;}
    .site-footer { background: rgba(52,73,94,0.95); padding: 20px; text-align: center; font-size: 14px; width: 100%;}
    .site-footer a { color: #3498db; text-decoration: none; } .site-footer a:hover { color: #2980b9; } .site-footer .version-info {font-size: 14px; }
    .barcode-container {max-width: 100%; overflow: hidden; margin: 0.5mm auto; padding: 0 2mm; position:relative; margin-top:2mm; }
    #barcode { max-width: 90%; height: auto !important; margin-top: 2mm;}
    .barcode-number { font-size: 10px; text-align: center; margin-top: 1mm; display: none; font-family: 'Courier New', monospace; letter-spacing: 1px; }
    .total-harian { font-size: 14px; color: #2c3e50; border: 1px solid #3498db; margin-top: 15px; padding: 10px; border-radius: 5px; background: #f8f9fa; text-align:center; }
    .readonly-field { background-color: #f5f5f5; cursor: not-allowed; }

    .floating-notification { position: fixed; top: 20px; right: 20px; padding: 15px 25px; border-radius: 8px; color: white; font-weight: bold; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); opacity: 0; transform: translateX(120%); transition: all 0.5s cubic-bezier(0.68,-0.55,0.265,1.55); z-index: 99999; max-width: 300px; }
    .floating-notification.show { opacity: 1; transform: translateX(0); }
    .floating-notification.success { background: #2ecc71; border-left: 5px solid #27ae60; }
    .floating-notification.error { background: #e74c3c; border-left: 5px solid #c0392b; }
    .floating-notification.warning { background: #fff3cd; border-left: 5px solid #ffc107; color: #856404; }
    .floating-notification.warning button { transition: all 0.3s; font-weight: bold; padding: 5px 15px; border-radius:20px; color:white; border:none; cursor:pointer; }
    .floating-notification.warning button:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .floating-notification i { font-size: 18px; }

    .header-actions { display: flex; gap: 10px; }
    .header-actions .refresh-btn { background: rgba(255,255,255,0.1); color: white; border: 2px solid rgba(255,255,255,0.3); padding: 10px 20px; border-radius: 30px; cursor: pointer; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); display: flex; align-items: center; gap: 12px; backdrop-filter: blur(5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-decoration: none; }
    .header-actions .refresh-btn:hover { background: rgba(255,255,255,0.2); transform: rotate(5deg) scale(1.03); border-color: rgba(255,255,255,0.5); box-shadow: 0 6px 12px rgba(0,0,0,0.2); }
    .header-actions .refresh-btn:hover i { transform: scale(1.1) rotate(10deg); }
    .header-actions .refresh-btn i { transition: transform 0.5s ease; font-size: 1.1em; }
    .header-actions .refresh-btn span { font-weight: 600; letter-spacing: 0.5px; }
    .header-actions .refresh-btn:active { transform: scale(0.95); }

    button[style*="#2ecc71"]:hover { background: #27ae60 !important; border-color: #219a52 !important; }
    .text-danger { color: #e74c3c; font-weight:normal; }

    @media print {
        body * { visibility: hidden; }
        .invoice-container, .invoice-container * { visibility: visible; }
        .invoice-container { position: absolute; left: 0; top: 0; margin: 0; padding: 1mm 2mm; border: none !important; box-shadow: none; width: 105mm; min-height: 148mm; }
        .invoice-container .header { margin-bottom: 1mm; }
        .invoice-container .data-container { margin-top: 0.5mm; }
        .invoice-container #barcode { margin-top: 1mm; transform: scale(0.9); transform-origin: center; }
        .invoice-container .barcode-number { display: none !important; }
        .no-print { display: none !important; }
        .site-header, .site-footer,
        .page-wrapper > .main-content > .form-wrapper-for-width,
        .page-wrapper > .main-content > .history-container {
            display: none !important;
        }
        .page-wrapper > .main-content > .invoice-container {
            display: block !important;
        }
    }
</style>
</head>
<body>
    <div class="page-wrapper">
        <div id="floating-notification" class="floating-notification"></div>
        <header class="site-header no-print">
        <div class="container"><h1><img src="<?= htmlspecialchars($logo_header) ?>" alt="Logo Header">
            <span>khairudinfahmi</span><small>Invoice Generator</small></h1>
            <div class="header-actions">
                <button class="refresh-btn" onclick="resetFormAndSession(false);"><i class="fas fa-plus-circle"></i><span>Buat Baru</span></button>
                <a href="ongkir.php" class="refresh-btn">
                    <i class="fas fa-money-bill-wave"></i><span>Laporan Ongkir</span></a>
            </div>
        </div></header>

        <main class="main-content">
            <div class="form-wrapper-for-width no-print">
            <div class="form-container" id="form-invoice">
                <form method="POST" action="<?= $_SERVER['PHP_SELF'] . (!empty($active_search_params_hist) ? '?' . http_build_query($active_search_params_hist) : '') ?>" onsubmit="return validateMasterForm();">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <?php if($is_editing_current_form): ?><input type="hidden" name="id" value="<?= htmlspecialchars($last_input_values_for_form['id']) ?>"><?php endif; ?>
                    <div class="form-group"><label>Pilih Ekspedisi: <span class="text-danger">*</span></label><div class="courier-select">
                        <?php foreach ($available_couriers as $k => $n): ?>
                            <label class="courier-option <?= (isset($last_input_values_for_form['courier']) && $last_input_values_for_form['courier'] === $k) ? 'selected' : '' ?>" data-courier-key="<?= htmlspecialchars($k) // PERUBAHAN: Add data attribute ?>">
                                <input type="radio" name="courier" value="<?= htmlspecialchars($k) ?>" <?= (isset($last_input_values_for_form['courier']) && $last_input_values_for_form['courier'] === $k) ? 'checked' : '' ?> >
                                <img src="<?= htmlspecialchars($logos_dir.$k) ?>.png" alt="<?= htmlspecialchars($n) ?>" onerror="this.src='<?= htmlspecialchars($logos_dir) ?>default.png';this.alt='Logo Default';">
                                <div><?= htmlspecialchars($n) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div></div>
                    <div class="form-group"><label>Nomor Pesanan (Sesuaikan Kebutuhan):</label><input type="text" name="no_pesanan" value="<?= htmlspecialchars($auto_gen_no_pesanan_field) ?>" maxlength="20" oninput="updateCounterNoPesanan(this)"><small id="charCounterNoPesanan">0/20</small></div>
                    <div class="form-group"><label>Nama Penerima: <span class="text-danger">*</span></label><input type="text" name="nama_penerima" required value="<?= htmlspecialchars($last_input_values_for_form['nama_penerima'] ?? '') ?>" maxlength="60" oninput="updateCounterNamaPenerima(this)"><small id="charCounterNamaPenerima">0/60</small></div>
                    <div class="form-group"><label>Alamat Penerima: <span class="text-danger">*</span></label><textarea name="alamat_penerima" rows="3" required maxlength="200" oninput="updateCounterAlamat(this)"><?= htmlspecialchars($last_input_values_for_form['alamat_penerima'] ?? '') ?></textarea><small id="charCounterAlamat">0/200</small></div>
                    <div class="form-group"><label>Telepon Penerima: <span class="text-danger">*</span></label><input type="tel" name="telepon_penerima" required pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').substring(0,15);" value="<?= htmlspecialchars($last_input_values_for_form['telepon_penerima'] ?? '') ?>" maxlength="15"></div>
                    <div class="form-group"><label>Nama Admin: <span class="text-danger">*</span></label><select name="nama_admin_select" id="nama_admin_select" onchange="handleAdminSelectChange(this)" required>
                        <option value="">-- Pilih Admin --</option><?php $cur_adm_val = $last_input_values_for_form['nama_admin'] ?? ($_SESSION['default_nama_admin'] ?? ''); $f_in_list = false; foreach($distinct_admins as $adm_opt): $sel_attr = ($adm_opt==$cur_adm_val)?'selected':''; if($sel_attr) $f_in_list=true;?><option value="<?= htmlspecialchars($adm_opt) ?>" <?= $sel_attr ?>><?= htmlspecialchars($adm_opt) ?></option><?php endforeach; ?><option value="other" <?= (!$f_in_list && !empty($cur_adm_val) && !in_array($cur_adm_val, $distinct_admins))?'selected':'' ?>>-- Admin Lainnya --</option></select><input type="text" name="other_nama_admin" id="other_nama_admin_input" placeholder="Masukkan Nama Admin Baru" style="margin-top:5px; display: <?= (!$f_in_list && !empty($cur_adm_val) && !in_array($cur_adm_val, $distinct_admins))?'block':'none' ?>;" value="<?= (!$f_in_list && !empty($cur_adm_val) && !in_array($cur_adm_val, $distinct_admins))?htmlspecialchars($cur_adm_val):'' ?>"></div>

                    <div class="form-group">
                        <label>Nominal Ongkir: <span class="text-danger">*</span></label>
                        <div id="ongkir_options_radios"> <label style="margin-right:15px; font-weight:normal;"><input type="radio" name="ongkir_option" value="tidak" <?= $ongkir_option_form_display==='tidak'?'checked':''?> onclick="toggleNominalOngkirInput(false)"> Tidak</label>
                            <label style="font-weight:normal;"><input type="radio" name="ongkir_option" value="ya" <?= $ongkir_option_form_display==='ya'?'checked':''?> onclick="toggleNominalOngkirInput(true)"> Ya</label>
                        </div>
                        <input type="text" name="nominal_ongkir" id="nominal_ongkir_input" style="margin-top:5px; <?= $ongkir_option_form_display==='ya'?'display:block;':'display:none;'?>" inputmode="numeric" value="<?= $nominal_ongkir_for_form_field > 0 ? number_format($nominal_ongkir_for_form_field,0,',','.') : ($ongkir_option_form_display === 'ya' && isset($last_input_values_for_form['nominal_ongkir']) ? htmlspecialchars(str_replace('.',',',$last_input_values_for_form['nominal_ongkir'])) : '') ?>" placeholder="Masukkan nominal ongkir">
                    </div>
                    <div class="button-container <?= $is_editing_current_form?'edit-mode':''?>"><button type="submit"><i class="fas <?= $is_editing_current_form?'fa-save':'fa-cog'?>"></i> <?= $is_editing_current_form?'Update':'Generate'?> Invoice</button>
                    <?php if(!empty($invoice_data_for_preview_a6) && isset($invoice_data_for_preview_a6['no_pesanan']) && $invoice_data_for_preview_a6['no_pesanan']!=='N/A'): ?>
                        <button type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    <?php endif; ?>
                    <?php if($is_editing_current_form):?>
                        <button type="button" style="background:#f39c12;" onclick="resetFormAndSession(false);"><i class="fas fa-times-circle"></i> Batal Edit</button>
                    <?php endif; ?></div>
                </form>
            </div></div>

            <?php if(!empty($invoice_data_for_preview_a6) && isset($invoice_data_for_preview_a6['no_pesanan']) && $invoice_data_for_preview_a6['no_pesanan'] !== 'N/A'): ?>
            <div class="invoice-container" id="preview">
                <div class="invoice-border"></div><div class="header"><img src="<?= htmlspecialchars($invoice_data_for_preview_a6['company_logo']) ?>" class="logo" alt="Comp Logo"><div class="company-info"><div class="company-name"><?= htmlspecialchars($invoice_data_for_preview_a6['nama_pengirim']) ?></div><div class="company-address"><?= htmlspecialchars($invoice_data_for_preview_a6['alamat_pengirim']) ?><br><?= htmlspecialchars($invoice_data_for_preview_a6['kota_pengirim']) ?></div><div class="company-phone"><?= htmlspecialchars($invoice_data_for_preview_a6['telepon_pengirim']) ?></div></div><img src="<?= htmlspecialchars($invoice_data_for_preview_a6['expedition_logo']) ?>" class="logo" alt="Exp Logo"></div>
                <div class="order-number"><div>No. Pesanan: <?= htmlspecialchars($invoice_data_for_preview_a6['no_pesanan']) ?></div><div class="barcode-container"><canvas id="barcode"></canvas><div class="barcode-number"><?= htmlspecialchars($invoice_data_for_preview_a6['no_pesanan']) ?></div></div></div>
                <div class="data-container"><div class="data-box"><div class="data-header"><div class="data-title">PENERIMA:</div><div class="nama-penerima"><?= htmlspecialchars($invoice_data_for_preview_a6['nama_penerima']) ?></div></div><div class="data-content"><div><?= nl2br(htmlspecialchars($invoice_data_for_preview_a6['alamat_penerima'])) ?></div><div class="telepon-penerima">TELP: <?= htmlspecialchars($invoice_data_for_preview_a6['telepon_penerima']) ?></div></div></div><div class="data-box important-note"><div class="data-title">📢 PENTING!</div><div class="data-content"><br><strong>WAJIB VIDEO UNBOXING</strong><br>BERLAKU UNTUK SEMUA JENIS PRODUK.<br><br>TIDAK ADA GARANSI RETUR TANPA VIDEO UNBOXING.</div></div></div>
                <div class="invoice-footer"><?= htmlspecialchars($invoice_data_for_preview_a6['courier_name']) ?> | Tgl. Invoice: <?= date('d/m/Y H:i') ?> | Estimasi Sampai: <?= htmlspecialchars($invoice_data_for_preview_a6['shipping_date']) ?></div>
            </div><?php endif; ?>

            <div class="history-container no-print" id="history">
                <h2 class="history-title">📜 Riwayat Invoice</h2>
                <form method="GET" action="<?=$_SERVER['PHP_SELF']?>#history" class="history-search-form">
                    <div class="form-group"><label for="search_keyword_hist_input">Cari (No. Pesanan, Penerima, Ekspedisi, Admin)</label><input type="text" name="search_keyword" id="search_keyword_hist_input" value="<?=htmlspecialchars($search_keyword_hist)?>" placeholder="Masukkan kata kunci..."></div>
                    <div class="form-group"><label for="search_date_range_history_input_filter">Rentang Tanggal Invoice</label><input type="text" name="search_date_range" id="search_date_range_history_input_filter" class="flatpickr-range" value="<?=htmlspecialchars($search_date_range_hist)?>" placeholder="Pilih Rentang Tanggal..."></div>
                    <div class="buttons" style="grid-column: 1 / -1;"><button type="submit" class="btn-manual"><i class="fas fa-search"></i> Cari</button><a href="<?=strtok($_SERVER['PHP_SELF'],'?')?>#history" class="btn-manual" style="background:#f39c12;"><i class="fas fa-undo"></i> Reset Filter</a></div>
                </form>
                <?php if(isset($_SESSION['error_history'])):?><p class="alert error"><?=htmlspecialchars($_SESSION['error_history']);unset($_SESSION['error_history']);?></p><?php elseif(empty($history_list_data)&&$total_hist_pg_items==0&&!empty($active_search_params_hist)):?><p class="alert" style="background-color:#fff9e0;border:1px solid #ffecb5;color:#775c09;text-align:center;">Tidak ada invoice cocok.</p><?php elseif(empty($history_list_data)&&$total_hist_pg_items==0):?><p class="alert" style="background-color:#e9f7fe;border:1px solid #bce8f1;color:#1c6480;text-align:center;">Belum ada riwayat.</p><?php else:?>
                <table class="history-table"><thead><tr><th>No.</th><th>No.Pesanan</th><th>Penerima & Alamat</th><th>Kontak</th><th>Ekspedisi</th><th>Ongkir(Rp)</th><th>Admin</th><th>Tgl.Invoice</th><th>Aksi</th></tr></thead><tbody>
                    <?php $hrn=$offset_pg_hist+1;foreach($history_list_data as $e):?><tr><td><?=$hrn++?></td><td><?=htmlspecialchars($e['no_pesanan'])?></td><td><strong><?=htmlspecialchars($e['nama_penerima'])?></strong><br><small><?=nl2br(htmlspecialchars($e['alamat_penerima']))?></small></td><td><?=htmlspecialchars($e['telepon_penerima'])?></td><td><?=htmlspecialchars($e['courier_name'])?></td><td style="text-align:right;"><?=number_format($e['nominal_ongkir'],0,',','.')?></td><td><?=htmlspecialchars($e['nama_admin']??'-')?></td><td><?=date('d/m/y H:i',strtotime($e['created_at']))?></td>
                    <td class="action-buttons">
                        <a href="<?=$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($active_search_params_hist,['edit'=>$e['id']])).'#form-invoice'?>" class="edit-btn" title="Edit" onclick="return confirmEdit(event, this.href, '<?=htmlspecialchars(addslashes($e['no_pesanan']))?>');"><i class="fas fa-edit"></i> Edit</a>
                        <a href="<?=$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($active_search_params_hist,['print'=>$e['id']])).'#print_trigger'?>" class="print-btn-icon" title="Cetak"><i class="fas fa-print"></i></a>
                    </td></tr><?php endforeach;?>
                </tbody></table>
                <div class="pagination">
                    <?php if ($cur_pg_hist > 1): ?><a href="?page=1&<?=http_build_query($active_search_params_hist)?>#history" title="Awal"><i class="fas fa-angle-double-left"></i></a><a href="?page=<?=$cur_pg_hist-1?>&<?=http_build_query($active_search_params_hist)?>#history" title="Sebelumnya"><i class="fas fa-angle-left"></i></a><?php endif; ?>
                    <?php $nlh=2;$sph=max(1,$cur_pg_hist-$nlh);$eph=min($total_hist_pgs,$cur_pg_hist+$nlh); if($sph>1)echo"<span class=\"disabled\">...</span>"; for($i=$sph;$i<=$eph;$i++):?><a href="?page=<?=$i?>&<?=http_build_query($active_search_params_hist)?>#history" class="<?=($i==$cur_pg_hist)?'current-page':''?>"><?=$i?></a><?php endfor; if($eph<$total_hist_pgs)echo"<span class=\"disabled\">...</span>";?>
                    <?php if ($cur_pg_hist < $total_hist_pgs): ?><a href="?page=<?=$cur_pg_hist+1?>&<?=http_build_query($active_search_params_hist)?>#history" title="Berikutnya"><i class="fas fa-angle-right"></i></a><a href="?page=<?=$total_hist_pgs?>&<?=http_build_query($active_search_params_hist)?>#history" title="Akhir"><i class="fas fa-angle-double-right"></i></a><?php endif; ?>
                </div>
                <p style="text-align:center;margin-top:10px;font-size:0.9em;">Menampilkan <?=count($history_list_data)?> dari <?=$total_hist_pg_items?> total invoice. (Hal <?=$cur_pg_hist?> dari <?=$total_hist_pgs?>)</p><?php endif;?>
                <div class="total-harian">🚀 Total Invoice <?=htmlspecialchars($tanggal_sekarang_str)?>: <strong><?=$total_today_val?></strong></div>
            </div>
        </main>

        <footer class="site-footer no-print">
            <div class="container">
                <p>© <?=date('Y')?> All Rights Reserved. <span>Developed with ❤️ by </span><a href="https://khairudinfahmi.social-networking.me/" target="_blank" rel="noopener noreferrer" class="developer-link">Fahmi Khairudin</a></p>
                <p class="version-info">Invoice Generator | Stable Version</p>
            </div>
        </footer>
    </div>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script><script src="https://cdn.jsdelivr.net/npm/flatpickr"></script><script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script> <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.6/jquery.inputmask.min.js"></script>
<script>
    // PERUBAHAN: Make special couriers list available to JS
    const couriersWithOptionalOngkir = <?= $js_couriers_with_optional_ongkir ?>;

    function showNotification(type, message, duration = 3500) {
        console.log("showNotification called with:", type, message);
        const notification = document.getElementById('floating-notification');
        if (!notification) {
            console.error("Element #floating-notification not found!");
            return;
        }
        notification.className = `floating-notification ${type} show`;

        let iconClass = 'fas fa-info-circle';
        if (type === 'success') iconClass = 'fas fa-check-circle';
        else if (type === 'error') iconClass = 'fas fa-exclamation-circle';
        else if (type === 'warning') iconClass = 'fas fa-exclamation-triangle';

        while(notification.firstChild) notification.removeChild(notification.firstChild);
        const iconEl = document.createElement('i');
        iconEl.className = iconClass;
        notification.appendChild(iconEl);
        notification.appendChild(document.createTextNode(" " + message));

        setTimeout(() => { notification.classList.remove('show'); }, duration);
    }

    function confirmEdit(event, editUrl, no_pesanan) {
        event.preventDefault();
        const existingNotif = document.querySelector('.floating-notification.warning#custom-confirm-edit');
        if (existingNotif) existingNotif.remove();

        const notification = document.createElement('div');
        notification.id = 'custom-confirm-edit';
        notification.className = 'floating-notification warning show';
        notification.innerHTML = `
            <i class="fas fa-edit"></i>
            <div style="line-height:1.5;">Yakin ingin mengedit invoice<br><strong>${no_pesanan}</strong>?</div>
            <div style="margin-top:10px;display:flex;gap:10px;justify-content:flex-end;">
                <button class="cancel-btn" style="background:#3498db;">Batal</button>
                <button class="confirm-btn" style="background:#2ecc71;">Ya, Edit</button>
            </div>`;
        notification.querySelector('.cancel-btn').onclick = () => notification.remove();
        notification.querySelector('.confirm-btn').onclick = () => { window.location.href = editUrl; notification.remove(); };
        document.body.appendChild(notification); return false;
    }

function toggleNominalOngkirInput(show){
    const input=$('#nominal_ongkir_input');
    input.css('display',show?'block':'none');
    input.prop('required',show);
    if(!show){
        input.inputmask('unmaskedvalue'); // Ensure mask is removed for proper value setting
        input.val('0'); // Set to 0 if not shown
    } else {
        if(input.val()==='0'||input.val()===''){ // If shown and 0 or empty, clear for user input
            input.val('');
        }
        input.inputmask({alias:'numeric',groupSeparator:'.',radixPoint:',',autoGroup:true,digits:0,digitsOptional:false,placeholder:'0',removeMaskOnSubmit:true,rightAlign:false});
    }
}

// PERUBAHAN: New function to handle ongkir UI based on courier
function handleCourierOngkirLogic(selectedCourierKey) {
    const ongkirRadiosDiv = document.getElementById('ongkir_options_radios');
    const ongkirRadioYa = document.querySelector('input[name="ongkir_option"][value="ya"]');
    const ongkirRadioTidak = document.querySelector('input[name="ongkir_option"][value="tidak"]');
    const nominalOngkirInput = $('#nominal_ongkir_input');

    if (!ongkirRadiosDiv || !ongkirRadioYa || !ongkirRadioTidak || !nominalOngkirInput.length) {
        console.error("Ongkir related elements not found!");
        return;
    }

    if (couriersWithOptionalOngkir.includes(selectedCourierKey)) {
        // Special couriers: show radio buttons, let them control the input
        ongkirRadiosDiv.style.display = 'block';
        ongkirRadioYa.disabled = false;
        ongkirRadioTidak.disabled = false;
        // Initial state based on checked radio
        toggleNominalOngkirInput(ongkirRadioYa.checked);
    } else {
        // Other couriers: hide radio buttons, force "Ya" and show input
        ongkirRadiosDiv.style.display = 'none';
        ongkirRadioYa.checked = true; // Programmatically select "Ya"
        ongkirRadioTidak.checked = false;
        // ongkirRadioYa.disabled = true; // Optional: disable if hidden
        // ongkirRadioTidak.disabled = true;
        toggleNominalOngkirInput(true); // Force ongkir input to be visible and required
        if (nominalOngkirInput.val() === '0' || nominalOngkirInput.val() === '') {
             // nominalOngkirInput.val(''); // Clear if it was 0, for better UX
        }
    }
}


function updateCounterAlamat(textarea){const counter=document.getElementById('charCounterAlamat');const length=textarea.value.length;counter.textContent=`${length}/200`;counter.style.color=length>=200?'#e74c3c':(length>180?'#f39c12':'#666');textarea.style.borderColor=length>=200?'#e74c3c':'#ddd';}
function updateCounterNoPesanan(input){const counter=document.getElementById('charCounterNoPesanan');const length=input.value.length;counter.textContent=`${length}/20`;counter.style.color=length>=20?'#e74c3c':(length>15?'#f39c12':'#666');input.style.borderColor=length>=20?'#e74c3c':'#ddd';}
function updateCounterNamaPenerima(input){const counter=document.getElementById('charCounterNamaPenerima');const length=input.value.length;counter.textContent=`${length}/60`;counter.style.color=length>=60?'#e74c3c':(length>50?'#f39c12':'#666');input.style.borderColor=length>=60?'#e74c3c':'#ddd';}
function handleAdminSelectChange(selectElement){const otherAdminInput=document.getElementById('other_nama_admin_input');if(selectElement.value==='other'){otherAdminInput.style.display='block';otherAdminInput.required=true;otherAdminInput.focus();}else{otherAdminInput.style.display='none';otherAdminInput.required=false;otherAdminInput.value='';}}

function validateMasterForm(){
    console.log("Validating form start...");
    const courierSelectedRadio = document.querySelector('input[name="courier"]:checked');
    const courierSelectEl = document.querySelector('.courier-select');

    if(!courierSelectedRadio){
        console.log("Courier not selected");
        showNotification('error','Pilih ekspedisi terlebih dahulu lur!');
        if(courierSelectEl) {
            courierSelectEl.scrollIntoView({behavior:'smooth',block:'center'});
            courierSelectEl.style.outline = "2px solid red";
            courierSelectEl.style.borderRadius = "5px";
            setTimeout(() => { courierSelectEl.style.outline = ""; }, 3500);
        }
        return false;
    }
    console.log("Courier OK");
    if(courierSelectEl) { courierSelectEl.style.outline = ""; }

    const selectedCourierKey = courierSelectedRadio.value; // Get selected courier key

    const noPesananInput=document.querySelector('input[name="no_pesanan"]');
    if(noPesananInput && noPesananInput.value.trim().length > 20){
        showNotification('error','Nomor pesanan maksimal 20 karakter lur!');
        noPesananInput.focus();return false;
    }

    const namaPenerimaInput=document.querySelector('input[name="nama_penerima"]');
    if(namaPenerimaInput && namaPenerimaInput.value.trim().length > 60){
        showNotification('error','Nama Penerima maksimal 60 karakter lur!');
        namaPenerimaInput.focus();return false;
    }

    const alamatPenerima=document.querySelector('textarea[name="alamat_penerima"]');
    if(alamatPenerima && alamatPenerima.value.trim().length > 200){
        showNotification('error','Alamat maksimal 200 karakter lur!');
        alamatPenerima.focus();return false;
    }

    const adminSelect=document.getElementById('nama_admin_select');
    const otherAdminInput=document.getElementById('other_nama_admin_input');
    if(adminSelect && adminSelect.value===''){
        showNotification('error','Nama Admin wajib dipilih atau diisi!');
        adminSelect.focus();return false;
    }
    if(adminSelect && adminSelect.value==='other' && otherAdminInput && otherAdminInput.value.trim()===''){
        showNotification('error','Nama admin lainnya tidak boleh kosong!');
        otherAdminInput.focus();return false;
    }

    const ongkirYaRadio=document.querySelector('input[name="ongkir_option"][value="ya"]');
    const nominalOngkirInput=$('#nominal_ongkir_input');

    // PERUBAHAN: Validation based on courier type
    if (couriersWithOptionalOngkir.includes(selectedCourierKey)) {
        // For special couriers, validate only if "Ya" is selected
        if(ongkirYaRadio && ongkirYaRadio.checked){
            let unmaskedValue = nominalOngkirInput.inputmask('unmaskedvalue');
            if(unmaskedValue===''||parseFloat(unmaskedValue)<=0){
                showNotification('error','Nominal Ongkir wajib diisi dan > 0 jika "Ya" dipilih!');
                nominalOngkirInput.focus();return false;
            }
        }
    } else {
        // For other couriers, nominal ongkir is always required
        let unmaskedValue = nominalOngkirInput.inputmask('unmaskedvalue');
        if(unmaskedValue===''||parseFloat(unmaskedValue)<=0){
            showNotification('error','Nominal Ongkir wajib diisi dan > 0 untuk ekspedisi ini!');
            nominalOngkirInput.focus();return false;
        }
    }
    // END PERUBAHAN

    console.log("Validation successful");
    return true;
}

function resetFormAndSession(preserveFilters = false) {
    console.log("resetFormAndSession called, preserveFilters:", preserveFilters);
    const csrfTokenEl = document.querySelector('input[name="csrf_token"]');
    if (!csrfTokenEl) {
        console.error("CSRF token element not found for reset!");
        window.location.href = '<?= strtok($_SERVER['PHP_SELF'], '?') ?>#form-invoice';
        return;
    }
    const csrfToken = csrfTokenEl.value;

    const form = document.createElement('form');
    form.method = 'POST';

    let destinationAction = '<?= $_SERVER['PHP_SELF'] ?>';

    if (preserveFilters) {
        let currentUrl = new URL(window.location.href);
        let preservedParams = new URLSearchParams(currentUrl.search);
        preservedParams.delete('edit');
        preservedParams.delete('print');
        if (preservedParams.toString()) {
            destinationAction += '?' + preservedParams.toString();
        }

        const preserveInput = document.createElement('input');
        preserveInput.type = 'hidden';
        preserveInput.name = 'preserve_filters_on_reset';
        preserveInput.value = '1';
        form.appendChild(preserveInput);
    }

    form.action = destinationAction;

    const resetInput = document.createElement('input');
    resetInput.type = 'hidden';
    resetInput.name = 'reset_session';
    resetInput.value = 'true';
    form.appendChild(resetInput);

    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = csrfToken;
    form.appendChild(csrfInput);

    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded',function(){
    flatpickr(".flatpickr-range",{mode:"range",dateFormat:"Y-m-d",locale:"id"});
    $('#nominal_ongkir_input').inputmask({alias:'numeric',groupSeparator:'.',radixPoint:',',autoGroup:true,digits:0,digitsOptional:false,placeholder:'0',removeMaskOnSubmit:true,rightAlign:false});

    document.querySelectorAll('.courier-option').forEach(option=>{
        option.addEventListener('click',function(){
            document.querySelectorAll('.courier-option.selected').forEach(sel=>sel.classList.remove('selected'));
            this.classList.add('selected');
            const radio=this.querySelector('input[type="radio"]');
            if(radio) {
                radio.checked=true;
                // PERUBAHAN: Call ongkir logic handler
                handleCourierOngkirLogic(radio.value);
            }
        });
    });

    // PERUBAHAN: Initial call to ongkir logic handler on page load
    const initiallySelectedCourierRadio = document.querySelector('input[name="courier"]:checked');
    if (initiallySelectedCourierRadio) {
        handleCourierOngkirLogic(initiallySelectedCourierRadio.value);
    } else {
        // If no courier is initially selected, default to showing ongkir options
        // (or hide them if you prefer, but current PHP logic defaults to 'tidak' which hides input)
        // This typically happens on a fresh form.
        const ongkirRadiosDiv = document.getElementById('ongkir_options_radios');
        if (ongkirRadiosDiv) ongkirRadiosDiv.style.display = 'block';
        const ongkirRadioTidak = document.querySelector('input[name="ongkir_option"][value="tidak"]');
        if (ongkirRadioTidak && !document.querySelector('input[name="ongkir_option"][value="ya"]').checked) {
            toggleNominalOngkirInput(false); // Hide input if "Tidak" is default
        }
    }


    const ongkirOptionChecked=document.querySelector('input[name="ongkir_option"]:checked');
    // We let handleCourierOngkirLogic manage the initial state of nominal_ongkir_input display
    // if(ongkirOptionChecked)toggleNominalOngkirInput(ongkirOptionChecked.value==='ya'); // This line might be redundant now or handled by above

    const adminSelectInitial=document.getElementById('nama_admin_select');if(adminSelectInitial)handleAdminSelectChange(adminSelectInitial);
    const alamatTextarea=document.querySelector('textarea[name="alamat_penerima"]');if(alamatTextarea)updateCounterAlamat(alamatTextarea);
    const noPesananInputDOM=document.querySelector('input[name="no_pesanan"]');if(noPesananInputDOM)updateCounterNoPesanan(noPesananInputDOM);
    const namaPenerimaInputDOM=document.querySelector('input[name="nama_penerima"]');if(namaPenerimaInputDOM)updateCounterNamaPenerima(namaPenerimaInputDOM);

    const hash=window.location.hash;
    if(hash==='#print_trigger'){
        window.print();
        history.replaceState(null,null,window.location.pathname+window.location.search);
    } else if(hash){
        const targetElement=document.querySelector(hash);
        if(targetElement)setTimeout(()=>targetElement.scrollIntoView({behavior:'smooth',block:'start'}),100);
    }

    <?php if(!empty($invoice_data_for_preview_a6)&&isset($invoice_data_for_preview_a6['no_pesanan'])&&$invoice_data_for_preview_a6['no_pesanan']!=='N/A'):?>
    try{
        JsBarcode("#barcode","<?=htmlspecialchars(addslashes($invoice_data_for_preview_a6['no_pesanan']))?>",{
            format:"CODE128",displayValue:false,width:2.2,height:35,margin:5,lineColor:"#000",background:"#f5f5f5"});
    }catch(e){console.error("Error generating barcode:",e);}
    <?php endif;?>

    <?php if(isset($_SESSION['success'])):echo "showNotification('success','".addslashes(htmlspecialchars($_SESSION['success']))."');";unset($_SESSION['success']);endif;?>
    <?php if(isset($_SESSION['error'])):echo "showNotification('error','".addslashes(htmlspecialchars($_SESSION['error']))."');";unset($_SESSION['error']);endif;?>
    <?php if(isset($_SESSION['warning'])):echo "showNotification('warning','".addslashes(htmlspecialchars($_SESSION['warning']))."',5000);";unset($_SESSION['warning']);endif;?>
    <?php if(isset($_SESSION['error_history'])):echo "showNotification('error','".addslashes(htmlspecialchars($_SESSION['error_history']))."');";unset($_SESSION['error_history']);endif;?>
});
</script>
</body>
</html>
