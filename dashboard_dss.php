<?php
session_start();
include 'koneksi.php';      // $conn -> database mawarkuntum
include 'config_dss.php';   // $dss  -> database mawarkuntum_dss
 
// ===== Keamanan RBAC: hanya Manager dan Supervisor Operasional =====
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Manager', 'Supervisor Operasional'], true)) {
    header("Location: login.php");
    exit();
}
$user_id = (int) $_SESSION['user_id'];
 
$nama_bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
 
function dss_label_periode($tanggal) {
    global $nama_bulan;
    $t = strtotime($tanggal);
    return $nama_bulan[(int) date('n', $t)] . ' ' . date('Y', $t);
}
 
function dss_flash($tipe, $pesan) {
    $_SESSION['dss_flash'] = [$tipe, $pesan];
}
 
function dss_kembali($periode = '') {
    $url = 'dashboard_dss.php';
    if (preg_match('/^\d{4}-\d{2}-01$/', (string) $periode)) {
        $url .= '?periode=' . $periode;
    }
    header("Location: $url");
    exit();
}
 
// ===== Cek tabel DSS sudah dibuat =====
$tabel_dss = ['dss_parameter', 'dss_kriteria', 'dss_supplier', 'dss_produk_supplier',
              'dss_penilaian_produk', 'dss_rekomendasi', 'dss_keputusan'];
$ada = array_column(dss_rows($dss, "SELECT table_name AS nama FROM information_schema.tables WHERE table_schema = 'mawarkuntum_dss'"), 'nama');
$tabel_hilang = array_values(array_diff($tabel_dss, array_map('strtolower', $ada)));
$siap = empty($tabel_hilang);
 
// ===== Langkah perhitungan (sama dengan dss_hitung.sql) =====
$langkah_hitung = [];
$langkah_hitung['1. Hapus penilaian lama'] = <<<'SQL'
DELETE FROM dss_penilaian_produk
WHERE periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penjualan_produk)
SQL;
 
$langkah_hitung['2. Nilai dan normalisasi kriteria'] = <<<'SQL'
INSERT INTO dss_penilaian_produk (periode, product_id, id_kriteria, nilai_asli, nilai_normalisasi)
SELECT v.periode, v.product_id, k.id_kriteria, v.nilai,
       ROUND(CASE WHEN k.jenis = 'Benefit'
                  THEN IF(MAX(v.nilai) OVER (PARTITION BY v.kode) > 0,
                          v.nilai / MAX(v.nilai) OVER (PARTITION BY v.kode), 0)
                  ELSE (MIN(v.nilai) OVER (PARTITION BY v.kode) + 1) / (v.nilai + 1)
             END, 6)
FROM (
    SELECT per.periode, p.product_id, 'QTY_TERJUAL' AS kode, COALESCE(mp.qty_terjual, 0) AS nilai
    FROM (SELECT MAX(periode) AS periode FROM mawarkuntum.mis_penjualan_produk) per
    CROSS JOIN mawarkuntum.products p
    LEFT JOIN mawarkuntum.mis_penjualan_produk mp
           ON mp.product_id = p.product_id AND mp.periode = per.periode
    WHERE per.periode IS NOT NULL
    UNION ALL
    SELECT per.periode, p.product_id, 'PENDAPATAN', COALESCE(mp.pendapatan, 0)
    FROM (SELECT MAX(periode) AS periode FROM mawarkuntum.mis_penjualan_produk) per
    CROSS JOIN mawarkuntum.products p
    LEFT JOIN mawarkuntum.mis_penjualan_produk mp
           ON mp.product_id = p.product_id AND mp.periode = per.periode
    WHERE per.periode IS NOT NULL
    UNION ALL
    SELECT per.periode, p.product_id, 'SISA_STOK', p.stok
    FROM (SELECT MAX(periode) AS periode FROM mawarkuntum.mis_penjualan_produk) per
    CROSS JOIN mawarkuntum.products p
    WHERE per.periode IS NOT NULL
    UNION ALL
    SELECT per.periode, p.product_id, 'FREKUENSI_ORDER',
           (SELECT COUNT(DISTINCT od.order_id)
            FROM mawarkuntum.order_details od
            JOIN mawarkuntum.orders o ON o.order_id = od.order_id
            WHERE od.product_id = p.product_id
              AND o.status_pesanan <> 'Dibatalkan'
              AND o.tanggal_transaksi >= per.periode
              AND o.tanggal_transaksi < per.periode + INTERVAL 1 MONTH)
    FROM (SELECT MAX(periode) AS periode FROM mawarkuntum.mis_penjualan_produk) per
    CROSS JOIN mawarkuntum.products p
    WHERE per.periode IS NOT NULL
) v
JOIN dss_kriteria k ON k.kode_kriteria = v.kode
SQL;
 
$langkah_hitung['3. Skor, peringkat dan titik pesan'] = <<<'SQL'
INSERT INTO dss_rekomendasi
    (periode, product_id, skor_saw, peringkat, stok_saat_ini, rata_rata_harian,
     lead_time_hari, reorder_point, id_supplier)
SELECT
    s.periode, s.product_id, s.skor_saw, s.peringkat, s.stok, s.rata_harian, s.lead_time,
    GREATEST(
        CEIL(s.rata_harian * (s.lead_time + (SELECT nilai FROM dss_parameter WHERE kode_parameter = 'SAFETY_STOCK_HARI'))),
        (SELECT nilai FROM dss_parameter WHERE kode_parameter = 'STOK_MINIMUM')
    ),
    s.id_supplier
FROM (
    SELECT sk.periode, sk.product_id, sk.skor_saw,
           RANK() OVER (ORDER BY sk.skor_saw DESC) AS peringkat,
           p.stok,
           COALESCE(q.nilai_asli, 0) / DAY(LAST_DAY(sk.periode)) AS rata_harian,
           COALESCE(ps.lead_time_hari,
                    (SELECT nilai FROM dss_parameter WHERE kode_parameter = 'LEAD_TIME_DEFAULT')) AS lead_time,
           ps.id_supplier
    FROM (SELECT n.periode, n.product_id, ROUND(SUM(k.bobot * n.nilai_normalisasi), 6) AS skor_saw
          FROM dss_penilaian_produk n
          JOIN dss_kriteria k ON k.id_kriteria = n.id_kriteria
          WHERE n.periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penjualan_produk)
          GROUP BY n.periode, n.product_id) sk
    JOIN mawarkuntum.products p ON p.product_id = sk.product_id
    LEFT JOIN dss_penilaian_produk q
           ON q.periode = sk.periode AND q.product_id = sk.product_id
          AND q.id_kriteria = (SELECT id_kriteria FROM dss_kriteria WHERE kode_kriteria = 'QTY_TERJUAL')
    LEFT JOIN dss_produk_supplier ps ON ps.product_id = sk.product_id AND ps.is_utama = 1
) s
ON DUPLICATE KEY UPDATE
    skor_saw         = VALUES(skor_saw),
    peringkat        = VALUES(peringkat),
    stok_saat_ini    = VALUES(stok_saat_ini),
    rata_rata_harian = VALUES(rata_rata_harian),
    lead_time_hari   = VALUES(lead_time_hari),
    reorder_point    = VALUES(reorder_point),
    id_supplier      = VALUES(id_supplier)
SQL;
 
$langkah_hitung['4. Jumlah restock disarankan'] = <<<'SQL'
UPDATE dss_rekomendasi r
LEFT JOIN dss_produk_supplier ps
       ON ps.product_id = r.product_id AND ps.id_supplier = r.id_supplier
SET r.qty_disarankan = CASE
        WHEN r.stok_saat_ini <= r.reorder_point
        THEN GREATEST(
                 CEIL(r.reorder_point
                      + r.rata_rata_harian * (SELECT nilai FROM dss_parameter WHERE kode_parameter = 'HARI_COVER_TARGET')
                      - r.stok_saat_ini),
                 COALESCE(ps.minimal_order, 0))
        ELSE 0 END
WHERE r.periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penjualan_produk)
SQL;
 
$langkah_hitung['5. Estimasi biaya'] = <<<'SQL'
UPDATE dss_rekomendasi r
LEFT JOIN dss_produk_supplier ps
       ON ps.product_id = r.product_id AND ps.id_supplier = r.id_supplier
SET r.estimasi_biaya = r.qty_disarankan * COALESCE(ps.harga_beli, 0)
WHERE r.periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penjualan_produk)
SQL;
 
$langkah_hitung['6. Aksi dan alasan'] = <<<'SQL'
UPDATE dss_rekomendasi r
SET r.aksi = CASE
        WHEN r.stok_saat_ini <= r.reorder_point * 0.5
             OR (r.stok_saat_ini <= r.reorder_point AND r.peringkat <= 3) THEN 'Restock Segera'
        WHEN r.stok_saat_ini <= r.reorder_point THEN 'Restock'
        WHEN r.rata_rata_harian = 0 THEN 'Evaluasi'
        ELSE 'Pertahankan' END,
    r.alasan = CASE
        WHEN r.stok_saat_ini <= r.reorder_point * 0.5
             OR (r.stok_saat_ini <= r.reorder_point AND r.peringkat <= 3)
             THEN CONCAT('Stok ', r.stok_saat_ini, ' pcs, titik pesan ', r.reorder_point, ' pcs, peringkat ', r.peringkat)
        WHEN r.stok_saat_ini <= r.reorder_point
             THEN CONCAT('Stok ', r.stok_saat_ini, ' pcs di bawah titik pesan ', r.reorder_point, ' pcs')
        WHEN r.rata_rata_harian = 0 THEN 'Tidak ada penjualan pada periode ini, stok masih aman'
        ELSE 'Stok di atas titik pesan dan penjualan berjalan' END
WHERE r.periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penjualan_produk)
SQL;
 
 
// ===== Pemrosesan form (POST) =====
if ($siap && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    $periode_post = $_POST['periode'] ?? '';
 
    // --- 1. Hitung ulang rekomendasi ---
    if ($do === 'hitung') {
        $n = dss_rows($dss, "SELECT COUNT(*) AS n FROM mawarkuntum.mis_penjualan_produk");
        if (!$n || (int) $n[0]['n'] === 0) {
            dss_flash('err', 'Data penjualan di tabel MIS masih kosong. Buka Dashboard MIS dulu supaya datanya terisi, lalu hitung ulang.');
            dss_kembali($periode_post);
        }
        foreach ($langkah_hitung as $nama => $sql) {
            $hasil = dss_jalankan($dss, $sql);
            if ($hasil !== true) {
                dss_flash('err', "Perhitungan berhenti pada langkah \"$nama\": $hasil");
                dss_kembali($periode_post);
            }
        }
        $jml = dss_rows($dss, "SELECT COUNT(*) AS n, MAX(periode) AS p FROM dss_rekomendasi WHERE periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penjualan_produk)");
        dss_flash('ok', 'Rekomendasi selesai dihitung ulang: ' . (int) ($jml[0]['n'] ?? 0) . ' produk dianalisis untuk periode ' . dss_label_periode($jml[0]['p'] ?? date('Y-m-01')) . '.');
        dss_kembali($jml[0]['p'] ?? '');
    }
 
    // --- 2. Simpan keputusan Manager ---
    if ($do === 'keputusan') {
        $id_rek   = (int) ($_POST['id_rekomendasi'] ?? 0);
        $kep      = $_POST['keputusan'] ?? '';
        $qty      = (int) ($_POST['qty'] ?? 0);
        $id_sup   = (int) ($_POST['id_supplier'] ?? 0);
        $id_staff = (int) ($_POST['id_staff'] ?? 0);
        $catatan  = trim($_POST['catatan'] ?? '');
 
        $rek = dss_rows($dss, "SELECT r.*, p.nama_produk FROM dss_rekomendasi r
                               JOIN mawarkuntum.products p ON p.product_id = r.product_id
                               WHERE r.id_rekomendasi = ?", 'i', [$id_rek]);
        $terakhir = dss_rows($dss, "SELECT keputusan FROM dss_keputusan WHERE id_rekomendasi = ? ORDER BY id_keputusan DESC LIMIT 1", 'i', [$id_rek]);
 
        if (!$rek) {
            dss_flash('err', 'Rekomendasi tidak ditemukan.');
        } elseif (!in_array($kep, ['Disetujui', 'Ditolak', 'Ditunda'], true)) {
            dss_flash('err', 'Pilihan keputusan tidak dikenal.');
        } elseif ($terakhir && in_array($terakhir[0]['keputusan'], ['Disetujui', 'Ditolak'], true)) {
            dss_flash('err', 'Rekomendasi ini sudah diputuskan (' . $terakhir[0]['keputusan'] . ').');
        } elseif ($kep === 'Disetujui' && $qty <= 0) {
            dss_flash('err', 'Jumlah restock harus lebih dari 0 untuk menyetujui.');
        } else {
            $rek = $rek[0];
            $sup_db = null;
            $nama_sup = '-';
            $harga_beli = 0;
            if ($id_sup > 0) {
                $s = dss_rows($dss, "SELECT s.nama_supplier, COALESCE(ps.harga_beli, 0) AS harga_beli
                                     FROM dss_supplier s
                                     LEFT JOIN dss_produk_supplier ps ON ps.id_supplier = s.id_supplier AND ps.product_id = ?
                                     WHERE s.id_supplier = ?", 'ii', [(int) $rek['product_id'], $id_sup]);
                if ($s) {
                    $sup_db = $id_sup;
                    $nama_sup = $s[0]['nama_supplier'];
                    $harga_beli = (float) $s[0]['harga_beli'];
                }
            }
            $qty_db = ($kep === 'Disetujui') ? $qty : null;
            $cat_db = ($catatan !== '') ? $catatan : null;
 
            $id_kep = dss_exec($dss, "INSERT INTO dss_keputusan (id_rekomendasi, keputusan, qty_disetujui, id_supplier, catatan, diputuskan_oleh)
                                      VALUES (?, ?, ?, ?, ?, ?)", 'isiisi', [$id_rek, $kep, $qty_db, $sup_db, $cat_db, $user_id]);
            if ($id_kep === false) {
                dss_flash('err', 'Keputusan gagal disimpan: ' . mysqli_error($dss));
            } else {
                $pesan = "Keputusan \"$kep\" untuk {$rek['nama_produk']} berhasil disimpan.";
 
                // Keputusan "Disetujui" -> buat tugas restock untuk Staff Gudang (OAS)
                if ($kep === 'Disetujui' && $id_staff > 0) {
                    $staf = dss_rows($conn, "SELECT u.user_id FROM users u
                                             JOIN sub_roles sr ON sr.id_sub_role = u.id_sub_role
                                             WHERE u.user_id = ? AND u.status = 'active' AND sr.nama_sub_role = 'Staff Gudang'", 'i', [$id_staff]);
                    if ($staf) {
                        $judul = 'Restock ' . $rek['nama_produk'] . ' (' . $qty . ' pcs)';
                        $desk  = 'Keputusan DSS periode ' . dss_label_periode($rek['periode'])
                               . ': pesan ' . $qty . ' pcs ' . $rek['nama_produk'] . ' ke ' . $nama_sup
                               . '. Estimasi biaya ' . dss_rp($qty * $harga_beli) . '.'
                               . ($catatan !== '' ? ' Catatan Manager: ' . $catatan : '');
                        $prio = ($rek['aksi'] === 'Restock Segera') ? 'High' : 'Medium';
 
                        $id_tugas = dss_exec($conn, "INSERT INTO tasks (judul_tugas, deskripsi, diberikan_oleh, dikerjakan_oleh, prioritas, status_tugas, tgl_dibuat)
                                                     VALUES (?, ?, ?, ?, ?, 'To-Do', NOW())", 'ssiis', [$judul, $desk, $user_id, $id_staff, $prio]);
                        if ($id_tugas === false) { // cadangan: pola yang sama dengan checkout.php
                            $id_tugas = dss_exec($conn, "INSERT INTO tasks (judul_tugas, deskripsi, dikerjakan_oleh, prioritas, status_tugas)
                                                         VALUES (?, ?, ?, ?, 'To-Do')", 'ssis', [$judul, $desk, $id_staff, $prio]);
                        }
                        if ($id_tugas) {
                            dss_exec($dss, "UPDATE dss_keputusan SET id_tugas_oas = ? WHERE id_keputusan = ?", 'ii', [$id_tugas, $id_kep]);
                            $pesan .= ' Tugas restock sudah dikirim ke Staff Gudang (OAS).';
                        } else {
                            $pesan .= ' Namun tugas OAS gagal dibuat: ' . mysqli_error($conn);
                        }
                    } else {
                        $pesan .= ' Tugas OAS tidak dibuat karena staf yang dipilih tidak valid.';
                    }
                }
                dss_flash('ok', $pesan);
            }
        }
        dss_kembali($periode_post);
    }
 
    // --- 3. Simpan bobot kriteria ---
    if ($do === 'bobot') {
        $bobot = $_POST['bobot'] ?? [];
        $ok = is_array($bobot) && count($bobot) > 0;
        $total = 0.0;
        $bersih = [];
        if ($ok) {
            foreach ($bobot as $id => $v) {
                $v = round((float) str_replace(',', '.', (string) $v), 4);
                if ($v < 0 || $v > 1) { $ok = false; break; }
                $bersih[(int) $id] = $v;
                $total += $v;
            }
        }
        if (!$ok || abs($total - 1) > 0.00011) {
            dss_flash('err', 'Total bobot harus tepat 1,00 dan tiap bobot antara 0 dan 1 (total sekarang ' . number_format($total, 4, ',', '.') . ').');
        } else {
            foreach ($bersih as $id => $v) {
                dss_exec($dss, "UPDATE dss_kriteria SET bobot = ? WHERE id_kriteria = ?", 'di', [$v, $id]);
            }
            dss_flash('ok', 'Bobot kriteria disimpan. Klik "Hitung Ulang" agar peringkat memakai bobot baru.');
        }
        dss_kembali($periode_post);
    }
 
    // --- 4. Simpan parameter model ---
    if ($do === 'parameter') {
        $nilai = $_POST['nilai'] ?? [];
        $ok = is_array($nilai) && count($nilai) > 0;
        $bersih = [];
        if ($ok) {
            foreach ($nilai as $id => $v) {
                $v = str_replace(',', '.', trim((string) $v));
                if (!is_numeric($v) || (float) $v < 0) { $ok = false; break; }
                $bersih[(int) $id] = (float) $v;
            }
        }
        if (!$ok) {
            dss_flash('err', 'Semua nilai parameter harus berupa angka 0 atau lebih.');
        } else {
            foreach ($bersih as $id => $v) {
                dss_exec($dss, "UPDATE dss_parameter SET nilai = ? WHERE id_parameter = ?", 'di', [$v, $id]);
            }
            dss_flash('ok', 'Parameter disimpan. Klik "Hitung Ulang" agar rekomendasi memakai nilai baru.');
        }
        dss_kembali($periode_post);
    }
 
    // --- 5. Tambah supplier ---
    if ($do === 'supplier') {
        $nama   = trim($_POST['nama_supplier'] ?? '');
        $kontak = trim($_POST['kontak_person'] ?? '');
        $telp   = trim($_POST['no_telepon'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $skor   = (float) str_replace(',', '.', (string) ($_POST['skor_ketepatan'] ?? 0));
        if ($nama === '' || mb_strlen($nama) > 100) {
            dss_flash('err', 'Nama supplier wajib diisi (maksimal 100 karakter).');
        } elseif ($skor < 0 || $skor > 100) {
            dss_flash('err', 'Skor ketepatan harus antara 0 dan 100.');
        } else {
            $id = dss_exec($dss, "INSERT INTO dss_supplier (nama_supplier, kontak_person, no_telepon, alamat, skor_ketepatan)
                                  VALUES (?, ?, ?, ?, ?)", 'ssssd', [$nama, $kontak, $telp, $alamat, $skor]);
            if ($id === false) {
                dss_flash('err', 'Supplier gagal ditambahkan. Mungkin nama tersebut sudah ada.');
            } else {
                dss_flash('ok', "Supplier \"$nama\" ditambahkan. Atur harga beli produknya lewat tabel dss_produk_supplier di phpMyAdmin.");
            }
        }
        dss_kembali($periode_post);
    }
}
 
// ===== Data untuk ditampilkan =====
$flash = $_SESSION['dss_flash'] ?? null;
unset($_SESSION['dss_flash']);
 
$daftar_periode = [];
$periode = '';
$rows = [];
$kriteria = [];
$parameter = [];
$supplier = [];
$produk_supplier = [];   // produk yang dipasok tiap supplier, dikelompokkan per id_supplier
$riwayat = [];
$form = null;          // data untuk form keputusan
$opsi_supplier = [];
$opsi_staf = [];
$dihitung_at = null;
$mis_kosong = false;
 
if ($siap) {
    $daftar_periode = array_column(dss_rows($dss, "SELECT DISTINCT periode FROM dss_rekomendasi ORDER BY periode DESC"), 'periode');
    $periode = $_GET['periode'] ?? '';
    if (!in_array($periode, $daftar_periode, true)) {
        $periode = $daftar_periode[0] ?? '';
    }
 
    $cek_mis = dss_rows($dss, "SELECT COUNT(*) AS n FROM mawarkuntum.mis_penjualan_produk");
    $mis_kosong = !$cek_mis || (int) $cek_mis[0]['n'] === 0;
 
    if ($periode !== '') {
        $rows = dss_rows($dss, "SELECT r.*, p.nama_produk, p.kategori, s.nama_supplier,
                                       (SELECT k.keputusan FROM dss_keputusan k WHERE k.id_rekomendasi = r.id_rekomendasi
                                        ORDER BY k.id_keputusan DESC LIMIT 1) AS keputusan_terakhir
                                FROM dss_rekomendasi r
                                JOIN mawarkuntum.products p ON p.product_id = r.product_id
                                LEFT JOIN dss_supplier s ON s.id_supplier = r.id_supplier
                                WHERE r.periode = ?
                                ORDER BY FIELD(r.aksi, 'Restock Segera', 'Restock', 'Evaluasi', 'Pertahankan'), r.peringkat", 's', [$periode]);
        foreach ($rows as $r) {
            if ($dihitung_at === null || $r['dihitung_at'] > $dihitung_at) {
                $dihitung_at = $r['dihitung_at'];
            }
        }
    }
 
    $kriteria  = dss_rows($dss, "SELECT * FROM dss_kriteria ORDER BY id_kriteria");
    $parameter = dss_rows($dss, "SELECT * FROM dss_parameter ORDER BY id_parameter");
    $supplier  = dss_rows($dss, "SELECT s.*, (SELECT COUNT(*) FROM dss_produk_supplier ps WHERE ps.id_supplier = s.id_supplier) AS jml_produk
                                 FROM dss_supplier s ORDER BY s.status, s.nama_supplier");
    foreach (dss_rows($dss, "SELECT ps.id_supplier, ps.is_utama, ps.harga_beli, ps.lead_time_hari, ps.minimal_order,
                                    p.nama_produk, p.kategori, p.stok,
                                    COALESCE((SELECT SUM(k.qty_disetujui)
                                              FROM dss_keputusan k
                                              JOIN dss_rekomendasi r ON r.id_rekomendasi = k.id_rekomendasi
                                              WHERE k.keputusan = 'Disetujui' AND k.id_supplier = ps.id_supplier
                                                AND r.product_id = ps.product_id), 0) AS total_dipesan
                             FROM dss_produk_supplier ps
                             JOIN mawarkuntum.products p ON p.product_id = ps.product_id
                             ORDER BY ps.id_supplier, p.nama_produk") as $ps) {
        $produk_supplier[(int) $ps['id_supplier']][] = $ps;
    }
    $riwayat   = dss_rows($dss, "SELECT k.*, p.nama_produk, r.periode, s.nama_supplier, u.email, ps.harga_beli
                                 FROM dss_keputusan k
                                 JOIN dss_rekomendasi r ON r.id_rekomendasi = k.id_rekomendasi
                                 JOIN mawarkuntum.products p ON p.product_id = r.product_id
                                 LEFT JOIN dss_supplier s ON s.id_supplier = k.id_supplier
                                 LEFT JOIN mawarkuntum.users u ON u.user_id = k.diputuskan_oleh
                                 LEFT JOIN dss_produk_supplier ps ON ps.product_id = r.product_id AND ps.id_supplier = k.id_supplier
                                 ORDER BY k.id_keputusan DESC LIMIT 10");
 
    // Form keputusan untuk satu rekomendasi (?putuskan=ID)
    $id_putuskan = (int) ($_GET['putuskan'] ?? 0);
    if ($id_putuskan > 0) {
        foreach ($rows as $r) {
            if ((int) $r['id_rekomendasi'] === $id_putuskan && !in_array($r['keputusan_terakhir'], ['Disetujui', 'Ditolak'], true)) {
                $form = $r;
                break;
            }
        }
        if ($form) {
            $opsi_supplier = dss_rows($dss, "SELECT s.id_supplier, s.nama_supplier, s.skor_ketepatan,
                                                    ps.harga_beli, ps.lead_time_hari, ps.minimal_order
                                             FROM dss_supplier s
                                             LEFT JOIN dss_produk_supplier ps ON ps.id_supplier = s.id_supplier AND ps.product_id = ?
                                             WHERE s.status = 'Aktif'
                                             ORDER BY (ps.id_produk_supplier IS NULL), ps.is_utama DESC, s.skor_ketepatan DESC", 'i', [(int) $form['product_id']]);
            $opsi_staf = dss_rows($conn, "SELECT u.user_id, u.email FROM users u
                                          JOIN sub_roles sr ON sr.id_sub_role = u.id_sub_role
                                          WHERE sr.nama_sub_role = 'Staff Gudang' AND u.status = 'active'
                                          ORDER BY u.email");
        }
    }
}
 
// ===== Filter tampilan tabel & ringkasan =====
$filter = $_GET['aksi'] ?? 'semua';
$daftar_aksi = ['Restock Segera', 'Restock', 'Evaluasi', 'Pertahankan'];
if ($filter !== 'semua' && !in_array($filter, $daftar_aksi, true)) {
    $filter = 'semua';
}
 
$hitung_aksi = array_fill_keys($daftar_aksi, 0);
$biaya_menunggu = 0;
$perlu_putusan = 0;
$sudah_putus = 0;
foreach ($rows as $r) {
    $hitung_aksi[$r['aksi']]++;
    if (in_array($r['aksi'], ['Restock Segera', 'Restock'], true)) {
        $perlu_putusan++;
        if (in_array($r['keputusan_terakhir'], ['Disetujui', 'Ditolak'], true)) {
            $sudah_putus++;
        } else {
            $biaya_menunggu += (float) $r['estimasi_biaya'];
        }
    }
}
$rows_tampil = ($filter === 'semua') ? $rows : array_values(array_filter($rows, function ($r) use ($filter) {
    return $r['aksi'] === $filter;
}));
 
function dss_kelas_aksi($aksi) {
    return ['Restock Segera' => 'b-urgent', 'Restock' => 'b-restock', 'Evaluasi' => 'b-eval', 'Pertahankan' => 'b-ok'][$aksi] ?? 'b-eval';
}
function dss_kelas_keputusan($k) {
    return ['Disetujui' => 'b-ok', 'Ditolak' => 'b-urgent', 'Ditunda' => 'b-restock'][$k] ?? 'b-eval';
}
function dss_url($ekstra = []) {
    global $periode, $filter;
    $q = ['periode' => $periode, 'aksi' => $filter];
    foreach ($ekstra as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
    }
    if (($q['aksi'] ?? '') === 'semua') { unset($q['aksi']); }
    if (($q['periode'] ?? '') === '') { unset($q['periode']); }
    return 'dashboard_dss.php' . ($q ? '?' . http_build_query($q) : '');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard DSS - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .header small { color: #666; display: block; font-weight: normal; margin-top: 3px; }
        .header .aksi { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
 
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 5px solid #2e7d32; }
        .stat-card h4 { margin: 0; font-size: 12px; color: #666; text-transform: uppercase; }
        .stat-card .value { font-size: 22px; font-weight: bold; color: #1b5e20; margin-top: 5px; }
        .stat-card .sub { font-size: 12px; color: #777; margin-top: 3px; }
 
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid > div { min-width: 0; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #2e7d32; font-size: 17px; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; }
        .card h3 small { font-weight: normal; color: #777; font-size: 12px; margin-left: 6px; }
        .tabel-wrap { overflow-x: auto; }
 
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #e8f5e9; color: #1b5e20; font-weight: bold; white-space: nowrap; }
        td small { color: #777; }
        .angka { text-align: right; white-space: nowrap; }
 
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; white-space: nowrap; display: inline-block; }
        .b-urgent { background: #ffcdd2; color: #b71c1c; }
        .b-restock { background: #ffe0b2; color: #e65100; }
        .b-eval { background: #e0e0e0; color: #424242; }
        .b-ok { background: #c8e6c9; color: #1b5e20; }
 
        .bar { background: #eceff1; border-radius: 4px; height: 8px; width: 120px; margin: 4px 0 3px; overflow: hidden; }
        .bar > span { display: block; height: 100%; }
        .skor { font-size: 12px; color: #555; }
 
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 4px; color: #2e7d32; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 13px; font-family: inherit; }
        .baris { display: flex; gap: 10px; flex-wrap: wrap; }
        .baris > .form-group { flex: 1; min-width: 140px; }
 
        .btn { padding: 8px 14px; border-radius: 5px; border: none; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; color: white; background: #2e7d32; }
        .btn:hover { opacity: .9; }
        .btn-primary { background: #0288d1; }
        .btn-dss { background: #7b1fa2; }
        .btn-kecil { padding: 4px 10px; font-size: 12px; }
        .btn-abu { background: #78909c; }
        .btn-merah { background: #c62828; }
        .btn-oranye { background: #ef6c00; }
        .btn-logout { background: #e57373; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; font-size: 13px; }
 
        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 10px 14px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .alert-error { background: #ffebee; color: #c62828; padding: 10px 14px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .alert-warn { background: #fff8e1; color: #8d6e00; padding: 10px 14px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
 
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 15px; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .chip { padding: 5px 11px; border-radius: 15px; background: #eceff1; color: #455a64; font-size: 12px; font-weight: bold; text-decoration: none; }
        .chip.aktif { background: #2e7d32; color: white; }
 
        .form-keputusan { border: 2px solid #ce93d8; background: #fdf7ff; }
        .form-keputusan h3 { color: #7b1fa2; border-color: #e1bee7; }
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .ringkas div { background: white; border: 1px solid #e1bee7; border-radius: 6px; padding: 8px 10px; font-size: 12px; color: #666; }
        .ringkas b { display: block; font-size: 15px; color: #333; margin-top: 2px; }
        .tombol-putusan { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 5px; }
        .estimasi { font-weight: bold; color: #1b5e20; }
 
        details { font-size: 13px; line-height: 1.6; }
        details summary { cursor: pointer; font-weight: bold; color: #2e7d32; }
        details ul { margin: 8px 0 0 18px; padding: 0; }
        .kosong { text-align: center; color: #888; padding: 18px; }
        .sembunyi { display: none; }
        .sembunyi.terbuka { display: table-row; }
        a.lihat { color: #7b1fa2; font-weight: bold; text-decoration: none; white-space: nowrap; }
        a.lihat:hover { text-decoration: underline; }
 
        @media (max-width: 900px) {
            body { padding: 12px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 520px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css">
</head>
<body>
 
    <div class="header">
        <h2>🧠 Decision Support System (DSS)
            <small>Rekomendasi restock produk berdasarkan data TPS, OAS, dan MIS</small>
        </h2>
        <div class="aksi">
            <span>User: <b><?php echo dss_h($_SESSION['email'] ?? ''); ?></b></span>
            <a href="dashboard_manager.php" class="btn btn-abu">⬅ Dashboard Manager</a>
            <a href="dashboard_mis.php" class="btn btn-primary">📈 Dashboard MIS</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
 
    <?php if ($flash): ?>
        <div class="<?php echo $flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>"><?php echo ($flash[0] === 'ok' ? '✅ ' : '❌ ') . dss_h($flash[1]); ?></div>
    <?php endif; ?>
 
    <?php if (!$siap): ?>
        <div class="card">
            <h3>Database DSS belum siap</h3>
            <p>Tabel berikut belum ditemukan di database <b>mawarkuntum_dss</b>: <b><?php echo dss_h(implode(', ', $tabel_hilang)); ?></b>.</p>
            <p>Jalankan dulu <code>dss_schema.sql</code> lewat shell XAMPP (<code>source C:/xampp/dss_schema.sql</code>), lalu muat ulang halaman ini.</p>
        </div>
    <?php else: ?>
 
        <?php if ($mis_kosong): ?>
            <div class="alert-warn">⚠️ Data penjualan di tabel MIS masih kosong, jadi DSS belum bisa menghitung. Buka <a href="dashboard_mis.php">Dashboard MIS</a> sekali agar datanya terisi, lalu kembali ke sini dan klik <b>Hitung Ulang</b>.</div>
        <?php elseif ($periode === ''): ?>
            <div class="alert-warn">ℹ️ Belum ada hasil perhitungan DSS. Klik <b>Hitung Ulang</b> untuk membuat rekomendasi pertama.</div>
        <?php endif; ?>
 
        <!-- Ringkasan -->
        <div class="stats-grid">
            <div class="stat-card" style="border-color:#c62828;">
                <h4>Restock Segera</h4>
                <div class="value"><?php echo $hitung_aksi['Restock Segera']; ?> Produk</div>
                <div class="sub">stok jauh di bawah titik pesan</div>
            </div>
            <div class="stat-card" style="border-color:#ef6c00;">
                <h4>Perlu Restock</h4>
                <div class="value"><?php echo $hitung_aksi['Restock']; ?> Produk</div>
                <div class="sub">stok di bawah titik pesan</div>
            </div>
            <div class="stat-card" style="border-color:#0288d1;">
                <h4>Biaya Restock Menunggu Keputusan</h4>
                <div class="value"><?php echo dss_rp($biaya_menunggu); ?></div>
                <div class="sub">estimasi dari harga beli supplier</div>
            </div>
            <div class="stat-card" style="border-color:#7b1fa2;">
                <h4>Sudah Diputuskan</h4>
                <div class="value"><?php echo $sudah_putus; ?> / <?php echo $perlu_putusan; ?></div>
                <div class="sub">dari produk yang perlu restock</div>
            </div>
        </div>
 
        <!-- Form keputusan -->
        <?php if ($form): ?>
            <div class="card form-keputusan" id="form-keputusan">
                <h3>✍️ Keputusan Restock: <?php echo dss_h($form['nama_produk']); ?></h3>
                <div class="ringkas">
                    <div>Stok sekarang<b><?php echo (int) $form['stok_saat_ini']; ?> pcs</b></div>
                    <div>Titik pesan ulang<b><?php echo (int) $form['reorder_point']; ?> pcs</b></div>
                    <div>Rata-rata terjual<b><?php echo number_format((float) $form['rata_rata_harian'], 2, ',', '.'); ?> / hari</b></div>
                    <div>Saran jumlah<b><?php echo (int) $form['qty_disarankan']; ?> pcs</b></div>
                    <div>Peringkat SAW<b>#<?php echo (int) $form['peringkat']; ?> (<?php echo number_format((float) $form['skor_saw'], 3, ',', '.'); ?>)</b></div>
                </div>
                <p style="font-size:13px; margin-top:0;"><b>Alasan sistem:</b> <?php echo dss_h($form['alasan']); ?></p>
 
                <form method="POST" action="dashboard_dss.php">
                    <input type="hidden" name="do" value="keputusan">
                    <input type="hidden" name="periode" value="<?php echo dss_h($periode); ?>">
                    <input type="hidden" name="id_rekomendasi" value="<?php echo (int) $form['id_rekomendasi']; ?>">
                    <div class="baris">
                        <div class="form-group">
                            <label for="f-qty">Jumlah restock (pcs)</label>
                            <input type="number" min="0" id="f-qty" name="qty" class="form-control" value="<?php echo (int) $form['qty_disarankan']; ?>">
                        </div>
                        <div class="form-group" style="flex:2;">
                            <label for="f-sup">Supplier</label>
                            <select name="id_supplier" id="f-sup" class="form-control">
                                <?php foreach ($opsi_supplier as $o): ?>
                                    <option value="<?php echo (int) $o['id_supplier']; ?>"
                                            data-harga="<?php echo $o['harga_beli'] !== null ? (float) $o['harga_beli'] : 0; ?>"
                                            <?php echo ((int) $o['id_supplier'] === (int) $form['id_supplier']) ? 'selected' : ''; ?>>
                                        <?php
                                        echo dss_h($o['nama_supplier']) . ' | ketepatan ' . (float) $o['skor_ketepatan'] . '%';
                                        echo ($o['harga_beli'] !== null)
                                            ? ' | ' . dss_rp($o['harga_beli']) . '/pcs | ' . (int) $o['lead_time_hari'] . ' hari | min. ' . (int) $o['minimal_order']
                                            : ' | harga beli belum diatur';
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="baris">
                        <div class="form-group">
                            <label for="f-staf">Tugaskan ke (OAS)</label>
                            <select name="id_staff" id="f-staf" class="form-control">
                                <?php foreach ($opsi_staf as $s): ?>
                                    <option value="<?php echo (int) $s['user_id']; ?>"><?php echo dss_h($s['email']); ?> (Staff Gudang)</option>
                                <?php endforeach; ?>
                                <option value="0">Jangan buat tugas</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:2;">
                            <label for="f-cat">Catatan (opsional)</label>
                            <input type="text" name="catatan" id="f-cat" class="form-control" maxlength="255" placeholder="Contoh: kirim sebelum akhir pekan">
                        </div>
                    </div>
                    <p style="font-size:13px; margin:0 0 10px;">Estimasi biaya: <span class="estimasi" id="f-est">-</span></p>
                    <div class="tombol-putusan">
                        <button type="submit" name="keputusan" value="Disetujui" class="btn">✅ Setujui &amp; Buat Tugas</button>
                        <button type="submit" name="keputusan" value="Ditunda" class="btn btn-oranye">⏸ Tunda</button>
                        <button type="submit" name="keputusan" value="Ditolak" class="btn btn-merah" onclick="return confirm('Tolak rekomendasi ini? Keputusan yang sudah ditolak tidak bisa diubah.');">✖ Tolak</button>
                        <a href="<?php echo dss_h(dss_url(['putuskan' => null])); ?>" class="btn btn-abu">Batal</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
 
        <!-- Tabel rekomendasi -->
        <div class="card">
            <h3>📋 Rekomendasi Restock
                <?php if ($periode !== ''): ?><small>Periode <?php echo dss_h(dss_label_periode($periode)); ?><?php echo $dihitung_at ? ' | dihitung ' . dss_h(date('d M Y, H:i', strtotime($dihitung_at))) : ''; ?></small><?php endif; ?>
            </h3>
 
            <div class="toolbar">
                <div class="chips">
                    <a class="chip <?php echo $filter === 'semua' ? 'aktif' : ''; ?>" href="<?php echo dss_h(dss_url(['aksi' => 'semua', 'putuskan' => null])); ?>">Semua (<?php echo count($rows); ?>)</a>
                    <?php foreach ($daftar_aksi as $a): ?>
                        <a class="chip <?php echo $filter === $a ? 'aktif' : ''; ?>" href="<?php echo dss_h(dss_url(['aksi' => $a, 'putuskan' => null])); ?>"><?php echo dss_h($a); ?> (<?php echo $hitung_aksi[$a]; ?>)</a>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php if (count($daftar_periode) > 1): ?>
                        <form method="GET" style="margin:0;">
                            <select name="periode" class="form-control" style="width:auto;" onchange="this.form.submit()">
                                <?php foreach ($daftar_periode as $p): ?>
                                    <option value="<?php echo dss_h($p); ?>" <?php echo $p === $periode ? 'selected' : ''; ?>><?php echo dss_h(dss_label_periode($p)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Hitung ulang rekomendasi dari data MIS terbaru?');">
                        <input type="hidden" name="do" value="hitung">
                        <input type="hidden" name="periode" value="<?php echo dss_h($periode); ?>">
                        <button type="submit" class="btn btn-dss">🔄 Hitung Ulang</button>
                    </form>
                </div>
            </div>
 
            <div class="tabel-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Peringkat</th>
                        <th>Produk</th>
                        <th>Stok vs Titik Pesan</th>
                        <th>Saran Restock</th>
                        <th>Rekomendasi</th>
                        <th>Keputusan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows_tampil)): ?>
                        <tr><td colspan="6" class="kosong">Tidak ada data untuk ditampilkan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows_tampil as $r):
                            $rop = max(1, (int) $r['reorder_point']);
                            $stok = (int) $r['stok_saat_ini'];
                            $pct = min(100, (int) round($stok / $rop * 100));
                            $warna = ($stok <= $rop * 0.5) ? '#c62828' : (($stok <= $rop) ? '#ef6c00' : '#2e7d32');
                            $perlu = in_array($r['aksi'], ['Restock Segera', 'Restock'], true);
                            $final = in_array($r['keputusan_terakhir'], ['Disetujui', 'Ditolak'], true);
                        ?>
                        <tr>
                            <td><b>#<?php echo (int) $r['peringkat']; ?></b><br><span class="skor">skor <?php echo number_format((float) $r['skor_saw'], 3, ',', '.'); ?></span></td>
                            <td><b><?php echo dss_h($r['nama_produk']); ?></b><br><small><?php echo dss_h($r['kategori']); ?> | rata-rata <?php echo number_format((float) $r['rata_rata_harian'], 2, ',', '.'); ?>/hari</small></td>
                            <td>
                                <b><?php echo $stok; ?></b> <small>/ <?php echo (int) $r['reorder_point']; ?> pcs</small>
                                <div class="bar"><span style="width:<?php echo $pct; ?>%; background:<?php echo $warna; ?>;"></span></div>
                                <small>lead time <?php echo (int) $r['lead_time_hari']; ?> hari</small>
                            </td>
                            <td>
                                <?php if ((int) $r['qty_disarankan'] > 0): ?>
                                    <b><?php echo (int) $r['qty_disarankan']; ?> pcs</b><br>
                                    <small><?php echo dss_h($r['nama_supplier'] ?? 'Supplier belum diatur'); ?></small><br>
                                    <small><?php echo dss_rp($r['estimasi_biaya']); ?></small>
                                <?php else: ?>
                                    <small>Tidak perlu restock</small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo dss_kelas_aksi($r['aksi']); ?>"><?php echo dss_h($r['aksi']); ?></span><br><small><?php echo dss_h($r['alasan']); ?></small></td>
                            <td>
                                <?php if ($r['keputusan_terakhir']): ?>
                                    <span class="badge <?php echo dss_kelas_keputusan($r['keputusan_terakhir']); ?>"><?php echo dss_h($r['keputusan_terakhir']); ?></span><br>
                                <?php elseif ($perlu): ?>
                                    <span class="badge b-eval">Belum diputuskan</span><br>
                                <?php endif; ?>
                                <?php if ($perlu && !$final): ?>
                                    <a class="btn btn-dss btn-kecil" style="margin-top:5px;" href="<?php echo dss_h(dss_url(['putuskan' => (int) $r['id_rekomendasi']])) . '#form-keputusan'; ?>">Putuskan</a>
                                <?php elseif (!$perlu && !$r['keputusan_terakhir']): ?>
                                    <small>-</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
 
        <div class="grid">
            <div>
                <!-- Bobot kriteria -->
                <div class="card">
                    <h3>⚖️ Kriteria &amp; Bobot (SAW) <small>total harus 1,00</small></h3>
                    <form method="POST" action="dashboard_dss.php">
                        <input type="hidden" name="do" value="bobot">
                        <input type="hidden" name="periode" value="<?php echo dss_h($periode); ?>">
                        <div class="tabel-wrap"><table>
                            <tr><th>Kriteria</th><th>Jenis</th><th>Bobot</th></tr>
                            <?php foreach ($kriteria as $k): ?>
                                <tr>
                                    <td><b><?php echo dss_h($k['nama_kriteria']); ?></b><br><small><?php echo dss_h($k['sumber_data']); ?></small></td>
                                    <td><span class="badge <?php echo $k['jenis'] === 'Benefit' ? 'b-ok' : 'b-restock'; ?>"><?php echo dss_h($k['jenis']); ?></span></td>
                                    <td><input type="number" step="0.01" min="0" max="1" name="bobot[<?php echo (int) $k['id_kriteria']; ?>]" class="form-control" style="width:90px;" value="<?php echo number_format((float) $k['bobot'], 2, '.', ''); ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                        </table></div>
                        <div style="margin-top:12px;"><button type="submit" class="btn">Simpan Bobot</button></div>
                    </form>
                </div>
 
                <!-- Parameter -->
                <div class="card">
                    <h3>🎛️ Parameter Model</h3>
                    <form method="POST" action="dashboard_dss.php">
                        <input type="hidden" name="do" value="parameter">
                        <input type="hidden" name="periode" value="<?php echo dss_h($periode); ?>">
                        <div class="tabel-wrap"><table>
                            <tr><th>Parameter</th><th>Nilai</th></tr>
                            <?php foreach ($parameter as $p): ?>
                                <tr>
                                    <td><b><?php echo dss_h($p['nama_parameter']); ?></b><br><small><?php echo dss_h($p['keterangan']); ?></small></td>
                                    <td style="white-space:nowrap;"><input type="number" step="1" min="0" name="nilai[<?php echo (int) $p['id_parameter']; ?>]" class="form-control" style="width:80px; display:inline-block;" value="<?php echo (float) $p['nilai'] == (int) $p['nilai'] ? (int) $p['nilai'] : (float) $p['nilai']; ?>"> <small><?php echo dss_h($p['satuan']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </table></div>
                        <div style="margin-top:12px;"><button type="submit" class="btn">Simpan Parameter</button></div>
                    </form>
                </div>
 
                <!-- Cara kerja -->
                <div class="card">
                    <h3>ℹ️ Cara Kerja DSS</h3>
                    <details>
                        <summary>Lihat penjelasan singkat</summary>
                        <ul>
                            <li><b>Data masuk:</b> penjualan per produk dari MIS, stok dari TPS, dan data supplier.</li>
                            <li><b>Peringkat (SAW):</b> tiap produk dinilai dari jumlah terjual, pendapatan, frekuensi order (Benefit) dan sisa stok (Cost), dinormalisasi lalu dikali bobot.</li>
                            <li><b>Titik pesan ulang:</b> rata-rata terjual per hari x (lead time + cadangan hari), minimal sebesar batas stok minimum.</li>
                            <li><b>Saran jumlah:</b> bila stok sudah di bawah titik pesan, pesan sampai stok cukup untuk target hari penjualan, minimal sebesar minimal order supplier.</li>
                            <li><b>Keputusan:</b> Manager menyetujui, menunda, atau menolak. Persetujuan otomatis membuat tugas restock untuk Staff Gudang di OAS.</li>
                        </ul>
                    </details>
                </div>
            </div>
 
            <div>
                <!-- Supplier -->
                <div class="card">
                    <h3>🚚 Data Supplier</h3>
                    <div class="tabel-wrap">
                    <table>
                        <tr><th>Supplier</th><th style="text-align:center;">Ketepatan</th><th style="text-align:center;">Jml Produk</th><th style="text-align:center;">Status</th></tr>
                        <?php if (empty($supplier)): ?>
                            <tr><td colspan="4" class="kosong">Belum ada supplier.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($supplier as $s): ?>
                            <tr>
                                <td><b><?php echo dss_h($s['nama_supplier']); ?></b><br><small><?php echo dss_h(trim($s['kontak_person'] . ($s['kontak_person'] && $s['no_telepon'] ? ' | ' : '') . $s['no_telepon']) ?: '-'); ?></small></td>
                                <td style="text-align:center;"><?php echo (float) $s['skor_ketepatan']; ?>%</td>
                                <td style="text-align:center;"><?php if ((int) $s['jml_produk'] > 0): ?><a href="#" class="lihat" onclick="return dssBuka('sup-<?php echo (int) $s['id_supplier']; ?>');"><?php echo (int) $s['jml_produk']; ?> produk &#9662;</a><?php else: ?>0<?php endif; ?></td>
                                <td style="text-align:center;"><span class="badge <?php echo $s['status'] === 'Aktif' ? 'b-ok' : 'b-eval'; ?>"><?php echo dss_h($s['status']); ?></span></td>
                            </tr>
                            <tr id="sup-<?php echo (int) $s['id_supplier']; ?>" class="sembunyi">
                                <td colspan="4" style="background:#fafafa;">
                                    <b style="font-size:12px; color:#2e7d32;">Produk yang dipasok <?php echo dss_h($s['nama_supplier']); ?></b>
                                    <div class="tabel-wrap">
                                    <table>
                                        <tr><th>Produk</th><th style="text-align:right;">Harga Beli</th><th style="text-align:center;">Lead Time</th><th style="text-align:center;">Min. Order</th><th style="text-align:center;">Stok Kini</th><th style="text-align:center;">Sudah Dipesan (DSS)</th></tr>
                                        <?php foreach ($produk_supplier[(int) $s['id_supplier']] ?? [] as $ps): ?>
                                            <tr>
                                                <td><b><?php echo dss_h($ps['nama_produk']); ?></b> <?php echo $ps['is_utama'] ? '<span class="badge b-ok">Utama</span>' : ''; ?><br><small><?php echo dss_h($ps['kategori']); ?></small></td>
                                                <td class="angka"><?php echo dss_rp($ps['harga_beli']); ?></td>
                                                <td style="text-align:center;"><?php echo (int) $ps['lead_time_hari']; ?> hari</td>
                                                <td style="text-align:center;"><?php echo (int) $ps['minimal_order']; ?></td>
                                                <td style="text-align:center;"><?php echo (int) $ps['stok']; ?></td>
                                                <td style="text-align:center;"><?php echo (int) $ps['total_dipesan'] > 0 ? (int) $ps['total_dipesan'] . ' pcs' : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
 
                    <details style="margin-top:14px;">
                        <summary>+ Tambah supplier baru</summary>
                        <form method="POST" action="dashboard_dss.php" style="margin-top:10px;">
                            <input type="hidden" name="do" value="supplier">
                            <input type="hidden" name="periode" value="<?php echo dss_h($periode); ?>">
                            <div class="form-group"><label>Nama supplier</label><input type="text" name="nama_supplier" class="form-control" maxlength="100" required></div>
                            <div class="baris">
                                <div class="form-group"><label>Kontak person</label><input type="text" name="kontak_person" class="form-control" maxlength="100"></div>
                                <div class="form-group"><label>No. telepon</label><input type="text" name="no_telepon" class="form-control" maxlength="20"></div>
                            </div>
                            <div class="form-group"><label>Alamat</label><input type="text" name="alamat" class="form-control"></div>
                            <div class="form-group"><label>Skor ketepatan pengiriman (0-100)</label><input type="number" step="0.01" min="0" max="100" name="skor_ketepatan" class="form-control" value="80"></div>
                            <button type="submit" class="btn">Simpan Supplier</button>
                        </form>
                    </details>
                </div>
 
                <!-- Riwayat keputusan -->
                <div class="card">
                    <h3>🗂️ Riwayat Keputusan <small>10 terakhir</small></h3>
                    <div class="tabel-wrap">
                    <table>
                        <tr><th>Produk</th><th>Keputusan</th><th>Jumlah / Supplier</th><th>Oleh</th></tr>
                        <?php if (empty($riwayat)): ?>
                            <tr><td colspan="4" class="kosong">Belum ada keputusan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($riwayat as $h): ?>
                            <tr>
                                <td><b><?php echo dss_h($h['nama_produk']); ?></b><br><small><?php echo dss_h(dss_label_periode($h['periode'])); ?></small></td>
                                <td><span class="badge <?php echo dss_kelas_keputusan($h['keputusan']); ?>"><?php echo dss_h($h['keputusan']); ?></span>
                                    <?php if ($h['id_tugas_oas']): ?><br><small>tugas OAS #<?php echo (int) $h['id_tugas_oas']; ?></small><?php endif; ?></td>
                                <td>
                                    <?php if ($h['qty_disetujui']): ?>
                                        <b><?php echo (int) $h['qty_disetujui']; ?> pcs</b><br>
                                        <small><?php echo dss_h($h['nama_supplier'] ?? '-'); ?><?php echo $h['harga_beli'] !== null ? ' | ' . dss_rp($h['qty_disetujui'] * $h['harga_beli']) : ''; ?></small>
                                    <?php else: ?>
                                        <small><?php echo dss_h($h['catatan'] ?? '-'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo dss_h($h['email'] ?? '-'); ?><br><?php echo dss_h(date('d M Y, H:i', strtotime($h['tgl_keputusan']))); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
 
    <script>
    // Buka/tutup daftar produk milik satu supplier
    function dssBuka(id) {
        var baris = document.getElementById(id);
        if (baris) { baris.classList.toggle('terbuka'); }
        return false;
    }
    // Hitung estimasi biaya langsung saat jumlah atau supplier diganti
    (function () {
        var q = document.getElementById('f-qty'), s = document.getElementById('f-sup'), o = document.getElementById('f-est');
        if (!q || !s || !o) { return; }
        function hitung() {
            var opt = s.options[s.selectedIndex];
            var harga = opt ? (parseFloat(opt.getAttribute('data-harga')) || 0) : 0;
            var n = parseInt(q.value, 10) || 0;
            o.textContent = harga > 0 ? 'Rp ' + Math.round(harga * n).toLocaleString('id-ID') : 'harga beli supplier ini belum diatur';
        }
        q.addEventListener('input', hitung);
        s.addEventListener('change', hitung);
        hitung();
    })();
    </script>
</body>
</html>
 