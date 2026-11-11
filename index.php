<?php
require 'config.php';

// Cek Akses Manager/Admin
$user_id_kms = $_SESSION['user_id'] ?? 0;
$db_main = $db_main ?? 'mawarkuntum';
$q_role = mysqli_query($koneksi, "SELECT sr.nama_sub_role FROM $db_main.users u JOIN $db_main.sub_roles sr ON u.id_sub_role = sr.id_sub_role WHERE u.user_id = $user_id_kms");
$role_name = ($q_role && mysqli_num_rows($q_role) > 0) ? strtolower(mysqli_fetch_assoc($q_role)['nama_sub_role']) : '';
$role_utama = strtolower($_SESSION['role'] ?? '');
$is_manager = ($role_utama === 'admin' || strpos($role_name, 'manager') !== false || strpos($role_name, 'supervisor') !== false);

$judul_halaman = 'Daftar Artikel';
$cari = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$f_kategori = isset($_GET['kategori']) ? (int) $_GET['kategori'] : 0;
$f_status = isset($_GET['status']) ? $_GET['status'] : '';
$where = [];

if ($cari !== '') {
    $cari_aman = mysqli_real_escape_string($koneksi, $cari);
    $where[] = "(a.judul LIKE '%$cari_aman%' OR a.konten LIKE '%$cari_aman%')";
}
if ($f_kategori > 0) {
    $where[] = "a.id_kategori = $f_kategori";
}
if ($f_status !== '') {
    $status_aman = mysqli_real_escape_string($koneksi, $f_status);
    $where[] = "a.status = '$status_aman'";
}
$klausa_where = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT a.id_artikel, a.judul, a.tipe_dokumen, a.status, a.tgl_diperbarui, a.jumlah_dibaca,
        c.nama_kategori, u.email AS nama_penulis
        FROM kms_articles a
        LEFT JOIN kms_categories c ON a.id_kategori = c.id_kategori
        LEFT JOIN $db_main.users u ON a.penulis_id = u.user_id
        $klausa_where
        ORDER BY a.tgl_diperbarui DESC";
$hasil = mysqli_query($koneksi, $sql);
$daftar_kategori = mysqli_query($koneksi, "SELECT id_kategori, nama_kategori FROM kms_categories ORDER BY nama_kategori");

require 'includes/header.php';
?>
<div class="topline">
    <div>
        <h1>Daftar Artikel & SOP</h1>
        <p>Basis pengetahuan internal mawarkuntum - SOP, panduan, dan kebijakan.</p>
    </div>
    <?php if ($is_manager): ?>
        <a href="tambah.php" class="btn btn-primary">+ Tambah Artikel</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['pesan'])): ?>
    <div class="alert"><?= htmlspecialchars($_GET['pesan']) ?></div>
<?php endif; ?>

<div class="card">
    <form method="get" class="filters">
        <input type="text" name="cari" placeholder="Cari judul atau isi artikel..." value="<?= htmlspecialchars($cari) ?>">
        <select name="kategori">
            <option value="0">Semua kategori</option>
            <?php while ($k = mysqli_fetch_assoc($daftar_kategori)): ?>
                <option value="<?= $k['id_kategori'] ?>" <?= $f_kategori == $k['id_kategori'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($k['nama_kategori']) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <select name="status">
            <option value="">Semua status</option>
            <?php foreach (['Draft', 'Review', 'Published', 'Archived'] as $s): ?>
                <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline" type="submit">Terapkan</button>
        <?php if ($cari || $f_kategori || $f_status): ?>
            <a href="index.php" class="btn btn-outline">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (mysqli_num_rows($hasil) === 0): ?>
        <p class="empty" style="text-align: center; color: #666; padding: 20px;">Belum ada artikel yang cocok. Coba ubah pencarian.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Judul</th>
                <th>Kategori</th>
                <th>Tipe</th>
                <th>Status</th>
                <th>Penulis</th>
                <th>Diperbarui</th>
                <th>Dibaca</th>
            </tr>
            <?php while ($r = mysqli_fetch_assoc($hasil)): ?>
                <tr>
                    <td><a href="detail.php?id=<?= $r['id_artikel'] ?>" style="color: var(--primary); font-weight: bold; text-decoration: none;"><?= htmlspecialchars($r['judul']) ?></a></td>
                    <td class="meta"><?= htmlspecialchars($r['nama_kategori'] ?? '-') ?></td>
                    <td class="meta"><?= htmlspecialchars($r['tipe_dokumen']) ?></td>
                    <td><?= badge_status($r['status']) ?></td>
                    <td class="meta"><?= htmlspecialchars($r['nama_penulis'] ?? '-') ?></td>
                    <td class="meta"><?= date('d M Y', strtotime($r['tgl_diperbarui'] ?? 'now')) ?></td>
                    <td class="meta"><?= (int) $r['jumlah_dibaca'] ?>x</td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php endif; ?>
</div>
<?php require 'includes/footer.php'; ?>