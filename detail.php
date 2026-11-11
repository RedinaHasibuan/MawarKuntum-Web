<?php
require 'config.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// PERBAIKAN: u.user_id
$q = mysqli_query($koneksi, "
    SELECT a.*, c.nama_kategori, u.nama AS nama_penulis
    FROM kms_articles a
    LEFT JOIN kms_categories c ON a.id_kategori = c.id_kategori
    LEFT JOIN $db_main.users u ON a.penulis_id = u.user_id 
    WHERE a.id_artikel = $id
");
$artikel = $q ? mysqli_fetch_assoc($q) : null;

if (!$artikel) {
    header('Location: index.php?pesan=' . urlencode('Artikel tidak ditemukan.'));
    exit;
}
$judul_halaman = $artikel['judul'];

// ===== Tandai sudah dibaca =====
$cek_baca = mysqli_query($koneksi, "SELECT 1 FROM kms_article_reads WHERE id_artikel=$id AND user_id=$id_user_login");
if (mysqli_num_rows($cek_baca) === 0) {
    mysqli_query($koneksi, "INSERT INTO kms_article_reads (id_artikel, user_id) VALUES ($id, $id_user_login)");
    mysqli_query($koneksi, "UPDATE kms_articles SET jumlah_dibaca = jumlah_dibaca + 1 WHERE id_artikel = $id");
}
$jumlah_pembaca = mysqli_fetch_row(mysqli_query($koneksi, "SELECT COUNT(*) FROM kms_article_reads WHERE id_artikel=$id"))[0];

// ===== Proses kirim feedback =====
$pesan_feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['is_helpful'])) {
    $is_helpful = (int) $_POST['is_helpful'];
    $komentar = mysqli_real_escape_string($koneksi, trim($_POST['komentar'] ?? ''));
    mysqli_query($koneksi, "
        INSERT INTO kms_feedback (id_artikel, user_id, is_helpful, komentar)
        VALUES ($id, $id_user_login, $is_helpful, '$komentar')
        ON DUPLICATE KEY UPDATE is_helpful = $is_helpful, komentar = '$komentar', created_at = CURRENT_TIMESTAMP
    ");
    $pesan_feedback = 'Terima kasih, masukan kamu sudah tersimpan.';
}

// ===== Lampiran =====
$lampiran = mysqli_query($koneksi, "SELECT * FROM kms_attachments WHERE id_artikel=$id ORDER BY uploaded_at DESC");

// ===== Riwayat versi =====
// PERBAIKAN: u.user_id
$versi = mysqli_query($koneksi, "
    SELECT v.*, u.nama AS nama_pengubah
    FROM kms_article_versions v
    LEFT JOIN $db_main.users u ON v.diubah_oleh = u.user_id
    WHERE v.id_artikel=$id ORDER BY v.no_versi DESC
");

// ===== Feedback list & rekap =====
$rekap = mysqli_fetch_assoc(mysqli_query($koneksi, "
    SELECT SUM(is_helpful=1) AS suka, SUM(is_helpful=0) AS tidak_suka
    FROM kms_feedback WHERE id_artikel=$id
"));
// PERBAIKAN: u.user_id
$daftar_feedback = mysqli_query($koneksi, "
    SELECT f.*, u.nama AS nama_pemberi
    FROM kms_feedback f
    LEFT JOIN $db_main.users u ON f.user_id = u.user_id
    WHERE f.id_artikel=$id ORDER BY f.created_at DESC
");

require 'includes/header.php';
?>

<div class="topline">
  <div>
    <h1><?= htmlspecialchars($artikel['judul']) ?></h1>
    <p class="meta">
      <?= htmlspecialchars($artikel['nama_kategori'] ?? '—') ?> ·
      <?= htmlspecialchars($artikel['tipe_dokumen']) ?> ·
      Ditulis oleh <?= htmlspecialchars($artikel['nama_penulis'] ?? '—') ?> ·
      versi <?= (int) $artikel['versi_aktif'] ?> ·
      dibaca <?= (int) $jumlah_pembaca ?> orang
    </p>
  </div>
  <div>
    <?= badge_status($artikel['status']) ?>
  </div>
</div>

<div class="card">
  <div class="content-box"><?= nl2br(htmlspecialchars($artikel['konten'])) ?></div>
  <hr class="divider">
  <div class="actions">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-outline btn-sm">Edit Artikel</a>
    <a href="hapus.php?id=<?= $id ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus artikel ini beserta seluruh riwayat, lampiran, dan feedback-nya?');">Hapus</a>
    <a href="index.php" class="btn btn-outline btn-sm">Kembali ke Daftar</a>
  </div>
</div>

<div class="card">
  <h3>Lampiran</h3>
  <?php if (mysqli_num_rows($lampiran) === 0): ?>
    <p class="empty">Belum ada lampiran untuk artikel ini.</p>
  <?php else: ?>
    <?php while ($l = mysqli_fetch_assoc($lampiran)): ?>
      <div class="version-item">
        <a href="<?= htmlspecialchars($l['path_file']) ?>" target="_blank"><?= htmlspecialchars($l['nama_file']) ?></a>
        <span class="meta"> — diunggah <?= date('d M Y', strtotime($l['uploaded_at'])) ?></span>
      </div>
    <?php endwhile; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Riwayat Versi</h3>
  <?php while ($v = mysqli_fetch_assoc($versi)): ?>
    <div class="version-item">
      <strong>Versi <?= $v['no_versi'] ?></strong>
      <span class="meta"> — diubah oleh <?= htmlspecialchars($v['nama_pengubah'] ?? '—') ?>, <?= date('d M Y H:i', strtotime($v['dibuat_at'])) ?></span>
      <?php if ($v['catatan_perubahan']): ?>
        <div class="meta">Catatan: <?= htmlspecialchars($v['catatan_perubahan']) ?></div>
      <?php endif; ?>
    </div>
  <?php endwhile; ?>
</div>

<div class="card">
  <h3>Masukan Pembaca</h3>
  <p class="meta"><?= (int) ($rekap['suka'] ?? 0) ?> orang merasa terbantu · <?= (int) ($rekap['tidak_suka'] ?? 0) ?> orang merasa kurang membantu</p>

  <?php if ($pesan_feedback): ?><div class="alert"><?= htmlspecialchars($pesan_feedback) ?></div><?php endif; ?>

  <form method="post">
    <label>Apakah artikel ini membantu?</label>
    <div style="margin-bottom:10px;">
      <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-right:20px;">
        <input type="radio" name="is_helpful" value="1" required style="width:auto"> Ya, membantu
      </label>
      <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
        <input type="radio" name="is_helpful" value="0" style="width:auto"> Kurang membantu
      </label>
    </div>
    <label for="komentar">Komentar (opsional)</label>
    <textarea id="komentar" name="komentar" style="min-height:80px" placeholder="Bagian mana yang perlu diperjelas?"></textarea>
    <div style="margin-top:14px;"><button type="submit" class="btn btn-primary">Kirim Masukan</button></div>
  </form>

  <hr class="divider">

  <?php if (mysqli_num_rows($daftar_feedback) === 0): ?>
    <p class="empty">Belum ada masukan dari pembaca.</p>
  <?php else: ?>
    <?php while ($f = mysqli_fetch_assoc($daftar_feedback)): ?>
      <div class="feedback-item">
        <strong><?= htmlspecialchars($f['nama_pemberi'] ?? 'Pengguna') ?></strong>
        <span class="meta"> — <?= $f['is_helpful'] ? 'menganggap membantu' : 'menganggap kurang membantu' ?>, <?= date('d M Y', strtotime($f['created_at'])) ?></span>
        <?php if ($f['komentar']): ?><div><?= nl2br(htmlspecialchars($f['komentar'])) ?></div><?php endif; ?>
      </div>
    <?php endwhile; ?>
  <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>