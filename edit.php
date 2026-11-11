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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$error = '';

$q = mysqli_query($koneksi, "SELECT * FROM kms_articles WHERE id_artikel=$id");
$artikel = $q ? mysqli_fetch_assoc($q) : null;

if (!$artikel) {
    header('Location: index.php?pesan=' . urlencode('Artikel tidak ditemukan.'));
    exit;
}

$judul_halaman = 'Edit - ' . $artikel['judul'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $judul = trim($_POST['judul'] ?? '');
    $id_kategori = (int) ($_POST['id_kategori'] ?? 0);
    $tipe = $_POST['tipe_dokumen'] ?? 'SOP';
    $konten = trim($_POST['konten'] ?? '');
    $status = $_POST['status'] ?? 'Draft';
    $catatan = trim($_POST['catatan_perubahan'] ?? '');

    if ($judul === '' || $konten === '' || $id_kategori === 0) {
        $error = 'Judul, kategori, dan isi artikel wajib diisi.';
    } else {
        $versi_baru = (int) $artikel['versi_aktif'] + 1;
        $judul_e = mysqli_real_escape_string($koneksi, $judul);
        $konten_e = mysqli_real_escape_string($koneksi, $konten);
        $tipe_e = mysqli_real_escape_string($koneksi, $tipe);
        $status_e = mysqli_real_escape_string($koneksi, $status);
        $catatan_e = mysqli_real_escape_string($koneksi, $catatan !== '' ? $catatan : 'Pembaruan konten');

        mysqli_query($koneksi, "UPDATE kms_articles SET id_kategori=$id_kategori, judul='$judul_e', konten='$konten_e', tipe_dokumen='$tipe_e', status='$status_e', versi_aktif=$versi_baru WHERE id_artikel=$id");
        mysqli_query($koneksi, "INSERT INTO kms_article_versions (id_artikel, no_versi, konten, catatan_perubahan, diubah_oleh) VALUES ($id, $versi_baru, '$konten_e', '$catatan_e', $user_id_login)");

        if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
            $nama_asli = basename($_FILES['lampiran']['name']);
            $nama_unik = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $nama_asli);
            $tujuan = 'uploads/' . $nama_unik;

            if (move_uploaded_file($_FILES['lampiran']['tmp_name'], $tujuan)) {
                $tipe_file = mysqli_real_escape_string($koneksi, $_FILES['lampiran']['type']);
                $nama_asli_e = mysqli_real_escape_string($koneksi, $nama_asli);
                mysqli_query($koneksi, "INSERT INTO kms_attachments (id_artikel, nama_file, path_file, tipe_file) VALUES ($id, '$nama_asli_e', '$tujuan', '$tipe_file')");
            }
        }
        header('Location: detail.php?id=' . $id);
        exit;
    }
}

$daftar_kategori = mysqli_query($koneksi, "SELECT id_kategori, nama_kategori FROM kms_categories ORDER BY nama_kategori");
require 'includes/header.php';
?>
<h1>Edit Artikel</h1>
<p class="meta">Menyimpan perubahan akan otomatis membuat versi <?= (int) $artikel['versi_aktif'] + 1 ?>.</p>
<div class="card" style="max-width: 720px;">
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <label for="judul">Judul</label>
        <input type="text" id="judul" name="judul" required value="<?= htmlspecialchars($_POST['judul'] ?? $artikel['judul']) ?>">
        
        <div class="row2">
            <div>
                <label for="id_kategori">Kategori</label>
                <select id="id_kategori" name="id_kategori" required>
                    <?php while ($k = mysqli_fetch_assoc($daftar_kategori)): ?>
                        <option value="<?= $k['id_kategori'] ?>" <?= $k['id_kategori'] == $artikel['id_kategori'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($k['nama_kategori']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label for="tipe_dokumen">Tipe Dokumen</label>
                <select id="tipe_dokumen" name="tipe_dokumen">
                    <?php foreach (['SOP', 'Panduan', 'Troubleshooting', 'FAQ', 'Kebijakan'] as $t): ?>
                        <option value="<?= $t ?>" <?= $t === $artikel['tipe_dokumen'] ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label for="konten">Isi Artikel</label>
        <textarea id="konten" name="konten" required><?= htmlspecialchars($_POST['konten'] ?? $artikel['konten']) ?></textarea>
        
        <label for="catatan_perubahan">Catatan Perubahan</label>
        <input type="text" id="catatan_perubahan" name="catatan_perubahan" placeholder="Contoh: perbarui jam operasional">
        
        <div class="row2">
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['Draft', 'Review', 'Published', 'Archived'] as $s): ?>
                        <option value="<?= $s ?>" <?= $s === $artikel['status'] ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="lampiran">Tambah Lampiran (opsional)</label>
                <input type="file" id="lampiran" name="lampiran">
            </div>
        </div>

        <div style="margin-top: 22px;">
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            <a href="detail.php?id=<?= $id ?>" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
<?php require 'includes/footer.php'; ?>