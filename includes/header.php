<?php
// Deteksi Role User untuk Keamanan Navigasi
$role_utama = strtolower($_SESSION['role'] ?? '');
$sub_role   = strtolower($_SESSION['nama_sub_role'] ?? '');
$is_manager = ($role_utama === 'admin' || strpos($sub_role, 'manager') !== false || strpos($sub_role, 'supervisor') !== false);

// Link kembali ke dashboard sesuai role
$link_kembali = "katalog.php";
if ($role_utama === 'admin') {
    $link_kembali = "index.php";
} elseif (strpos($sub_role, 'manager') !== false || strpos($sub_role, 'supervisor') !== false) {
    $link_kembali = "dashboard_manager.php";
} elseif (strpos($sub_role, 'gudang') !== false) {
    $link_kembali = "dashboard_gudang.php";
} elseif (strpos($sub_role, 'pesanan') !== false) {
    $link_kembali = "dashboard_pesanan.php";
}

if (!function_exists('badge_status')) {
    function badge_status($status) {
        $warna = match(strtolower($status)) {
            'draft' => 'background-color:#e0e0e0; color:#424242;',
            'review' => 'background-color:#fff59d; color:#f57f17;',
            'published' => 'background-color:#a5d6a7; color:#1b5e20;',
            'archived' => 'background-color:#ef9a9a; color:#c62828;',
            default => 'background-color:#eee; color:#333;'
        };
        return "<span style='padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; $warna'>$status</span>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($judul_halaman) ? htmlspecialchars($judul_halaman) . ' - ' : '' ?>KMS Mawarkuntum</title>
    <style>
        :root {
            --primary: #d81b60;
            --primary-light: #f48fb1;
            --primary-hover: #f06292;
            --bg-color: #e8f5e9;
            --text-main: #2e7d32;
            --text-dark: #333333;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --border-color: #f8bbd0;
        }
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-color); color: var(--text-dark); display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: var(--sidebar-bg); border-right: 2px solid var(--border-color); padding: 20px 0; flex-shrink: 0; box-shadow: 2px 0 10px rgba(129, 199, 132, 0.1); position: relative; }
        .sidebar .brand { text-align: center; color: var(--primary); font-size: 22px; font-weight: bold; padding-bottom: 20px; border-bottom: 1px solid var(--border-color); margin-bottom: 15px; }
        .sidebar a { display: block; padding: 12px 20px; color: var(--text-main); text-decoration: none; font-weight: 600; transition: 0.3s; }
        .sidebar a:hover { background-color: #fce4ec; color: var(--primary); border-left: 5px solid var(--primary); }
        .sidebar .user-info { position: absolute; bottom: 20px; left: 20px; font-size: 13px; color: var(--text-main); }
        .main-content { flex-grow: 1; padding: 30px; overflow-y: auto; }
        h1, h2, h3 { color: var(--primary); margin-top: 0; }
        .topline { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #c8e6c9; padding-bottom: 10px; }
        .card { background: var(--card-bg); padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(129, 199, 132, 0.15); border: 1px solid var(--border-color); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; border-bottom: 1px solid #c8e6c9; text-align: left; }
        th { background-color: #fce4ec; color: var(--primary); }
        tr:hover { background-color: #f9fbe7; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; cursor: pointer; border: none; transition: 0.3s; }
        .btn-primary { background-color: var(--primary-light); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); }
        .btn-outline { background-color: transparent; border: 2px solid var(--primary-light); color: var(--primary); }
        .btn-danger { background-color: #ef5350; color: white; }
        input[type="text"], input[type="file"], select, textarea { width: 100%; padding: 10px; margin: 8px 0 15px; border: 1px solid #c8e6c9; border-radius: 6px; background-color: #fbfcfa; box-sizing: border-box; font-family: inherit; }
        label { font-weight: bold; color: var(--text-main); }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .alert { background-color: #e8f5e9; color: #2e7d32; padding: 12px; border-radius: 6px; border: 1px solid #a5d6a7; margin-bottom: 15px; }
        .alert-error { background-color: #ffebee; color: #c62828; border-color: #ffcdd2; }
        .meta { color: #666; font-size: 13px; }
        .filters { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .filters input, .filters select { margin: 0; width: auto; flex: 1; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand">🌸 Mawarkuntum 🌸<br><span style="font-size: 14px; font-weight: normal;">KMS Portal</span></div>
    
    <a href="index.php">Daftar Artikel</a>
    
    <?php if ($is_manager): ?>
        <a href="tambah.php">Tambah Artikel</a>
        <a href="kategori.php">Kelola Kategori</a>
    <?php endif; ?>
    
    <a href="<?= $link_kembali ?>" style="margin-top: 20px; border-top: 1px solid var(--border-color);">⬅ Kembali ke Aplikasi</a>

    <div class="user-info">
        Masuk sebagai:<br>
        <strong style="color: var(--primary);"><?= htmlspecialchars($_SESSION['email'] ?? 'User') ?></strong>
    </div>
</div>

<div class="main-content">