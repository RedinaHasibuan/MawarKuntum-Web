-- Jalankan pada database mawarkuntum_es melalui phpMyAdmin.
-- Menambahkan satu basis pengetahuan khusus yang dicatat ke riwayat ES.
USE `mawarkuntum_es`;

INSERT INTO `es_pengetahuan`
  (`id_topik`, `contoh_pertanyaan`, `jawaban`, `jenis_jawaban`, `kunci_data`, `status`)
SELECT
  3,
  'Kulit aku berminyak, rekomendasi produk yang bagus untuk aku beli?',
  'Berikut produk yang sudah ditandai cocok untuk kulit {jenis_kulit} di katalog Mawarkuntum.',
  'Dinamis',
  'REKOMENDASI_PRODUK_KULIT',
  'Aktif'
WHERE NOT EXISTS (
  SELECT 1 FROM `es_pengetahuan` WHERE `kunci_data` = 'REKOMENDASI_PRODUK_KULIT'
);

SET @id_pengetahuan_rekomendasi = (
  SELECT `id_pengetahuan` FROM `es_pengetahuan`
  WHERE `kunci_data` = 'REKOMENDASI_PRODUK_KULIT'
  ORDER BY `id_pengetahuan` ASC LIMIT 1
);

INSERT INTO `es_kata_kunci` (`id_pengetahuan`, `kata_kunci`, `bobot`)
SELECT @id_pengetahuan_rekomendasi, data_kata.kata_kunci, data_kata.bobot
FROM (
  SELECT 'rekomendasi' AS kata_kunci, 3 AS bobot UNION ALL
  SELECT 'rekomend', 3 UNION ALL
  SELECT 'cocok', 2 UNION ALL
  SELECT 'kulit', 1 UNION ALL
  SELECT 'beli', 2 UNION ALL
  SELECT 'bagus', 1 UNION ALL
  SELECT 'saran', 2 UNION ALL
  SELECT 'sarankan', 2
) AS data_kata
WHERE @id_pengetahuan_rekomendasi IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `es_kata_kunci` k
    WHERE k.`id_pengetahuan` = @id_pengetahuan_rekomendasi
      AND k.`kata_kunci` = data_kata.kata_kunci
  );

UPDATE `es_topik`
SET `deskripsi` = 'Rekomendasi produk katalog berdasarkan jenis kulit, jenis produk, finish, dan karakteristik'
WHERE `id_topik` = 3;
