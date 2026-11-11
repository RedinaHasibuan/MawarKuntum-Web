<?php
session_start();
include 'koneksi.php';

// Keamanan RBAC: Hanya untuk Staff Pesanan / User terautentikasi
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fungsi Helper untuk Mendapatkan Struktur Kolom Tabel Orders Secara Real-Time
function getOrdersSchema($conn) {
    $cols = [];
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM orders");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

// 1. Handler Update Status Tugas Operasional (OAS)
if (isset($_POST['update_task'])) {
    $task_id = intval($_POST['task_id']);
    $pk_col  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['pk_col'] ?? 'id');
    $status  = mysqli_real_escape_string($conn, $_POST['status_tugas']);
    
    mysqli_query($conn, "UPDATE tasks SET status_tugas='$status' WHERE `$pk_col`=$task_id");
    header("Location: dashboard_pesanan.php");
    exit();
}

// 2. Handler Update Status Order/Pesanan (TPS)
if (isset($_POST['update_order_status'])) {
    $order_id   = intval($_POST['order_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['status_order']);
    $cols       = getOrdersSchema($conn);

    if (!empty($cols)) {
        // Cari Primary Key secara otomatis
        $pk_col = 'id';
        foreach (['order_id', 'id', 'id_order', 'id_pesanan'] as $cand) {
            if (in_array($cand, $cols)) { $pk_col = $cand; break; }
        }

        // Cari Kolom Status secara otomatis
        $status_col = '';
        foreach (['status_order', 'order_status', 'st_pesanan', 'status', 'status_pesanan'] as $cand) {
            if (in_array($cand, $cols)) { $status_col = $cand; break; }
        }
        if (empty($status_col)) {
            foreach ($cols as $c) {
                if (strpos(strtolower($c), 'status') !== false) { 
                    $status_col = $c; 
                    break; 
                }
            }
        }

        // Jalankan Query jika kolom status ditemukan
        if (!empty($status_col)) {
            mysqli_query($conn, "UPDATE orders SET `$status_col`='$new_status' WHERE `$pk_col`=$order_id");
        }
    }

    header("Location: dashboard_pesanan.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Staff Pesanan - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fdf2f8; padding: 20px; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1.3fr; gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #c2185b; font-size: 17px; border-bottom: 2px solid #f8bbd0; padding-bottom: 8px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        th { background: #fce4ec; color: #880e4f; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-todo { background: #ffe0b2; color: #e65100; }
        .badge-progress { background: #bbdefb; color: #0d47a1; }
        .badge-done { background: #c8e6c9; color: #1b5e20; }
        .badge-pending { background: #fff9c4; color: #f57f17; }
        
        .btn-logout { background: #e57373; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; font-size: 13px; }
        .btn-sub { background: #c2185b; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-sub:hover { background: #880e4f; }
        select { padding: 4px; border-radius: 4px; border: 1px solid #ccc; font-size: 12px; }
    </style>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css">
</head>
<body>

    <div class="header">
        <h2 style="color:#c2185b; margin:0;">📦 Portal Order Fulfillment (Staff Pesanan)</h2>
        <div>
            <span>Logged in: <b><?php echo $_SESSION['email']; ?></b></span> | 
            <a href="index.php" class="btn" style="display:inline-block; padding:8px 15px; border-radius:5px; text-decoration:none; font-weight:bold; font-size:13px; background:#d81b60; color:white; margin-right:5px;">📚 Panduan & SOP (KMS)</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="grid">
        <!-- Kolom Kiri: Tugas Operasional (OAS) -->
        <div>
            <div class="card">
                <h3>📝 Tugas Operasional Aktif (OAS)</h3>
                <table>
                    <tr>
                        <th>Tugas</th>
                        <th>Prioritas</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                    <?php
                    $tasks = mysqli_query($conn, "SELECT * FROM tasks WHERE dikerjakan_oleh='$user_id' AND status_tugas != 'Done'");
                    
                    if (!$tasks || mysqli_num_rows($tasks) == 0) {
                        echo "<tr><td colspan='4' style='text-align:center;'>Tidak ada tugas aktif.</td></tr>";
                    } else {
                        while ($t = mysqli_fetch_assoc($tasks)) {
                            $st = $t['status_tugas'];
                            
                            $pk_col = 'id';
                            $tid = 0;
                            foreach (['id_task', 'id', 'id_tugas', 'task_id'] as $col) {
                                if (array_key_exists($col, $t)) {
                                    $pk_col = $col;
                                    $tid = $t[$col];
                                    break;
                                }
                            }

                            $bClass = ($st == 'To-Do') ? 'badge-todo' : 'badge-progress';
                            echo "<tr>
                                <td><b>{$t['judul_tugas']}</b><br><small style='color:#666;'>{$t['deskripsi']}</small></td>
                                <td>{$t['prioritas']}</td>
                                <td><span class='badge {$bClass}'>{$st}</span></td>
                                <td>
                                    <form method='POST' style='display:flex; gap:3px;'>
                                        <input type='hidden' name='pk_col' value='{$pk_col}'>
                                        <input type='hidden' name='task_id' value='{$tid}'>
                                        <select name='status_tugas'>
                                            <option value='To-Do' ".($st=='To-Do'?'selected':'').">To-Do</option>
                                            <option value='In Progress' ".($st=='In Progress'?'selected':'').">In Progress</option>
                                            <option value='Done'>Done</option>
                                        </select>
                                        <button type='submit' name='update_task' class='btn-sub'>OK</button>
                                    </form>
                                </td>
                            </tr>";
                        }
                    }
                    ?>
                </table>
            </div>

            <div class="card">
                <h3>✅ Riwayat Tugas Selesai</h3>
                <table>
                    <tr>
                        <th>Judul Tugas</th>
                        <th>Deskripsi</th>
                        <th>Status</th>
                    </tr>
                    <?php
                    $done_tasks = mysqli_query($conn, "SELECT * FROM tasks WHERE dikerjakan_oleh='$user_id' AND status_tugas='Done' ORDER BY tgl_dibuat DESC");
                    if (!$done_tasks || mysqli_num_rows($done_tasks) == 0) {
                        echo "<tr><td colspan='3' style='text-align:center;'>Belum ada tugas selesai.</td></tr>";
                    } else {
                        while ($d = mysqli_fetch_assoc($done_tasks)) {
                            echo "<tr>
                                <td><b>{$d['judul_tugas']}</b></td>
                                <td>{$d['deskripsi']}</td>
                                <td><span class='badge badge-done'>Selesai</span></td>
                            </tr>";
                        }
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- Kolom Kanan: Processing Pesanan Masuk (TPS) -->
        <div>
            <div class="card">
                <h3>🛒 Processing Pesanan Masuk (Order Fulfillment TPS)</h3>
                <table>
                    <tr>
                        <th>ID / Penerima</th>
                        <th>Total</th>
                        <th>Status Pesanan</th>
                        <th>Update Status</th>
                    </tr>
                    <?php
                    $cols = getOrdersSchema($conn);

                    if (!empty($cols)) {
                        // Deteksi Nama Kolom
                        $ord_pk = 'id';
                        foreach (['order_id', 'id', 'id_order', 'id_pesanan'] as $cand) {
                            if (in_array($cand, $cols)) { $ord_pk = $cand; break; }
                        }

                        $ord_st_col = '';
                        foreach (['status_order', 'order_status', 'st_pesanan', 'status', 'status_pesanan'] as $cand) {
                            if (in_array($cand, $cols)) { $ord_st_col = $cand; break; }
                        }
                        if (empty($ord_st_col)) {
                            foreach ($cols as $c) {
                                if (strpos(strtolower($c), 'status') !== false) { $ord_st_col = $c; break; }
                            }
                        }

                        $ord_price_col = 'total_harga';
                        foreach (['total_harga', 'total_price', 'total', 'harga_total', 'grand_total'] as $cand) {
                            if (in_array($cand, $cols)) { $ord_price_col = $cand; break; }
                        }

                        $ord_name_col = 'user_id';
                        foreach (['nama_penerima', 'nama_pelanggan', 'nama', 'customer_name', 'user_id'] as $cand) {
                            if (in_array($cand, $cols)) { $ord_name_col = $cand; break; }
                        }

                        $orders = mysqli_query($conn, "SELECT * FROM orders ORDER BY `$ord_pk` DESC");

                        if (!$orders || mysqli_num_rows($orders) == 0) {
                            echo "<tr><td colspan='4' style='text-align:center;'>Belum ada pesanan masuk.</td></tr>";
                        } else {
                            while ($o = mysqli_fetch_assoc($orders)) {
                                $oid = $o[$ord_pk];
                                $tot = isset($o[$ord_price_col]) ? "Rp " . number_format($o[$ord_price_col], 0, ',', '.') : '-';
                                $curr_st = (!empty($ord_st_col) && isset($o[$ord_st_col])) ? $o[$ord_st_col] : 'Pending';
                                $penerima = $o[$ord_name_col] ?? 'Pelanggan';

                                $bStyle = ($curr_st == 'Selesai' || $curr_st == 'Dikirim') ? 'badge-done' : (($curr_st == 'Diproses') ? 'badge-progress' : 'badge-pending');

                                echo "<tr>
                                    <td><b>#{$oid}</b><br><small>{$penerima}</small></td>
                                    <td>{$tot}</td>
                                    <td><span class='badge {$bStyle}'>{$curr_st}</span></td>
                                    <td>
                                        <form method='POST' style='display:flex; gap:3px;'>
                                            <input type='hidden' name='order_id' value='{$oid}'>
                                            <select name='status_order'>
                                                <option value='Pending' ".($curr_st=='Pending'?'selected':'').">Pending</option>
                                                <option value='Diproses' ".($curr_st=='Diproses'?'selected':'').">Diproses</option>
                                                <option value='Dikirim' ".($curr_st=='Dikirim'?'selected':'').">Dikirim</option>
                                                <option value='Selesai' ".($curr_st=='Selesai'?'selected':'').">Selesai</option>
                                            </select>
                                            <button type='submit' name='update_order_status' class='btn-sub'>Update</button>
                                        </form>
                                    </td>
                                </tr>";
                            }
                        }
                    } else {
                        echo "<tr><td colspan='4' style='text-align:center;'>Tabel orders belum ditemukan.</td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>
    </div>

</body>
</html>