<?php
require 'config.php';

// RBAC Lock: Hanya Admin / Manager
$user_id_login = $_SESSION['user_id'] ?? 0;
$db_main = $db_main ?? 'mawarkuntum';
$q_role = mysqli_query($koneksi, "SELECT sr.nama_sub_role FROM $db_main.users u JOIN $db_main.sub_roles sr ON u.id_sub_role = sr.id_sub_role WHERE u.user_id = $user_id_login");
$role_name = ($q_role && mysqli_num_rows($q_role) > 0) ? strtolower(mysqli_fetch_assoc($q_role)['nama_sub_role']) : '';
$role_utama = strtolower($_SESSION['role'] ?? '');
if ($role_utama !== 'admin' && strpos($role_name, 'manager') === false && strpos($role_name, 'supervisor') === false) {
    echo "<script>alert('Akses Ditolak! Anda hanya memiliki hak baca (Read-only).'); window.location.href='index.php';</script>";
    exit();
}

$judul_halaman = 'Kelola Kategori';
$error = '';
$sukses = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nama_kategori'])) {
    $nama = trim($_POST['nama_kategori']);
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    
    if ($nama === '') {
        $error = 'Nama kategori wajib diisi.';
    } else {
        $nama_e = mysqli_real_escape_string($koneksi, $nama);
        $deskripsi_e = mysqli_real_escape_string($koneksi, $deskripsi);
        
        if (!mysqli_query($koneksi, "INSERT INTO kms_categories (nama_kategori, deskripsi) VALUES ('$nama_e', '$deskripsi_e')")) {
            $error = 'Kategori dengan nama itu sudah ada.';
        } else {
            $sukses = 'Kategori berhasil ditambahkan.';
        }
    }
}

if (isset($_GET['hapus'])) {
    $id_hapus = (int) $_GET['hapus'];
    if (!mysqli_query($koneksi, "DELETE FROM kms_categories WHERE id_kategori=$id_hapus")) {
        $error = 'Kategori tidak bisa dihapus karena masih dipakai oleh artikel. Pindahkan artikelnya dulu.';
    } else {
        $sukses = 'Kategori berhasil dihapus.';
    }
}

$daftar = mysqli_query($koneksi, "
    SELECT c.*, COUNT(a.id_artikel) AS jumlah_artikel
    FROM kms_categories c
    LEFT JOIN kms_articles a ON a.id_kategori = c.id_kategori
    GROUP BY c.id_kategori
    ORDER BY c.nama_kategori
");
require 'includes/header.php';
?>
<h1>Kelola Kategori</h1>
<p class="meta">Kategori dipakai untuk mengelompokkan artikel & SOP agar mudah dicari.</p>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($sukses): ?><div class="alert"><?= htmlspecialchars($sukses) ?></div><?php endif; ?>

<div class="card" style="max-width:600px;">
    <h3>Tambah Kategori Baru</h3>
    <form method="post">
        <label for="nama_kategori">Nama Kategori</label>
        <input type="text" id="nama_kategori" name="nama_kategori" required placeholder="Contoh: SOP Gudang">
        
        <label for="deskripsi">Deskripsi (opsional)</label>
        <textarea id="deskripsi" name="deskripsi" style="min-height:80px" placeholder="Ringkasan singkat isi kategori ini"></textarea>
        
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary">Tambah Kategori</button>
        </div>
    </form>
</div>

<div class="card">
    <h3>Daftar Kategori</h3>
    <?php if (mysqli_num_rows($daftar) === 0): ?>
        <p class="empty">Belum ada kategori.</p>
    <?php else: ?>
        <table>
            <tr><th>Nama</th><th>Deskripsi</th><th>Jumlah Artikel</th><th></th></tr>
            <?php while ($k = mysqli_fetch_assoc($daftar)): ?>
                <tr>
                    <td><?= htmlspecialchars($k['nama_kategori']) ?></td>
                    <td class="meta"><?= htmlspecialchars($k['deskripsi'] ?: '-') ?></td>
                    <td class="meta"><?= (int) $k['jumlah_artikel'] ?></td>
                    <td>
                        <a href="kategori.php?hapus=<?= $k['id_kategori'] ?>" class="meta" onclick="return confirm('Hapus kategori <?= htmlspecialchars(addslashes($k['nama_kategori'])) ?>?');">Hapus</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php endif; ?>
</div>
<?php require 'includes/footer.php'; ?>