<?php
session_start();
include 'koneksi.php';

// Keamanan RBAC: Hanya untuk Staff Gudang / User terautentikasi
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 1. Handler Update Status Tugas (OAS)
if(isset($_POST['update_task'])) {
    $task_id = intval($_POST['task_id']);
    $pk_col = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['pk_col'] ?? 'id');
    $status = mysqli_real_escape_string($conn, $_POST['status_tugas']);
    
    mysqli_query($conn, "UPDATE tasks SET status_tugas='$status' WHERE `$pk_col`=$task_id");
    header("Location: dashboard_gudang.php"); exit();
}

// 2. Handler Restock Barang Lama (TPS)
if(isset($_POST['add_stock'])) {
    $product_id = intval($_POST['product_id']);
    $qty_tambah = intval($_POST['qty_tambah']);
    mysqli_query($conn, "UPDATE products SET stok = stok + $qty_tambah WHERE product_id=$product_id");
    header("Location: dashboard_gudang.php"); exit();
}

// 3. Handler Tambah Produk Baru
if(isset($_POST['create_product'])) {
    $nama = mysqli_real_escape_string($conn, $_POST['nama_produk']);
    $kategori = mysqli_real_escape_string($conn, $_POST['kategori']);
    $harga = intval($_POST['harga']);
    $stok = intval($_POST['stok']);
    $gambar = mysqli_real_escape_string($conn, $_POST['gambar']);

    mysqli_query($conn, "INSERT INTO products (nama_produk, kategori, harga, stok, gambar) VALUES ('$nama', '$kategori', $harga, $stok, '$gambar')");
    header("Location: dashboard_gudang.php"); exit();
}

// 4. Handler Edit Produk
if(isset($_POST['edit_product'])) {
    $product_id = intval($_POST['product_id']);
    $nama = mysqli_real_escape_string($conn, $_POST['nama_produk']);
    $kategori = mysqli_real_escape_string($conn, $_POST['kategori']);
    $harga = intval($_POST['harga']);
    $stok = intval($_POST['stok']);

    mysqli_query($conn, "UPDATE products SET nama_produk='$nama', kategori='$kategori', harga=$harga, stok=$stok WHERE product_id=$product_id");
    header("Location: dashboard_gudang.php"); exit();
}

// 5. Handler Kirim Laporan Audit Stok ke Manager
if(isset($_POST['send_audit_report'])) {
    $judul = mysqli_real_escape_string($conn, $_POST['judul_laporan']);
    $catatan = mysqli_real_escape_string($conn, $_POST['catatan_audit']);
    $kondisi_stok = mysqli_real_escape_string($conn, $_POST['kondisi_stok'] ?? 'Sesuai / Fisik Match');

    // Buat tabel laporan_audit otomatis jika belum ada di database
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS laporan_audit (
        id_laporan INT AUTO_INCREMENT PRIMARY KEY,
        id_user INT NOT NULL,
        judul_laporan VARCHAR(255) NOT NULL,
        kondisi_stok VARCHAR(100) DEFAULT 'Sesuai / Fisik Match',
        catatan_audit TEXT NOT NULL,
        status_review VARCHAR(50) DEFAULT 'Menunggu Review',
        tgl_kirim DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $q_insert = "INSERT INTO laporan_audit (id_user, judul_laporan, kondisi_stok, catatan_audit, status_review) 
                 VALUES ('$user_id', '$judul', '$kondisi_stok', '$catatan', 'Menunggu Review')";
    
    if(mysqli_query($conn, $q_insert)) {
        $_SESSION['success_msg'] = "🚀 Laporan Audit Stok berhasil dikirim ke Manager!";
    } else {
        $_SESSION['error_msg'] = "❌ Gagal mengirim laporan: " . mysqli_error($conn);
    }

    header("Location: dashboard_gudang.php"); exit();
}

// Ambil Data Produk yang akan Diedit
$edit_data = null;
if(isset($_GET['action']) && $_GET['action'] == 'edit') {
    $id_edit = intval($_GET['id']);
    $res = mysqli_query($conn, "SELECT * FROM products WHERE product_id=$id_edit");
    $edit_data = mysqli_fetch_assoc($res);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Staff Gudang - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1.3fr; gap: 20px; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #2e7d32; font-size: 18px; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        th { background: #e8f5e9; color: #1b5e20; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-todo { background: #ffe0b2; color: #e65100; }
        .badge-progress { background: #bbdefb; color: #0d47a1; }
        .badge-done { background: #c8e6c9; color: #1b5e20; }
        .badge-low { background: #ffcdd2; color: #b71c1c; }
        
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 4px; color: #2e7d32; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 13px; }
        .btn-logout { background: #e57373; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; }
        .btn-sub { background: #2e7d32; color: white; border: none; padding: 8px 14px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 13px; }
        .btn-sub:hover { background: #1b5e20; }
        .btn-audit { background: #0288d1; color: white; border: none; padding: 8px 14px; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%; font-size: 13px; }
        .btn-audit:hover { background: #01579b; }
        .btn-edit { background: #ff9800; color: white; text-decoration: none; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        select { padding: 5px; border-radius: 4px; border: 1px solid #ccc; }

        .alert { padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="header">
        <h2>🏭 Portal Inventory Management (Staff Gudang)</h2>
        <div><span>Logged in: <b><?php echo $_SESSION['email']; ?></b></span> |
        <a href="index.php" class="btn" style="display:inline-block; padding:8px 15px; border-radius:5px; text-decoration:none; font-weight:bold; font-size:13px; background:#d81b60; color:white; margin-right:5px;">📚 Panduan & SOP Gudang (KMS)</a>
        <a href="logout.php" class="btn-logout">Logout</a></div>
    </div>

    <!-- Notifikasi Sukses/Gagal -->
    <?php if(isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success">
            <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger">
            <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
        </div>
    <?php endif; ?>

    <div class="grid">
        <!-- Kolom Kiri: Form Input Produk, Form Audit, & Tugas Operasional -->
        <div>
            <!-- Form Tambah / Edit Produk Baru -->
            <div class="card">
                <h3><?php echo $edit_data ? '✏️ Edit Data Produk' : '✨ Tambah Produk Baru (Katalog)'; ?></h3>
                <form method="POST" action="dashboard_gudang.php">
                    <?php if($edit_data): ?>
                        <input type="hidden" name="product_id" value="<?php echo $edit_data['product_id']; ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Nama Produk</label>
                        <input type="text" name="nama_produk" class="form-control" placeholder="Contoh: Mawarkuntum Micellar Water" value="<?php echo $edit_data['nama_produk'] ?? ''; ?>" required>
                    </div>

                    <div style="display:flex; gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Kategori</label>
                            <select name="kategori" class="form-control" required>
                                <option value="Face" <?php echo (isset($edit_data) && $edit_data['kategori']=='Face')?'selected':''; ?>>Face</option>
                                <option value="Lips" <?php echo (isset($edit_data) && $edit_data['kategori']=='Lips')?'selected':''; ?>>Lips</option>
                                <option value="Eye" <?php echo (isset($edit_data) && $edit_data['kategori']=='Eye')?'selected':''; ?>>Eye</option>
                                <option value="Cleanser" <?php echo (isset($edit_data) && $edit_data['kategori']=='Cleanser')?'selected':''; ?>>Cleanser</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Harga (Rp)</label>
                            <input type="number" name="harga" class="form-control" placeholder="50000" value="<?php echo $edit_data['harga'] ?? ''; ?>" required>
                        </div>
                    </div>

                    <div style="display:flex; gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Stok Awal</label>
                            <input type="number" name="stok" class="form-control" placeholder="50" value="<?php echo $edit_data['stok'] ?? ''; ?>" required>
                        </div>
                        <?php if(!$edit_data): ?>
                        <div class="form-group" style="flex:1;">
                            <label>URL Gambar / Path</label>
                            <input type="text" name="gambar" class="form-control" placeholder="images/micellar.jpg" value="https://via.placeholder.com/200" required>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if($edit_data): ?>
                        <button type="submit" name="edit_product" class="btn-sub" style="background:#ff9800;">Simpan Perubahan</button>
                        <a href="dashboard_gudang.php" style="margin-left:10px; font-size:12px; color:#666;">Batal</a>
                    <?php else: ?>
                        <button type="submit" name="create_product" class="btn-sub">+ Tambah ke Katalog</button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Form Pengiriman Laporan Audit Stok ke Manager -->
            <div class="card">
                <h3>📋 Kirim Laporan Audit Stok ke Manager</h3>
                <form method="POST" action="dashboard_gudang.php">
                    <div class="form-group">
                        <label>Judul Laporan Audit</label>
                        <input type="text" name="judul_laporan" class="form-control" placeholder="Contoh: Audit Stok Mingguan Kebayoran Warehouse" required>
                    </div>

                    <div class="form-group">
                        <label>Kondisi Stok Fisik vs Sistem</label>
                        <select name="kondisi_stok" class="form-control" required>
                            <option value="Sesuai / Fisik Match">Sesuai / Fisik Match (100%)</option>
                            <option value="Ada Selisih (Perlu Penyesuaian)">Ada Selisih (Perlu Penyesuaian)</option>
                            <option value="Terdapat Barang Rusak/Expired">Terdapat Barang Rusak / Expired</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Catatan & Rincian Audit</label>
                        <textarea name="catatan_audit" class="form-control" rows="3" placeholder="Tuliskan detail barang yang ter-audit, sisa fisik di rak, atau kendala jika ada..." required></textarea>
                    </div>

                    <button type="submit" name="send_audit_report" class="btn-audit">🚀 Kirim Laporan Audit ke Manager</button>
                </form>
            </div>

            <!-- Tugas Operasional Gudang (OAS) -->
            <div class="card">
                <h3>📝 Tugas Operasional Gudang (OAS)</h3>
                <table>
                    <tr><th>Tugas</th><th>Prioritas</th><th>Status</th><th>Aksi</th></tr>
                    <?php
                    $tasks = mysqli_query($conn, "SELECT * FROM tasks WHERE dikerjakan_oleh='$user_id' AND status_tugas != 'Done'");
                    if(!$tasks || mysqli_num_rows($tasks) == 0) { 
                        echo "<tr><td colspan='4' style='text-align:center;'>Tidak ada tugas aktif.</td></tr>"; 
                    } else {
                        while($t = mysqli_fetch_assoc($tasks)) {
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

                            $bClass = $st == 'To-Do' ? 'badge-todo' : 'badge-progress';
                            echo "<tr>
                                <td><b>{$t['judul_tugas']}</b><br><small>{$t['deskripsi']}</small></td>
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
                                        <button type='submit' name='update_task' class='btn-sub' style='padding:3px 6px;'>OK</button>
                                    </form>
                                </td>
                            </tr>";
                        }
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- Kolom Kanan: Kelola Stok Barang TPS, Riwayat Audit & Riwayat Tugas -->
        <div>
            <!-- Kelola Stok Barang & Restock (TPS) -->
            <div class="card">
                <h3>📦 Kelola Stok Barang & Restock (TPS)</h3>
                <table>
                    <tr><th>Produk</th><th>Harga</th><th>Sisa Stok</th><th>Restock Cepat</th><th>Aksi</th></tr>
                    <?php
                    $products = mysqli_query($conn, "SELECT * FROM products ORDER BY product_id DESC");
                    while($p = mysqli_fetch_assoc($products)) {
                        $isLow = $p['stok'] < 25;
                        $badgeStok = $isLow ? "<span class='badge badge-low'>{$p['stok']} pcs</span>" : "<b>{$p['stok']} pcs</b>";
                        echo "<tr>
                            <td><b>{$p['nama_produk']}</b><br><small style='color:#777;'>{$p['kategori']}</small></td>
                            <td>Rp ".number_format($p['harga'],0,',','.')."</td>
                            <td>{$badgeStok}</td>
                            <td>
                                <form method='POST' style='display:flex; gap:4px;'>
                                    <input type='hidden' name='product_id' value='{$p['product_id']}'>
                                    <input type='number' name='qty_tambah' value='10' min='1' style='width:45px; padding:3px;'>
                                    <button type='submit' name='add_stock' class='btn-sub' style='padding:3px 6px;'>+ Tambah</button>
                                </form>
                            </td>
                            <td>
                                <a href='dashboard_gudang.php?action=edit&id={$p['product_id']}' class='btn-edit'>Edit</a>
                            </td>
                        </tr>";
                    }
                    ?>
                </table>
            </div>

            <!-- Riwayat Laporan Audit Stok -->
            <div class="card">
                <h3>📜 Riwayat Laporan Audit Stok</h3>
                <table>
                    <tr><th>Judul Audit</th><th>Kondisi</th><th>Catatan</th><th>Status Review</th></tr>
                    <?php
                    $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'laporan_audit'");
                    if($check_table && mysqli_num_rows($check_table) > 0) {
                        $reports = mysqli_query($conn, "SELECT * FROM laporan_audit WHERE id_user='$user_id' ORDER BY tgl_kirim DESC");
                        if(!$reports || mysqli_num_rows($reports) == 0) {
                            echo "<tr><td colspan='4' style='text-align:center;'>Belum ada laporan audit yang dikirim.</td></tr>";
                        } else {
                            while($r = mysqli_fetch_assoc($reports)) {
                                $stReview = $r['status_review'] ?? 'Menunggu Review';
                                $badgeClass = ($stReview == 'Disetujui' || $stReview == 'Approved') ? 'badge-done' : 'badge-todo';
                                echo "<tr>
                                    <td><b>" . htmlspecialchars($r['judul_laporan']) . "</b><br><small style='color:#777;'>" . date('d M Y, H:i', strtotime($r['tgl_kirim'])) . "</small></td>
                                    <td><small>" . htmlspecialchars($r['kondisi_stok'] ?? '-') . "</small></td>
                                    <td><small>" . htmlspecialchars($r['catatan_audit']) . "</small></td>
                                    <td><span class='badge {$badgeClass}'>" . htmlspecialchars($stReview) . "</span></td>
                                </tr>";
                            }
                        }
                    } else {
                        echo "<tr><td colspan='4' style='text-align:center;'>Belum ada laporan audit yang dikirim.</td></tr>";
                    }
                    ?>
                </table>
            </div>

            <!-- Riwayat Tugas Selesai -->
            <div class="card">
                <h3>✅ Riwayat Tugas Selesai</h3>
                <table>
                    <tr><th>Judul Tugas</th><th>Deskripsi</th><th>Status</th></tr>
                    <?php
                    $done_tasks = mysqli_query($conn, "SELECT * FROM tasks WHERE dikerjakan_oleh='$user_id' AND status_tugas='Done' ORDER BY tgl_dibuat DESC");
                    if(!$done_tasks || mysqli_num_rows($done_tasks) == 0) { 
                        echo "<tr><td colspan='3' style='text-align:center;'>Belum ada riwayat tugas yang selesai.</td></tr>"; 
                    } else {
                        while($d = mysqli_fetch_assoc($done_tasks)) {
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
    </div>
</body>
</html>