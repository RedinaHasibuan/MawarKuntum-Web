-- Jalankan pada database mawarkuntum melalui phpMyAdmin.
-- Tidak mengubah tabel products, transaksi, stok, MIS, DSS, maupun KMS.
USE `mawarkuntum`;

CREATE TABLE IF NOT EXISTS `product_recommendation_profiles` (
  `product_id` int(11) NOT NULL,
  `jenis_produk` varchar(60) NOT NULL,
  `finish` varchar(40) DEFAULT NULL,
  `catatan_rekomendasi` varchar(255) DEFAULT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`),
  KEY `idx_rekomendasi_jenis_produk` (`jenis_produk`),
  KEY `idx_rekomendasi_finish` (`finish`),
  CONSTRAINT `fk_rekomendasi_profile_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_profile_skin_types` (
  `product_id` int(11) NOT NULL,
  `jenis_kulit` varchar(40) NOT NULL,
  PRIMARY KEY (`product_id`, `jenis_kulit`),
  KEY `idx_profile_skin_type` (`jenis_kulit`),
  CONSTRAINT `fk_profile_skin_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_profile_tags` (
  `product_id` int(11) NOT NULL,
  `nama_tag` varchar(60) NOT NULL,
  PRIMARY KEY (`product_id`, `nama_tag`),
  KEY `idx_profile_tag` (`nama_tag`),
  CONSTRAINT `fk_profile_tag_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Siapkan jenis produk untuk katalog yang saat ini sudah ada.
-- Finish dan kecocokan kulit TIDAK ditebak; Staff Gudang mengisinya saat mengedit produk.
INSERT IGNORE INTO `product_recommendation_profiles` (`product_id`, `jenis_produk`)
SELECT `product_id`,
  CASE
    WHEN LOWER(`nama_produk`) LIKE '%cushion%' THEN 'Cushion'
    WHEN LOWER(`nama_produk`) LIKE '%setting spray%' THEN 'Setting Spray'
    WHEN LOWER(`nama_produk`) LIKE '%lip tint%' THEN 'Lip Tint'
    WHEN LOWER(`nama_produk`) LIKE '%mascara%' THEN 'Mascara'
    WHEN LOWER(`nama_produk`) LIKE '%blush%' THEN 'Blush On'
    WHEN LOWER(`nama_produk`) LIKE '%highlighter%' THEN 'Highlighter'
    WHEN LOWER(`nama_produk`) LIKE '%eyeshadow%' THEN 'Eyeshadow Palette'
    WHEN LOWER(`nama_produk`) LIKE '%eyebrow%' THEN 'Pensil Alis'
    WHEN LOWER(`nama_produk`) LIKE '%micellar%' THEN 'Micellar Water'
    WHEN LOWER(`nama_produk`) LIKE '%eyeliner%' THEN 'Eyeliner'
    WHEN LOWER(`nama_produk`) LIKE '%contour%' THEN 'Contour'
    ELSE 'Lainnya'
  END
FROM `products`;
