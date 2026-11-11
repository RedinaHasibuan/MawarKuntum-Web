<?php
session_start();
include 'koneksi.php';

// Keamanan: Wajib Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 1. Logika Update Kuantitas (Tambah/Kurang)
if (isset($_POST['action_qty'])) {
    $id_p = intval($_POST['id_produk']);
    $action = $_POST['action_qty'];

    if ($action == 'plus') {
        $_SESSION['cart'][$id_p] += 1;
    } elseif ($action == 'minus') {
        $_SESSION['cart'][$id_p] -= 1;
        if ($_SESSION['cart'][$id_p] <= 0) {
            unset($_SESSION['cart'][$id_p]); // Hapus dari keranjang jika 0
        }
    }
    header("Location: keranjang.php");
    exit;
}

// 2. Logika Hapus Produk
if (isset($_GET['hapus'])) {
    $id_p = intval($_GET['hapus']);
    unset($_SESSION['cart'][$id_p]);
    header("Location: keranjang.php");
    exit;
}

// Ambil data produk di dalam keranjang
$cart_items = [];
$total_bayar = 0;

if (!empty($_SESSION['cart'])) {
    $ids = implode(',', array_keys($_SESSION['cart']));
    $query = mysqli_query($conn, "SELECT * FROM products WHERE product_id IN ($ids)");
    while ($row = mysqli_fetch_assoc($query)) {
        $p_id = $row['product_id'];
        $qty = $_SESSION['cart'][$p_id];
        $subtotal = $row['harga'] * $qty;
        $total_bayar += $subtotal;

        $cart_items[] = [
            'id' => $p_id,
            'nama' => $row['nama_produk'],
            'harga' => $row['harga'],
            'qty' => $qty,
            'subtotal' => $subtotal
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Keranjang Belanja - Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #fce4ec; padding: 20px; color: #880e4f; margin: 0; }
        .container { max-width: 650px; margin: 20px auto; background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h2 { text-align: center; color: #d81b60; margin-top: 0; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #f8bbd0; color: #880e4f; padding: 10px; text-align: left; }
        td { padding: 12px 10px; border-bottom: 1px solid #f8bbd0; vertical-align: middle; }
        .total-row { background-color: #f8bbd0; font-weight: bold; }
        
        /* Control Qty */
        .qty-control { display: flex; align-items: center; gap: 6px; }
        .btn-qty { background: #fce4ec; color: #d81b60; border: 1px solid #f8bbd0; border-radius: 4px; width: 26px; height: 26px; font-weight: bold; cursor: pointer; }
        .btn-qty:hover { background: #f8bbd0; }
        .btn-hapus { color: #e57373; text-decoration: none; font-size: 16px; margin-left: 8px; }
        .btn-hapus:hover { color: #d32f2f; }

        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #880e4f; }
        input[type="text"], textarea, select { width: 100%; padding: 10px; border: 1px solid #f8bbd0; border-radius: 8px; box-sizing: border-box; }
        
        .btn-submit { background-color: #66bb6a; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: bold; width: 100%; font-size: 16px; cursor: pointer; transition: 0.2s; }
        .btn-submit:hover { background-color: #4caf50; }
        .btn-back { display: inline-block; margin-bottom: 15px; color: #d81b60; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <a href="katalog.php" class="btn-back">← Kembali ke Katalog</a>
    <h2>🛍️ Keranjang Belanja</h2>

    <?php if (empty($cart_items)): ?>
        <p style="text-align: center; color: #888; padding: 30px 0;">Keranjang Anda masih kosong.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Produk</th>
                    <th style="text-align: center;">Jml</th>
                    <th style="text-align: right;">Subtotal</th>
                    <th style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cart_items as $item): ?>
                    <tr>
                        <td><b><?php echo htmlspecialchars($item['nama']); ?></b></td>
                        <td align="center">
                            <div class="qty-control">
                                <!-- Tombol Kurang (-) -->
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="id_produk" value="<?php echo $item['id']; ?>">
                                    <button type="submit" name="action_qty" value="minus" class="btn-qty">-</button>
                                </form>
                                
                                <span><b><?php echo $item['qty']; ?></b></span>

                                <!-- Tombol Tambah (+) -->
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="id_produk" value="<?php echo $item['id']; ?>">
                                    <button type="submit" name="action_qty" value="plus" class="btn-qty">+</button>
                                </form>
                            </div>
                        </td>
                        <td align="right">Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></td>
                        <td align="center">
                            <a href="keranjang.php?hapus=<?php echo $item['id']; ?>" class="btn-hapus" onclick="return confirm('Hapus item ini dari keranjang?')" title="Hapus Item">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">TOTAL BAYAR:</td>
                    <td align="right" colspan="2">Rp <?php echo number_format($total_bayar, 0, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Form Pengisian Checkout -->
        <form method="POST" action="checkout.php">
            <div class="form-group">
                <label>Nama Penerima:</label>
                <input type="text" name="nama_penerima" required placeholder="Nama Lengkap">
            </div>
            
            <div class="form-group">
                <label>Nomor HP/WA:</label>
                <input type="text" name="nohp" required placeholder="08xxxxxxxxxx">
            </div>

            <div class="form-group">
                <label>Alamat Lengkap:</label>
                <textarea name="alamat" rows="3" required placeholder="Jl. Raya No..., Kota, Kode Pos"></textarea>
            </div>

            <div class="form-group">
                <label>Metode Pembayaran:</label>
                <select name="metode_pembayaran" required>
                    <option value="">-- Pilih --</option>
                    <option value="COD (Bayar di Tempat)">COD (Bayar di Tempat)</option>
                    <option value="Transfer Bank">Transfer Bank</option>
                    <option value="E-Wallet">E-Wallet (OVO/Dana/Gopay)</option>
                </select>
            </div>

            <button type="submit" class="btn-submit">Bayar Sekarang & Checkout ✓</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>