<?php
/**
 * es_engine.php
 * Mesin expert system Mawarkuntum: pencocokan pertanyaan dengan basis pengetahuan
 * dan pengisi jawaban dinamis dari TPS, MIS, dan DSS.
 *
 * Syarat: config_es.php sudah di-include (menyediakan $es dan fungsi es_rows/es_exec).
 */

const ES_AMBANG        = 3;    // skor minimal agar sebuah pengetahuan dianggap cocok
const ES_STOK_MINIMUM  = 25;   // sama dengan batas stok menipis di dashboard gudang, MIS, dan DSS
const ES_MAKS_PANJANG  = 300;  // panjang maksimal satu pertanyaan

const ES_JAWABAN_CADANGAN = 'Maaf, saya belum memahami pertanyaan itu. Pertanyaan Anda sudah dicatat agar tim kami bisa menambahkan jawabannya. Coba gunakan kata lain, atau pilih salah satu pertanyaan contoh.';

/**
 * Jenis jawaban dinamis yang sudah didukung kode di bawah.
 * kunci => [keterangan, peran yang boleh menerima]
 */
const ES_KUNCI_DINAMIS = [
    'STOK_PRODUK'             => ['Stok produk (TPS)',                         'Semua'],
    'HARGA_PRODUK'            => ['Harga produk (TPS)',                        'Semua'],
    'PRODUK_TERLARIS'         => ['Produk terlaris tanpa pendapatan (MIS)',    'Semua'],
    'STATUS_PESANAN'          => ['Status pesanan terakhir pengguna (TPS)',    'Pelanggan'],
    'PENJUALAN_BULAN_INI'     => ['Total penjualan bulan terakhir (MIS)',      'Manajer'],
    'PRODUK_TERLARIS_MANAJER' => ['Produk terlaris dengan pendapatan (MIS)',   'Manajer'],
    'KINERJA_TUGAS'           => ['Ringkasan kinerja tugas (MIS, OAS)',        'Manajer'],
    'STATUS_DOKUMEN'          => ['Ringkasan status dokumen (MIS, OAS)',       'Manajer'],
    'ARTIKEL_POPULER'         => ['Artikel KMS paling banyak dibaca (MIS)',    'Manajer'],
    'STOK_MENIPIS'            => ['Produk dengan stok menipis (TPS)',          'Manajer'],
    'REKOMENDASI_RESTOCK'     => ['Rekomendasi restock (DSS)',                 'Manajer'],
    'BIAYA_RESTOCK'           => ['Estimasi biaya restock belum diputuskan (DSS)', 'Manajer'],
    'DAFTAR_SUPPLIER'         => ['Daftar supplier aktif (DSS)',               'Manajer'],
];

// Kata yang terlalu umum untuk dijadikan nama produk atau kata kunci
const ES_KATA_UMUM = ['produk', 'stok', 'harga', 'berapa', 'apa', 'ada', 'masih', 'tersedia', 'sisa', 'habis',
                      'yang', 'ini', 'itu', 'dan', 'di', 'ke', 'dari', 'untuk', 'kak', 'ya', 'gak', 'nggak',
                      'tidak', 'bisa', 'mau', 'tanya', 'mawarkuntum', 'biaya', 'cek', 'dong', 'sih', 'saya',
                      'aku', 'kamu', 'anda', 'bagaimana', 'gimana', 'cara', 'kapan', 'siapa', 'dengan', 'atau'];

/** Peran chat berdasarkan sesi login: Manajer, Pelanggan, atau null bila tidak berhak. */
function es_peran_sesi() {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['Manager', 'Supervisor Operasional'], true)) {
        return 'Manajer';
    }
    if (in_array($role, ['Staff Gudang', 'Staff Pesanan', 'admin'], true)) {
        return null;
    }
    return 'Pelanggan';
}

/** Nama bulan Indonesia untuk tanggal Y-m-d. */
function es_label_periode($tanggal) {
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
              'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $t = strtotime((string) $tanggal);
    return $t ? $bulan[(int) date('n', $t)] . ' ' . date('Y', $t) : '-';
}

/** Ambil peta sinonim: kata sehari-hari => kata baku. */
function es_muat_sinonim($es) {
    $peta = [];
    foreach (es_rows($es, "SELECT kata_umum, kata_baku FROM es_sinonim") as $r) {
        $peta[mb_strtolower($r['kata_umum'], 'UTF-8')] = mb_strtolower($r['kata_baku'], 'UTF-8');
    }
    return $peta;
}

/**
 * Pecah teks menjadi daftar kata unik (huruf kecil), buang akhiran -nya/-lah/-kah,
 * lalu ganti kata sehari-hari dengan kata bakunya.
 */
function es_kata($teks, $sinonim = []) {
    $teks = mb_strtolower((string) $teks, 'UTF-8');
    preg_match_all('/[a-z0-9]+/', $teks, $m);
    $hasil = [];
    foreach ($m[0] as $k) {
        foreach (['nya', 'lah', 'kah'] as $akhiran) {
            $pj = strlen($akhiran);
            if (strlen($k) > $pj + 2 && substr($k, -$pj) === $akhiran) {
                $k = substr($k, 0, -$pj);
            }
        }
        $k = $sinonim[$k] ?? $k;
        $hasil[$k] = true;
    }
    return array_slice(array_keys($hasil), 0, 30);
}

/**
 * Cari pengetahuan dengan skor kata kunci tertinggi untuk peran tertentu.
 * Mengembalikan baris pengetahuan (dengan kolom skor) atau null bila skor di bawah ambang.
 */
function es_cocokkan($es, $peran, array $kata) {
    if (!$kata) {
        return null;
    }
    $ph = implode(',', array_fill(0, count($kata), '?'));
    $sql = "SELECT p.id_pengetahuan, p.id_topik, p.contoh_pertanyaan, p.jawaban, p.jenis_jawaban,
                   p.kunci_data, p.id_artikel_kms, t.nama_topik, SUM(k.bobot) AS skor
            FROM es_pengetahuan p
            JOIN es_topik t ON t.id_topik = p.id_topik
            JOIN es_kata_kunci k ON k.id_pengetahuan = p.id_pengetahuan
            WHERE p.status = 'Aktif' AND t.untuk_peran IN (?, 'Semua') AND k.kata_kunci IN ($ph)
            GROUP BY p.id_pengetahuan, p.id_topik, p.contoh_pertanyaan, p.jawaban, p.jenis_jawaban,
                     p.kunci_data, p.id_artikel_kms, t.nama_topik
            ORDER BY skor DESC, p.id_pengetahuan ASC
            LIMIT 1";
    $hasil = es_rows($es, $sql, str_repeat('s', count($kata) + 1), array_merge([$peran], $kata));
    if ($hasil && (float) $hasil[0]['skor'] >= ES_AMBANG) {
        return $hasil[0];
    }
    return null;
}

/** Ganti tanda {nama} pada template dengan nilai yang diberikan. */
function es_isi($template, array $nilai) {
    $peta = [];
    foreach ($nilai as $k => $v) {
        $peta['{' . $k . '}'] = (string) $v;
    }
    return strtr($template, $peta);
}

/** Cari produk yang disebut di pertanyaan (maksimal 5 produk dengan kecocokan terbaik). */
function es_cari_produk($es, array $kata) {
    $kata = array_values(array_filter($kata, function ($k) {
        return strlen($k) >= 3 && !in_array($k, ES_KATA_UMUM, true);
    }));
    if (!$kata) {
        return [];
    }
    $skor = [];
    foreach (es_rows($es, "SELECT product_id, nama_produk, harga, stok FROM mawarkuntum.products") as $p) {
        $kataNama = es_kata($p['nama_produk']);
        $s = 0;
        foreach ($kata as $k) {
            foreach ($kataNama as $n) {
                $mirip = (strlen($k) >= 5 && strlen($n) >= 5 && (strpos($n, $k) === 0 || strpos($k, $n) === 0));
                if ($n === $k || $mirip) {
                    $s++;
                    break;
                }
            }
        }
        if ($s > 0) {
            $skor[] = ['skor' => $s, 'produk' => $p];
        }
    }
    if (!$skor) {
        return [];
    }
    usort($skor, function ($a, $b) {
        return $b['skor'] <=> $a['skor'];
    });
    $terbaik = $skor[0]['skor'];
    $hasil = [];
    foreach ($skor as $x) {
        if ($x['skor'] === $terbaik && count($hasil) < 5) {
            $hasil[] = $x['produk'];
        }
    }
    return $hasil;
}

/** Isi jawaban berjenis Dinamis dari data sistem lain. Selalu mengembalikan teks. */
function es_jawab_dinamis($es, array $p, $peran, array $kata, $user_id) {
    $kunci = $p['kunci_data'] ?? '';
    $tpl   = $p['jawaban'];

    if (!isset(ES_KUNCI_DINAMIS[$kunci])) {
        return 'Jawaban untuk pertanyaan ini belum bisa ditampilkan.';
    }
    $boleh = ES_KUNCI_DINAMIS[$kunci][1];
    if ($boleh !== 'Semua' && $boleh !== $peran) {
        return 'Maaf, informasi ini tidak tersedia untuk akun Anda.';
    }

    switch ($kunci) {
        case 'STOK_PRODUK':
        case 'HARGA_PRODUK':
            $cocok = es_cari_produk($es, $kata);
            if (!$cocok) {
                $contoh = ($kunci === 'STOK_PRODUK') ? 'stok lipstik' : 'harga lipstik';
                return 'Produk apa yang Anda maksud? Sebutkan nama produknya, misalnya "' . $contoh . '".';
            }
            $baris = [];
            foreach ($cocok as $pr) {
                $baris[] = es_isi($tpl, ['nama_produk' => $pr['nama_produk'], 'stok' => (int) $pr['stok'], 'harga' => es_rp($pr['harga'])]);
            }
            return implode("\n", $baris);

        case 'PRODUK_TERLARIS':
        case 'PRODUK_TERLARIS_MANAJER':
            $per = es_rows($es, "SELECT MAX(periode) AS p FROM mawarkuntum.mis_penjualan_produk");
            $periode = $per[0]['p'] ?? null;
            if (!$periode) {
                return 'Data penjualan belum tersedia.';
            }
            $rows = es_rows($es, "SELECT p.nama_produk, m.qty_terjual, m.pendapatan
                                  FROM mawarkuntum.mis_penjualan_produk m
                                  JOIN mawarkuntum.products p ON p.product_id = m.product_id
                                  WHERE m.periode = ?
                                  ORDER BY m.qty_terjual DESC, m.pendapatan DESC LIMIT 3", 's', [$periode]);
            $d = [];
            foreach ($rows as $r) {
                $d[] = $r['nama_produk'] . ' (' . (int) $r['qty_terjual'] . ' terjual'
                     . ($peran === 'Manajer' ? ', ' . es_rp($r['pendapatan']) : '') . ')';
            }
            return $d ? es_isi($tpl, ['periode' => es_label_periode($periode), 'daftar_produk' => implode(', ', $d)])
                      : 'Data penjualan belum tersedia.';

        case 'STATUS_PESANAN':
            $r = es_rows($es, "SELECT order_id, status_pesanan FROM mawarkuntum.orders
                               WHERE user_id = ? ORDER BY order_id DESC LIMIT 1", 'i', [(int) $user_id]);
            if (!$r) {
                return 'Anda belum memiliki pesanan. Silakan pilih produk di katalog lalu lakukan checkout.';
            }
            return es_isi($tpl, ['order_id' => (int) $r[0]['order_id'], 'status_pesanan' => $r[0]['status_pesanan']]);

        case 'PENJUALAN_BULAN_INI':
            $r = es_rows($es, "SELECT DATE_FORMAT(MAX(tanggal), '%Y-%m-01') AS periode, MAX(tanggal) AS terakhir,
                                      SUM(total_pendapatan) AS total, SUM(jumlah_pesanan) AS pesanan
                               FROM mawarkuntum.mis_penjualan_harian
                               WHERE DATE_FORMAT(tanggal, '%Y-%m') = (SELECT DATE_FORMAT(MAX(tanggal), '%Y-%m') FROM mawarkuntum.mis_penjualan_harian)");
            if (!$r || !$r[0]['periode']) {
                return 'Data penjualan belum tersedia. Buka Dashboard MIS terlebih dahulu agar datanya terisi.';
            }
            return es_isi($tpl, ['periode' => es_label_periode($r[0]['periode']), 'total_pendapatan' => es_rp($r[0]['total']),
                                 'jumlah_pesanan' => (int) $r[0]['pesanan']])
                 . ' (data MIS sampai ' . date('d/m/Y', strtotime($r[0]['terakhir'])) . ')';

        case 'KINERJA_TUGAS':
            $r = es_rows($es, "SELECT periode, SUM(total_tugas) AS total, SUM(tugas_selesai) AS selesai, SUM(tugas_tertunda) AS tertunda
                               FROM mawarkuntum.mis_kinerja_tugas
                               WHERE periode = (SELECT MAX(periode) FROM mawarkuntum.mis_kinerja_tugas)
                               GROUP BY periode");
            if (!$r) {
                return 'Data kinerja tugas belum tersedia.';
            }
            $total = (int) $r[0]['total'];
            $persen = $total > 0 ? round($r[0]['selesai'] / $total * 100) : 0;
            return es_isi($tpl, ['periode' => es_label_periode($r[0]['periode']), 'total_tugas' => $total,
                                 'tugas_selesai' => (int) $r[0]['selesai'], 'tugas_tertunda' => (int) $r[0]['tertunda'],
                                 'persen_selesai' => $persen . '%']);

        case 'STATUS_DOKUMEN':
            $r = es_rows($es, "SELECT periode, total_dokumen, jumlah_pending, jumlah_approved, jumlah_rejected
                               FROM mawarkuntum.mis_ringkasan_dokumen ORDER BY periode DESC LIMIT 1");
            if (!$r) {
                return 'Data dokumen belum tersedia.';
            }
            return es_isi($tpl, ['periode' => es_label_periode($r[0]['periode']), 'total_dokumen' => (int) $r[0]['total_dokumen'],
                                 'dokumen_pending' => (int) $r[0]['jumlah_pending'], 'dokumen_approved' => (int) $r[0]['jumlah_approved'],
                                 'dokumen_rejected' => (int) $r[0]['jumlah_rejected']]);

        case 'ARTIKEL_POPULER':
            $rows = es_rows($es, "SELECT periode, judul_artikel, jumlah_dibaca FROM mawarkuntum.mis_penggunaan_kms
                                  WHERE periode = (SELECT MAX(periode) FROM mawarkuntum.mis_penggunaan_kms)
                                  ORDER BY jumlah_dibaca DESC LIMIT 3");
            if (!$rows) {
                return 'Data penggunaan artikel KMS belum tersedia.';
            }
            $d = [];
            foreach ($rows as $r) {
                $d[] = $r['judul_artikel'] . ' (' . (int) $r['jumlah_dibaca'] . ' kali dibaca)';
            }
            return es_isi($tpl, ['periode' => es_label_periode($rows[0]['periode']), 'daftar_artikel' => implode(', ', $d)]);

        case 'STOK_MENIPIS':
            $rows = es_rows($es, "SELECT nama_produk, stok FROM mawarkuntum.products WHERE stok < ?
                                  ORDER BY stok ASC, nama_produk ASC LIMIT 8", 'i', [ES_STOK_MINIMUM]);
            if (!$rows) {
                return 'Semua produk stoknya masih aman (di atas ' . ES_STOK_MINIMUM . ' pcs).';
            }
            $jml = es_rows($es, "SELECT COUNT(*) AS n FROM mawarkuntum.products WHERE stok < ?", 'i', [ES_STOK_MINIMUM]);
            $d = [];
            foreach ($rows as $r) {
                $d[] = $r['nama_produk'] . ' (' . ((int) $r['stok'] === 0 ? 'habis' : (int) $r['stok'] . ' pcs') . ')';
            }
            $lain = (int) ($jml[0]['n'] ?? 0) - count($rows);
            return es_isi($tpl, ['daftar_produk' => implode(', ', $d) . ($lain > 0 ? ', dan ' . $lain . ' produk lainnya' : '')]);

        case 'REKOMENDASI_RESTOCK':
            $per = es_rows($es, "SELECT MAX(periode) AS p FROM mawarkuntum_dss.dss_rekomendasi");
            if (empty($per[0]['p'])) {
                return 'Belum ada hasil analisis DSS. Buka Dashboard DSS lalu klik Hitung Ulang.';
            }
            $rows = es_rows($es, "SELECT p.nama_produk, r.qty_disarankan, r.aksi
                                  FROM mawarkuntum_dss.dss_rekomendasi r
                                  JOIN mawarkuntum.products p ON p.product_id = r.product_id
                                  WHERE r.periode = ? AND r.aksi IN ('Restock Segera', 'Restock')
                                  ORDER BY FIELD(r.aksi, 'Restock Segera', 'Restock'), r.peringkat LIMIT 8", 's', [$per[0]['p']]);
            if (!$rows) {
                return 'Tidak ada produk yang perlu restock saat ini berdasarkan analisis DSS ' . es_label_periode($per[0]['p']) . '.';
            }
            $d = [];
            foreach ($rows as $r) {
                $d[] = $r['nama_produk'] . ' (' . (int) $r['qty_disarankan'] . ' pcs, ' . $r['aksi'] . ')';
            }
            return es_isi($tpl, ['periode' => es_label_periode($per[0]['p']), 'daftar_produk' => implode(', ', $d)]);

        case 'BIAYA_RESTOCK':
            $r = es_rows($es, "SELECT COALESCE(SUM(r.estimasi_biaya), 0) AS total
                               FROM mawarkuntum_dss.dss_rekomendasi r
                               WHERE r.periode = (SELECT MAX(periode) FROM mawarkuntum_dss.dss_rekomendasi)
                                 AND r.aksi IN ('Restock Segera', 'Restock')
                                 AND NOT EXISTS (SELECT 1 FROM mawarkuntum_dss.dss_keputusan k
                                                 WHERE k.id_rekomendasi = r.id_rekomendasi
                                                   AND k.keputusan IN ('Disetujui', 'Ditolak'))");
            return es_isi($tpl, ['total_biaya' => es_rp($r[0]['total'] ?? 0)]);

        case 'DAFTAR_SUPPLIER':
            $rows = es_rows($es, "SELECT nama_supplier, skor_ketepatan FROM mawarkuntum_dss.dss_supplier
                                  WHERE status = 'Aktif' ORDER BY skor_ketepatan DESC, nama_supplier ASC LIMIT 10");
            if (!$rows) {
                return 'Belum ada supplier aktif.';
            }
            $d = [];
            foreach ($rows as $r) {
                $d[] = $r['nama_supplier'] . ' (ketepatan ' . (float) $r['skor_ketepatan'] . '%)';
            }
            return es_isi($tpl, ['daftar_supplier' => implode(', ', $d)]);
    }
    return 'Jawaban untuk pertanyaan ini belum bisa ditampilkan.';
}

/**
 * Proses satu pertanyaan.
 * Hasil: cocok (bool), jawaban (teks), id_pengetahuan, skor, topik, id_artikel_kms
 */
function es_proses($es, $peran, $user_id, $teks) {
    $sin  = es_muat_sinonim($es);
    $kata = es_kata($teks, $sin);
    $p    = es_cocokkan($es, $peran, $kata);

    if (!$p) {
        return ['cocok' => false, 'jawaban' => ES_JAWABAN_CADANGAN, 'id_pengetahuan' => null,
                'skor' => 0, 'topik' => null, 'id_artikel_kms' => null];
    }
    $jawaban = ($p['jenis_jawaban'] === 'Dinamis')
        ? es_jawab_dinamis($es, $p, $peran, $kata, $user_id)
        : $p['jawaban'];

    return ['cocok' => true, 'jawaban' => $jawaban, 'id_pengetahuan' => (int) $p['id_pengetahuan'],
            'skor' => (float) $p['skor'], 'topik' => $p['nama_topik'],
            'id_artikel_kms' => $p['id_artikel_kms'] !== null ? (int) $p['id_artikel_kms'] : null];
}

/**
 * Ubah teks "kata, kata:3, kata" menjadi [kata => bobot].
 * Tanpa angka: bobot 3 bila hanya ada satu kata kunci, selain itu 2.
 */
function es_parse_kata_kunci($teks) {
    $item = preg_split('/[,\n]+/', mb_strtolower((string) $teks, 'UTF-8'));
    $hasil = [];
    foreach ($item as $i) {
        $i = trim($i);
        if ($i === '') {
            continue;
        }
        $bagian = explode(':', $i, 2);
        $kata = preg_replace('/[^a-z0-9]/', '', $bagian[0]);
        if (strlen($kata) < 2 || strlen($kata) > 50) {
            continue;
        }
        $bobot = isset($bagian[1]) && ctype_digit(trim($bagian[1])) ? (int) trim($bagian[1]) : 0;
        $hasil[$kata] = $bobot;
    }
    $default = (count($hasil) === 1) ? 3 : 2;
    foreach ($hasil as $k => $b) {
        $hasil[$k] = ($b >= 1) ? min(5, $b) : $default;
    }
    return $hasil;
}

/** Saran kata kunci dari sebuah pertanyaan: kata penting tanpa kata umum. */
function es_saran_kata_kunci($pertanyaan, $sinonim = []) {
    $hasil = [];
    foreach (es_kata($pertanyaan, $sinonim) as $k) {
        if (strlen($k) >= 3 && !in_array($k, ES_KATA_UMUM, true)) {
            $hasil[] = $k;
        }
    }
    return array_slice($hasil, 0, 6);
}