<?php
/**
 * config_dss.php
 * Koneksi ke database DSS (mawarkuntum_dss) beserta fungsi bantu kecil.
 *
 * - Database utama (mawarkuntum) tetap lewat koneksi.php  -> variabel $conn
 * - Database DSS lewat file ini                           -> variabel $dss
 * - Tidak memanggil session_start(); sesi dimulai oleh halaman yang meng-include file ini.
 * - Kedua database ada di server yang sama, jadi query boleh memakai awalan
 *   mawarkuntum.products atau mawarkuntum_dss.dss_rekomendasi.
 */
 
// Jangan lempar exception saat query gagal; semua hasil dicek manual di halaman.
mysqli_report(MYSQLI_REPORT_OFF);
 
$dss = mysqli_connect('localhost', 'root', '', 'mawarkuntum_dss');
if (!$dss) {
    die('Koneksi ke database mawarkuntum_dss gagal: ' . mysqli_connect_error()
        . '<br>Pastikan dss_schema.sql sudah dijalankan.');
}
mysqli_set_charset($dss, 'utf8mb4');
 
/** Escape teks untuk HTML. */
function dss_h($teks) {
    return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
}
 
/** Format rupiah, contoh 1500000 -> Rp 1.500.000 */
function dss_rp($angka) {
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}
 
/**
 * Jalankan query yang memakai tanda tanya (?) lalu kembalikan statement-nya.
 * Mengembalikan false bila query gagal.
 */
function dss_siapkan($link, $sql, $tipe = '', $param = []) {
    $st = mysqli_prepare($link, $sql);
    if (!$st) {
        return false;
    }
    if ($tipe !== '' && !mysqli_stmt_bind_param($st, $tipe, ...$param)) {
        mysqli_stmt_close($st);
        return false;
    }
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);
        return false;
    }
    return $st;
}
 
/** SELECT: kembalikan array baris (array kosong bila tidak ada atau gagal). */
function dss_rows($link, $sql, $tipe = '', $param = []) {
    $st = dss_siapkan($link, $sql, $tipe, $param);
    if (!$st) {
        return [];
    }
    $hasil = mysqli_stmt_get_result($st);
    $baris = $hasil ? mysqli_fetch_all($hasil, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $baris;
}
 
/** INSERT/UPDATE/DELETE: kembalikan insert id (bisa 0 untuk UPDATE) atau false bila gagal. */
function dss_exec($link, $sql, $tipe = '', $param = []) {
    $st = dss_siapkan($link, $sql, $tipe, $param);
    if (!$st) {
        return false;
    }
    $id = mysqli_stmt_insert_id($st);
    mysqli_stmt_close($st);
    return $id;
}
 
/** Query biasa tanpa parameter. Mengembalikan true bila berhasil, atau teks error. */
function dss_jalankan($link, $sql) {
    return mysqli_query($link, $sql) ? true : mysqli_error($link);
}
 