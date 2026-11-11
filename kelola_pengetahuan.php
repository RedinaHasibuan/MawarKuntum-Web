<?php
session_start();
include 'config_es.php';   // $es -> database mawarkuntum_es
include 'es_engine.php';

// ===== Keamanan RBAC: hanya Manager dan Supervisor Operasional =====
if (!isset($_SESSION['user_id']) || es_peran_sesi() !== 'Manajer') {
    header("Location: login.php");
    exit();
}
$user_id = (int) $_SESSION['user_id'];

function es_flash($tipe, $pesan) {
    $_SESSION['es_flash'] = [$tipe, $pesan];
}

function es_kembali($tab = '', array $tambahan = []) {
    $q = [];
    if ($tab !== '') {
        $q['tab'] = $tab;
    }
    foreach ($tambahan as $k => $v) {
        $q[$k] = $v;
    }
    header('Location: kelola_pengetahuan.php' . ($q ? '?' . http_build_query($q) : ''));
    exit();
}

// ===== Cek tabel =====
$tabel_es = ['es_topik', 'es_pengetahuan', 'es_kata_kunci', 'es_sinonim', 'es_percakapan',
             'es_pesan', 'es_pertanyaan_belum_terjawab', 'es_umpan_balik'];
$ada = array_column(es_rows($es, "SELECT table_name AS nama FROM information_schema.tables WHERE table_schema = 'mawarkuntum_es'"), 'nama');
$tabel_hilang = array_values(array_diff($tabel_es, array_map('strtolower', $ada)));
$siap = empty($tabel_hilang);

/** Ganti seluruh kata kunci sebuah pengetahuan. */
function es_simpan_kata_kunci($es, $id, array $kata) {
    if (es_exec($es, "DELETE FROM es_kata_kunci WHERE id_pengetahuan = ?", 'i', [$id]) === false) {
        return false;
    }
    foreach ($kata as $k => $b) {
        if (es_exec($es, "INSERT INTO es_kata_kunci (id_pengetahuan, kata_kunci, bobot) VALUES (?, ?, ?)", 'isi', [$id, (string) $k, (int) $b]) === false) {
            return false;
        }
    }
    return true;
}

/** Validasi isian form pengetahuan. Mengembalikan array bersih, atau null dengan pesan di $galat. */
function es_validasi_pengetahuan($es, array $in, &$galat) {
    $id_topik = (int) ($in['id_topik'] ?? 0);
    $q        = trim((string) ($in['contoh_pertanyaan'] ?? ''));
    $a        = trim((string) ($in['jawaban'] ?? ''));
    $jenis    = (($in['jenis'] ?? '') === 'Dinamis') ? 'Dinamis' : 'Statis';
    $kunci    = trim((string) ($in['kunci_data'] ?? ''));
    $kata     = es_parse_kata_kunci($in['kata_kunci'] ?? '');
    $artikel  = (int) ($in['id_artikel_kms'] ?? 0);

    $topik = es_rows($es, "SELECT untuk_peran FROM es_topik WHERE id_topik = ?", 'i', [$id_topik]);
    if (!$topik) {
        $galat = 'Pilih topik yang valid.';
    } elseif ($q === '' || mb_strlen($q) > 255) {
        $galat = 'Contoh pertanyaan wajib diisi (maksimal 255 karakter).';
    } elseif ($a === '') {
        $galat = 'Jawaban wajib diisi.';
    } elseif (!$kata) {
        $galat = 'Isi minimal satu kata kunci.';
    } elseif (array_sum($kata) < ES_AMBANG) {
        $galat = 'Total bobot kata kunci minimal ' . ES_AMBANG . ' agar pengetahuan ini bisa cocok dengan pertanyaan.';
    } elseif ($jenis === 'Dinamis' && !isset(ES_KUNCI_DINAMIS[$kunci])) {
        $galat = 'Pilih sumber data yang valid untuk jawaban dinamis.';
    } elseif ($jenis === 'Dinamis' && ES_KUNCI_DINAMIS[$kunci][1] !== 'Semua' && ES_KUNCI_DINAMIS[$kunci][1] !== $topik[0]['untuk_peran']) {
        $galat = 'Sumber data tersebut hanya untuk ' . ES_KUNCI_DINAMIS[$kunci][1] . ', sedangkan topik yang dipilih untuk ' . $topik[0]['untuk_peran'] . '.';
    } else {
        return ['id_topik' => $id_topik, 'q' => $q, 'a' => $a, 'jenis' => $jenis,
                'kunci' => ($jenis === 'Dinamis') ? $kunci : null, 'kata' => $kata,
                'artikel' => $artikel > 0 ? $artikel : null];
    }
    return null;
}

// ===== Pemrosesan form =====
if ($siap && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    // --- Tambah pengetahuan ---
    if ($do === 'tambah') {
        $galat = '';
        $d = es_validasi_pengetahuan($es, $_POST, $galat);
        if (!$d) {
            es_flash('err', $galat);
            es_kembali('pengetahuan');
        }
        mysqli_begin_transaction($es);
        $id = es_exec($es, "INSERT INTO es_pengetahuan (id_topik, contoh_pertanyaan, jawaban, jenis_jawaban, kunci_data, id_artikel_kms, dibuat_oleh)
                            VALUES (?, ?, ?, ?, ?, ?, ?)", 'issssii',
                      [$d['id_topik'], $d['q'], $d['a'], $d['jenis'], $d['kunci'], $d['artikel'], $user_id]);
        if ($id && es_simpan_kata_kunci($es, $id, $d['kata'])) {
            mysqli_commit($es);
            es_flash('ok', 'Pengetahuan baru ditambahkan dengan ' . count($d['kata']) . ' kata kunci.');
        } else {
            mysqli_rollback($es);
            es_flash('err', 'Pengetahuan gagal disimpan: ' . mysqli_error($es));
        }
        es_kembali('pengetahuan');
    }

    // --- Ubah pengetahuan ---
    if ($do === 'ubah') {
        $id = (int) ($_POST['id_pengetahuan'] ?? 0);
        $galat = '';
        $d = es_validasi_pengetahuan($es, $_POST, $galat);
        if (!$d) {
            es_flash('err', $galat);
            es_kembali('pengetahuan', ['edit' => $id]);
        }
        mysqli_begin_transaction($es);
        $r = es_exec($es, "UPDATE es_pengetahuan SET id_topik = ?, contoh_pertanyaan = ?, jawaban = ?, jenis_jawaban = ?,
                                  kunci_data = ?, id_artikel_kms = ? WHERE id_pengetahuan = ?", 'issssii',
                     [$d['id_topik'], $d['q'], $d['a'], $d['jenis'], $d['kunci'], $d['artikel'], $id]);
        if ($r !== false && es_simpan_kata_kunci($es, $id, $d['kata'])) {
            mysqli_commit($es);
            es_flash('ok', 'Pengetahuan berhasil diperbarui.');
        } else {
            mysqli_rollback($es);
            es_flash('err', 'Pengetahuan gagal diperbarui: ' . mysqli_error($es));
        }
        es_kembali('pengetahuan');
    }

    // --- Aktif / nonaktif ---
    if ($do === 'status') {
        $id = (int) ($_POST['id_pengetahuan'] ?? 0);
        es_exec($es, "UPDATE es_pengetahuan SET status = IF(status = 'Aktif', 'Nonaktif', 'Aktif') WHERE id_pengetahuan = ?", 'i', [$id]);
        es_flash('ok', 'Status pengetahuan diubah.');
        es_kembali('pengetahuan');
    }

    // --- Hapus pengetahuan ---
    if ($do === 'hapus') {
        $id = (int) ($_POST['id_pengetahuan'] ?? 0);
        $r = es_exec($es, "DELETE FROM es_pengetahuan WHERE id_pengetahuan = ?", 'i', [$id]);
        es_flash($r === false ? 'err' : 'ok', $r === false ? 'Pengetahuan gagal dihapus.' : 'Pengetahuan dihapus beserta kata kuncinya.');
        es_kembali('pengetahuan');
    }

    // --- Jadikan pertanyaan belum terjawab sebagai pengetahuan baru ---
    if ($do === 'jadikan') {
        $id_bt = (int) ($_POST['id_belum_terjawab'] ?? 0);
        $bt = es_rows($es, "SELECT pertanyaan FROM es_pertanyaan_belum_terjawab WHERE id_belum_terjawab = ? AND status = 'Baru'", 'i', [$id_bt]);
        if (!$bt) {
            es_flash('err', 'Pertanyaan tidak ditemukan atau sudah ditinjau.');
            es_kembali('belum');
        }
        $in = ['id_topik' => $_POST['id_topik'] ?? 0, 'contoh_pertanyaan' => mb_substr($bt[0]['pertanyaan'], 0, 255),
               'jawaban' => $_POST['jawaban'] ?? '', 'jenis' => 'Statis', 'kata_kunci' => $_POST['kata_kunci'] ?? ''];
        $galat = '';
        $d = es_validasi_pengetahuan($es, $in, $galat);
        if (!$d) {
            es_flash('err', $galat);
            es_kembali('belum');
        }
        mysqli_begin_transaction($es);
        $id = es_exec($es, "INSERT INTO es_pengetahuan (id_topik, contoh_pertanyaan, jawaban, jenis_jawaban, dibuat_oleh)
                            VALUES (?, ?, ?, 'Statis', ?)", 'issi', [$d['id_topik'], $d['q'], $d['a'], $user_id]);
        $ok = $id && es_simpan_kata_kunci($es, $id, $d['kata'])
              && es_exec($es, "UPDATE es_pertanyaan_belum_terjawab SET status = 'Dijawab', jawaban_manajer = ?, id_pengetahuan_baru = ?,
                                      ditinjau_oleh = ?, ditinjau_at = NOW() WHERE id_belum_terjawab = ?", 'siii', [$d['a'], $id, $user_id, $id_bt]) !== false;
        if ($ok) {
            mysqli_commit($es);
            es_flash('ok', 'Pertanyaan dijadikan pengetahuan baru. Pertanyaan serupa akan langsung terjawab.');
        } else {
            mysqli_rollback($es);
            es_flash('err', 'Gagal menyimpan: ' . mysqli_error($es));
        }
        es_kembali('belum');
    }

    // --- Abaikan pertanyaan belum terjawab ---
    if ($do === 'abaikan') {
        $id_bt = (int) ($_POST['id_belum_terjawab'] ?? 0);
        es_exec($es, "UPDATE es_pertanyaan_belum_terjawab SET status = 'Diabaikan', ditinjau_oleh = ?, ditinjau_at = NOW()
                      WHERE id_belum_terjawab = ? AND status = 'Baru'", 'ii', [$user_id, $id_bt]);
        es_flash('ok', 'Pertanyaan diabaikan.');
        es_kembali('belum');
    }

    // --- Sinonim ---
    if ($do === 'sinonim_tambah') {
        $umum = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($_POST['kata_umum'] ?? ''), 'UTF-8'));
        $baku = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($_POST['kata_baku'] ?? ''), 'UTF-8'));
        if (strlen($umum) < 2 || strlen($baku) < 2 || $umum === $baku) {
            es_flash('err', 'Isi dua kata yang berbeda (huruf atau angka saja, tanpa spasi).');
        } elseif (es_exec($es, "INSERT INTO es_sinonim (kata_umum, kata_baku) VALUES (?, ?)", 'ss', [$umum, $baku]) === false) {
            es_flash('err', 'Sinonim gagal ditambahkan. Kata tersebut mungkin sudah ada.');
        } else {
            es_flash('ok', "Sinonim \"$umum\" menjadi \"$baku\" ditambahkan.");
        }
        es_kembali('sinonim');
    }
    if ($do === 'sinonim_hapus') {
        es_exec($es, "DELETE FROM es_sinonim WHERE id_sinonim = ?", 'i', [(int) ($_POST['id_sinonim'] ?? 0)]);
        es_flash('ok', 'Sinonim dihapus.');
        es_kembali('sinonim');
    }
}

// ===== Data tampilan =====
$flash = $_SESSION['es_flash'] ?? null;
unset($_SESSION['es_flash']);

$stat = ['percakapan' => 0, 'pesan' => 0, 'terjawab' => 0, 'penilaian' => 0, 'membantu' => 0, 'baru' => 0, 'pengetahuan' => 0, 'sinonim' => 0];
$daftar_topik = [];
$tab = $_GET['tab'] ?? '';

if ($siap) {
    $r = es_rows($es, "SELECT
        (SELECT COUNT(*) FROM es_percakapan) AS percakapan,
        (SELECT COUNT(*) FROM es_pesan) AS pesan,
        (SELECT COUNT(*) FROM es_pesan WHERE id_pengetahuan IS NOT NULL) AS terjawab,
        (SELECT COUNT(*) FROM es_umpan_balik) AS penilaian,
        (SELECT COALESCE(SUM(is_helpful), 0) FROM es_umpan_balik) AS membantu,
        (SELECT COUNT(*) FROM es_pertanyaan_belum_terjawab WHERE status = 'Baru') AS baru,
        (SELECT COUNT(*) FROM es_pengetahuan) AS pengetahuan,
        (SELECT COUNT(*) FROM es_sinonim) AS sinonim");
    if ($r) {
        $stat = array_map('intval', $r[0]);
    }
    $daftar_topik = es_rows($es, "SELECT id_topik, nama_topik, untuk_peran FROM es_topik ORDER BY id_topik");
    if (!in_array($tab, ['belum', 'pengetahuan', 'sinonim', 'statistik'], true)) {
        $tab = $stat['baru'] > 0 ? 'belum' : 'pengetahuan';
    }
}

$persen_terjawab = $stat['pesan'] > 0 ? round($stat['terjawab'] / $stat['pesan'] * 100) : 0;
$persen_membantu = $stat['penilaian'] > 0 ? round($stat['membantu'] / $stat['penilaian'] * 100) : 0;

function es_url_tab($t, array $tambahan = []) {
    return 'kelola_pengetahuan.php?' . http_build_query(array_merge(['tab' => $t], $tambahan));
}

/** Cetak form tambah / ubah pengetahuan. */
function es_form_pengetahuan($mode, array $topik, $data = []) {
    $d = array_merge(['id_pengetahuan' => 0, 'id_topik' => 0, 'contoh_pertanyaan' => '', 'jawaban' => '', 'jenis_jawaban' => 'Statis',
                      'kunci_data' => '', 'id_artikel_kms' => '', 'kata_kunci' => ''], $data);
    ?>
    <form method="POST" action="kelola_pengetahuan.php">
        <input type="hidden" name="do" value="<?php echo $mode === 'ubah' ? 'ubah' : 'tambah'; ?>">
        <?php if ($mode === 'ubah'): ?><input type="hidden" name="id_pengetahuan" value="<?php echo (int) $d['id_pengetahuan']; ?>"><?php endif; ?>
        <div class="baris">
            <div class="form-group" style="flex:1;">
                <label>Topik</label>
                <select name="id_topik" class="form-control" required>
                    <?php foreach ($topik as $t): ?>
                        <option value="<?php echo (int) $t['id_topik']; ?>" <?php echo (int) $t['id_topik'] === (int) $d['id_topik'] ? 'selected' : ''; ?>>
                            <?php echo es_h($t['nama_topik']) . ' (' . es_h($t['untuk_peran']) . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label>Contoh pertanyaan</label>
                <input type="text" name="contoh_pertanyaan" class="form-control" maxlength="255" required value="<?php echo es_h($d['contoh_pertanyaan']); ?>" placeholder="Contoh: Apakah produk ini aman untuk ibu hamil?">
            </div>
        </div>
        <div class="form-group">
            <label>Jawaban <small>(untuk jenis Dinamis, tulis kalimat dengan tanda seperti {stok}, {daftar_produk})</small></label>
            <textarea name="jawaban" class="form-control" rows="3" required><?php echo es_h($d['jawaban']); ?></textarea>
        </div>
        <div class="baris">
            <div class="form-group">
                <label>Jenis jawaban</label>
                <select name="jenis" class="form-control">
                    <option value="Statis" <?php echo $d['jenis_jawaban'] === 'Statis' ? 'selected' : ''; ?>>Statis (teks tetap)</option>
                    <option value="Dinamis" <?php echo $d['jenis_jawaban'] === 'Dinamis' ? 'selected' : ''; ?>>Dinamis (ambil data sistem)</option>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label>Sumber data (khusus Dinamis)</label>
                <select name="kunci_data" class="form-control">
                    <option value="">-</option>
                    <?php foreach (ES_KUNCI_DINAMIS as $k => $v): ?>
                        <option value="<?php echo es_h($k); ?>" <?php echo $d['kunci_data'] === $k ? 'selected' : ''; ?>><?php echo es_h($v[0]) . ' - untuk ' . es_h($v[1]); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>ID artikel KMS (opsional)</label>
                <input type="number" min="0" name="id_artikel_kms" class="form-control" value="<?php echo es_h($d['id_artikel_kms']); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Kata kunci <small>(pisahkan dengan koma; bobot opsional, contoh: <code>jerawat:3, komedo, bruntusan:2</code>. Total bobot minimal <?php echo ES_AMBANG; ?>)</small></label>
            <textarea name="kata_kunci" class="form-control" rows="2" required><?php echo es_h($d['kata_kunci']); ?></textarea>
        </div>
        <button type="submit" class="btn"><?php echo $mode === 'ubah' ? 'Simpan Perubahan' : 'Simpan Pengetahuan'; ?></button>
        <?php if ($mode === 'ubah'): ?><a href="<?php echo es_h(es_url_tab('pengetahuan')); ?>" class="btn btn-abu">Batal</a><?php endif; ?>
    </form>
    <?php
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Pengetahuan - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .header small { color: #666; display: block; font-weight: normal; margin-top: 3px; }
        .header .aksi { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 16px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 5px solid #2e7d32; }
        .stat-card h4 { margin: 0; font-size: 12px; color: #666; text-transform: uppercase; }
        .stat-card .value { font-size: 22px; font-weight: bold; color: #1b5e20; margin-top: 5px; }
        .stat-card .sub { font-size: 12px; color: #777; margin-top: 3px; }

        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #2e7d32; font-size: 17px; border-bottom: 2px solid #a5d6a7; padding-bottom: 8px; }
        .card h3 small { font-weight: normal; color: #777; font-size: 12px; margin-left: 6px; }
        .tabel-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #e8f5e9; color: #1b5e20; font-weight: bold; white-space: nowrap; }
        td small { color: #777; }

        .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .tab { padding: 8px 16px; border-radius: 20px; background: white; color: #455a64; font-size: 13px; font-weight: bold; text-decoration: none; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .tab.aktif { background: #7b1fa2; color: white; }
        .tab .n { background: #ffcdd2; color: #b71c1c; border-radius: 10px; padding: 1px 7px; margin-left: 5px; font-size: 11px; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; white-space: nowrap; display: inline-block; }
        .b-ok { background: #c8e6c9; color: #1b5e20; }
        .b-merah { background: #ffcdd2; color: #b71c1c; }
        .b-oranye { background: #ffe0b2; color: #e65100; }
        .b-abu { background: #e0e0e0; color: #424242; }
        .b-ungu { background: #e1bee7; color: #6a1b9a; }

        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 4px; color: #2e7d32; }
        .form-group label small { font-weight: normal; color: #777; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 13px; font-family: inherit; }
        .baris { display: flex; gap: 10px; flex-wrap: wrap; }
        .baris > .form-group { flex: 1; min-width: 150px; }

        .btn { padding: 8px 14px; border-radius: 5px; border: none; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; color: white; background: #2e7d32; }
        .btn:hover { opacity: .9; }
        .btn-kecil { padding: 4px 10px; font-size: 12px; }
        .btn-abu { background: #78909c; }
        .btn-merah { background: #c62828; }
        .btn-oranye { background: #ef6c00; }
        .btn-ungu { background: #7b1fa2; }
        .btn-biru { background: #0288d1; }
        .btn-logout { background: #e57373; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; font-size: 13px; }
        form.inline { display: inline; margin: 0; }

        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 10px 14px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .alert-error { background: #ffebee; color: #c62828; padding: 10px 14px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .alert-warn { background: #fff8e1; color: #8d6e00; padding: 10px 14px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; }
        .kosong { text-align: center; color: #888; padding: 18px; }
        .teks-jawaban { max-width: 380px; white-space: pre-wrap; overflow-wrap: anywhere; }
        .kutipan { background: #fafafa; border-left: 3px solid #ce93d8; padding: 6px 10px; margin: 4px 0; font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }

        details { font-size: 13px; }
        details summary { cursor: pointer; font-weight: bold; color: #7b1fa2; }
        .kotak-tinjau { background: #fdf7ff; border: 1px solid #e1bee7; border-radius: 8px; padding: 12px; margin-top: 8px; }

        @media (max-width: 900px) { body { padding: 12px; } .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 520px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css">
</head>
<body>

    <div class="header">
        <h2>🧠 Kelola Basis Pengetahuan
            <small>Expert system Mawarkuntum: pertanyaan, jawaban, kata kunci, dan sinonim</small>
        </h2>
        <div class="aksi">
            <span>User: <b><?php echo es_h($_SESSION['email'] ?? ''); ?></b></span>
            <a href="asisten.php" class="btn btn-ungu">💬 Buka Asisten</a>
            <a href="dashboard_manager.php" class="btn btn-abu">⬅ Dashboard Manager</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="<?php echo $flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>"><?php echo ($flash[0] === 'ok' ? '✅ ' : '❌ ') . es_h($flash[1]); ?></div>
    <?php endif; ?>

    <?php if (!$siap): ?>
        <div class="card">
            <h3>Database expert system belum siap</h3>
            <p>Tabel berikut belum ditemukan di database <b>mawarkuntum_es</b>: <b><?php echo es_h(implode(', ', $tabel_hilang)); ?></b>.</p>
            <p>Jalankan dulu <code>es_schema.sql</code> lewat shell XAMPP (<code>source C:/xampp/es_schema.sql</code>), lalu muat ulang halaman ini.</p>
        </div>
    <?php else: ?>

        <div class="stats-grid">
            <div class="stat-card" style="border-color:#7b1fa2;">
                <h4>Percakapan</h4>
                <div class="value"><?php echo $stat['percakapan']; ?></div>
                <div class="sub"><?php echo $stat['pesan']; ?> pertanyaan masuk</div>
            </div>
            <div class="stat-card" style="border-color:#2e7d32;">
                <h4>Berhasil Dijawab</h4>
                <div class="value"><?php echo $persen_terjawab; ?>%</div>
                <div class="sub"><?php echo $stat['terjawab']; ?> dari <?php echo $stat['pesan']; ?> pertanyaan</div>
            </div>
            <div class="stat-card" style="border-color:#0288d1;">
                <h4>Dinilai Membantu</h4>
                <div class="value"><?php echo $stat['penilaian'] > 0 ? $persen_membantu . '%' : '-'; ?></div>
                <div class="sub"><?php echo $stat['penilaian']; ?> penilaian pengguna</div>
            </div>
            <div class="stat-card" style="border-color:#c62828;">
                <h4>Belum Terjawab</h4>
                <div class="value"><?php echo $stat['baru']; ?></div>
                <div class="sub">menunggu ditinjau</div>
            </div>
            <div class="stat-card" style="border-color:#ef6c00;">
                <h4>Basis Pengetahuan</h4>
                <div class="value"><?php echo $stat['pengetahuan']; ?></div>
                <div class="sub"><?php echo $stat['sinonim']; ?> sinonim</div>
            </div>
        </div>

        <div class="tabs">
            <a class="tab <?php echo $tab === 'belum' ? 'aktif' : ''; ?>" href="<?php echo es_h(es_url_tab('belum')); ?>">Belum Terjawab<?php echo $stat['baru'] > 0 ? '<span class="n">' . $stat['baru'] . '</span>' : ''; ?></a>
            <a class="tab <?php echo $tab === 'pengetahuan' ? 'aktif' : ''; ?>" href="<?php echo es_h(es_url_tab('pengetahuan')); ?>">Pengetahuan</a>
            <a class="tab <?php echo $tab === 'sinonim' ? 'aktif' : ''; ?>" href="<?php echo es_h(es_url_tab('sinonim')); ?>">Sinonim</a>
            <a class="tab <?php echo $tab === 'statistik' ? 'aktif' : ''; ?>" href="<?php echo es_h(es_url_tab('statistik')); ?>">Statistik</a>
        </div>

        <?php /* ===================== TAB: BELUM TERJAWAB ===================== */ ?>
        <?php if ($tab === 'belum'):
            $belum = es_rows($es, "SELECT id_belum_terjawab, pertanyaan, peran, created_at FROM es_pertanyaan_belum_terjawab
                                   WHERE status = 'Baru' ORDER BY created_at DESC LIMIT 50");
            $sin_map = es_muat_sinonim($es);
        ?>
            <div class="card">
                <h3>❓ Pertanyaan yang Belum Terjawab <small>tulis jawabannya agar pertanyaan serupa langsung terjawab</small></h3>
                <?php if (empty($belum)): ?>
                    <div class="kosong">Tidak ada pertanyaan yang menunggu. 🎉</div>
                <?php endif; ?>
                <?php foreach ($belum as $b):
                    $topik_cocok = array_filter($daftar_topik, function ($t) use ($b) {
                        return in_array($t['untuk_peran'], [$b['peran'], 'Semua'], true);
                    });
                ?>
                    <div class="kotak-tinjau" style="margin-bottom:12px;">
                        <div><span class="badge <?php echo $b['peran'] === 'Manajer' ? 'b-ungu' : 'b-ok'; ?>"><?php echo es_h($b['peran']); ?></span>
                            <small><?php echo es_h(date('d M Y, H:i', strtotime($b['created_at']))); ?></small></div>
                        <p style="margin:8px 0; font-size:14px;"><b>"<?php echo es_h($b['pertanyaan']); ?>"</b></p>
                        <details>
                            <summary>Tulis jawaban</summary>
                            <form method="POST" action="kelola_pengetahuan.php" style="margin-top:10px;">
                                <input type="hidden" name="do" value="jadikan">
                                <input type="hidden" name="id_belum_terjawab" value="<?php echo (int) $b['id_belum_terjawab']; ?>">
                                <div class="form-group">
                                    <label>Topik</label>
                                    <select name="id_topik" class="form-control" required>
                                        <?php foreach ($topik_cocok as $t): ?>
                                            <option value="<?php echo (int) $t['id_topik']; ?>"><?php echo es_h($t['nama_topik']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Jawaban</label>
                                    <textarea name="jawaban" class="form-control" rows="3" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Kata kunci <small>(saran otomatis dari pertanyaan, silakan ubah)</small></label>
                                    <input type="text" name="kata_kunci" class="form-control" required value="<?php echo es_h(implode(', ', es_saran_kata_kunci($b['pertanyaan'], $sin_map))); ?>">
                                </div>
                                <button type="submit" class="btn">✅ Jadikan Pengetahuan Baru</button>
                            </form>
                            <form method="POST" action="kelola_pengetahuan.php" class="inline" style="display:block; margin-top:8px;">
                                <input type="hidden" name="do" value="abaikan">
                                <input type="hidden" name="id_belum_terjawab" value="<?php echo (int) $b['id_belum_terjawab']; ?>">
                                <button type="submit" class="btn btn-abu btn-kecil">Abaikan pertanyaan ini</button>
                            </form>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php /* ===================== TAB: PENGETAHUAN ===================== */ ?>
        <?php if ($tab === 'pengetahuan'):
            $edit_id = (int) ($_GET['edit'] ?? 0);
            $edit = null;
            if ($edit_id > 0) {
                $e = es_rows($es, "SELECT * FROM es_pengetahuan WHERE id_pengetahuan = ?", 'i', [$edit_id]);
                if ($e) {
                    $edit = $e[0];
                    $kt = [];
                    foreach (es_rows($es, "SELECT kata_kunci, bobot FROM es_kata_kunci WHERE id_pengetahuan = ? ORDER BY bobot DESC, kata_kunci", 'i', [$edit_id]) as $k) {
                        $kt[] = $k['kata_kunci'] . ':' . $k['bobot'];
                    }
                    $edit['kata_kunci'] = implode(', ', $kt);
                }
            }
            $f_topik = (int) ($_GET['topik'] ?? 0);
            $f_cari  = trim((string) ($_GET['cari'] ?? ''));
            $cari_like = '%' . addcslashes($f_cari, '%_\\') . '%';
            $daftar = es_rows($es, "SELECT p.id_pengetahuan, p.contoh_pertanyaan, p.jawaban, p.jenis_jawaban, p.kunci_data, p.status,
                                           t.nama_topik, t.untuk_peran,
                                           (SELECT COUNT(*) FROM es_kata_kunci k WHERE k.id_pengetahuan = p.id_pengetahuan) AS jml_kata,
                                           (SELECT COUNT(*) FROM es_pesan m WHERE m.id_pengetahuan = p.id_pengetahuan) AS dipakai
                                    FROM es_pengetahuan p
                                    JOIN es_topik t ON t.id_topik = p.id_topik
                                    WHERE (? = 0 OR p.id_topik = ?) AND (? = '' OR p.contoh_pertanyaan LIKE ?)
                                    ORDER BY p.id_topik, p.id_pengetahuan LIMIT 200", 'iiss', [$f_topik, $f_topik, $f_cari, $cari_like]);
        ?>
            <?php if ($edit): ?>
                <div class="card" style="border:2px solid #ce93d8;">
                    <h3>✏️ Ubah Pengetahuan #<?php echo (int) $edit['id_pengetahuan']; ?></h3>
                    <?php es_form_pengetahuan('ubah', $daftar_topik, $edit); ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <details>
                        <summary>➕ Tambah pengetahuan baru</summary>
                        <div style="margin-top:12px;"><?php es_form_pengetahuan('tambah', $daftar_topik); ?></div>
                    </details>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3>📚 Daftar Pengetahuan <small><?php echo count($daftar); ?> ditampilkan</small></h3>
                <form method="GET" action="kelola_pengetahuan.php" class="baris" style="align-items:flex-end;">
                    <input type="hidden" name="tab" value="pengetahuan">
                    <div class="form-group">
                        <label>Topik</label>
                        <select name="topik" class="form-control">
                            <option value="0">Semua topik</option>
                            <?php foreach ($daftar_topik as $t): ?>
                                <option value="<?php echo (int) $t['id_topik']; ?>" <?php echo $f_topik === (int) $t['id_topik'] ? 'selected' : ''; ?>><?php echo es_h($t['nama_topik']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>Cari pertanyaan</label>
                        <input type="text" name="cari" class="form-control" value="<?php echo es_h($f_cari); ?>">
                    </div>
                    <div class="form-group" style="flex:0;"><button type="submit" class="btn btn-biru">Cari</button></div>
                </form>

                <div class="tabel-wrap">
                <table>
                    <thead><tr><th>#</th><th>Pertanyaan dan Jawaban</th><th>Topik</th><th>Jenis</th><th>Kata Kunci</th><th>Dipakai</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php if (empty($daftar)): ?>
                        <tr><td colspan="8" class="kosong">Tidak ada pengetahuan yang cocok.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($daftar as $p): ?>
                        <tr>
                            <td><?php echo (int) $p['id_pengetahuan']; ?></td>
                            <td class="teks-jawaban"><b><?php echo es_h($p['contoh_pertanyaan']); ?></b>
                                <div class="kutipan"><?php echo es_h($p['jawaban']); ?></div></td>
                            <td><?php echo es_h($p['nama_topik']); ?><br><span class="badge <?php echo $p['untuk_peran'] === 'Manajer' ? 'b-ungu' : ($p['untuk_peran'] === 'Pelanggan' ? 'b-ok' : 'b-abu'); ?>"><?php echo es_h($p['untuk_peran']); ?></span></td>
                            <td><span class="badge <?php echo $p['jenis_jawaban'] === 'Dinamis' ? 'b-oranye' : 'b-abu'; ?>"><?php echo es_h($p['jenis_jawaban']); ?></span>
                                <?php if ($p['kunci_data']): ?><br><small><?php echo es_h($p['kunci_data']); ?></small><?php endif; ?></td>
                            <td><?php echo (int) $p['jml_kata']; ?></td>
                            <td><?php echo (int) $p['dipakai']; ?>x</td>
                            <td><span class="badge <?php echo $p['status'] === 'Aktif' ? 'b-ok' : 'b-merah'; ?>"><?php echo es_h($p['status']); ?></span></td>
                            <td style="white-space:nowrap;">
                                <a class="btn btn-biru btn-kecil" href="<?php echo es_h(es_url_tab('pengetahuan', ['edit' => (int) $p['id_pengetahuan']])); ?>">Ubah</a>
                                <form method="POST" action="kelola_pengetahuan.php" class="inline">
                                    <input type="hidden" name="do" value="status">
                                    <input type="hidden" name="id_pengetahuan" value="<?php echo (int) $p['id_pengetahuan']; ?>">
                                    <button type="submit" class="btn btn-oranye btn-kecil"><?php echo $p['status'] === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                                </form>
                                <form method="POST" action="kelola_pengetahuan.php" class="inline" onsubmit="return confirm('Hapus pengetahuan ini beserta kata kuncinya?');">
                                    <input type="hidden" name="do" value="hapus">
                                    <input type="hidden" name="id_pengetahuan" value="<?php echo (int) $p['id_pengetahuan']; ?>">
                                    <button type="submit" class="btn btn-merah btn-kecil">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <?php /* ===================== TAB: SINONIM ===================== */ ?>
        <?php if ($tab === 'sinonim'):
            $sinonim = es_rows($es, "SELECT id_sinonim, kata_umum, kata_baku FROM es_sinonim ORDER BY kata_baku, kata_umum");
        ?>
            <div class="grid-sin" style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
                <div class="card">
                    <h3>➕ Tambah Sinonim</h3>
                    <p style="font-size:13px; color:#666; margin-top:0;">Samakan kata sehari-hari dengan kata baku yang dipakai pada kata kunci, misalnya <b>jerawatan</b> menjadi <b>jerawat</b>.</p>
                    <form method="POST" action="kelola_pengetahuan.php">
                        <input type="hidden" name="do" value="sinonim_tambah">
                        <div class="form-group"><label>Kata sehari-hari</label><input type="text" name="kata_umum" class="form-control" maxlength="50" required></div>
                        <div class="form-group"><label>Menjadi kata baku</label><input type="text" name="kata_baku" class="form-control" maxlength="50" required></div>
                        <button type="submit" class="btn">Simpan</button>
                    </form>
                </div>
                <div class="card">
                    <h3>🔤 Daftar Sinonim <small><?php echo count($sinonim); ?> kata</small></h3>
                    <div class="tabel-wrap">
                    <table>
                        <thead><tr><th>Kata sehari-hari</th><th>Kata baku</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($sinonim)): ?><tr><td colspan="3" class="kosong">Belum ada sinonim.</td></tr><?php endif; ?>
                        <?php foreach ($sinonim as $s): ?>
                            <tr>
                                <td><?php echo es_h($s['kata_umum']); ?></td>
                                <td><b><?php echo es_h($s['kata_baku']); ?></b></td>
                                <td>
                                    <form method="POST" action="kelola_pengetahuan.php" class="inline" onsubmit="return confirm('Hapus sinonim ini?');">
                                        <input type="hidden" name="do" value="sinonim_hapus">
                                        <input type="hidden" name="id_sinonim" value="<?php echo (int) $s['id_sinonim']; ?>">
                                        <button type="submit" class="btn btn-merah btn-kecil">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            <style>@media (max-width: 900px) { .grid-sin { grid-template-columns: 1fr !important; } .grid-sin > div { min-width: 0; } }</style>
        <?php endif; ?>

        <?php /* ===================== TAB: STATISTIK ===================== */ ?>
        <?php if ($tab === 'statistik'):
            $populer = es_rows($es, "SELECT p.id_pengetahuan, p.contoh_pertanyaan, t.nama_topik, COUNT(*) AS n
                                     FROM es_pesan m
                                     JOIN es_pengetahuan p ON p.id_pengetahuan = m.id_pengetahuan
                                     JOIN es_topik t ON t.id_topik = p.id_topik
                                     GROUP BY p.id_pengetahuan, p.contoh_pertanyaan, t.nama_topik
                                     ORDER BY n DESC, p.id_pengetahuan ASC LIMIT 10");
            $kurang = es_rows($es, "SELECT m.pertanyaan, m.jawaban, m.id_pengetahuan, u.created_at
                                    FROM es_umpan_balik u
                                    JOIN es_pesan m ON m.id_pesan = u.id_pesan
                                    WHERE u.is_helpful = 0
                                    ORDER BY u.id_umpan_balik DESC LIMIT 10");
        ?>
            <div class="card">
                <h3>🔥 Pertanyaan Paling Sering Ditanyakan</h3>
                <div class="tabel-wrap">
                <table>
                    <thead><tr><th>Pengetahuan</th><th>Topik</th><th>Jumlah</th></tr></thead>
                    <tbody>
                    <?php if (empty($populer)): ?><tr><td colspan="3" class="kosong">Belum ada pertanyaan yang terjawab.</td></tr><?php endif; ?>
                    <?php foreach ($populer as $p): ?>
                        <tr><td><?php echo es_h($p['contoh_pertanyaan']); ?></td><td><?php echo es_h($p['nama_topik']); ?></td><td><b><?php echo (int) $p['n']; ?>x</b></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card">
                <h3>👎 Jawaban yang Dinilai Kurang Membantu <small>perlu ditinjau</small></h3>
                <div class="tabel-wrap">
                <table>
                    <thead><tr><th>Pertanyaan pengguna</th><th>Jawaban yang diberikan</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php if (empty($kurang)): ?><tr><td colspan="3" class="kosong">Belum ada jawaban yang dinilai kurang membantu.</td></tr><?php endif; ?>
                    <?php foreach ($kurang as $k): ?>
                        <tr>
                            <td class="teks-jawaban"><b><?php echo es_h($k['pertanyaan']); ?></b><br><small><?php echo es_h(date('d M Y, H:i', strtotime($k['created_at']))); ?></small></td>
                            <td class="teks-jawaban"><?php echo es_h($k['jawaban']); ?></td>
                            <td>
                                <?php if ($k['id_pengetahuan']): ?>
                                    <a class="btn btn-biru btn-kecil" href="<?php echo es_h(es_url_tab('pengetahuan', ['edit' => (int) $k['id_pengetahuan']])); ?>">Ubah pengetahuan</a>
                                <?php else: ?><small>-</small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</body>
</html>