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
    'REKOMENDASI_PRODUK_KULIT'=> ['Rekomendasi produk berdasarkan jenis kulit (TPS)', 'Pelanggan'],
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


/** Deteksi apakah pengguna sedang meminta rekomendasi produk. */
function es_intent_rekomendasi_produk($teks) {
    $t = (string) $teks;
    if (preg_match('/\b(rekomendasi|rekomend|saran|sarankan|cocok|beli|membeli|dibeli|pilih)\b/ui', $t)) {
        return true;
    }
    // Variasi bahasa alami: "aku butuh cushion" atau "carikan produk untuk kulit berminyak".
    $adaKeinginan = preg_match('/\b(butuh|cari|carikan|mencari|ingin|mau)\b/ui', $t);
    $adaKonteksProduk = preg_match('/\b(produk|makeup|kosmetik|skincare|cushion|setting\s+spray|foundation|concealer|bedak|primer|blush|highlighter|lipstick|lipstik|lip\s+tint|mascara|maskara|eyeshadow|eyeliner|sunscreen|serum|moisturizer|toner)\b/ui', $t);
    $adaJenisKulit = preg_match('/\b(normal|berminyak|minyak|oily|kering|dry|kombinasi|combination)\b/ui', $t);
    return (bool) (($adaKeinginan && $adaKonteksProduk) || ($adaKonteksProduk && $adaJenisKulit));
}

/** Ambil jenis kulit yang disebut pengguna. Mengembalikan label yang dipakai tabel atribut produk. */
function es_deteksi_jenis_kulit_rekomendasi($teks) {
    $t = mb_strtolower((string) $teks, 'UTF-8');
    if (preg_match('/\b(kombinasi|combination|combination skin)\b/u', $t)) return 'Kombinasi';
    if (preg_match('/\b(berminyak|minyak|oily)\b/u', $t)) {
        if (preg_match('/\b(tidak|bukan|kurang)\s+(?:terlalu\s+)?(?:berminyak|minyak|oily)\b/u', $t)) return null;
        return 'Berminyak';
    }
    if (preg_match('/\b(kering|dry)\b/u', $t)) return 'Kering';
    if (preg_match('/\b(normal)\b/u', $t)) return 'Normal';
    return null;
}

/** Deteksi finish hanya jika pelanggan memang menyebutkannya. */
function es_deteksi_finish_rekomendasi($teks) {
    $t = mb_strtolower((string) $teks, 'UTF-8');
    $map = [
        'Matte' => ['matte', 'mat'],
        'Dewy' => ['dewy', 'lembap bercahaya'],
        'Satin' => ['satin'],
        'Natural' => ['natural finish', 'finish natural', 'hasil natural', 'tampilan natural', 'natural look', 'look natural'],
        'Glowy' => ['glowy finish', 'finish glowy', 'glowing finish', 'finish glowing', 'tampilan glowing', 'look glowing'],
    ];
    foreach ($map as $label => $kata) {
        foreach ($kata as $k) {
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/u', $t)) return $label;
        }
    }
    return null;
}

/** Deteksi jenis produk opsional, misalnya cushion atau setting spray. */
function es_deteksi_jenis_produk_rekomendasi($teks) {
    $t = mb_strtolower((string) $teks, 'UTF-8');
    $map = [
        'Setting Spray' => ['setting spray'],
        'Cushion' => ['cushion'],
        'Foundation' => ['foundation'],
        'Concealer' => ['concealer'],
        'Bedak' => ['bedak', 'powder'],
        'Primer' => ['primer'],
        'Blush On' => ['blush on', 'blush'],
        'Highlighter' => ['highlighter'],
        'Contour' => ['contour', 'kontur'],
        'Lip Tint' => ['lip tint', 'liptint'],
        'Lipstick' => ['lipstick', 'lipstik'],
        'Lip Gloss' => ['lip gloss'],
        'Mascara' => ['mascara', 'maskara'],
        'Eyeshadow Palette' => ['eyeshadow', 'eye shadow'],
        'Pensil Alis' => ['pensil alis', 'eyebrow'],
        'Eyeliner' => ['eyeliner'],
        'Micellar Water' => ['micellar water', 'micellar'],
        'Facial Wash' => ['facial wash', 'sabun muka', 'facial cleanser'],
        'Toner' => ['toner'],
        'Serum' => ['serum'],
        'Moisturizer' => ['moisturizer', 'pelembap'],
        'Sunscreen' => ['sunscreen', 'sunblock', 'tabir surya'],
    ];
    foreach ($map as $label => $kata) {
        foreach ($kata as $k) {
            if (strpos($t, $k) !== false) return $label;
        }
    }
    return null;
}

/** Deteksi satu karakteristik produk yang secara eksplisit diminta pelanggan. */
function es_deteksi_tag_rekomendasi($teks) {
    $t = mb_strtolower((string) $teks, 'UTF-8');
    $map = [
        'Oil Control' => ['oil control', 'mengontrol minyak', 'kontrol minyak', 'anti kilap'],
        'Lightweight' => ['lightweight', 'ringan', 'tidak berat'],
        'Hydrating' => ['hydrating', 'melembapkan', 'lembap', 'hidrasi'],
        'Long Lasting' => ['long lasting', 'tahan lama', 'awet seharian'],
        'Non-Comedogenic' => ['non-comedogenic', 'non comedogenic', 'tidak menyumbat pori'],
        'Waterproof' => ['waterproof', 'tahan air'],
        'Transferproof' => ['transferproof', 'anti transfer', 'tidak transfer'],
        'Fragrance-Free' => ['fragrance-free', 'fragrance free', 'tanpa pewangi'],
        'Coverage Ringan' => ['coverage ringan', 'light coverage'],
        'Coverage Medium' => ['coverage medium', 'medium coverage'],
        'Coverage Full' => ['coverage full', 'full coverage'],
    ];
    foreach ($map as $label => $kata) {
        foreach ($kata as $k) {
            if (strpos($t, $k) !== false) return $label;
        }
    }
    return null;
}

/** Periksa apakah tabel atribut rekomendasi sudah diimpor. */
function es_tabel_rekomendasi_siap($es) {
    $wajib = ['product_recommendation_profiles', 'product_profile_skin_types', 'product_profile_tags'];
    $ada = array_column(es_rows($es, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'mawarkuntum' AND table_name IN ('product_recommendation_profiles','product_profile_skin_types','product_profile_tags')"), 'table_name');
    return count(array_intersect($wajib, $ada)) === count($wajib);
}

/** Ambil produk yang sudah disetujui Staff Gudang untuk jenis kulit, finish, dan jenis produk tertentu. */
function es_cari_rekomendasi_produk_kulit($es, $jenisKulit, $finish = null, $jenisProduk = null, $tag = null) {
    if (!es_tabel_rekomendasi_siap($es)) return null;

    $sql = "SELECT p.product_id, p.nama_produk, p.kategori, p.gambar, p.harga, p.stok,
                   rp.jenis_produk, COALESCE(rp.finish, '') AS finish,
                   COALESCE(rp.catatan_rekomendasi, '') AS catatan_rekomendasi,
                   COALESCE(tag.tag_list, '') AS tags, COALESCE(skin.skin_list, '') AS jenis_kulit
            FROM mawarkuntum.products p
            JOIN mawarkuntum.product_recommendation_profiles rp ON rp.product_id = p.product_id
            JOIN (
                SELECT DISTINCT product_id
                FROM mawarkuntum.product_profile_skin_types
                WHERE jenis_kulit = ? OR jenis_kulit = 'Semua jenis kulit'
            ) cocok ON cocok.product_id = p.product_id
            LEFT JOIN (
                SELECT product_id, GROUP_CONCAT(nama_tag ORDER BY nama_tag SEPARATOR ', ') AS tag_list
                FROM mawarkuntum.product_profile_tags GROUP BY product_id
            ) tag ON tag.product_id = p.product_id
            LEFT JOIN (
                SELECT product_id, GROUP_CONCAT(jenis_kulit ORDER BY jenis_kulit SEPARATOR ', ') AS skin_list
                FROM mawarkuntum.product_profile_skin_types GROUP BY product_id
            ) skin ON skin.product_id = p.product_id
            WHERE rp.aktif = 1 AND p.stok > 0";
    $tipe = 's';
    $param = [$jenisKulit];
    if ($finish !== null) {
        $sql .= " AND rp.finish = ?";
        $tipe .= 's';
        $param[] = $finish;
    }
    if ($jenisProduk !== null) {
        $sql .= " AND rp.jenis_produk = ?";
        $tipe .= 's';
        $param[] = $jenisProduk;
    }
    if ($tag !== null) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM mawarkuntum.product_profile_tags filter_tag
            WHERE filter_tag.product_id = p.product_id AND filter_tag.nama_tag = ?
        )";
        $tipe .= 's';
        $param[] = $tag;
    }
    $sql .= " ORDER BY p.stok DESC, p.nama_produk ASC LIMIT 6";
    return es_rows($es, $sql, $tipe, $param);
}

/** Bangun hasil rekomendasi untuk percakapan baru maupun pemuatan ulang riwayat. */
function es_hasil_rekomendasi_produk($es, $teks) {
    if (!es_intent_rekomendasi_produk($teks)) return null;
    $jenisKulit = es_deteksi_jenis_kulit_rekomendasi($teks);
    if (!$jenisKulit) {
        $kb = es_rows($es, "SELECT id_pengetahuan FROM es_pengetahuan WHERE kunci_data = 'REKOMENDASI_PRODUK_KULIT' AND status = 'Aktif' ORDER BY id_pengetahuan ASC LIMIT 1");
        return [
            'cocok' => true,
            'jawaban' => 'Boleh tahu jenis kulitmu apa? Aku bisa mencarikan produk yang sudah ditandai cocok untuk kulit normal, berminyak, kering, atau kombinasi. Kamu juga boleh menyebutkan finish yang kamu suka, misalnya matte, dewy, atau satin.',
            'id_pengetahuan' => $kb ? (int) $kb[0]['id_pengetahuan'] : null,
            'skor' => 5,
            'topik' => 'Rekomendasi Produk',
            'id_artikel_kms' => null,
            'rekomendasi' => [],
        ];
    }

    $finish = es_deteksi_finish_rekomendasi($teks);
    $jenisProduk = es_deteksi_jenis_produk_rekomendasi($teks);
    $tag = es_deteksi_tag_rekomendasi($teks);
    $produk = es_cari_rekomendasi_produk_kulit($es, $jenisKulit, $finish, $jenisProduk, $tag);
    $kb = es_rows($es, "SELECT id_pengetahuan FROM es_pengetahuan WHERE kunci_data = 'REKOMENDASI_PRODUK_KULIT' AND status = 'Aktif' ORDER BY id_pengetahuan ASC LIMIT 1");
    $idPengetahuan = $kb ? (int) $kb[0]['id_pengetahuan'] : null;

    if ($produk === null) {
        $jawaban = 'Fitur rekomendasi produk belum diaktifkan pada database katalog. Silakan minta administrator menjalankan file migrasi produk rekomendasi terlebih dahulu.';
    } elseif (!$produk) {
        $filter = ' untuk kulit ' . $jenisKulit;
        if ($jenisProduk) $filter .= ' pada jenis produk ' . $jenisProduk;
        if ($finish) $filter .= ' dengan finish ' . $finish;
        if ($tag) $filter .= ' dengan karakteristik ' . $tag;
        $jawaban = 'Aku belum menemukan produk yang sudah ditandai cocok' . $filter . '. Staff Gudang perlu melengkapi kecocokan jenis kulit dan atribut produk pada data katalog terlebih dahulu. Aku tidak akan menyarankan produk yang belum memiliki data kecocokan.';
    } else {
        $filter = 'kulit ' . $jenisKulit;
        if ($jenisProduk) $filter .= ', jenis produk ' . $jenisProduk;
        if ($finish) $filter .= ', finish ' . $finish;
        if ($tag) $filter .= ', karakteristik ' . $tag;
        $jawaban = 'Berikut produk Mawarkuntum yang sudah ditandai cocok untuk ' . $filter . ' dan stoknya tersedia. Pilih produk sesuai kebutuhanmu, ya.';
    }

    return [
        'cocok' => true,
        'jawaban' => $jawaban,
        'id_pengetahuan' => $idPengetahuan,
        'skor' => 10,
        'topik' => 'Rekomendasi Produk',
        'id_artikel_kms' => null,
        'rekomendasi' => is_array($produk) ? $produk : [],
    ];
}

/**
 * Proses satu pertanyaan.
 * Hasil: cocok (bool), jawaban (teks), id_pengetahuan, skor, topik, id_artikel_kms
 */
function es_proses($es, $peran, $user_id, $teks) {
    // Jalur khusus rekomendasi: mengambil data aktual dari katalog, bukan jawaban statis.
    if ($peran === 'Pelanggan') {
        $hasilRekomendasi = es_hasil_rekomendasi_produk($es, $teks);
        if ($hasilRekomendasi !== null) return $hasilRekomendasi;
    }

    $sin  = es_muat_sinonim($es);
    $kata = es_kata($teks, $sin);
    $p    = es_cocokkan($es, $peran, $kata);

    if (!$p) {
        return ['cocok' => false, 'jawaban' => ES_JAWABAN_CADANGAN, 'id_pengetahuan' => null,
                'skor' => 0, 'topik' => null, 'id_artikel_kms' => null, 'rekomendasi' => []];
    }
    $jawaban = ($p['jenis_jawaban'] === 'Dinamis')
        ? es_jawab_dinamis($es, $p, $peran, $kata, $user_id)
        : $p['jawaban'];

    return ['cocok' => true, 'jawaban' => $jawaban, 'id_pengetahuan' => (int) $p['id_pengetahuan'],
            'skor' => (float) $p['skor'], 'topik' => $p['nama_topik'],
            'id_artikel_kms' => $p['id_artikel_kms'] !== null ? (int) $p['id_artikel_kms'] : null,
            'rekomendasi' => []];
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