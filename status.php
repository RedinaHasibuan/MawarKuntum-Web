<?php
/**
 * status.php — satu-satunya pintu perubahan status artikel (hanya lewat POST + CSRF).
 *
 *   ajukan     Draft      -> Review      (penulis / Admin)
 *   tarik      Review     -> Draft       (penulis / Admin)
 *   setujui    Review     -> Published   (Admin)
 *   kembalikan Review     -> Draft       (Admin, catatan wajib)
 *   terbitkan  Draft/Archived -> Published (Admin)
 *   arsip      Published  -> Archived    (Admin)
 */
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_cek();

$id    = (int) ($_POST['id'] ?? 0);
$aksi  = $_POST['aksi'] ?? '';
$catatan = trim($_POST['catatan'] ?? '');

$q = mysqli_query($koneksi, "SELECT * FROM kms_articles WHERE id_artikel=$id");
$artikel = $q ? mysqli_fetch_assoc($q) : null;
if (!$artikel) {
    header('Location: index.php?pesan=' . urlencode('Artikel tidak ditemukan.'));
    exit;
}

$aturan = [
    'ajukan'     => ['dari' => ['Draft'],              'ke' => 'Review',    'peran' => ['manager', 'admin'], 'penulis' => true],
    'tarik'      => ['dari' => ['Review'],             'ke' => 'Draft',     'peran' => ['manager', 'admin'], 'penulis' => true],
    'setujui'    => ['dari' => ['Review'],             'ke' => 'Published', 'peran' => ['admin']],
    'kembalikan' => ['dari' => ['Review'],             'ke' => 'Draft',     'peran' => ['admin']],
    'terbitkan'  => ['dari' => ['Draft', 'Archived'],  'ke' => 'Published', 'peran' => ['admin']],
    'arsip'      => ['dari' => ['Published'],          'ke' => 'Archived',  'peran' => ['admin']],
];

function kembali($id, $pesan) {
    header('Location: detail.php?id=' . $id . '&pesan=' . urlencode($pesan));
    exit;
}

if (!isset($aturan[$aksi])) kembali($id, 'Aksi tidak dikenal.');
$r = $aturan[$aksi];

if (!in_array($peran, $r['peran'], true)) kembali($id, 'Anda tidak memiliki hak untuk aksi ini.');
if (!empty($r['penulis']) && $peran === 'manager' && (int) $artikel['penulis_id'] !== $id_user_login) {
    kembali($id, 'Hanya penulis dokumen yang bisa melakukan aksi ini.');
}
if (!in_array($artikel['status'], $r['dari'], true)) {
    kembali($id, 'Status dokumen sudah berubah. Muat ulang halaman untuk melihat status terbaru.');
}
if ($aksi === 'kembalikan' && $catatan === '') {
    kembali($id, 'Catatan revisi wajib diisi agar Manager tahu apa yang perlu diperbaiki.');
}

$ke = esc($r['ke']);
$cat_sql = $catatan !== '' ? "'" . esc($catatan) . "'" : 'NULL';

switch ($aksi) {
    case 'ajukan':
        $set = "status='$ke', tgl_diajukan=NOW(), catatan_review=NULL";
        $pesan = 'Dokumen diajukan ke Admin KMS untuk direview.';
        break;
    case 'tarik':
        $set = "status='$ke'";
        $pesan = 'Pengajuan ditarik. Dokumen kembali menjadi Draft.';
        break;
    case 'setujui':
        $set = "status='$ke', direview_oleh=$id_user_login, tgl_review=NOW(), catatan_review=$cat_sql";
        $pesan = 'Dokumen disetujui dan kini terbit untuk seluruh staff.';
        break;
    case 'kembalikan':
        $set = "status='$ke', direview_oleh=$id_user_login, tgl_review=NOW(), catatan_review=$cat_sql";
        $pesan = 'Dokumen dikembalikan ke penulis beserta catatan revisi.';
        break;
    case 'terbitkan':
        $set = "status='$ke', direview_oleh=$id_user_login, tgl_review=NOW(), catatan_review=NULL";
        $pesan = 'Dokumen diterbitkan.';
        break;
    case 'arsip':
        $set = "status='$ke'";
        $pesan = 'Dokumen diarsipkan dan tidak lagi tampil untuk staff.';
        break;
}

mysqli_query($koneksi, "UPDATE kms_articles SET $set WHERE id_artikel=$id AND status='" . esc($artikel['status']) . "'");
kembali($id, $pesan);
