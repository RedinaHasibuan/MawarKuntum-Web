<?php
require 'config.php';

// RBAC Lock: Hanya Admin / Manager
$user_id_login = $_SESSION['user_id'] ?? 0;
$db_main = $db_main ?? 'mawarkuntum';
$q_role = mysqli_query($koneksi, "SELECT sr.nama_sub_role FROM $db_main.users u JOIN $db_main.sub_roles sr ON u.id_sub_role = sr.id_sub_role WHERE u.user_id = $user_id_login");
$role_name = ($q_role && mysqli_num_rows($q_role) > 0) ? strtolower(mysqli_fetch_assoc($q_role)['nama_sub_role']) : '';
$role_utama = strtolower($_SESSION['role'] ?? '');
if ($role_utama !== 'admin' && strpos($role_name, 'manager') === false && strpos($role_name, 'supervisor') === false) {
    echo "<script>alert('Akses Ditolak! Anda tidak memiliki izin menghapus artikel.'); window.location.href='index.php';</script>";
    exit();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id > 0) {
    mysqli_query($koneksi, "DELETE FROM kms_articles WHERE id_artikel=$id");
}
header('Location: index.php?pesan=' . urlencode('Artikel berhasil dihapus.'));
exit;