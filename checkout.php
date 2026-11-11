<?php
session_start();
include 'koneksi.php';

if(empty($_SESSION['cart']) || !isset($_POST['nama_penerima'])) { 
    die("<h2 style='text-align:center; margin-top:50px;'>Oops, keranjangmu masih kosong!</h2>"); 
}

$user_id = $_SESSION['user_id'];
$nama    = mysqli_real_escape_string($conn, $_POST['nama_penerima']);
$raw_telp = $_POST['no_telepon'] ?? $_POST['nohp'] ?? '-';
$telp    = mysqli_real_escape_string($conn, $raw_telp);
$alamat  = mysqli_real_escape_string($conn, $_POST['alamat']);
$metode  = mysqli_real_escape_string($conn, $_POST['metode_pembayaran']);

$total_harga = 0;
$stok_errors = [];

// 1. VALIDASI STOK SEBELUM TRANSAKSI DIPROSES
foreach($_SESSION['cart'] as $id => $jml) {
    $q = mysqli_query($conn, "SELECT nama_produk, harga, stok FROM products WHERE product_id = '$id'");
    $b = mysqli_fetch_assoc($q);
    
    if(!$b) {
        $stok_errors[] = "Produk tidak ditemukan di database.";
        continue;
    }

    // Cek apakah jumlah beli melebihi stok gudang
    if($jml > $b['stok']) {
        if($b['stok'] <= 0) {
            $stok_errors[] = "Stok <b>" . htmlspecialchars($b['nama_produk']) . "</b> sudah habis (0 pcs).";
        } else {
            $stok_errors[] = "Stok <b>" . htmlspecialchars($b['nama_produk']) . "</b> tersisa " . $b['stok'] . " pcs (Kamu memesan " . $jml . " pcs).";
        }
    }

    $total_harga += ($b['harga'] * $jml);
}

// 2. JIKA ADA STOK TIDAK CUKUP -> TAMPILKAN POPUP/PERINGATAN DAN BATALKAN TRANSAKSI
if(!empty($stok_errors)) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Gagal Checkout - Stok Tidak Cukup</title>
        <style>
            body { font-family: Arial, sans-serif; background-color: #ffebee; padding-top: 50px; display: flex; justify-content: center; }
            .error-card { background: white; width: 450px; padding: 25px; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.08); border-top: 10px solid #ef5350; text-align: center; }
            .error-card h2 { color: #c62828; margin-top: 0; font-size: 20px; }
            .error-list { text-align: left; background: #fff8f8; padding: 15px 20px; border-radius: 8px; border: 1px solid #ffcdd2; margin: 20px 0; color: #b71c1c; font-size: 14px; }
            .error-list ul { margin: 0; padding-left: 20px; }
            .error-list li { margin-bottom: 8px; }
            .btn-kembali { display: inline-block; padding: 12px 20px; background: #ef5350; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
            .btn-kembali:hover { background: #e53935; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <h2>⚠️ Pesanan Gagal Diproses</h2>
            <p style="color: #666; font-size: 14px;">Maaf, ada kendala pada jumlah stok barang yang ingin kamu beli:</p>
            
            <div class="error-list">
                <ul>
                    <?php foreach($stok_errors as $err) { echo "<li>$err</li>"; } ?>
                </ul>
            </div>
            
            <a href="keranjang.php" class="btn-kembali">← Kembali ke Keranjang</a>
        </div>
    </body>
    </html>
    <?php
    exit(); // Hentikan script agar tidak melakukan insert ke database
}

// 3. Simpan Transaksi TPS (Hanya berjalan jika semua stok aman)
mysqli_query($conn, "INSERT INTO orders (user_id, nama_penerima, no_telepon, alamat, metode_pembayaran, total_harga) 
VALUES ('$user_id', '$nama', '$telp', '$alamat', '$metode', '$total_harga')");
$order_id = mysqli_insert_id($conn);

// 4. OTOMATISASI OAS: Buat Tugas Baru untuk Staff Pesanan
$q_staff = mysqli_query($conn, "SELECT user_id FROM users WHERE id_sub_role = 4 LIMIT 1");
if($r_staff = mysqli_fetch_assoc($q_staff)) {
    $staff_id = $r_staff['user_id'];
    $judul_tugas = "Packing Pesanan Baru #" . $order_id;
    $deskripsi_tugas = "Segera kemas produk untuk penerima: " . $nama . " (Metode: " . $metode . ")";
    
    mysqli_query($conn, "INSERT INTO tasks (judul_tugas, deskripsi, dikerjakan_oleh, prioritas, status_tugas) 
                         VALUES ('$judul_tugas', '$deskripsi_tugas', '$staff_id', 'High', 'To-Do')");
}

// 5. Simpan Detail Produk & Potong Stok
foreach($_SESSION['cart'] as $id => $jml) {
    $q = mysqli_query($conn, "SELECT harga FROM products WHERE product_id = '$id'");
    $b = mysqli_fetch_assoc($q);
    $sub = $b['harga'] * $jml;
    
    mysqli_query($conn, "INSERT INTO order_details (order_id, product_id, jumlah, subtotal) VALUES ('$order_id', '$id', '$jml', '$sub')");
    mysqli_query($conn, "UPDATE products SET stok = stok - $jml WHERE product_id = '$id'");
}

unset($_SESSION['cart']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Status Pesanan - Mawarkuntum</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #e8f5e9; padding-top: 40px; display: flex; justify-content: center; }
        .receipt { background: white; width: 450px; padding: 25px; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.08); border-top: 10px solid #f48fb1; }
        .pesan-box { padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .pesan-box h2 { margin-top: 0; font-size: 22px; margin-bottom: 10px; }
        .pesan-box p { font-size: 15px; line-height: 1.5; margin: 0; }
        .tema-transfer { background-color: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .tema-ewallet { background-color: #f3e5f5; color: #7b1fa2; border: 1px solid #e1bee7; }
        .tema-cod { background-color: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
        .info-box { border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; background-color: #fafafa; }
        .info-row { margin-bottom: 8px; }
        .info-row span { font-weight: bold; color: #555; display: inline-block; width: 90px; }
        .total-box { background: #fce4ec; padding: 15px; text-align: center; font-size: 18px; font-weight: bold; color: #333; border-radius: 8px; border: 2px dashed #f8bbd0; }
        .nominal-harga { color: #d81b60; font-size: 26px; display: block; margin-top: 5px; }
        .btn-kembali { display: block; text-align: center; margin-top: 25px; padding: 12px; background: #66bb6a; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="receipt">
        <?php if (strpos($metode, 'Transfer') !== false) { ?>
            <div class="pesan-box tema-transfer">
                <h2>💳 Pembayaran Berhasil!</h2>
                <p>Terima kasih, pembayaranmu sudah kami verifikasi. Mohon menunggu, pesanan Anda sedang kami siapkan! 🌸</p>
            </div>
        <?php } elseif (strpos($metode, 'E-Wallet') !== false) { ?>
            <div class="pesan-box tema-ewallet">
                <h2>📱 Pesanan Berhasil Dibuat!</h2>
                <p>Silakan selesaikan pembayaran via E-Wallet ke <b>0812-3456-7890</b> (OVO/Dana/GoPay). Pesananmu akan segera diproses! ✨</p>
            </div>
        <?php } else { ?>
            <div class="pesan-box tema-cod">
                <h2>📦 Pesanan Berhasil Dibuat!</h2>
                <p>Pesananmu akan segera dikirim. <b>Mohon siapkan uang tunai pas</b> untuk kurir saat paket tiba! 🛵💨</p>
            </div>
        <?php } ?>
        
        <h3 style="color: #333; text-align: center; margin-bottom: 15px;">Rincian Struk #<?php echo $order_id; ?></h3>
        
        <div class="info-box">
            <div class="info-row"><span>Penerima</span>: <?php echo htmlspecialchars($nama); ?></div>
            <div class="info-row"><span>No. HP</span>: <?php echo htmlspecialchars($telp); ?></div>
            <div class="info-row"><span>Alamat</span>: <?php echo htmlspecialchars($alamat); ?></div>
            <div class="info-row"><span>Metode</span>: <b><?php echo htmlspecialchars($metode); ?></b></div>
        </div>
        
        <div class="total-box">
            Total Tagihan:
            <span class="nominal-harga">Rp <?php echo number_format($total_harga, 0, ',', '.'); ?></span>
        </div>
        
        <a href="katalog.php" class="btn-kembali">← Lanjut Belanja Skincare</a>
    </div>
</body>
</html>