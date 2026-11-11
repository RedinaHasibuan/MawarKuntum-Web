<?php
/**
 * Koneksi database Expert System Mawarkuntum.
 * Database yang digunakan: mawarkuntum_es
 * File ini menyediakan variabel $es serta fungsi es_rows() dan es_exec().
 */

mysqli_report(MYSQLI_REPORT_OFF);

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'mawarkuntum_es';

$es = mysqli_connect($host, $user, $pass, $db);

if (!$es) {
    die('Koneksi ke database mawarkuntum_es gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

mysqli_set_charset($es, 'utf8mb4');

/** Melindungi teks sebelum ditampilkan ke HTML. */
function es_h($teks) {
    return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
}

/** Menyiapkan dan menjalankan prepared statement. */
function es_siapkan($link, $sql, $tipe = '', $param = []) {
    $stmt = mysqli_prepare($link, $sql);
    if (!$stmt) {
        return false;
    }

    if ($tipe !== '') {
        // mysqli_stmt_bind_param membutuhkan parameter yang dikirim sebagai referensi.
        $referensi = [];
        foreach ($param as $i => &$nilai) {
            $referensi[$i] = &$nilai;
        }
        array_unshift($referensi, $tipe);
        array_unshift($referensi, $stmt);

        if (!call_user_func_array('mysqli_stmt_bind_param', $referensi)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    return $stmt;
}

/** Mengambil hasil SELECT dalam bentuk array asosiatif. */
function es_rows($link, $sql, $tipe = '', $param = []) {
    $stmt = es_siapkan($link, $sql, $tipe, $param);
    if (!$stmt) {
        return [];
    }

    $hasil = mysqli_stmt_get_result($stmt);
    $baris = $hasil ? mysqli_fetch_all($hasil, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);

    return $baris;
}

/** Menjalankan INSERT, UPDATE, atau DELETE. Mengembalikan ID insert atau false jika gagal. */
function es_exec($link, $sql, $tipe = '', $param = []) {
    $stmt = es_siapkan($link, $sql, $tipe, $param);
    if (!$stmt) {
        return false;
    }

    $id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return $id;
}
?>
