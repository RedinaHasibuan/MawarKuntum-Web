<?php
session_start();
include 'koneksi.php';

// Keamanan: halaman hanya dapat dibuka oleh pengguna yang sudah login.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = (int) $_SESSION['user_id'];

$jenis_produk_opsi = [
    'Cushion', 'Setting Spray', 'Foundation', 'Concealer', 'Bedak', 'Primer',
    'Blush On', 'Highlighter', 'Contour', 'Lip Tint', 'Lipstick', 'Lip Gloss',
    'Mascara', 'Eyeshadow Palette', 'Pensil Alis', 'Eyeliner', 'Micellar Water',
    'Facial Wash', 'Toner', 'Serum', 'Moisturizer', 'Sunscreen', 'Lainnya'
];
$jenis_kulit_opsi = ['Normal', 'Berminyak', 'Kering', 'Kombinasi', 'Semua jenis kulit'];
$finish_opsi = ['Matte', 'Dewy', 'Satin', 'Natural', 'Glowy', 'Tidak berlaku'];
$tag_opsi = [
    'Oil Control', 'Lightweight', 'Hydrating', 'Long Lasting', 'Non-Comedogenic',
    'Waterproof', 'Transferproof', 'Fragrance-Free', 'Coverage Ringan',
    'Coverage Medium', 'Coverage Full'
];

function mwk_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function mwk_tabel_rekomendasi_ada($conn, $namaTabel) {
    $allowed = ['product_recommendation_profiles', 'product_profile_skin_types', 'product_profile_tags'];
    if (!in_array($namaTabel, $allowed, true)) return false;
    $nama = mysqli_real_escape_string($conn, $namaTabel);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '{$nama}'");
    return $q && mysqli_num_rows($q) > 0;
}

$fitur_rekomendasi_siap = mwk_tabel_rekomendasi_ada($conn, 'product_recommendation_profiles')
    && mwk_tabel_rekomendasi_ada($conn, 'product_profile_skin_types')
    && mwk_tabel_rekomendasi_ada($conn, 'product_profile_tags');

/** Simpan atribut rekomendasi untuk satu produk. Harus dipanggil di dalam transaksi. */
function mwk_simpan_atribut_rekomendasi($conn, $productId, $jenisProduk, $finish, $catatan, array $jenisKulit, array $tags) {
    $finishDb = ($finish === '' || $finish === 'Tidak berlaku') ? null : $finish;
    $catatan = trim($catatan);
    if (mb_strlen($catatan, 'UTF-8') > 255) $catatan = mb_substr($catatan, 0, 255, 'UTF-8');

    $stmt = mysqli_prepare($conn, "INSERT INTO product_recommendation_profiles
        (product_id, jenis_produk, finish, catatan_rekomendasi, aktif)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE jenis_produk = VALUES(jenis_produk), finish = VALUES(finish),
        catatan_rekomendasi = VALUES(catatan_rekomendasi), aktif = 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'isss', $productId, $jenisProduk, $finishDb, $catatan);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) return false;

    foreach (['product_profile_skin_types', 'product_profile_tags'] as $tabel) {
        $del = mysqli_prepare($conn, "DELETE FROM {$tabel} WHERE product_id = ?");
        if (!$del) return false;
        mysqli_stmt_bind_param($del, 'i', $productId);
        $ok = mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        if (!$ok) return false;
    }

    if ($jenisKulit) {
        $insSkin = mysqli_prepare($conn, "INSERT INTO product_profile_skin_types (product_id, jenis_kulit) VALUES (?, ?)");
        if (!$insSkin) return false;
        foreach ($jenisKulit as $skin) {
            mysqli_stmt_bind_param($insSkin, 'is', $productId, $skin);
            if (!mysqli_stmt_execute($insSkin)) { mysqli_stmt_close($insSkin); return false; }
        }
        mysqli_stmt_close($insSkin);
    }

    if ($tags) {
        $insTag = mysqli_prepare($conn, "INSERT INTO product_profile_tags (product_id, nama_tag) VALUES (?, ?)");
        if (!$insTag) return false;
        foreach ($tags as $tag) {
            mysqli_stmt_bind_param($insTag, 'is', $productId, $tag);
            if (!mysqli_stmt_execute($insTag)) { mysqli_stmt_close($insTag); return false; }
        }
        mysqli_stmt_close($insTag);
    }
    return true;
}

function mwk_validasi_atribut_rekomendasi($post, $jenisProdukOpsi, $jenisKulitOpsi, $finishOpsi, $tagOpsi) {
    $jenisProduk = trim((string) ($post['jenis_produk'] ?? ''));
    $finish = trim((string) ($post['finish'] ?? ''));
    $catatan = trim((string) ($post['catatan_rekomendasi'] ?? ''));
    $jenisKulit = array_values(array_unique(array_intersect($jenisKulitOpsi, (array) ($post['jenis_kulit'] ?? []))));
    $tags = array_values(array_unique(array_intersect($tagOpsi, (array) ($post['karakteristik'] ?? []))));

    if (!in_array($jenisProduk, $jenisProdukOpsi, true)) {
        return [false, 'Pilih jenis produk terlebih dahulu.', null];
    }
    if ($finish !== '' && !in_array($finish, $finishOpsi, true)) {
        return [false, 'Pilihan finish tidak valid.', null];
    }
    if (!$jenisKulit) {
        return [false, 'Pilih minimal satu kecocokan jenis kulit. Gunakan Semua jenis kulit jika produk dapat digunakan semua jenis kulit.', null];
    }
    if (in_array('Semua jenis kulit', $jenisKulit, true)) {
        $jenisKulit = ['Semua jenis kulit'];
    }
    return [true, '', [
        'jenis_produk' => $jenisProduk,
        'finish' => $finish,
        'catatan' => $catatan,
        'jenis_kulit' => $jenisKulit,
        'tags' => $tags
    ]];
}

// 1. Handler Update Status Tugas (OAS)
if (isset($_POST['update_task'])) {
    $task_id = intval($_POST['task_id']);
    $pk_col = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['pk_col'] ?? 'id');
    $status = mysqli_real_escape_string($conn, $_POST['status_tugas']);
    mysqli_query($conn, "UPDATE tasks SET status_tugas='$status' WHERE `$pk_col`=$task_id");
    header("Location: dashboard_gudang.php"); exit();
}

// 2. Handler Restock Barang Lama (TPS)
if (isset($_POST['add_stock'])) {
    $product_id = intval($_POST['product_id']);
    $qty_tambah = max(0, intval($_POST['qty_tambah']));
    mysqli_query($conn, "UPDATE products SET stok = stok + $qty_tambah WHERE product_id=$product_id");
    header("Location: dashboard_gudang.php"); exit();
}

// 3. Handler Tambah Produk Baru beserta atribut rekomendasi
if (isset($_POST['create_product'])) {
    if (!$fitur_rekomendasi_siap) {
        $_SESSION['error_msg'] = 'Tabel rekomendasi belum tersedia. Jalankan db_migration/01_migrasi_produk_rekomendasi.sql terlebih dahulu.';
    } else {
        [$valid, $pesanValidasi, $atribut] = mwk_validasi_atribut_rekomendasi($_POST, $jenis_produk_opsi, $jenis_kulit_opsi, $finish_opsi, $tag_opsi);
        $nama = trim($_POST['nama_produk'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        $harga = (float) ($_POST['harga'] ?? 0);
        $stok = (int) ($_POST['stok'] ?? 0);
        $gambar = trim($_POST['gambar'] ?? '');

        if (!$valid) {
            $_SESSION['error_msg'] = $pesanValidasi;
        } elseif ($nama === '' || $kategori === '' || $harga < 0 || $stok < 0 || $gambar === '') {
            $_SESSION['error_msg'] = 'Lengkapi data produk dengan benar. Harga dan stok tidak boleh negatif.';
        } else {
            mysqli_begin_transaction($conn);
            $stmt = mysqli_prepare($conn, "INSERT INTO products (nama_produk, kategori, harga, stok, gambar) VALUES (?, ?, ?, ?, ?)");
            $ok = false;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssdis', $nama, $kategori, $harga, $stok, $gambar);
                // Tipe bind yang benar: string, string, double, integer, string.
                $ok = mysqli_stmt_execute($stmt);
                $productId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }
            if ($ok && mwk_simpan_atribut_rekomendasi($conn, $productId, $atribut['jenis_produk'], $atribut['finish'], $atribut['catatan'], $atribut['jenis_kulit'], $atribut['tags'])) {
                mysqli_commit($conn);
                $_SESSION['success_msg'] = 'Produk berhasil ditambahkan ke katalog beserta atribut rekomendasinya.';
            } else {
                mysqli_rollback($conn);
                $_SESSION['error_msg'] = 'Produk gagal disimpan. Periksa kembali database dan jalankan kedua tabel migrasi produk rekomendasi.';
            }
        }
    }
    header("Location: dashboard_gudang.php"); exit();
}

// 4. Handler Edit Produk beserta atribut rekomendasi
if (isset($_POST['edit_product'])) {
    if (!$fitur_rekomendasi_siap) {
        $_SESSION['error_msg'] = 'Tabel rekomendasi belum tersedia. Jalankan db_migration/01_migrasi_produk_rekomendasi.sql terlebih dahulu.';
    } else {
        [$valid, $pesanValidasi, $atribut] = mwk_validasi_atribut_rekomendasi($_POST, $jenis_produk_opsi, $jenis_kulit_opsi, $finish_opsi, $tag_opsi);
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $nama = trim($_POST['nama_produk'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        $harga = (float) ($_POST['harga'] ?? 0);
        $stok = (int) ($_POST['stok'] ?? 0);

        if (!$valid) {
            $_SESSION['error_msg'] = $pesanValidasi;
        } elseif ($product_id <= 0 || $nama === '' || $kategori === '' || $harga < 0 || $stok < 0) {
            $_SESSION['error_msg'] = 'Data produk tidak valid.';
        } else {
            mysqli_begin_transaction($conn);
            $stmt = mysqli_prepare($conn, "UPDATE products SET nama_produk = ?, kategori = ?, harga = ?, stok = ? WHERE product_id = ?");
            $ok = false;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssdii', $nama, $kategori, $harga, $stok, $product_id);
                $ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            if ($ok && mwk_simpan_atribut_rekomendasi($conn, $product_id, $atribut['jenis_produk'], $atribut['finish'], $atribut['catatan'], $atribut['jenis_kulit'], $atribut['tags'])) {
                mysqli_commit($conn);
                $_SESSION['success_msg'] = 'Data produk dan atribut rekomendasi berhasil diperbarui.';
            } else {
                mysqli_rollback($conn);
                $_SESSION['error_msg'] = 'Perubahan produk gagal disimpan. Periksa kembali database.';
            }
        }
    }
    header("Location: dashboard_gudang.php"); exit();
}

// 5. Handler Kirim Laporan Audit Stok ke Manager
if (isset($_POST['send_audit_report'])) {
    $judul = mysqli_real_escape_string($conn, $_POST['judul_laporan']);
    $catatan = mysqli_real_escape_string($conn, $_POST['catatan_audit']);
    $kondisi_stok = mysqli_real_escape_string($conn, $_POST['kondisi_stok'] ?? 'Sesuai / Fisik Match');
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
    if (mysqli_query($conn, $q_insert)) {
        $_SESSION['success_msg'] = 'Laporan Audit Stok berhasil dikirim ke Manager!';
    } else {
        $_SESSION['error_msg'] = 'Gagal mengirim laporan audit.';
    }
    header("Location: dashboard_gudang.php"); exit();
}

// Ambil Data Produk yang akan Diedit
$edit_data = null;
$edit_profile = ['jenis_produk' => '', 'finish' => '', 'catatan_rekomendasi' => ''];
$edit_skin_types = [];
$edit_tags = [];
if (isset($_GET['action']) && $_GET['action'] === 'edit') {
    $id_edit = (int) ($_GET['id'] ?? 0);
    $res = mysqli_query($conn, "SELECT * FROM products WHERE product_id=$id_edit");
    $edit_data = $res ? mysqli_fetch_assoc($res) : null;
    if ($edit_data && $fitur_rekomendasi_siap) {
        $qProfile = mysqli_prepare($conn, "SELECT jenis_produk, finish, catatan_rekomendasi FROM product_recommendation_profiles WHERE product_id = ?");
        if ($qProfile) {
            mysqli_stmt_bind_param($qProfile, 'i', $id_edit);
            mysqli_stmt_execute($qProfile);
            $profileResult = mysqli_stmt_get_result($qProfile);
            $edit_profile = mysqli_fetch_assoc($profileResult) ?: $edit_profile;
            mysqli_stmt_close($qProfile);
        }
        $qSkin = mysqli_query($conn, "SELECT jenis_kulit FROM product_profile_skin_types WHERE product_id=$id_edit");
        while ($qSkin && $r = mysqli_fetch_assoc($qSkin)) $edit_skin_types[] = $r['jenis_kulit'];
        $qTags = mysqli_query($conn, "SELECT nama_tag FROM product_profile_tags WHERE product_id=$id_edit");
        while ($qTags && $r = mysqli_fetch_assoc($qTags)) $edit_tags[] = $r['nama_tag'];
    }
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
        .subsection { margin: 16px 0 12px; padding: 13px; border: 1px solid #f3d7e3; border-radius: 9px; background: #fff9fc; }
        .subsection h4 { margin: 0 0 6px; color: #ad426e; font-size: 14px; }
        .subsection p { margin: 0 0 10px; color: #777; font-size: 11px; line-height: 1.5; }
        .metadata-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .check-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 7px 10px; }
        .check-item { display: flex; align-items: center; gap: 7px; color: #555; font-size: 12px; font-weight: 500; }
        .check-item input { accent-color: #d81b60; }
        .hint { color: #777; font-size: 11px; line-height: 1.5; }
        @media(max-width:850px) { .grid { grid-template-columns: 1fr; } }
        @media(max-width:520px) { body { padding: 10px; } .header { display:block; } .header h2 { font-size:18px; } .metadata-grid { grid-template-columns:1fr; } }

        .alert { padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="header">
        <h2>🏭 Portal Inventory Management (Staff Gudang)</h2>
        <div><span>Logged in: <b><?php echo mwk_h($_SESSION['email'] ?? ''); ?></b></span> |
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

    <?php if (!$fitur_rekomendasi_siap): ?>
        <div class="alert alert-danger">Fitur rekomendasi produk belum disiapkan. Import <b>db_migration/01_migrasi_produk_rekomendasi.sql</b> ke database <b>mawarkuntum</b> terlebih dahulu.</div>
    <?php endif; ?>

    <div class="grid">
        <!-- Kolom Kiri: Form Input Produk, Form Audit, & Tugas Operasional -->
        <div>
            <!-- Form Tambah / Edit Produk Baru -->
            <div class="card">
                <h3><?php echo $edit_data ? '✏️ Edit Data Produk' : '✨ Tambah Produk Baru (Katalog)'; ?></h3>
                <form method="POST" action="dashboard_gudang.php">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="product_id" value="<?php echo (int) $edit_data['product_id']; ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Nama Produk</label>
                        <input type="text" name="nama_produk" class="form-control" placeholder="Contoh: Mawarkuntum Matte Cushion" value="<?php echo mwk_h($edit_data['nama_produk'] ?? ''); ?>" required>
                    </div>

                    <div class="metadata-grid">
                        <div class="form-group">
                            <label>Kategori Katalog</label>
                            <select name="kategori" class="form-control" required>
                                <?php foreach (['Face', 'Lips', 'Eye', 'Eyes', 'Cleanser'] as $kategoriPilihan): ?>
                                    <option value="<?php echo mwk_h($kategoriPilihan); ?>" <?php echo (($edit_data['kategori'] ?? 'Face') === $kategoriPilihan) ? 'selected' : ''; ?>><?php echo mwk_h($kategoriPilihan); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">Tetap dipakai untuk filter kategori katalog yang sudah ada.</span>
                        </div>
                        <div class="form-group">
                            <label>Jenis Produk</label>
                            <select name="jenis_produk" class="form-control" required>
                                <option value="">-- Pilih jenis produk --</option>
                                <?php foreach ($jenis_produk_opsi as $opsi): ?>
                                    <option value="<?php echo mwk_h($opsi); ?>" <?php echo (($edit_profile['jenis_produk'] ?? '') === $opsi) ? 'selected' : ''; ?>><?php echo mwk_h($opsi); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="metadata-grid">
                        <div class="form-group">
                            <label>Harga (Rp)</label>
                            <input type="number" name="harga" class="form-control" min="0" step="1" placeholder="125000" value="<?php echo mwk_h($edit_data['harga'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Stok Awal</label>
                            <input type="number" name="stok" class="form-control" min="0" step="1" placeholder="50" value="<?php echo mwk_h($edit_data['stok'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <?php if (!$edit_data): ?>
                        <div class="form-group">
                            <label>URL Gambar / Path</label>
                            <input type="text" name="gambar" class="form-control" placeholder="https://... atau images/produk.jpg" value="" required>
                        </div>
                    <?php endif; ?>

                    <div class="subsection">
                        <h4>🌸 Atribut untuk Rekomendasi Expert System</h4>
                        <p>Isi berdasarkan deskripsi resmi produk atau informasi supplier. Atribut ini akan dipakai Asisten Virtual untuk mencocokkan kebutuhan pelanggan.</p>

                        <div class="form-group">
                            <label>Cocok untuk Jenis Kulit <span style="color:#d81b60">*</span></label>
                            <div class="check-grid" id="skin-options">
                                <?php foreach ($jenis_kulit_opsi as $i => $skin): ?>
                                    <label class="check-item">
                                        <input type="checkbox" name="jenis_kulit[]" value="<?php echo mwk_h($skin); ?>" class="skin-check" <?php echo in_array($skin, $edit_skin_types, true) ? 'checked' : ''; ?>>
                                        <?php echo mwk_h($skin); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <span class="hint">Pilih minimal satu. Jika memilih “Semua jenis kulit”, pilihan lainnya otomatis dinonaktifkan.</span>
                        </div>

                        <div class="form-group">
                            <label>Finish / Hasil Akhir Makeup</label>
                            <select name="finish" class="form-control">
                                <option value="">-- Belum ditentukan / tidak perlu --</option>
                                <?php foreach ($finish_opsi as $opsi): ?>
                                    <option value="<?php echo mwk_h($opsi); ?>" <?php echo (($edit_profile['finish'] ?? '') === $opsi) ? 'selected' : ''; ?>><?php echo mwk_h($opsi); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">Contoh: Matte, Dewy, Satin, Natural, atau Glowy. Untuk produk tanpa finish, biarkan kosong atau pilih Tidak berlaku.</span>
                        </div>

                        <div class="form-group">
                            <label>Karakteristik / Manfaat Produk</label>
                            <div class="check-grid">
                                <?php foreach ($tag_opsi as $tag): ?>
                                    <label class="check-item">
                                        <input type="checkbox" name="karakteristik[]" value="<?php echo mwk_h($tag); ?>" <?php echo in_array($tag, $edit_tags, true) ? 'checked' : ''; ?>>
                                        <?php echo mwk_h($tag); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Catatan Kecocokan (opsional)</label>
                            <input type="text" name="catatan_rekomendasi" class="form-control" maxlength="255" placeholder="Contoh: formula ringan dan membantu mengontrol kilap" value="<?php echo mwk_h($edit_profile['catatan_rekomendasi'] ?? ''); ?>">
                        </div>
                    </div>

                    <?php if ($edit_data): ?>
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
                    if ($fitur_rekomendasi_siap) {
                        $products = mysqli_query($conn, "SELECT p.*, rp.jenis_produk, rp.finish,
                            (SELECT GROUP_CONCAT(s.jenis_kulit SEPARATOR ', ') FROM product_profile_skin_types s WHERE s.product_id = p.product_id) AS rekomendasi_kulit
                            FROM products p LEFT JOIN product_recommendation_profiles rp ON rp.product_id = p.product_id
                            ORDER BY p.product_id DESC");
                    } else {
                        $products = mysqli_query($conn, "SELECT * FROM products ORDER BY product_id DESC");
                    }
                    while($products && $p = mysqli_fetch_assoc($products)) {
                        $isLow = $p['stok'] < 25;
                        $badgeStok = $isLow ? "<span class='badge badge-low'>{$p['stok']} pcs</span>" : "<b>{$p['stok']} pcs</b>";
                        echo "<tr>
                            <td><b>" . mwk_h($p['nama_produk']) . "</b><br><small style='color:#777;'>" . mwk_h($p['kategori']) . "</small>" . ($fitur_rekomendasi_siap ? "<br><small style='color:#ad426e;'>" . mwk_h($p['jenis_produk'] ?? 'Jenis belum diisi') . " · Finish: " . mwk_h($p['finish'] ?? 'Belum diisi') . " · Kulit: " . mwk_h($p['rekomendasi_kulit'] ?? 'Belum diisi') . "</small>" : "") . "</td>
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
<script>
(function () {
    var checks = Array.prototype.slice.call(document.querySelectorAll('.skin-check'));
    if (!checks.length) return;
    var semua = checks.find(function (c) { return c.value === 'Semua jenis kulit'; });
    function aturKulit() {
        if (!semua) return;
        checks.forEach(function (c) {
            if (c !== semua) {
                c.disabled = semua.checked;
                if (semua.checked) c.checked = false;
            }
        });
    }
    checks.forEach(function (c) { c.addEventListener('change', aturKulit); });
    aturKulit();
})();
</script>
</body>
</html>