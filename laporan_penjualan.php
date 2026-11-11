<?php
session_start();
include 'koneksi.php';

// Keamanan RBAC: Manager dan Supervisor Operasional yang boleh akses
if(!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Manager', 'Supervisor Operasional'], true)) {
    header("Location: login.php");
    exit();
}

// Default filter: Bulan dan Tahun saat ini
$selected_month = isset($_GET['bulan']) ? max(1, min(12, intval($_GET['bulan']))) : intval(date('m'));
$selected_year  = isset($_GET['tahun']) ? intval($_GET['tahun']) : intval(date('Y'));

// Query Laporan Penjualan TPS Berdasarkan Bulan & Tahun
$query_str = "SELECT * FROM orders 
              WHERE MONTH(tanggal_transaksi) = $selected_month 
              AND YEAR(tanggal_transaksi) = $selected_year 
              ORDER BY order_id DESC";
$orders = mysqli_query($conn, $query_str);

// Kalkulasi Total Bulanan
$total_omset = 0;
$total_transaksi = mysqli_num_rows($orders);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Laporan Penjualan Bulanan - Executive Manager</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 20px; color: #333; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #880e4f; padding-bottom: 15px; margin-bottom: 20px; }
        .header h2 { color: #880e4f; margin: 0; }
        .filter-box { background: #fce4ec; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; }
        .card-summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .card { background: #fff3e0; padding: 15px; border-radius: 8px; text-align: center; border-left: 5px solid #ff9800; }
        .card h4 { margin: 0 0 5px; color: #e65100; font-size: 13px; }
        .card p { margin: 0; font-size: 20px; font-weight: bold; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #880e4f; color: white; }
        .btn { background: #880e4f; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: bold; }
        .btn-print { background: #2e7d32; }
        
        /* Layout Khusus Mode Cetak / Print PDF */
        @media print {
            .filter-box, .btn-back, .btn-print { display: none; }
            body { background: white; padding: 0; }
            .container { box-shadow: none; max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h2>📊 Laporan Penjualan Bulanan (TPS)</h2>
                <small>Toko Cosmetics Mawarkuntum</small>
            </div>
            <div>
                <a href="dashboard_manager.php" class="btn btn-back">⬅ Kembali</a>
                <button onclick="window.print()" class="btn btn-print">🖨️ Cetak Laporan</button>
            </div>
        </div>

        <!-- Filter Periode -->
        <form method="GET" class="filter-box">
            <label><b>Pilih Periode:</b></label>
            <select name="bulan" style="padding: 6px; border-radius: 4px;">
                <?php
                $nama_bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                for($m=1; $m<=12; $m++) {
                    $selected = ($m == $selected_month) ? 'selected' : '';
                    echo "<option value='$m' $selected>{$nama_bulan[$m]}</option>";
                }
                ?>
            </select>
            <select name="tahun" style="padding: 6px; border-radius: 4px;">
                <?php
                for($y=2024; $y<=2026; $y++) {
                    $selected = ($y == $selected_year) ? 'selected' : '';
                    echo "<option value='$y' $selected>$y</option>";
                }
                ?>
            </select>
            <button type="submit" class="btn">Tampilkan</button>
        </form>

        <?php 
        // Hitung total omset dari hasil query
        $temp_orders = mysqli_query($conn, $query_str);
        while($r = mysqli_fetch_assoc($temp_orders)) { $total_omset += $r['total_harga']; }
        $estimasi_laba = $total_omset * 0.25; // Asumsi Margin Laba 25%
        ?>

        <!-- Stat Ringkasan Bulanan -->
        <div class="card-summary">
            <div class="card">
                <h4>TOTAL TRANSAKSI</h4>
                <p><?php echo $total_transaksi; ?> Pesanan</p>
            </div>
            <div class="card" style="border-left-color: #2e7d32; background: #e8f5e9;">
                <h4 style="color: #2e7d32;">TOTAL OMSET</h4>
                <p>Rp <?php echo number_format($total_omset, 0, ',', '.'); ?></p>
            </div>
            <div class="card" style="border-left-color: #0288d1; background: #e1f5fe;">
                <h4 style="color: #0288d1;">ESTIMASI LABA BERSIH (25%)</h4>
                <p>Rp <?php echo number_format($estimasi_laba, 0, ',', '.'); ?></p>
            </div>
        </div>

        <!-- Tabel Rincian -->
        <table>
            <thead>
                <tr>
                    <th>ID Order</th>
                    <th>Penerima</th>
                    <th>Metode Pembayaran</th>
                    <th>Total Pembayaran</th>
                    <th>Status Pesanan</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if(mysqli_num_rows($orders) > 0) {
                    while($row = mysqli_fetch_assoc($orders)) {
                        $st = $row['status_pesanan'] ?? 'Pending';
                        echo "<tr>
                            <td>#{$row['order_id']}</td>
                            <td>".htmlspecialchars($row['nama_penerima'] ?? 'Pelanggan')."</td>
                            <td>".htmlspecialchars($row['metode_pembayaran'] ?? 'COD')."</td>
                            <td>Rp ".number_format($row['total_harga'], 0, ',', '.')."</td>
                            <td><b>".htmlspecialchars($st)."</b></td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' style='text-align:center;'>Tidak ada data transaksi pada bulan ini.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</body>
</html>