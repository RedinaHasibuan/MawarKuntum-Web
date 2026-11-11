<?php
/**
 * dashboard_mis.php
 * Dashboard MIS Mawarkuntum: membaca tabel ringkasan mis_* (hasil olahan TPS dan OAS).
 * Letakkan di folder yang sama dengan dashboard_manager.php.
 */
session_start();
include 'koneksi.php';
mysqli_set_charset($conn, 'utf8mb4');

// ---------- Keamanan RBAC: hanya Manager / Supervisor Operasional ----------
$role_boleh = ['Manager', 'Supervisor Operasional'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $role_boleh, true)) {
    header("Location: login.php");
    exit();
}

// ---------- Helper ----------
function e($teks) {
    return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
}
function rupiah($v) {
    return 'Rp ' . number_format((float) $v, 0, ',', '.');
}
function rupiah_singkat($v) {
    $v = (float) $v;
    if ($v >= 1000000) return 'Rp ' . rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',') . ' jt';
    if ($v >= 1000)    return 'Rp ' . number_format($v / 1000, 0, ',', '.') . ' rb';
    return 'Rp ' . number_format($v, 0, ',', '.');
}
function fmt_nilai($v, $satuan) {
    if ($v === null) return '-';
    if ($satuan === 'Rupiah') return rupiah($v);
    if ($satuan === 'Persen') return number_format((float) $v, 1, ',', '.') . '%';
    return number_format((float) $v, 0, ',', '.') . ' ' . $satuan;
}

// Query pengisian ulang tabel mis_* dari TPS, OAS dan KMS (sama dengan sp_refresh_mis,
// jadi halaman ini tetap jalan walau stored procedure belum dibuat).
function refresh_data_mis($conn) {
    $query = [];

    $query['Penjualan harian'] = <<<'SQL'
INSERT INTO mis_penjualan_harian
    (tanggal, jumlah_pesanan, total_pendapatan, rata_rata_nilai_pesanan, jumlah_item_terjual)
SELECT
    DATE(o.tanggal_transaksi),
    COUNT(DISTINCT o.order_id),
    COALESCE(SUM(o.total_harga), 0),
    COALESCE(AVG(o.total_harga), 0),
    COALESCE((SELECT SUM(od.jumlah)
              FROM order_details od
              JOIN orders o2 ON o2.order_id = od.order_id
              WHERE DATE(o2.tanggal_transaksi) = DATE(o.tanggal_transaksi)
                AND o2.status_pesanan <> 'Dibatalkan'), 0)
FROM orders o
WHERE o.status_pesanan <> 'Dibatalkan'
GROUP BY DATE(o.tanggal_transaksi)
ON DUPLICATE KEY UPDATE
    jumlah_pesanan          = VALUES(jumlah_pesanan),
    total_pendapatan        = VALUES(total_pendapatan),
    rata_rata_nilai_pesanan = VALUES(rata_rata_nilai_pesanan),
    jumlah_item_terjual     = VALUES(jumlah_item_terjual)
SQL;

    $query['Penjualan produk'] = <<<'SQL'
INSERT INTO mis_penjualan_produk (periode, product_id, kategori, qty_terjual, pendapatan)
SELECT
    DATE_FORMAT(o.tanggal_transaksi, '%Y-%m-01'),
    od.product_id,
    p.kategori,
    SUM(od.jumlah),
    SUM(od.subtotal)
FROM order_details od
JOIN orders   o ON o.order_id   = od.order_id
JOIN products p ON p.product_id = od.product_id
WHERE o.status_pesanan <> 'Dibatalkan'
GROUP BY DATE_FORMAT(o.tanggal_transaksi, '%Y-%m-01'), od.product_id, p.kategori
ON DUPLICATE KEY UPDATE
    kategori    = VALUES(kategori),
    qty_terjual = VALUES(qty_terjual),
    pendapatan  = VALUES(pendapatan)
SQL;

    $query['Peringatan stok'] = <<<'SQL'
INSERT INTO mis_stok_peringatan (product_id, stok_saat_ini, stok_minimum, status)
SELECT
    p.product_id,
    p.stok,
    25,
    CASE WHEN p.stok = 0 THEN 'Habis'
         WHEN p.stok < 25 THEN 'Menipis'
         ELSE 'Aman' END
FROM products p
ON DUPLICATE KEY UPDATE
    stok_saat_ini = VALUES(stok_saat_ini),
    status = CASE WHEN VALUES(stok_saat_ini) = 0 THEN 'Habis'
                  WHEN VALUES(stok_saat_ini) < stok_minimum THEN 'Menipis'
                  ELSE 'Aman' END
SQL;

    $query['Kinerja tugas (bersihkan)'] = <<<'SQL'
DELETE FROM mis_kinerja_tugas
SQL;

    $query['Kinerja tugas'] = <<<'SQL'
INSERT INTO mis_kinerja_tugas
    (periode, user_id, total_tugas, tugas_todo, tugas_progress, tugas_review,
     tugas_selesai, tugas_tertunda, persen_selesai)
SELECT
    DATE_FORMAT(t.tgl_dibuat, '%Y-%m-01'),
    t.dikerjakan_oleh,
    COUNT(*),
    SUM(t.status_tugas = 'To-Do'),
    SUM(t.status_tugas = 'In Progress'),
    SUM(t.status_tugas = 'Review'),
    SUM(t.status_tugas = 'Done'),
    SUM(t.status_tugas <> 'Done' AND t.tgl_dibuat < (NOW() - INTERVAL 7 DAY)),
    ROUND(SUM(t.status_tugas = 'Done') / COUNT(*) * 100, 2)
FROM tasks t
JOIN users u ON u.user_id = t.dikerjakan_oleh
WHERE t.tgl_dibuat > '2000-01-01'
GROUP BY DATE_FORMAT(t.tgl_dibuat, '%Y-%m-01'), t.dikerjakan_oleh
SQL;

    $query['Ringkasan dokumen (bersihkan)'] = <<<'SQL'
DELETE FROM mis_ringkasan_dokumen
SQL;

    $query['Ringkasan dokumen'] = <<<'SQL'
INSERT INTO mis_ringkasan_dokumen
    (periode, total_dokumen, jumlah_pending, jumlah_approved, jumlah_rejected)
SELECT
    DATE_FORMAT(d.tgl_unggah, '%Y-%m-01'),
    COUNT(*),
    SUM(d.status_persetujuan = 'Pending'),
    SUM(d.status_persetujuan = 'Approved'),
    SUM(d.status_persetujuan = 'Rejected')
FROM documents d
WHERE d.tgl_unggah > '2000-01-01'
GROUP BY DATE_FORMAT(d.tgl_unggah, '%Y-%m-01')
SQL;

    $query['Penggunaan KMS (bersihkan)'] = <<<'SQL'
DELETE FROM mis_penggunaan_kms
SQL;

    $query['Penggunaan KMS'] = <<<'SQL'
INSERT INTO mis_penggunaan_kms
    (periode, id_artikel, judul_artikel, jumlah_dibaca, feedback_helpful, feedback_tidak_helpful)
SELECT
    x.periode,
    x.id_artikel,
    a.judul,
    SUM(x.dibaca),
    SUM(x.helpful),
    SUM(x.tidak_helpful)
FROM (
    SELECT DATE_FORMAT(r.dibaca_at, '%Y-%m-01') AS periode, r.id_artikel,
           1 AS dibaca, 0 AS helpful, 0 AS tidak_helpful
    FROM mawarkuntum_kms.kms_article_reads r
    WHERE r.dibaca_at IS NOT NULL
    UNION ALL
    SELECT DATE_FORMAT(f.created_at, '%Y-%m-01'), f.id_artikel,
           0, IF(f.is_helpful = 1, 1, 0), IF(f.is_helpful = 1, 0, 1)
    FROM mawarkuntum_kms.kms_feedback f
    WHERE f.created_at IS NOT NULL
) x
JOIN mawarkuntum_kms.kms_articles a ON a.id_artikel = x.id_artikel
GROUP BY x.periode, x.id_artikel, a.judul
SQL;

    $query['KPI penjualan bulanan'] = <<<'SQL'
INSERT INTO mis_kpi_nilai (id_kpi, tipe_periode, periode, nilai_aktual, nilai_target, status)
SELECT k.id_kpi, 'Bulanan', DATE_FORMAT(h.tanggal, '%Y-%m-01'),
       CASE k.kode_kpi
            WHEN 'TOTAL_PENDAPATAN_BULANAN' THEN SUM(h.total_pendapatan)
            WHEN 'JUMLAH_PESANAN_BULANAN'   THEN SUM(h.jumlah_pesanan)
            WHEN 'ITEM_TERJUAL_BULANAN'     THEN SUM(h.jumlah_item_terjual)
       END,
       k.target_default,
       CASE WHEN (CASE k.kode_kpi
                       WHEN 'TOTAL_PENDAPATAN_BULANAN' THEN SUM(h.total_pendapatan)
                       WHEN 'JUMLAH_PESANAN_BULANAN'   THEN SUM(h.jumlah_pesanan)
                       WHEN 'ITEM_TERJUAL_BULANAN'     THEN SUM(h.jumlah_item_terjual)
                  END) >= k.target_default
            THEN 'Tercapai' ELSE 'Belum Tercapai' END
FROM mis_penjualan_harian h
JOIN mis_kpi k ON k.kode_kpi IN ('TOTAL_PENDAPATAN_BULANAN','JUMLAH_PESANAN_BULANAN','ITEM_TERJUAL_BULANAN')
GROUP BY k.id_kpi, k.kode_kpi, k.target_default, DATE_FORMAT(h.tanggal, '%Y-%m-01')
ON DUPLICATE KEY UPDATE
    nilai_aktual = VALUES(nilai_aktual),
    nilai_target = VALUES(nilai_target),
    status       = VALUES(status)
SQL;

    $query['KPI tugas selesai'] = <<<'SQL'
INSERT INTO mis_kpi_nilai (id_kpi, tipe_periode, periode, nilai_aktual, nilai_target, status)
SELECT k.id_kpi, 'Bulanan', m.periode, m.nilai, k.target_default,
       CASE WHEN m.nilai >= k.target_default THEN 'Tercapai' ELSE 'Belum Tercapai' END
FROM (SELECT periode,
             ROUND(SUM(tugas_selesai) / NULLIF(SUM(total_tugas), 0) * 100, 2) AS nilai
      FROM mis_kinerja_tugas
      GROUP BY periode) m
JOIN mis_kpi k ON k.kode_kpi = 'TUGAS_SELESAI_PERSEN'
WHERE m.nilai IS NOT NULL
ON DUPLICATE KEY UPDATE
    nilai_aktual = VALUES(nilai_aktual),
    nilai_target = VALUES(nilai_target),
    status       = VALUES(status)
SQL;

    $query['KPI artikel dibaca'] = <<<'SQL'
INSERT INTO mis_kpi_nilai (id_kpi, tipe_periode, periode, nilai_aktual, nilai_target, status)
SELECT k.id_kpi, 'Bulanan', m.periode, m.nilai, k.target_default,
       CASE WHEN m.nilai >= k.target_default THEN 'Tercapai' ELSE 'Belum Tercapai' END
FROM (SELECT periode, SUM(jumlah_dibaca) AS nilai
      FROM mis_penggunaan_kms
      GROUP BY periode) m
JOIN mis_kpi k ON k.kode_kpi = 'ARTIKEL_DIBACA_BULANAN'
ON DUPLICATE KEY UPDATE
    nilai_aktual = VALUES(nilai_aktual),
    nilai_target = VALUES(nilai_target),
    status       = VALUES(status)
SQL;

    $query['KPI artikel membantu'] = <<<'SQL'
INSERT INTO mis_kpi_nilai (id_kpi, tipe_periode, periode, nilai_aktual, nilai_target, status)
SELECT k.id_kpi, 'Bulanan', m.periode, m.nilai, k.target_default,
       CASE WHEN m.nilai >= k.target_default THEN 'Tercapai' ELSE 'Belum Tercapai' END
FROM (SELECT periode,
             ROUND(SUM(feedback_helpful)
                   / NULLIF(SUM(feedback_helpful + feedback_tidak_helpful), 0) * 100, 2) AS nilai
      FROM mis_penggunaan_kms
      GROUP BY periode) m
JOIN mis_kpi k ON k.kode_kpi = 'ARTIKEL_HELPFUL_PERSEN'
WHERE m.nilai IS NOT NULL
ON DUPLICATE KEY UPDATE
    nilai_aktual = VALUES(nilai_aktual),
    nilai_target = VALUES(nilai_target),
    status       = VALUES(status)
SQL;

    $query['KPI stok harian'] = <<<'SQL'
INSERT INTO mis_kpi_nilai (id_kpi, tipe_periode, periode, nilai_aktual, nilai_target, status)
SELECT k.id_kpi, 'Harian', CURDATE(),
       (SELECT COUNT(*) FROM mis_stok_peringatan WHERE status <> 'Aman'),
       k.target_default,
       CASE WHEN (SELECT COUNT(*) FROM mis_stok_peringatan WHERE status <> 'Aman') <= k.target_default
            THEN 'Tercapai' ELSE 'Belum Tercapai' END
FROM mis_kpi k
WHERE k.kode_kpi = 'PRODUK_STOK_BERMASALAH'
ON DUPLICATE KEY UPDATE
    nilai_aktual = VALUES(nilai_aktual),
    nilai_target = VALUES(nilai_target),
    status       = VALUES(status)
SQL;

    $query['KPI dokumen pending harian'] = <<<'SQL'
INSERT INTO mis_kpi_nilai (id_kpi, tipe_periode, periode, nilai_aktual, nilai_target, status)
SELECT k.id_kpi, 'Harian', CURDATE(),
       (SELECT COUNT(*) FROM documents WHERE status_persetujuan = 'Pending'),
       k.target_default,
       CASE WHEN (SELECT COUNT(*) FROM documents WHERE status_persetujuan = 'Pending') <= k.target_default
            THEN 'Tercapai' ELSE 'Belum Tercapai' END
FROM mis_kpi k
WHERE k.kode_kpi = 'DOKUMEN_PENDING'
ON DUPLICATE KEY UPDATE
    nilai_aktual = VALUES(nilai_aktual),
    nilai_target = VALUES(nilai_target),
    status       = VALUES(status)
SQL;

    $gagal = [];
    foreach ($query as $nama => $sql) {
        if (!mysqli_query($conn, $sql)) {
            $gagal[] = $nama . ': ' . mysqli_error($conn);
        }
    }
    return $gagal;
}

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// ---------- Pastikan tabel MIS sudah ada ----------
$tabel_wajib = ['mis_kpi', 'mis_kpi_nilai', 'mis_penjualan_harian', 'mis_penjualan_produk',
                'mis_stok_peringatan', 'mis_kinerja_tugas', 'mis_ringkasan_dokumen', 'mis_penggunaan_kms'];
foreach ($tabel_wajib as $tb) {
    $cek = mysqli_query($conn, "SHOW TABLES LIKE '$tb'");
    if (!$cek || mysqli_num_rows($cek) === 0) {
        die("Tabel <b>$tb</b> belum ada. Jalankan <b>mis_schema.sql</b> lalu <b>mis_update.sql</b> di database mawarkuntum.");
    }
}

// ---------- Refresh data (manual lewat tombol, otomatis maksimal tiap 10 menit) ----------
$pesan = null; // [tipe, teks]
$manual = isset($_POST['refresh_mis']);
$auto   = empty($_SESSION['mis_last_refresh']) || (time() - $_SESSION['mis_last_refresh']) > 600;

if ($manual || $auto) {
    $gagal = refresh_data_mis($conn);
    if (empty($gagal)) {
        $_SESSION['mis_last_refresh'] = time();
        if ($manual) {
            $pesan = ['success', 'Data MIS berhasil diperbarui dari TPS, OAS dan KMS.'];
        }
    } else {
        $pesan = ['error', 'Pembaruan data gagal. ' . implode(' | ', $gagal)];
    }
}
$terakhir_refresh = !empty($_SESSION['mis_last_refresh']) ? date('d M Y, H:i', $_SESSION['mis_last_refresh']) : '-';

// ---------- Periode yang dipilih (default: bulan penjualan terbaru) ----------
$r = mysqli_query($conn, "SELECT MAX(tanggal) AS p FROM mis_penjualan_harian");
$row_latest = $r ? mysqli_fetch_assoc($r) : null;
if (empty($row_latest['p'])) {
    $r = mysqli_query($conn, "SELECT MAX(periode) AS p FROM mis_kpi_nilai WHERE tipe_periode = 'Bulanan'");
    $row_latest = $r ? mysqli_fetch_assoc($r) : null;
}
$ts_default = !empty($row_latest['p']) ? strtotime($row_latest['p']) : time();

$bulan = isset($_GET['bulan']) ? max(1, min(12, intval($_GET['bulan']))) : (int) date('n', $ts_default);
$tahun = isset($_GET['tahun']) ? intval($_GET['tahun']) : (int) date('Y', $ts_default);
$periode       = sprintf('%04d-%02d-01', $tahun, $bulan);
$periode_akhir = date('Y-m-t', strtotime($periode));

// Daftar tahun untuk filter (dari data + tahun berjalan)
$daftar_tahun = [(int) date('Y'), $tahun];
$r = mysqli_query($conn, "SELECT MIN(YEAR(tanggal)) AS a, MAX(YEAR(tanggal)) AS b FROM mis_penjualan_harian");
if ($r && ($t = mysqli_fetch_assoc($r)) && $t['a']) {
    for ($y = (int) $t['a']; $y <= (int) $t['b']; $y++) $daftar_tahun[] = $y;
}
$daftar_tahun = array_unique($daftar_tahun);
sort($daftar_tahun);

// ---------- 1. KPI ----------
$kpi_list = [];
$r = mysqli_query($conn, "SELECT * FROM mis_kpi ORDER BY id_kpi");
while ($r && $k = mysqli_fetch_assoc($r)) {
    $id = (int) $k['id_kpi'];
    // Ambil nilai bulanan untuk periode terpilih; kalau tidak ada, pakai nilai harian terbaru
    $rn = mysqli_query($conn, "SELECT * FROM mis_kpi_nilai
                               WHERE id_kpi = $id
                                 AND ((tipe_periode = 'Bulanan' AND periode = '$periode') OR tipe_periode = 'Harian')
                               ORDER BY (tipe_periode = 'Bulanan') DESC, periode DESC LIMIT 1");
    $k['nilai'] = $rn ? mysqli_fetch_assoc($rn) : null;
    $kpi_list[] = $k;
}

// ---------- 2. Penjualan harian pada bulan terpilih ----------
$harian = [];
$total_pendapatan = 0; $total_pesanan = 0; $total_item = 0;
$r = mysqli_query($conn, "SELECT * FROM mis_penjualan_harian
                          WHERE tanggal BETWEEN '$periode' AND '$periode_akhir' ORDER BY tanggal");
while ($r && $h = mysqli_fetch_assoc($r)) {
    $harian[] = $h;
    $total_pendapatan += $h['total_pendapatan'];
    $total_pesanan    += $h['jumlah_pesanan'];
    $total_item       += $h['jumlah_item_terjual'];
}
$rata_pesanan = $total_pesanan > 0 ? $total_pendapatan / $total_pesanan : 0;
$maks_harian = 0;
foreach ($harian as $h) $maks_harian = max($maks_harian, (float) $h['total_pendapatan']);

// ---------- 3. Produk terlaris & kategori ----------
$top_produk = [];
$r = mysqli_query($conn, "SELECT p.nama_produk, mp.kategori, mp.qty_terjual, mp.pendapatan
                          FROM mis_penjualan_produk mp
                          JOIN products p ON p.product_id = mp.product_id
                          WHERE mp.periode = '$periode'
                          ORDER BY mp.qty_terjual DESC, mp.pendapatan DESC LIMIT 5");
while ($r && $p = mysqli_fetch_assoc($r)) $top_produk[] = $p;
$maks_qty = 0;
foreach ($top_produk as $p) $maks_qty = max($maks_qty, (int) $p['qty_terjual']);

$per_kategori = [];
$r = mysqli_query($conn, "SELECT COALESCE(NULLIF(kategori, ''), 'Tanpa Kategori') AS kategori,
                                 SUM(qty_terjual) AS qty, SUM(pendapatan) AS pendapatan
                          FROM mis_penjualan_produk WHERE periode = '$periode'
                          GROUP BY 1 ORDER BY pendapatan DESC");
while ($r && $c = mysqli_fetch_assoc($r)) $per_kategori[] = $c;
$total_pend_kat = 0;
foreach ($per_kategori as $c) $total_pend_kat += $c['pendapatan'];

// ---------- 4. Peringatan stok ----------
$stok_ringkas = ['Aman' => 0, 'Menipis' => 0, 'Habis' => 0];
$r = mysqli_query($conn, "SELECT status, COUNT(*) AS n FROM mis_stok_peringatan GROUP BY status");
while ($r && $s = mysqli_fetch_assoc($r)) $stok_ringkas[$s['status']] = (int) $s['n'];

$stok_masalah = [];
$r = mysqli_query($conn, "SELECT p.nama_produk, p.kategori, s.stok_saat_ini, s.stok_minimum, s.status
                          FROM mis_stok_peringatan s
                          JOIN products p ON p.product_id = s.product_id
                          WHERE s.status <> 'Aman' ORDER BY s.stok_saat_ini ASC, p.nama_produk ASC");
while ($r && $s = mysqli_fetch_assoc($r)) $stok_masalah[] = $s;

// ---------- 5. OAS: kinerja tugas per pegawai ----------
$kinerja = [];
$r = mysqli_query($conn, "SELECT COALESCE(NULLIF(u.nama, ''), u.email) AS pegawai, kt.*
                          FROM mis_kinerja_tugas kt
                          JOIN users u ON u.user_id = kt.user_id
                          WHERE kt.periode = '$periode'
                          ORDER BY kt.persen_selesai DESC, kt.total_tugas DESC");
while ($r && $k = mysqli_fetch_assoc($r)) $kinerja[] = $k;

// ---------- 6. OAS: status persetujuan dokumen (6 bulan terakhir) ----------
$dokumen = [];
$r = mysqli_query($conn, "SELECT * FROM mis_ringkasan_dokumen ORDER BY periode DESC LIMIT 6");
while ($r && $d = mysqli_fetch_assoc($r)) $dokumen[] = $d;

// ---------- 7. KMS: artikel paling banyak dibaca pada periode terpilih ----------
$artikel = [];
$r = mysqli_query($conn, "SELECT judul_artikel, jumlah_dibaca, feedback_helpful, feedback_tidak_helpful
                          FROM mis_penggunaan_kms WHERE periode = '$periode'
                          ORDER BY jumlah_dibaca DESC, feedback_helpful DESC LIMIT 5");
while ($r && $a = mysqli_fetch_assoc($r)) $artikel[] = $a;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard MIS - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .header small { color: #777; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 5px solid #2e7d32; }
        .stat-card h4 { margin: 0; font-size: 12px; color: #666; text-transform: uppercase; }
        .stat-card .value { font-size: 22px; font-weight: bold; color: #1b5e20; margin-top: 5px; }
        .stat-card .target { font-size: 12px; color: #666; margin-top: 2px; }
        .progress { height: 6px; background: #e0e0e0; border-radius: 3px; margin: 10px 0 8px; overflow: hidden; }
        .progress > span { display: block; height: 100%; background: #43a047; }
        .progress.warn > span { background: #fb8c00; }

        .grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #2e7d32; font-size: 17px; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; margin-bottom: 15px; }
        .card-header-flex h3 { border-bottom: none; padding-bottom: 0; margin: 0; }

        .summary-strip { background: #e8f5e9; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; display: flex; gap: 25px; flex-wrap: wrap; font-size: 13px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        th { background: #e8f5e9; color: #1b5e20; font-weight: bold; }
        td.num, th.num { text-align: right; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .badge-ok { background: #c8e6c9; color: #1b5e20; }
        .badge-warn { background: #ffe0b2; color: #e65100; }
        .badge-bad { background: #ffcdd2; color: #b71c1c; }
        .badge-none { background: #eeeeee; color: #666; }

        .form-control { padding: 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 13px; }
        .btn { padding: 8px 14px; border-radius: 5px; border: none; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-primary { background: #0288d1; color: white; }
        .btn-green { background: #2e7d32; color: white; }
        .btn-green:hover { background: #1b5e20; }
        .btn-print { background: #388e3c; color: white; }
        .btn-back { background: #eceff1; color: #333; }
        .btn-logout { background: #e57373; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; font-size: 13px; }

        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .alert-error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .empty { text-align: center; color: #888; padding: 18px; font-size: 13px; }

        /* Grafik batang penjualan harian (tanpa library, jalan offline) */
        .chart { display: flex; align-items: flex-end; gap: 8px; height: 220px; padding: 10px 4px 0; border-bottom: 2px solid #a5d6a7; overflow-x: auto; margin-bottom: 14px; }
        .bar-col { flex: 1 0 44px; max-width: 90px; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; }
        .bar-val { font-size: 11px; color: #1b5e20; font-weight: bold; margin-bottom: 4px; white-space: nowrap; }
        .bar { width: 70%; background: #66bb6a; border-radius: 4px 4px 0 0; min-height: 3px; }
        .bar-lbl { font-size: 11px; color: #666; margin-top: 6px; height: 16px; }

        .tag { display: inline-block; font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 3px; background: #eceff1; color: #546e7a; margin-left: 6px; vertical-align: middle; }
        .hbar { background: #e0e0e0; border-radius: 3px; height: 8px; min-width: 90px; }
        .hbar > span { display: block; height: 100%; background: #66bb6a; border-radius: 3px; }

        .print-only-header { display: none; }
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .grid { grid-template-columns: 1fr; }
        }
        @media print {
            body { background: white; padding: 0; color: black; }
            .no-print { display: none !important; }
            .card, .stat-card { box-shadow: none; border: 1px solid #ccc; break-inside: avoid; }
            .print-only-header { display: block; text-align: center; margin-bottom: 15px; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css">
</head>
<body>

<div class="print-only-header">
    <h2>LAPORAN MIS MAWARKUNTUM</h2>
    <p>Periode: <b><?= e($nama_bulan[$bulan] . ' ' . $tahun) ?></b></p>
    <hr>
</div>

<div class="header no-print">
    <div>
        <h2>📈 Dashboard MIS (Management Information System)</h2>
        <small>Ringkasan data TPS, OAS dan KMS untuk pengambilan keputusan &middot; terakhir diperbarui: <?= e($terakhir_refresh) ?></small>
    </div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <form method="POST" style="margin:0;">
            <button type="submit" name="refresh_mis" class="btn btn-green">🔄 Perbarui Data</button>
        </form>
        <button onclick="window.print()" class="btn btn-print">🖨️ Cetak</button>
        <a href="dashboard_manager.php" class="btn btn-back">⬅ Dashboard Manager</a>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<?php if ($pesan): ?>
    <div class="alert-<?= $pesan[0] === 'success' ? 'success' : 'error' ?> no-print"><?= e($pesan[1]) ?></div>
<?php endif; ?>

<!-- Filter periode -->
<div class="card no-print" style="padding:14px 20px;">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
        <b style="font-size:13px;">Periode laporan:</b>
        <select name="bulan" class="form-control">
            <?php for ($i = 1; $i <= 12; $i++): ?>
                <option value="<?= $i ?>" <?= $i === $bulan ? 'selected' : '' ?>><?= e($nama_bulan[$i]) ?></option>
            <?php endfor; ?>
        </select>
        <select name="tahun" class="form-control">
            <?php foreach ($daftar_tahun as $y): ?>
                <option value="<?= $y ?>" <?= $y === $tahun ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Tampilkan</button>
    </form>
</div>

<!-- KPI -->
<div class="stats-grid">
    <?php foreach ($kpi_list as $k):
        $n = $k['nilai'];
        $ada = $n !== null;
        $maks_type = $k['jenis_target'] === 'Maksimal';
        $persen = 0;
        if ($ada && !$maks_type && (float) $n['nilai_target'] > 0) {
            $persen = min(100, round($n['nilai_aktual'] / $n['nilai_target'] * 100));
        }
        $tercapai = $ada && $n['status'] === 'Tercapai';
    ?>
    <div class="stat-card" style="border-color: <?= !$ada ? '#bdbdbd' : ($tercapai ? '#4caf50' : '#ff9800') ?>;">
        <h4><?= e($k['nama_kpi']) ?><span class="tag"><?= e($k['sumber_sistem']) ?></span></h4>
        <div class="value"><?= $ada ? e(fmt_nilai($n['nilai_aktual'], $k['satuan'])) : '-' ?></div>
        <?php if ($ada && !$maks_type): ?>
            <div class="progress <?= $tercapai ? '' : 'warn' ?>"><span style="width: <?= $persen ?>%"></span></div>
        <?php else: ?>
            <div style="height:14px;"></div>
        <?php endif; ?>
        <div class="target">
            <?php if ($ada): ?>
                Target <?= $maks_type ? 'maks.' : 'min.' ?> <?= e(fmt_nilai($n['nilai_target'], $k['satuan'])) ?>
                <?= !$maks_type ? '(' . $persen . '%)' : '' ?>
                &nbsp;<span class="badge <?= $tercapai ? 'badge-ok' : 'badge-warn' ?>"><?= e($n['status']) ?></span>
            <?php else: ?>
                Belum ada data periode ini <span class="badge badge-none">-</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid">
    <div>
        <!-- Penjualan harian -->
        <div class="card">
            <div class="card-header-flex">
                <h3>📊 Penjualan Harian — <?= e($nama_bulan[$bulan] . ' ' . $tahun) ?></h3>
            </div>
            <div class="summary-strip">
                <span>Total pendapatan: <b style="color:#1b5e20;"><?= e(rupiah($total_pendapatan)) ?></b></span>
                <span>Pesanan: <b><?= (int) $total_pesanan ?></b></span>
                <span>Item terjual: <b><?= (int) $total_item ?></b></span>
                <span>Rata-rata/pesanan: <b><?= e(rupiah($rata_pesanan)) ?></b></span>
            </div>

            <?php if (empty($harian)): ?>
                <div class="empty">Belum ada transaksi pada periode ini. Coba pilih bulan lain atau klik "Perbarui Data".</div>
            <?php else: ?>
                <div>
                    <div class="chart">
                        <?php foreach ($harian as $h):
                            $tinggi = $maks_harian > 0 ? max(2, round($h['total_pendapatan'] / $maks_harian * 78)) : 2; ?>
                            <div class="bar-col" title="<?= e(date('d M Y', strtotime($h['tanggal'])) . ': ' . rupiah($h['total_pendapatan']) . ', ' . $h['jumlah_pesanan'] . ' pesanan') ?>">
                                <div class="bar-val"><?= e(rupiah_singkat($h['total_pendapatan'])) ?></div>
                                <div class="bar" style="height: <?= $tinggi ?>%"></div>
                                <div class="bar-lbl"><?= e(date('d/m', strtotime($h['tanggal']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr><th>Tanggal</th><th class="num">Pesanan</th><th class="num">Item</th><th class="num">Rata-rata/Pesanan</th><th class="num">Pendapatan</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($harian) as $h): ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime($h['tanggal']))) ?></td>
                            <td class="num"><?= (int) $h['jumlah_pesanan'] ?></td>
                            <td class="num"><?= (int) $h['jumlah_item_terjual'] ?></td>
                            <td class="num"><?= e(rupiah($h['rata_rata_nilai_pesanan'])) ?></td>
                            <td class="num"><b><?= e(rupiah($h['total_pendapatan'])) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <!-- Produk terlaris -->
        <div class="card">
            <h3>🏆 5 Produk Terlaris</h3>
            <?php if (empty($top_produk)): ?>
                <div class="empty">Belum ada penjualan produk pada periode ini.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>#</th><th>Produk</th><th>Terjual</th><th class="num">Pendapatan</th></tr></thead>
                    <tbody>
                    <?php foreach ($top_produk as $i => $p): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><b><?= e($p['nama_produk']) ?></b><br><small style="color:#777;"><?= e($p['kategori'] ?: '-') ?></small></td>
                            <td>
                                <b><?= (int) $p['qty_terjual'] ?></b> pcs
                                <div class="hbar"><span style="width: <?= $maks_qty > 0 ? round($p['qty_terjual'] / $maks_qty * 100) : 0 ?>%"></span></div>
                            </td>
                            <td class="num"><?= e(rupiah($p['pendapatan'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Per kategori -->
        <div class="card">
            <h3>🗂️ Penjualan per Kategori</h3>
            <?php if (empty($per_kategori)): ?>
                <div class="empty">Belum ada data kategori pada periode ini.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Kategori</th><th class="num">Qty</th><th>Kontribusi</th><th class="num">Pendapatan</th></tr></thead>
                    <tbody>
                    <?php foreach ($per_kategori as $c):
                        $porsi = $total_pend_kat > 0 ? round($c['pendapatan'] / $total_pend_kat * 100) : 0; ?>
                        <tr>
                            <td><b><?= e($c['kategori']) ?></b></td>
                            <td class="num"><?= (int) $c['qty'] ?></td>
                            <td><?= $porsi ?>%<div class="hbar"><span style="width: <?= $porsi ?>%"></span></div></td>
                            <td class="num"><?= e(rupiah($c['pendapatan'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Exception report stok -->
<div class="card">
    <div class="card-header-flex">
        <h3>⚠️ Laporan Pengecualian: Stok Perlu Perhatian</h3>
        <span style="font-size:13px;">
            Aman: <b><?= $stok_ringkas['Aman'] ?></b> &middot;
            Menipis: <b style="color:#e65100;"><?= $stok_ringkas['Menipis'] ?></b> &middot;
            Habis: <b style="color:#b71c1c;"><?= $stok_ringkas['Habis'] ?></b>
        </span>
    </div>
    <?php if (empty($stok_masalah)): ?>
        <div class="empty">✅ Semua produk memiliki stok di atas batas minimum.</div>
    <?php else: ?>
        <table>
            <thead><tr><th>Produk</th><th>Kategori</th><th class="num">Stok Saat Ini</th><th class="num">Batas Minimum</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($stok_masalah as $s): ?>
                <tr>
                    <td><b><?= e($s['nama_produk']) ?></b></td>
                    <td><?= e($s['kategori'] ?: '-') ?></td>
                    <td class="num"><b><?= (int) $s['stok_saat_ini'] ?></b> pcs</td>
                    <td class="num"><?= (int) $s['stok_minimum'] ?> pcs</td>
                    <td><span class="badge <?= $s['status'] === 'Habis' ? 'badge-bad' : 'badge-warn' ?>"><?= e($s['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- OAS: kinerja tugas -->
<div class="card">
    <div class="card-header-flex">
        <h3>👥 Kinerja Tugas Tim (OAS) — <?= e($nama_bulan[$bulan] . ' ' . $tahun) ?></h3>
        <span style="font-size:12px; color:#666;">Tertunda = belum Done dan sudah lebih dari 7 hari sejak dibuat</span>
    </div>
    <?php if (empty($kinerja)): ?>
        <div class="empty">Belum ada tugas yang dibuat pada periode ini.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Pegawai</th><th class="num">Total</th><th class="num">To-Do</th><th class="num">In Progress</th><th class="num">Review</th><th class="num">Done</th><th class="num">Tertunda</th><th>% Selesai</th></tr>
            </thead>
            <tbody>
            <?php foreach ($kinerja as $k): ?>
                <tr>
                    <td><b><?= e($k['pegawai']) ?></b></td>
                    <td class="num"><?= (int) $k['total_tugas'] ?></td>
                    <td class="num"><?= (int) $k['tugas_todo'] ?></td>
                    <td class="num"><?= (int) $k['tugas_progress'] ?></td>
                    <td class="num"><?= (int) $k['tugas_review'] ?></td>
                    <td class="num"><b><?= (int) $k['tugas_selesai'] ?></b></td>
                    <td class="num"><?= (int) $k['tugas_tertunda'] > 0 ? '<span class="badge badge-warn">' . (int) $k['tugas_tertunda'] . '</span>' : '0' ?></td>
                    <td><?= e(number_format((float) $k['persen_selesai'], 0)) ?>%<div class="hbar"><span style="width: <?= (int) round($k['persen_selesai']) ?>%"></span></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="grid" style="grid-template-columns: 1fr 1fr;">
    <!-- OAS: dokumen -->
    <div class="card">
        <h3>📄 Persetujuan Dokumen (OAS)</h3>
        <?php if (empty($dokumen)): ?>
            <div class="empty">Belum ada dokumen yang diunggah.</div>
        <?php else: ?>
            <table>
                <thead><tr><th>Bulan unggah</th><th class="num">Total</th><th class="num">Pending</th><th class="num">Approved</th><th class="num">Rejected</th></tr></thead>
                <tbody>
                <?php foreach ($dokumen as $d): ?>
                    <tr>
                        <td><?= e($nama_bulan[(int) date('n', strtotime($d['periode']))] . ' ' . date('Y', strtotime($d['periode']))) ?></td>
                        <td class="num"><?= (int) $d['total_dokumen'] ?></td>
                        <td class="num"><?= (int) $d['jumlah_pending'] > 0 ? '<span class="badge badge-warn">' . (int) $d['jumlah_pending'] . '</span>' : '0' ?></td>
                        <td class="num"><?= (int) $d['jumlah_approved'] ?></td>
                        <td class="num"><?= (int) $d['jumlah_rejected'] > 0 ? '<span class="badge badge-bad">' . (int) $d['jumlah_rejected'] . '</span>' : '0' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- KMS: artikel -->
    <div class="card">
        <h3>📚 Artikel KMS Paling Banyak Dibaca — <?= e($nama_bulan[$bulan] . ' ' . $tahun) ?></h3>
        <?php if (empty($artikel)): ?>
            <div class="empty">Belum ada artikel yang dibaca pada periode ini.</div>
        <?php else: ?>
            <table>
                <thead><tr><th>Artikel</th><th class="num">Dibaca</th><th class="num">Membantu</th><th class="num">Tidak</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php foreach ($artikel as $a): ?>
                    <tr>
                        <td><b><?= e($a['judul_artikel']) ?></b></td>
                        <td class="num"><?= (int) $a['jumlah_dibaca'] ?></td>
                        <td class="num"><?= (int) $a['feedback_helpful'] ?></td>
                        <td class="num"><?= (int) $a['feedback_tidak_helpful'] ?></td>
                        <td><?= (int) $a['feedback_tidak_helpful'] > (int) $a['feedback_helpful'] ? '<span class="badge badge-warn">Perlu ditinjau</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<p class="no-print" style="font-size:12px; color:#888; text-align:center;">
    Sumber data: tabel ringkasan <code>mis_*</code> yang dihitung dari TPS (orders, order_details, products),
    OAS (tasks, documents) dan KMS (kms_article_reads, kms_feedback).
</p>

</body>
</html>