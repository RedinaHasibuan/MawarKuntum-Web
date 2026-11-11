<?php
/**
 * config.php
 * Koneksi MySQL lintas database (mawarkuntum_kms dan mawarkuntum utama).
 */

session_start();

$host    = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_kms  = 'mawarkuntum_kms'; // Disesuaikan dengan nama database baru kamu
$db_main = 'mawarkuntum';     // Nama database utama

$koneksi = mysqli_connect($host, $db_user, $db_pass, $db_kms);
if (!$koneksi) {
    die('Koneksi database gagal: ' . mysqli_connect_error());
}
mysqli_set_charset($koneksi, 'utf8mb4');

// ===== SIMULASI LOGIN =====
// Ganti dengan session dari sistem utama jika sudah terintegrasi
if (!isset($_SESSION['id_user'])) {
    $_SESSION['id_user'] = 1; // Asumsi user_id = 1 ada di mawarkuntum.users
}
$id_user_login = (int) $_SESSION['id_user'];

function buat_slug($teks, $koneksi) {
    $slug = strtolower(trim($teks));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug_asli = $slug;
    $i = 1;
    while (true) {
        $cek = mysqli_query($koneksi, "SELECT id_artikel FROM kms_articles WHERE slug = '" . mysqli_real_escape_string($koneksi, $slug) . "'");
        if (mysqli_num_rows($cek) === 0) {
            break;
        }
        $slug = $slug_asli . '-' . $i;
        $i++;
    }
    return $slug;
}

function badge_status($status) {
    $peta = [
        'Draft'     => 'badge-draft',
        'Review'    => 'badge-review',
        'Published' => 'badge-published',
        'Archived'  => 'badge-archived',
    ];
    $kelas = $peta[$status] ?? 'badge-draft';
    return "<span class=\"badge $kelas\">$status</span>";
}
?>