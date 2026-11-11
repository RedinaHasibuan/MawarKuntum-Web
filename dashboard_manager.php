<?php
session_start();
include 'koneksi.php';

// Keamanan RBAC: Hanya untuk Manager / Supervisor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Manager', 'Supervisor Operasional'], true)) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg_task = '';
$msg_emp = '';

// 1. Handler Tambah Pegawai Baru (Management User)
if (isset($_POST['add_employee'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);
    $id_sub_role = intval($_POST['id_sub_role']);
    $status = 'active';

    // Cek apakah email sudah terdaftar
    $check_email = mysqli_query($conn, "SELECT * FROM users WHERE email='$email'");
    if (mysqli_num_rows($check_email) > 0) {
        $msg_emp = "<div class='alert-error'>❌ Email sudah terdaftar! Gunakan email lain.</div>";
    } else {
        $q_add = "INSERT INTO users (email, password, id_sub_role, status) VALUES ('$email', '$password', $id_sub_role, '$status')";
        if (mysqli_query($conn, $q_add)) {
            $msg_emp = "<div class='alert-success'>✅ Pegawai baru berhasil ditambahkan!</div>";
        } else {
            $msg_emp = "<div class='alert-error'>❌ Gagal menambah pegawai: " . mysqli_error($conn) . "</div>";
        }
    }
}

// 2. Handler Tambah Tugas Baru (OAS Assign Task)
if (isset($_POST['assign_task'])) {
    $judul = mysqli_real_escape_string($conn, $_POST['judul_tugas']);
    $deskripsi = mysqli_real_escape_string($conn, $_POST['deskripsi']);
    $prioritas = mysqli_real_escape_string($conn, $_POST['prioritas']);
    $assignee_id = intval($_POST['dikerjakan_oleh']);

    $q_insert = "INSERT INTO tasks (judul_tugas, deskripsi, prioritas, status_tugas, dikerjakan_oleh, tgl_dibuat) 
                 VALUES ('$judul', '$deskripsi', '$prioritas', 'To-Do', $assignee_id, NOW())";
    if (mysqli_query($conn, $q_insert)) {
        $msg_task = "<div class='alert-success'>✅ Tugas berhasil diberikan kepada staf!</div>";
    } else {
        $msg_task = "<div class='alert-error'>❌ Gagal memberikan tugas: " . mysqli_error($conn) . "</div>";
    }
}

// 3. Handler Review Status Laporan Audit Stok Gudang
if (isset($_POST['update_audit_status'])) {
    $id_laporan = intval($_POST['id_laporan']);
    $status_review = mysqli_real_escape_string($conn, $_POST['status_review']);

    mysqli_query($conn, "UPDATE laporan_audit SET status_review='$status_review' WHERE id_laporan=$id_laporan");
    header("Location: dashboard_manager.php");
    exit();
}

// 4. Handling Filter Laporan Sales Bulanan
$bulan = isset($_GET['bulan']) ? intval($_GET['bulan']) : intval(date('m'));
$tahun = isset($_GET['tahun']) ? intval($_GET['tahun']) : intval(date('Y'));

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$q_sales = "SELECT * FROM orders WHERE MONTH(tanggal_transaksi) = '$bulan' AND YEAR(tanggal_transaksi) = '$tahun' ORDER BY order_id DESC";
$res_sales = mysqli_query($conn, $q_sales);
$sales_monthly_total = 0;
$sales_monthly_count = 0;
$sales_data = [];

if ($res_sales) {
    while ($r = mysqli_fetch_assoc($res_sales)) {
        $sales_data[] = $r;
        $sales_monthly_total += $r['total_harga'] ?? 0;
        $sales_monthly_count++;
    }
}

// 5. Deteksi Otomatis Ringkasan Metrik Akumulasi
$total_pendapatan = 0;
$total_pesanan = 0;
$check_orders = mysqli_query($conn, "SHOW TABLES LIKE 'orders'");
if ($check_orders && mysqli_num_rows($check_orders) > 0) {
    $q_count = mysqli_query($conn, "SELECT COUNT(*) as total_ord FROM orders");
    if ($q_count) {
        $d_count = mysqli_fetch_assoc($q_count);
        $total_pesanan = $d_count['total_ord'] ?? 0;
    }

    $q_sum = mysqli_query($conn, "SELECT SUM(total_harga) as total_rev FROM orders");
    if ($q_sum) {
        $d_sum = mysqli_fetch_assoc($q_sum);
        $total_pendapatan = $d_sum['total_rev'] ?? 0;
    }
}

$total_produk = 0;
$q_prod = mysqli_query($conn, "SELECT COUNT(*) as total_p FROM products");
if ($q_prod) {
    $d_prod = mysqli_fetch_assoc($q_prod);
    $total_produk = $d_prod['total_p'] ?? 0;
}

$total_pegawai = 0;
$q_usr = mysqli_query($conn, "SELECT COUNT(*) as total_u FROM users u JOIN sub_roles sr ON u.id_sub_role = sr.id_sub_role WHERE sr.nama_sub_role NOT LIKE '%Pelanggan%'");
if ($q_usr) {
    $d_usr = mysqli_fetch_assoc($q_usr);
    $total_pegawai = $d_usr['total_u'] ?? 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Manager - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; margin: 0; }
        .header { display: block; background: white; padding: 22px 28px; border-radius: 14px; box-shadow: 0 3px 12px rgba(28, 55, 35, 0.07); margin-bottom: 20px; }
        .header-main { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; }
        .header-main h2 { margin: 0; color: #26352a; font-size: 25px; line-height: 1.25; }
        .header-user { color: #65746a; font-size: 13px; padding-top: 5px; white-space: nowrap; }
        .header-nav { display: flex; align-items: center; flex-wrap: wrap; gap: 9px; border-top: 1px solid #edf1ed; margin-top: 18px; padding-top: 15px; }
        .header-nav::before { content: 'MENU DASHBOARD'; color: #839087; font-size: 10px; font-weight: 800; letter-spacing: 1.1px; margin-right: 5px; }
        .header-nav .btn, .header-nav .btn-logout { min-height: 38px; padding: 10px 15px; border-radius: 8px; }
        .header-nav .btn { transition: transform .18s ease, box-shadow .18s ease; }
        .header-nav .btn:hover, .header-nav .btn-logout:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,.12); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 5px solid #2e7d32; }
        .stat-card h4 { margin: 0; font-size: 12px; color: #666; text-transform: uppercase; }
        .stat-card .value { font-size: 22px; font-weight: bold; color: #1b5e20; margin-top: 5px; }
        .grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 20px; margin-top: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #2e7d32; font-size: 17px; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; margin-bottom: 15px; }
        .card-header-flex h3 { border-bottom: none; padding-bottom: 0; margin: 0; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        th { background: #e8f5e9; color: #1b5e20; font-weight: bold; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-todo { background: #ffe0b2; color: #e65100; }
        .badge-progress { background: #bbdefb; color: #0d47a1; }
        .badge-done { background: #c8e6c9; color: #1b5e20; }
        
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 4px; color: #2e7d32; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 13px; }
        
        .btn { padding: 8px 14px; border-radius: 5px; border: none; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-sub { background: #2e7d32; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%; }
        .btn-sub:hover { background: #1b5e20; }
        .btn-primary { background: #0288d1; color: white; }
        .btn-print { background: #388e3c; color: white; }
        .btn-logout { background: #e57373; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; font-size: 13px; }
        
        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .alert-error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        
        @media (max-width: 760px) {
            body { padding: 12px; }
            .header { padding: 18px; }
            .header-main { flex-direction: column; }
            .header-main h2 { font-size: 21px; }
            .header-user { white-space: normal; }
            .header-nav { gap: 8px; }
            .header-nav::before { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid { grid-template-columns: 1fr; }
        }

        /* FORMATTING KHUSUS TAMPILAN PRINT/PDF */
        @media print {
            body { background: white; padding: 0; color: black; }
            .no-print, .header, .stats-grid, .grid, .btn { display: none !important; }
            .card { box-shadow: none; padding: 0; margin: 0; }
            .card-header-flex h3 { font-size: 18px; text-align: center; }
            table { width: 100%; border: 1px solid #000; }
            th, td { border: 1px solid #000; padding: 8px; }
            .print-only-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-only-header { display: none; }
    </style>
</head>
<body>

    <!-- Header khusus saat print PDF -->
    <div class="print-only-header">
        <h2>LAPORAN PENJUALAN BULANAN MAWARKUNTUM</h2>
        <p>Periode Transaksi: <b><?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></b></p>
        <hr>
    </div>

    <!-- Header Utama Dashboard -->
    <div class="header no-print">
        <div class="header-main">
            <h2>Executive Dashboard<br><span style="font-size:17px;color:#6b7b70;font-weight:500;">Manager / Supervisor</span></h2>
            <div class="header-user">Login sebagai <b><?php echo htmlspecialchars($_SESSION['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></b></div>
        </div>
        <nav class="header-nav" aria-label="Menu dashboard">
            <a href="dashboard_mis.php" class="btn btn-primary">📊 Dashboard MIS</a>
            <a href="dashboard_dss.php" class="btn btn-primary" style="background:#7b1fa2;">📈 Dashboard DSS</a>
            <a href="asisten.php" class="btn" style="background:#00897b; color:white;">💬 Asisten Virtual</a>
            <a href="kelola_pengetahuan.php" class="btn" style="background:#9c3d83; color:white;">🧠 Kelola Basis Pengetahuan</a>
            <a href="index.php" class="btn" style="background:#d81b60; color:white;">📚 KMS (SOP & Panduan)</a>
            <a href="logout.php" class="btn-logout">Keluar ↗</a>
        </nav>
    </div>

    <!-- Ringkasan Eksekutif Utama -->
    <div class="stats-grid no-print">
        <div class="stat-card" style="border-color: #4caf50;">
            <h4>Total Pendapatan (Akumulasi)</h4>
            <div class="value">Rp <?php echo number_format($total_pendapatan, 0, ',', '.'); ?></div>
        </div>
        <div class="stat-card" style="border-color: #2196f3;">
            <h4>Total Pesanan (Akumulasi)</h4>
            <div class="value"><?php echo $total_pesanan; ?> Orders</div>
        </div>
        <div class="stat-card" style="border-color: #ff9800;">
            <h4>Katalog Produk</h4>
            <div class="value"><?php echo $total_produk; ?> Items</div>
        </div>
        <div class="stat-card" style="border-color: #9c27b0;">
            <h4>Total Tim Pegawai</h4>
            <div class="value"><?php echo $total_pegawai; ?> Staf</div>
        </div>
    </div>

    <!-- SECTION LAPORAN SALES BULANAN (FILTER & CETAK) -->
    <div class="card">
        <div class="card-header-flex">
            <h3>📈 Laporan Sales Bulanan</h3>
            <div class="no-print" style="display: flex; gap: 10px; align-items: center;">
                <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                    <select name="bulan" class="form-control" style="width: auto;">
                        <?php for($i=1; $i<=12; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == $bulan) ? 'selected': ''; ?>>
                                <?php echo $nama_bulan[$i]; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select name="tahun" class="form-control" style="width: auto;">
                        <?php
                        $curr_yr = date('Y');
                        for ($y = $curr_yr - 2; $y <= $curr_yr + 1; $y++):
                        ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y == $tahun) ? 'selected': ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
                <button onclick="window.print()" class="btn btn-print">🖨️ Cetak Laporan</button>
            </div>
        </div>

        <div style="background: #e8f5e9; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; display: flex; gap: 25px; font-size: 13px;">
            <span>Periode: <b><?php echo $nama_bulan[$bulan] . ' ' . $tahun; ?></b></span>
            <span>Jumlah Pesanan: <b><?php echo $sales_monthly_count; ?> Order</b></span>
            <span>Total Omset Bulan Ini: <b style="color: #1b5e20;">Rp <?php echo number_format($sales_monthly_total, 0, ',', '.'); ?></b></span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID Order</th>
                    <th>Tanggal Transaksi</th>
                    <th>Nama Penerima</th>
                    <th>Metode Pembayaran</th>
                    <th>Total Pembayaran</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales_data)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #888; padding: 15px;">Tidak ada transaksi penjualan pada bulan dan tahun ini.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sales_data as $row): ?>
                        <tr>
                            <td><b>#<?php echo $row['order_id']; ?></b></td>
                            <td><?php echo date('d M Y, H:i', strtotime($row['tanggal_transaksi'])); ?></td>
                            <td><?php echo htmlspecialchars($row['nama_penerima'] ?? 'Pelanggan'); ?></td>
                            <td><?php echo htmlspecialchars($row['metode_pembayaran'] ?? '-'); ?></td>
                            <td><b>Rp <?php echo number_format($row['total_harga'], 0, ',', '.'); ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- SECTION KELOLA PEGAWAI & OPERASIONAL TUGAS -->
    <div class="grid no-print">
        <!-- Kolom Kiri: Form Registrasi Pegawai Baru & Form Tugas -->
        <div>
            <!-- Form Tambah Pegawai Baru -->
            <div class="card">
                <h3>👤 Tambah Pegawai Baru</h3>
                <?php echo $msg_emp; ?>
                <form method="POST" action="dashboard_manager.php">
                    <div class="form-group">
                        <label>Email Pegawai</label>
                        <input type="email" name="email" class="form-control" placeholder="contoh: staf1@mawarkuntum.com" required>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" placeholder="******" required>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Jabatan / Sub-Role</label>
                            <select name="id_sub_role" class="form-control" required>
                                <?php
                                $roles = mysqli_query($conn, "SELECT * FROM sub_roles WHERE nama_sub_role NOT LIKE '%Pelanggan%'");
                                while ($r = mysqli_fetch_assoc($roles)) {
                                    echo "<option value='{$r['id_sub_role']}'>{$r['nama_sub_role']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_employee" class="btn-sub" style="background:#0288d1;">+ Registrasi Pegawai</button>
                </form>
            </div>

            <!-- Form Buat Tugas Baru (OAS) -->
            <div class="card">
                <h3>📝 Buat & Alokasikan Tugas Staff (OAS)</h3>
                <?php echo $msg_task; ?>
                <form method="POST" action="dashboard_manager.php">
                    <div class="form-group">
                        <label>Judul Tugas</label>
                        <input type="text" name="judul_tugas" class="form-control" placeholder="Contoh: Audit Stok Gudang / Cek Pesanan Pendekat" required>
                    </div>
                    <div class="form-group">
                        <label>Deskripsi Tugas</label>
                        <textarea name="deskripsi" class="form-control" rows="2" placeholder="Jelaskan detail instruksi..." required></textarea>
                    </div>
                    <div style="display: flex; gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Prioritas</label>
                            <select name="prioritas" class="form-control">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1.5;">
                            <label>Tugaskan Kepada</label>
                            <select name="dikerjakan_oleh" class="form-control" required>
                                <?php
                                $staffs = mysqli_query($conn, "SELECT u.user_id, u.email, sr.nama_sub_role 
                                                               FROM users u 
                                                               JOIN sub_roles sr ON u.id_sub_role = sr.id_sub_role 
                                                               WHERE sr.nama_sub_role NOT LIKE '%Pelanggan%' AND u.status = 'active' 
                                                               ORDER BY sr.nama_sub_role ASC");
                                while ($s = mysqli_fetch_assoc($staffs)) {
                                    echo "<option value='{$s['user_id']}'>{$s['email']} ({$s['nama_sub_role']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="assign_task" class="btn-sub">+ Kirim Tugas ke Staff</button>
                </form>
            </div>
        </div>

        <!-- Kolom Kanan: Review Audit, Monitoring Tugas & Daftar Pegawai -->
        <div>
            <!-- Section Laporan Audit Stok Gudang (Integrasi dari Staff Gudang) -->
            <div class="card">
                <h3>📋 Review Laporan Audit Stok Gudang</h3>
                <table>
                    <tr>
                        <th>Tanggal & Pengirim</th>
                        <th>Judul & Kondisi</th>
                        <th>Catatan Audit</th>
                        <th>Status Review</th>
                    </tr>
                    <?php
                    $check_audit = mysqli_query($conn, "SHOW TABLES LIKE 'laporan_audit'");
                    if ($check_audit && mysqli_num_rows($check_audit) > 0) {
                        $audits = mysqli_query($conn, "SELECT l.*, u.email FROM laporan_audit l LEFT JOIN users u ON l.id_user = u.user_id ORDER BY l.tgl_kirim DESC");
                        if (!$audits || mysqli_num_rows($audits) == 0) {
                            echo "<tr><td colspan='4' style='text-align:center;'>Belum ada laporan audit masuk.</td></tr>";
                        } else {
                            while ($a = mysqli_fetch_assoc($audits)) {
                                $stRev = $a['status_review'] ?? 'Menunggu Review';
                                $bRevClass = ($stRev == 'Disetujui' || $stRev == 'Approved') ? 'badge-done' : 'badge-todo';
                                echo "<tr>
                                    <td><b>" . htmlspecialchars($a['email'] ?? 'Staff Gudang') . "</b><br><small style='color:#777;'>" . date('d M Y, H:i', strtotime($a['tgl_kirim'])) . "</small></td>
                                    <td><b>" . htmlspecialchars($a['judul_laporan']) . "</b><br><small style='color:#2e7d32;'>" . htmlspecialchars($a['kondisi_stok'] ?? '-') . "</small></td>
                                    <td><small>" . htmlspecialchars($a['catatan_audit']) . "</small></td>
                                    <td>
                                        <form method='POST' style='display:flex; flex-direction:column; gap:4px;'>
                                            <input type='hidden' name='id_laporan' value='{$a['id_laporan']}'>
                                            <select name='status_review' style='font-size:11px; padding:2px;'>
                                                <option value='Menunggu Review' " . ($stRev == 'Menunggu Review' ? 'selected' : '') . ">Menunggu Review</option>
                                                <option value='Disetujui' " . ($stRev == 'Disetujui' ? 'selected' : '') . ">Disetujui</option>
                                                <option value='Perlu Penyesuaian' " . ($stRev == 'Perlu Penyesuaian' ? 'selected' : '') . ">Perlu Penyesuaian</option>
                                            </select>
                                            <button type='submit' name='update_audit_status' class='btn-sub' style='padding:2px 5px; font-size:11px;'>Simpan</button>
                                        </form>
                                    </td>
                                </tr>";
                            }
                        }
                    } else {
                        echo "<tr><td colspan='4' style='text-align:center;'>Belum ada laporan audit masuk.</td></tr>";
                    }
                    ?>
                </table>
            </div>

            <!-- Monitoring Operasional Tim -->
            <div class="card">
                <h3>📌 Monitoring Operasional Tim (All Tasks)</h3>
                <table>
                    <tr>
                        <th>Tugas</th>
                        <th>Dikerjakan Oleh</th>
                        <th>Prioritas</th>
                        <th>Status</th>
                    </tr>
                    <?php
                    $all_tasks = mysqli_query($conn, "SELECT t.*, u.email FROM tasks t LEFT JOIN users u ON t.dikerjakan_oleh = u.user_id ORDER BY t.tgl_dibuat DESC");
                    if (!$all_tasks || mysqli_num_rows($all_tasks) == 0) {
                        echo "<tr><td colspan='4' style='text-align:center;'>Belum ada tugas yang diberikan.</td></tr>";
                    } else {
                        while ($t = mysqli_fetch_assoc($all_tasks)) {
                            $st = $t['status_tugas'];
                            $bClass = ($st == 'Done') ? 'badge-done' : (($st == 'In Progress') ? 'badge-progress' : 'badge-todo');
                            $email_staff = $t['email'] ?? 'Unassigned';
                            echo "<tr>
                                <td><b>{$t['judul_tugas']}</b><br><small style='color:#666;'>{$t['deskripsi']}</small></td>
                                <td><small>{$email_staff}</small></td>
                                <td>{$t['prioritas']}</td>
                                <td><span class='badge {$bClass}'>{$st}</span></td>
                            </tr>";
                        }
                    }
                    ?>
                </table>
            </div>

            <!-- Daftar Pegawai & Role -->
            <div class="card">
                <h3>👥 Daftar Pegawai & Role</h3>
                <table>
                    <tr>
                        <th>Email</th>
                        <th>Sub-Role</th>
                        <th>Status</th>
                    </tr>
                    <?php
                    $user_list = mysqli_query($conn, "SELECT u.email, u.status, sr.nama_sub_role FROM users u JOIN sub_roles sr ON u.id_sub_role = sr.id_sub_role WHERE sr.nama_sub_role NOT LIKE '%Pelanggan%' ORDER BY sr.nama_sub_role ASC, u.email ASC");
                    if (!$user_list || mysqli_num_rows($user_list) == 0) {
                        echo "<tr><td colspan='3' style='text-align:center;'>Belum ada data pegawai.</td></tr>";
                    } else {
                        while ($u = mysqli_fetch_assoc($user_list)) {
                            echo "<tr>
                                <td><b>{$u['email']}</b></td>
                                <td><b>{$u['nama_sub_role']}</b></td>
                                <td><span class='badge badge-done'>{$u['status']}</span></td>
                            </tr>";
                        }
                    }
                    ?>
                </table>
            </div>
        </div>
    </div>

</body>
</html>