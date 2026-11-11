<?php
session_start();
include 'config_es.php';   // $es -> database mawarkuntum_es
include 'es_engine.php';   // pencocokan pertanyaan dan jawaban dinamis

// ===== Wajib login =====
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = (int) $_SESSION['user_id'];
$peran   = es_peran_sesi();

// Peran selain Pelanggan dan Manajer tidak memakai asisten ini
if ($peran === null) {
    $balik = ['Staff Gudang' => 'dashboard_gudang.php', 'Staff Pesanan' => 'dashboard_pesanan.php', 'admin' => 'index.php'];
    $tujuan = $balik[$_SESSION['role'] ?? ''] ?? 'login.php';
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Asisten Mawarkuntum</title></head>'
       . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#e8f5e9;padding:40px;color:#2e7d32;">'
       . '<h3>Asisten virtual hanya tersedia untuk Pelanggan dan Manajer.</h3><p><a href="' . es_h($tujuan) . '">Kembali</a></p></body></html>';
    exit();
}

$link_kembali = ($peran === 'Manajer') ? 'dashboard_manager.php' : 'katalog.php';
$label_kembali = ($peran === 'Manajer') ? 'Dashboard Manager' : 'Katalog';

// ===== Cek tabel expert system sudah dibuat =====
$tabel_es = ['es_topik', 'es_pengetahuan', 'es_kata_kunci', 'es_sinonim', 'es_percakapan',
             'es_pesan', 'es_pertanyaan_belum_terjawab', 'es_umpan_balik'];
$ada = array_column(es_rows($es, "SELECT table_name AS nama FROM information_schema.tables WHERE table_schema = 'mawarkuntum_es'"), 'nama');
$tabel_hilang = array_values(array_diff($tabel_es, array_map('strtolower', $ada)));
$siap = empty($tabel_hilang);

/** Percakapan aktif milik user; dibuat baru bila belum ada. */
function es_percakapan_aktif($es, $user_id, $peran) {
    $id = (int) ($_SESSION['es_percakapan'] ?? 0);
    if ($id > 0) {
        $cek = es_rows($es, "SELECT id_percakapan FROM es_percakapan WHERE id_percakapan = ? AND user_id = ? AND selesai_at IS NULL", 'ii', [$id, $user_id]);
        if ($cek) {
            return $id;
        }
    }
    $baru = es_exec($es, "INSERT INTO es_percakapan (user_id, peran) VALUES (?, ?)", 'is', [$user_id, $peran]);
    if ($baru) {
        $_SESSION['es_percakapan'] = $baru;
        return $baru;
    }
    return 0;
}

// ===== Permintaan AJAX (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $aksi = $_POST['aksi'] ?? '';

    if (!$siap) {
        echo json_encode(['ok' => false, 'pesan' => 'Database expert system belum siap. Jalankan es_schema.sql terlebih dahulu.']);
        exit();
    }

    // --- Pertanyaan baru ---
    if ($aksi === 'tanya') {
        $teks = trim((string) ($_POST['pertanyaan'] ?? ''));
        $teks = mb_substr($teks, 0, ES_MAKS_PANJANG);
        if ($teks === '') {
            echo json_encode(['ok' => false, 'pesan' => 'Pertanyaan tidak boleh kosong.']);
            exit();
        }
        $hasil = es_proses($es, $peran, $user_id, $teks);
        $id_percakapan = es_percakapan_aktif($es, $user_id, $peran);

        $id_pesan = 0;
        if ($id_percakapan) {
            $id_pesan = es_exec($es, "INSERT INTO es_pesan (id_percakapan, pertanyaan, jawaban, id_pengetahuan, skor_kecocokan)
                                      VALUES (?, ?, ?, ?, ?)", 'issid',
                                [$id_percakapan, $teks, $hasil['jawaban'], $hasil['id_pengetahuan'], $hasil['skor']]);
            if (!$hasil['cocok'] && $id_pesan) {
                es_exec($es, "INSERT INTO es_pertanyaan_belum_terjawab (id_pesan, pertanyaan, peran) VALUES (?, ?, ?)",
                        'iss', [$id_pesan, $teks, $peran]);
            }
        }
        echo json_encode([
            'ok'       => true,
            'id_pesan' => $id_pesan ?: null,
            'jawaban'  => $hasil['jawaban'],
            'cocok'    => $hasil['cocok'],
            'topik'    => $hasil['topik'],
            'artikel'  => $hasil['id_artikel_kms'] ? 'detail.php?id=' . $hasil['id_artikel_kms'] : null,
            'rekomendasi' => $hasil['rekomendasi'] ?? [],
        ]);
        exit();
    }

    // --- Penilaian jawaban ---
    if ($aksi === 'feedback') {
        $id_pesan = (int) ($_POST['id_pesan'] ?? 0);
        $helpful  = (($_POST['helpful'] ?? '') === '1') ? 1 : 0;
        $milik = es_rows($es, "SELECT m.id_pesan FROM es_pesan m
                               JOIN es_percakapan c ON c.id_percakapan = m.id_percakapan
                               WHERE m.id_pesan = ? AND c.user_id = ?", 'ii', [$id_pesan, $user_id]);
        if (!$milik) {
            echo json_encode(['ok' => false, 'pesan' => 'Jawaban tidak ditemukan.']);
            exit();
        }
        $r = es_exec($es, "INSERT INTO es_umpan_balik (id_pesan, user_id, is_helpful) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE is_helpful = VALUES(is_helpful)", 'iii', [$id_pesan, $user_id, $helpful]);
        echo json_encode(['ok' => $r !== false]);
        exit();
    }

    // --- Percakapan baru ---
    if ($aksi === 'baru') {
        $id = (int) ($_SESSION['es_percakapan'] ?? 0);
        if ($id > 0) {
            es_exec($es, "UPDATE es_percakapan SET selesai_at = NOW() WHERE id_percakapan = ? AND user_id = ?", 'ii', [$id, $user_id]);
        }
        unset($_SESSION['es_percakapan']);
        echo json_encode(['ok' => true]);
        exit();
    }

    echo json_encode(['ok' => false, 'pesan' => 'Permintaan tidak dikenal.']);
    exit();
}

// ===== Data untuk tampilan awal =====
$riwayat = [];
$contoh  = [];
if ($siap) {
    $id = (int) ($_SESSION['es_percakapan'] ?? 0);
    if ($id > 0) {
        $riwayat = es_rows($es, "SELECT m.id_pesan, m.pertanyaan, m.jawaban, u.is_helpful
                                 FROM es_pesan m
                                 JOIN es_percakapan c ON c.id_percakapan = m.id_percakapan
                                 LEFT JOIN es_umpan_balik u ON u.id_pesan = m.id_pesan
                                 WHERE m.id_percakapan = ? AND c.user_id = ? AND c.selesai_at IS NULL
                                 ORDER BY m.id_pesan", 'ii', [$id, $user_id]);
        foreach ($riwayat as &$itemRiwayat) {
            if ($peran === 'Pelanggan') {
                $hasilLama = es_hasil_rekomendasi_produk($es, $itemRiwayat['pertanyaan']);
                $itemRiwayat['rekomendasi'] = $hasilLama['rekomendasi'] ?? [];
            } else {
                $itemRiwayat['rekomendasi'] = [];
            }
        }
        unset($itemRiwayat);
    }
    $contoh = array_column(es_rows($es, "SELECT p.contoh_pertanyaan FROM es_pengetahuan p
                                         JOIN es_topik t ON t.id_topik = p.id_topik
                                         WHERE p.status = 'Aktif' AND t.untuk_peran IN (?, 'Semua')
                                           AND t.nama_topik <> 'Sapaan & Bantuan'
                                           AND (p.kunci_data IS NULL OR p.kunci_data NOT IN ('STOK_PRODUK', 'HARGA_PRODUK'))
                                         ORDER BY RAND() LIMIT 6", 's', [$peran]), 'contoh_pertanyaan');
}

$sapaan = ($peran === 'Manajer')
    ? 'Halo! Saya asisten virtual Mawarkuntum. Anda bisa bertanya tentang penjualan, produk terlaris, stok menipis, rekomendasi restock, kinerja tugas, dokumen, dan supplier. Data diambil dari sistem MIS, DSS, dan TPS.'
    : 'Halo! Saya asisten virtual Mawarkuntum. Anda bisa bertanya tentang stok dan harga produk, rekomendasi untuk jenis kulit, status pesanan, pembayaran, pengiriman, dan tips perawatan kulit.';

$js_opsi = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asisten Virtual - Mawarkuntum</title>
    <style>
        :root {
            --pink: #d92d72; --pink-dark: #b91f5b; --pink-soft: #ffe3ee;
            --pink-pale: #fff6fa; --green: #557d62; --green-soft: #eaf3e8;
            --ink: #3e3540; --muted: #887d87; --line: #f3d6e2;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif; color: var(--ink); margin: 0; min-height: 100vh;
            padding: 28px 22px 34px; overflow-x: hidden; position: relative;
            background:
                radial-gradient(circle at 8% 12%, rgba(255,255,255,.95) 0 5%, transparent 5.3%),
                radial-gradient(circle at 91% 77%, rgba(255,255,255,.8) 0 7%, transparent 7.3%),
                linear-gradient(135deg, #f8e9ef 0%, #f7f0e8 48%, #e9f2e7 100%);
        }
        body::before, body::after { content: ''; position: fixed; border-radius: 50%; z-index: 0; pointer-events: none; filter: blur(1px); }
        body::before { width: 300px; height: 300px; left: -145px; top: 24%; background: rgba(244,177,202,.28); }
        body::after { width: 370px; height: 370px; right: -190px; bottom: -120px; background: rgba(166,199,166,.24); }
        .dekorasi { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
        .stiker { position: absolute; display: grid; place-items: center; border: 1px solid rgba(255,255,255,.8); box-shadow: 0 12px 30px rgba(142,81,108,.12); border-radius: 22px; background: rgba(255,255,255,.58); backdrop-filter: blur(5px); }
        .stiker.satu { left: 3.5%; top: 19%; width: 76px; height: 76px; font-size: 38px; transform: rotate(-12deg); }
        .stiker.dua { right: 4%; top: 22%; width: 82px; height: 82px; font-size: 40px; transform: rotate(10deg); }
        .stiker.tiga { left: 6%; bottom: 13%; width: 66px; height: 66px; font-size: 33px; transform: rotate(9deg); }
        .stiker.empat { right: 7%; bottom: 12%; width: 72px; height: 72px; font-size: 36px; transform: rotate(-9deg); }
        .wrap { max-width: 1160px; margin: 0 auto; position: relative; z-index: 1; }
        .bar {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
            background: rgba(255,255,255,.9); padding: 18px 22px; border: 1px solid rgba(255,255,255,.95);
            border-radius: 20px; box-shadow: 0 12px 35px rgba(110,76,91,.09); margin-bottom: 20px;
        }
        .brand-area { display: flex; align-items: center; gap: 13px; }
        .brand-icon { width: 52px; height: 52px; border-radius: 17px; display: grid; place-items: center; font-size: 25px; background: linear-gradient(145deg,#ffe0ed,#f8c3d9); box-shadow: inset 0 1px 0 #fff; }
        .eyebrow { color: var(--green); letter-spacing: 1.5px; font-size: 10px; font-weight: 800; margin-bottom: 3px; }
        .bar h2 { margin: 0; color: var(--ink); font-size: 21px; letter-spacing: -.3px; }
        .bar h2 span { color: var(--pink); }
        .bar small { color: var(--muted); display: block; margin-top: 4px; font-size: 12px; }
        .aksi { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 9px 13px; border-radius: 10px; border: 0; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; color: white; background: var(--green); transition: transform .18s ease, box-shadow .18s ease; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(80,80,80,.13); }
        .btn-abu { background: #82929a; }
        .btn-ungu { background: #a04c83; }
        .workspace { display: grid; grid-template-columns: 285px minmax(0,1fr); gap: 18px; align-items: stretch; }
        .beauty-panel { display: flex; flex-direction: column; gap: 14px; }
        .beauty-cover {
            min-height: 250px; overflow: hidden; position: relative; border-radius: 21px; padding: 23px 21px;
            color: #6a3b53; background: linear-gradient(145deg,#ffe0eb 0%,#f9cbdc 58%,#f7e2d3 100%);
            box-shadow: 0 12px 30px rgba(137,78,105,.1);
        }
        .beauty-cover::before { content: ''; position: absolute; width: 170px; height: 170px; border: 1px solid rgba(255,255,255,.65); border-radius: 50%; right: -55px; top: 40px; box-shadow: 0 0 0 15px rgba(255,255,255,.12), 0 0 0 31px rgba(255,255,255,.1); }
        .cover-kicker { font-size: 9px; letter-spacing: 1.7px; font-weight: 800; color: #a14b70; }
        .beauty-cover h3 { font-family: Georgia,serif; font-size: 26px; line-height: 1.13; max-width: 205px; margin: 14px 0 9px; font-weight: 600; }
        .beauty-cover p { font-size: 12px; line-height: 1.6; max-width: 205px; margin: 0; color: #80566a; }
        .produk-dekor { position: absolute; right: 10px; bottom: 8px; display: flex; align-items: end; gap: 1px; transform: rotate(-5deg); }
        .produk-dekor span { display: grid; place-items: center; width: 54px; height: 75px; border-radius: 15px 15px 10px 10px; font-size: 32px; background: rgba(255,255,255,.42); border: 1px solid rgba(255,255,255,.65); box-shadow: 0 8px 18px rgba(135,76,102,.1); }
        .produk-dekor span:nth-child(2) { height: 93px; width: 48px; font-size: 30px; transform: rotate(8deg); }
        .beauty-info { background: rgba(255,255,255,.88); border: 1px solid #fff; border-radius: 19px; padding: 18px; box-shadow: 0 8px 25px rgba(93,91,77,.06); }
        .beauty-info h4 { margin: 0 0 12px; color: #526d58; font-size: 13px; }
        .bantuan-item { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-top: 1px solid #f1e8eb; font-size: 12px; color: #655d63; }
        .bantuan-item:first-of-type { border-top: 0; }
        .bantuan-item span { width: 31px; height: 31px; flex: 0 0 31px; display: grid; place-items: center; border-radius: 11px; background: #fbeaf1; font-size: 16px; }
        .beauty-note { font-size: 11px; line-height: 1.6; color: #8b8087; padding: 0 5px; }
        .kotak { min-width: 0; background: rgba(255,255,255,.95); border-radius: 21px; box-shadow: 0 14px 38px rgba(110,76,91,.1); overflow: hidden; border: 1px solid rgba(255,255,255,.95); }
        .chat-head { min-height: 65px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 13px 20px; border-bottom: 1px solid #f3e6eb; background: linear-gradient(90deg,#fff,#fff8fb); }
        .chat-head strong { display: block; color: #4b3e47; font-size: 14px; }
        .chat-head small { color: #968a92; font-size: 11px; display: block; margin-top: 3px; }
        .status-online { border-radius: 30px; color: #4e815b; background: #eaf5eb; padding: 6px 10px; font-size: 9px; font-weight: 800; letter-spacing: .7px; white-space: nowrap; }
        .status-online::before { content: ''; display: inline-block; width: 6px; height: 6px; margin-right: 5px; border-radius: 50%; background: #5da66c; vertical-align: 1px; }
        .chat { height: min(62vh, 610px); min-height: 390px; overflow-y: auto; padding: 22px 20px; display: flex; flex-direction: column; gap: 14px; background-color: #fffafd; background-image: radial-gradient(#f3dce6 0.75px, transparent .75px); background-size: 19px 19px; }
        .baris { display: flex; gap: 9px; max-width: 86%; align-items: flex-end; animation: masuk .22s ease-out; }
        .baris.user { align-self: flex-end; flex-direction: row-reverse; }
        @keyframes masuk { from { opacity: 0; transform: translateY(7px); } to { opacity: 1; transform: translateY(0); } }
        .avatar { width: 34px; height: 34px; border-radius: 13px 13px 13px 4px; background: linear-gradient(145deg,#ffe4ef,#f7c2d8); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; box-shadow: 0 3px 8px rgba(170,92,126,.1); }
        .gelembung { padding: 12px 15px; border-radius: 17px; font-size: 13px; line-height: 1.65; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: anywhere; box-shadow: 0 3px 10px rgba(89,63,77,.045); }
        .bot .gelembung { background: white; border: 1px solid #f1d9e4; border-bottom-left-radius: 5px; }
        .user .gelembung { background: linear-gradient(135deg,#de4b89,#c82b70); color: white; border-bottom-right-radius: 5px; box-shadow: 0 5px 13px rgba(205,55,117,.18); }
        .gelembung.tidak-cocok { background: #fff9eb; border-color: #f4dfad; }
        .gelembung.galat { background: #fff0f0; border-color: #ffcaca; color: #a52d2d; }
        .menilai { display: flex; align-items: center; gap: 6px; margin: 7px 0 0 2px; font-size: 11px; color: #968b92; }
        .menilai button { background: #fff; border: 1px solid #eee0e6; border-radius: 12px; padding: 3px 9px; cursor: pointer; font-size: 12px; }
        .menilai button:hover, .menilai button.dipilih { background: var(--pink-soft); border-color: #efa9c5; }
        .menilai a { color: var(--pink); font-weight: bold; text-decoration: none; margin-left: 6px; }
        .mengetik { color: #a99ba3; font-style: italic; }
        .baris.bot.punya-rekomendasi { width: 98%; max-width: 98%; }
        .rekomendasi-label { margin: 10px 0 7px 2px; color: #866477; font-size: 10px; font-weight: 800; letter-spacing: .7px; text-transform: uppercase; }
        .rekomendasi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; width: 100%; }
        .produk-rekomendasi { min-width: 0; display: flex; gap: 10px; padding: 10px; border: 1px solid #f0dce5; border-radius: 14px; background: linear-gradient(145deg,#fff 0%,#fff8fb 100%); box-shadow: 0 4px 13px rgba(112,72,92,.06); }
        .produk-rekomendasi img { width: 76px; height: 82px; flex: 0 0 76px; object-fit: cover; border-radius: 10px; background: #f8edf2; }
        .produk-rekomendasi .pr-body { min-width: 0; display: flex; flex-direction: column; align-items: flex-start; }
        .produk-rekomendasi .pr-name { color: #493b44; font-size: 11px; line-height: 1.4; font-weight: 800; }
        .produk-rekomendasi .pr-meta { color: #897c85; font-size: 10px; margin-top: 3px; line-height: 1.4; }
        .produk-rekomendasi .pr-price { color: #c52e70; font-size: 12px; font-weight: 800; margin-top: 5px; }
        .produk-rekomendasi .pr-stock { color: #598363; font-size: 9px; margin-top: 2px; }
        .produk-rekomendasi .pr-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
        .produk-rekomendasi .pr-tags span { display: inline-block; background: #fbeaf1; color: #9e456b; border-radius: 9px; padding: 3px 6px; font-size: 9px; }
        .produk-rekomendasi .pr-link { display: inline-block; margin-top: auto; padding-top: 7px; color: #fff; background: #c83d7b; border-radius: 8px; text-decoration: none; font-size: 9px; font-weight: 800; padding: 6px 8px; }
        .contoh { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; padding: 13px 17px 10px; background: #fff; border-top: 1px solid #f3e6eb; }
        .contoh button { background: #fff4f8; color: #b52e69; border: 1px solid #f2d3e0; border-radius: 20px; padding: 7px 12px; font-size: 11px; cursor: pointer; font-family: inherit; transition: all .15s ease; }
        .contoh button:hover { background: #f9dce8; border-color: #e9a9c3; transform: translateY(-1px); }
        .contoh .label { width: 100%; font-size: 10px; letter-spacing: .8px; color: #988b93; font-weight: 800; text-transform: uppercase; }
        form.kirim { display: flex; gap: 9px; padding: 12px 17px 17px; background: #fff; }
        form.kirim input { flex: 1; padding: 13px 17px; border: 1px solid #ead9e1; border-radius: 22px; font-size: 13px; font-family: inherit; outline: none; min-width: 0; background: #fffafd; transition: border .18s, box-shadow .18s; }
        form.kirim input:focus { border-color: #df6b9b; box-shadow: 0 0 0 3px rgba(223,107,155,.12); }
        form.kirim button { background: linear-gradient(135deg,#df4c8d,#c92b70); color: white; border: 0; border-radius: 22px; padding: 0 23px; min-width: 95px; font-weight: 700; cursor: pointer; box-shadow: 0 5px 13px rgba(205,55,117,.2); }
        form.kirim button:hover { filter: brightness(.97); transform: translateY(-1px); }
        form.kirim button:disabled { background: #c9bfc4; cursor: not-allowed; box-shadow: none; }
        .catatan { text-align: center; font-size: 11px; color: #827782; margin: 13px 0 0; }
        .peringatan { background: #fff8e1; color: #8d6e00; padding: 16px 18px; border-radius: 14px; font-size: 14px; box-shadow: 0 8px 20px rgba(100,80,50,.07); }
        @media (max-width: 900px) {
            .workspace { grid-template-columns: 235px minmax(0,1fr); gap: 13px; }
            .beauty-cover { min-height: 230px; padding: 19px; }
            .beauty-cover h3 { font-size: 23px; }
            .stiker.satu { left: -22px; } .stiker.dua { right: -24px; }
        }
        @media (max-width: 680px) {
            body { padding: 13px 11px 25px; }
            .bar { padding: 15px; border-radius: 16px; }
            .brand-area { align-items: flex-start; }
            .bar h2 { font-size: 18px; }
            .aksi { width: 100%; }
            .aksi .btn { flex: 1; text-align: center; }
            .workspace { grid-template-columns: 1fr; }
            .beauty-cover { min-height: 174px; }
            .beauty-cover h3 { max-width: 62%; font-size: 22px; }
            .beauty-cover p { max-width: 63%; }
            .beauty-info { display: none; }
            .chat { height: 52vh; min-height: 330px; padding: 16px 12px; }
            .baris { max-width: 96%; }
            .stiker { opacity: .45; transform: scale(.7) !important; }
            .stiker.satu { left: -25px; top: 37%; }
            .stiker.dua { right: -28px; top: 42%; }
            .stiker.tiga, .stiker.empat { display: none; }
            .contoh { padding: 11px 12px 8px; }
            .rekomendasi-grid { grid-template-columns: 1fr; }
            .produk-rekomendasi img { width: 68px; height: 76px; flex-basis: 68px; }
            form.kirim { padding: 10px 12px 13px; }
            form.kirim button { min-width: 76px; padding: 0 15px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
<div class="dekorasi" aria-hidden="true">
    <div class="stiker satu">💄</div>
    <div class="stiker dua">🪞</div>
    <div class="stiker tiga">💅</div>
    <div class="stiker empat">🧴</div>
</div>
<div class="wrap">

    <div class="bar">
        <div class="brand-area">
            <div class="brand-icon">🌷</div>
            <div>
                <div class="eyebrow">MAWARKUNTUM • BEAUTY ASSISTANT</div>
                <h2>Asisten Virtual <span>Mawarkuntum</span></h2>
                <small>Masuk sebagai <?php echo es_h($peran); ?>: <?php echo es_h($_SESSION['email'] ?? ''); ?></small>
            </div>
        </div>
        <div class="aksi">
            <?php if ($siap): ?><button type="button" class="btn btn-abu" id="btn-baru">↻ Percakapan Baru</button><?php endif; ?>
            <?php if ($peran === 'Manajer'): ?><a href="kelola_pengetahuan.php" class="btn btn-ungu">🧠 Kelola Pengetahuan</a><?php endif; ?>
            <a href="<?php echo es_h($link_kembali); ?>" class="btn">← <?php echo es_h($label_kembali); ?></a>
        </div>
    </div>

    <?php if (!$siap): ?>
        <div class="peringatan">
            Database expert system belum siap. Tabel yang belum ditemukan di <b>mawarkuntum_es</b>:
            <b><?php echo es_h(implode(', ', $tabel_hilang)); ?></b>.
            Jalankan dulu <code>es_schema.sql</code> lewat shell XAMPP (<code>source C:/xampp/es_schema.sql</code>), lalu muat ulang halaman ini.
        </div>
    <?php else: ?>
        <div class="workspace">
            <aside class="beauty-panel">
                <div class="beauty-cover">
                    <div class="cover-kicker">BEAUTY CARE • EVERYDAY</div>
                    <h3>Rawat diri, mulai dari sini.</h3>
                    <p>Tanya apa saja seputar produk, pesanan, dan rutinitas perawatan kulitmu.</p>
                    <div class="produk-dekor" aria-hidden="true"><span>🧴</span><span>💄</span><span>🌸</span></div>
                </div>
                <div class="beauty-info">
                    <h4>✨ Aku bisa bantu kamu</h4>
                    <div class="bantuan-item"><span>🫧</span> Pilih skincare sesuai jenis kulit</div>
                    <div class="bantuan-item"><span>💋</span> Cari info harga dan stok produk</div>
                    <div class="bantuan-item"><span>📦</span> Cek pesanan dan pengiriman</div>
                    <div class="bantuan-item"><span>🌷</span> Temukan tips beauty harian</div>
                </div>
                <div class="beauty-note">Tips: tulis pertanyaan dengan bahasa sehari-hari. Asisten akan mencarikan jawaban dari basis pengetahuan Mawarkuntum.</div>
            </aside>

            <section class="kotak" aria-label="Percakapan dengan Asisten Virtual">
                <div class="chat-head">
                    <div><strong>💬 Ruang konsultasi</strong><small>Teman virtual untuk kebutuhan beauty dan tokomu</small></div>
                    <span class="status-online">SIAP MEMBANTU</span>
                </div>
                <div class="chat" id="chat" aria-live="polite"></div>
                <?php if ($contoh): ?>
                    <div class="contoh" id="contoh">
                        <span class="label">Pilih pertanyaan untuk memulai</span>
                        <?php foreach ($contoh as $c): ?>
                            <button type="button" data-q="<?php echo es_h($c); ?>"><?php echo es_h($c); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form class="kirim" id="form" autocomplete="off">
                    <input type="text" id="q" maxlength="<?php echo ES_MAKS_PANJANG; ?>" placeholder="Tulis pertanyaanmu di sini..." aria-label="Pertanyaan">
                    <button type="submit" id="btn-kirim">Kirim ➤</button>
                </form>
            </section>
        </div>
        <div class="catatan">🌸 Jawaban otomatis berdasarkan basis pengetahuan Mawarkuntum. Bantu kami berkembang dengan memberi penilaian 👍 atau 👎.</div>
    <?php endif; ?>

</div>

<?php if ($siap): ?>
<script>
(function () {
    var SAPAAN  = <?php echo json_encode($sapaan, $js_opsi); ?>;
    var RIWAYAT = <?php echo json_encode($riwayat, $js_opsi); ?>;
    var chat = document.getElementById('chat');
    var form = document.getElementById('form');
    var input = document.getElementById('q');
    var tombol = document.getElementById('btn-kirim');
    var kotakContoh = document.getElementById('contoh');

    function gulir() { chat.scrollTop = chat.scrollHeight; }

    function buatBaris(jenis, teks, kelas) {
        var baris = document.createElement('div');
        baris.className = 'baris ' + jenis;
        if (jenis === 'bot') {
            var av = document.createElement('div');
            av.className = 'avatar';
            av.textContent = '🌸';
            baris.appendChild(av);
        }
        var kolom = document.createElement('div');
        var g = document.createElement('div');
        g.className = 'gelembung' + (kelas ? ' ' + kelas : '');
        g.textContent = teks;           // textContent: aman dari HTML yang disisipkan
        kolom.appendChild(g);
        baris.appendChild(kolom);
        chat.appendChild(baris);
        gulir();
        return {baris: baris, kolom: kolom, gelembung: g};
    }

    function tambahKartuRekomendasi(kolom, daftarProduk) {
        if (!Array.isArray(daftarProduk) || daftarProduk.length === 0) return;
        var label = document.createElement('div');
        label.className = 'rekomendasi-label';
        label.textContent = 'Produk yang bisa kamu pertimbangkan';
        kolom.appendChild(label);

        var grid = document.createElement('div');
        grid.className = 'rekomendasi-grid';
        daftarProduk.forEach(function (p) {
            var card = document.createElement('article');
            card.className = 'produk-rekomendasi';

            var img = document.createElement('img');
            img.alt = p.nama_produk || 'Produk Mawarkuntum';
            img.loading = 'lazy';
            if (p.gambar) img.src = p.gambar;
            img.onerror = function () { img.style.display = 'none'; };
            card.appendChild(img);

            var body = document.createElement('div');
            body.className = 'pr-body';
            var name = document.createElement('div');
            name.className = 'pr-name';
            name.textContent = p.nama_produk || 'Produk Mawarkuntum';
            body.appendChild(name);

            var meta = document.createElement('div');
            meta.className = 'pr-meta';
            meta.textContent = (p.jenis_produk || p.kategori || 'Produk') + (p.finish ? ' • Finish ' + p.finish : '');
            body.appendChild(meta);

            var price = document.createElement('div');
            price.className = 'pr-price';
            var nominal = Number(p.harga || 0);
            price.textContent = 'Rp ' + nominal.toLocaleString('id-ID', {maximumFractionDigits: 0});
            body.appendChild(price);

            var stock = document.createElement('div');
            stock.className = 'pr-stock';
            stock.textContent = 'Stok tersedia: ' + Number(p.stok || 0) + ' pcs';
            body.appendChild(stock);

            var tags = document.createElement('div');
            tags.className = 'pr-tags';
            var daftarTag = [];
            if (p.jenis_kulit) daftarTag.push('Kulit: ' + p.jenis_kulit);
            if (p.finish) daftarTag.push('Finish: ' + p.finish);
            if (p.tags) p.tags.split(',').forEach(function (t) { if (t.trim()) daftarTag.push(t.trim()); });
            daftarTag.slice(0, 3).forEach(function (t) {
                var chip = document.createElement('span');
                chip.textContent = t;
                tags.appendChild(chip);
            });
            if (daftarTag.length) body.appendChild(tags);

            var link = document.createElement('a');
            link.className = 'pr-link';
            link.href = 'katalog.php#produk-' + encodeURIComponent(p.product_id);
            link.textContent = 'Lihat di katalog →';
            body.appendChild(link);

            card.appendChild(body);
            grid.appendChild(card);
        });
        kolom.appendChild(grid);
        var parentRow = kolom.parentElement;
        if (parentRow) parentRow.classList.add('punya-rekomendasi');
    }

    function tambahPenilaian(kolom, idPesan, nilaiAwal, tautan) {
        var w = document.createElement('div');
        w.className = 'menilai';
        var info = document.createElement('span');
        info.textContent = 'Membantu?';
        var ya = document.createElement('button');
        ya.type = 'button'; ya.textContent = '👍'; ya.title = 'Membantu';
        var tidak = document.createElement('button');
        tidak.type = 'button'; tidak.textContent = '👎'; tidak.title = 'Tidak membantu';
        function tandai(v) {
            ya.className = (v === 1) ? 'dipilih' : '';
            tidak.className = (v === 0) ? 'dipilih' : '';
        }
        if (nilaiAwal !== null && nilaiAwal !== undefined) { tandai(parseInt(nilaiAwal, 10)); }
        function kirimNilai(v) {
            var d = new FormData();
            d.append('aksi', 'feedback'); d.append('id_pesan', idPesan); d.append('helpful', v);
            fetch('asisten.php', {method: 'POST', body: d}).then(function (r) { return r.json(); })
                .then(function (j) { if (j.ok) { tandai(v); info.textContent = 'Terima kasih!'; } });
        }
        ya.onclick = function () { kirimNilai(1); };
        tidak.onclick = function () { kirimNilai(0); };
        w.appendChild(info); w.appendChild(ya); w.appendChild(tidak);
        if (tautan) {
            var a = document.createElement('a');
            a.href = tautan; a.textContent = 'Baca selengkapnya ➔';
            w.appendChild(a);
        }
        kolom.appendChild(w);
    }

    function tampilkanAwal() {
        chat.innerHTML = '';
        buatBaris('bot', SAPAAN);
        RIWAYAT.forEach(function (r) {
            buatBaris('user', r.pertanyaan);
            var b = buatBaris('bot', r.jawaban);
            tambahKartuRekomendasi(b.kolom, r.rekomendasi || []);
            tambahPenilaian(b.kolom, r.id_pesan, r.is_helpful, null);
        });
        if (RIWAYAT.length && kotakContoh) { kotakContoh.style.display = 'none'; }
    }

    function tanya(teks) {
        teks = teks.trim();
        if (!teks) { return; }
        if (kotakContoh) { kotakContoh.style.display = 'none'; }
        buatBaris('user', teks);
        input.value = '';
        tombol.disabled = true;
        var mengetik = buatBaris('bot', 'Sedang mencari jawaban...', 'mengetik');

        var d = new FormData();
        d.append('aksi', 'tanya'); d.append('pertanyaan', teks);
        fetch('asisten.php', {method: 'POST', body: d})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                chat.removeChild(mengetik.baris);
                if (!j.ok) { buatBaris('bot', j.pesan || 'Terjadi kesalahan.', 'galat'); return; }
                var b = buatBaris('bot', j.jawaban, j.cocok ? '' : 'tidak-cocok');
                tambahKartuRekomendasi(b.kolom, j.rekomendasi || []);
                if (j.id_pesan) { tambahPenilaian(b.kolom, j.id_pesan, null, j.artikel); }
            })
            .catch(function () {
                chat.removeChild(mengetik.baris);
                buatBaris('bot', 'Tidak dapat terhubung ke server. Coba lagi.', 'galat');
            })
            .then(function () { tombol.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); tanya(input.value); });
    if (kotakContoh) {
        kotakContoh.addEventListener('click', function (e) {
            var q = e.target.getAttribute && e.target.getAttribute('data-q');
            if (q) { tanya(q); }
        });
    }
    var baru = document.getElementById('btn-baru');
    if (baru) {
        baru.addEventListener('click', function () {
            var d = new FormData(); d.append('aksi', 'baru');
            fetch('asisten.php', {method: 'POST', body: d}).then(function () { window.location.reload(); });
        });
    }

    tampilkanAwal();
    input.focus();
})();
</script>
<?php endif; ?>
</body>
</html>