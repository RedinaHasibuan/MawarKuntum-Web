<?php
session_start();
include 'koneksi.php';

// Pastikan pelanggan sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$q_orders = "SELECT * FROM orders WHERE user_id = '$user_id' ORDER BY order_id DESC";
$res_orders = mysqli_query($conn, $q_orders);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Riwayat Pesanan Saya - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; margin: 0; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #a5d6a7; padding-bottom: 12px; margin-bottom: 20px; }
        .header h2 { margin: 0; color: #2e7d32; font-size: 20px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        th { background: #e8f5e9; color: #1b5e20; font-weight: bold; }
        
        .badge { padding: 6px 12px; border-radius: 20px; font-weight: bold; font-size: 12px; display: inline-block; }
        .badge-proses { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
        .badge-kirim { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        
        .btn-back { background: #555; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; font-size: 13px; font-weight: bold; }
        .btn-back:hover { background: #333; }
    </style>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css">
</head>
<body>

    <div class="container">
        <div class="header">
            <h2>📦 Riwayat Pesanan Saya</h2>
            <a href="katalog.php" class="btn-back">← Kembali ke Katalog</a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID Order</th>
                    <th>Tanggal Transaksi</th>
                    <th>Penerima</th>
                    <th>Total Bayar</th>
                    <th>Status Pesanan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$res_orders || mysqli_num_rows($res_orders) == 0): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #888; padding: 20px;">Belum ada pesanan yang dibuat.</td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($res_orders)): 
                        $status = $row['status_pesanan'] ?? 'Diproses/Dikemas';
                        $badge_class = ($status == 'Dikirim') ? 'badge-kirim' : 'badge-proses';
                    ?>
                        <tr>
                            <td><b>#<?php echo $row['order_id']; ?></b></td>
                            <td><?php echo date('d M Y, H:i', strtotime($row['tanggal_transaksi'])); ?></td>
                            <td><?php echo htmlspecialchars($row['nama_penerima'] ?? '-'); ?></td>
                            <td><b>Rp <?php echo number_format($row['total_harga'], 0, ',', '.'); ?></b></td>
                            <td>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo ($status == 'Dikirim') ? '🚚 Dikirim' : '⏳ Diproses/Dikemas'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>