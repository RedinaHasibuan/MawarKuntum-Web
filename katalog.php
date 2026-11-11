<?php 
session_start();
include 'koneksi.php'; 

// Keamanan RBAC: Wajib Login
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Logika Tambah Keranjang (Mendukung AJAX Async + Validasi Stok)
if(isset($_POST['add_to_cart'])) {
    $id_produk = intval($_POST['id_produk']);
    $jumlah_beli = intval($_POST['jumlah']);
    
    // Check stok terkini di database
    $q_stok = mysqli_query($conn, "SELECT stok, nama_produk FROM products WHERE product_id = '$id_produk'");
    $r_stok = mysqli_fetch_assoc($q_stok);

    if(!$r_stok) {
        $response = ['status' => 'error', 'message' => 'Produk tidak ditemukan.'];
    } else {
        $stok_db = intval($r_stok['stok']);
        $current_in_cart = isset($_SESSION['cart'][$id_produk]) ? $_SESSION['cart'][$id_produk] : 0;
        $total_minta = $current_in_cart + $jumlah_beli;

        if ($stok_db <= 0) {
            $response = ['status' => 'error', 'message' => 'Maaf, stok produk ini sudah habis!'];
        } elseif ($total_minta > $stok_db) {
            $response = [
                'status' => 'error', 
                'message' => 'Jumlah melebihi stok yang ada! Sisa stok: ' . $stok_db . ' pcs (Sudah ada ' . $current_in_cart . ' pcs di keranjang)'
            ];
        } else {
            $_SESSION['cart'][$id_produk] = $total_minta;
            $response = [
                'status' => 'success',
                'total_items' => array_sum($_SESSION['cart'])
            ];
        }
    }

    // Respon JSON khusus AJAX
    if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode($response);
        exit;
    }
    
    header("Location: katalog.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Katalog E-Commerce Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #e8f5e9; padding: 20px; color: #2e7d32; margin: 0; }
        
        .nav-header { display: flex; justify-content: space-between; align-items: center; max-width: 1000px; margin: 0 auto 20px; background: white; padding: 15px 20px; border-radius: 12px; border: 1px solid #f8bbd0; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
        .nav-header h2 { margin: 0; color: #d81b60; font-size: 22px; }
        .user-info { display: flex; align-items: center; gap: 12px; font-size: 14px; }
        
        .btn-orders { background-color: #2e7d32; color: white; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 13px; transition: 0.2s; }
        .btn-orders:hover { background-color: #1b5e20; }

        .btn-logout { background-color: #e57373; color: white; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 13px; transition: 0.2s; }
        .btn-logout:hover { background-color: #ef5350; }

        .katalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 25px; max-width: 1000px; margin: 0 auto 80px; }
        .produk-card { background: white; border-radius: 12px; padding: 15px; text-align: center; border: 1px solid #f8bbd0; box-shadow: 0 4px 8px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; }
        .produk-img { width: 100%; height: 200px; object-fit: cover; border-radius: 8px; margin-bottom: 15px; }
        .produk-nama { font-size: 16px; color: #2e7d32; height: 40px; margin: 0 0 10px; }
        .produk-harga { font-size: 18px; color: #d81b60; font-weight: bold; margin-bottom: 10px; }
        .stok-info { font-size: 12px; margin-bottom: 12px; color: #666; }
        .stok-habis { color: #e53935; font-weight: bold; background: #ffebee; padding: 6px; border-radius: 6px; margin-bottom: 10px; font-size: 13px; }

        .form-beli { display: flex; flex-direction: column; gap: 10px; align-items: center; }
        .qty-box { display: flex; align-items: center; border: 1px solid #f8bbd0; border-radius: 6px; overflow: hidden; }
        .btn-qty { background-color: #fce4ec; color: #d81b60; border: none; width: 32px; height: 32px; font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.2s; }
        .btn-qty:hover { background-color: #f8bbd0; }
        .input-qty { width: 45px; text-align: center; border: none; font-weight: bold; color: #2e7d32; outline: none; background: transparent; }
        
        .btn-beli { background-color: #f48fb1; color: white; padding: 10px 16px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; transition: 0.2s; }
        .btn-beli:hover { background-color: #f06292; }
        .btn-disabled { background-color: #ccc !important; cursor: not-allowed !important; }

        .cart-float { position: fixed; bottom: 30px; right: 30px; background-color: #66bb6a; color: white; padding: 15px 25px; border-radius: 30px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.2); z-index: 100; display: flex; align-items: center; gap: 10px; }
        .cart-float:hover { background-color: #4caf50; }
    </style>
</head>
<body>

    <div class="nav-header">
        <h2>🌸 Katalog Mawarkuntum 🌸</h2>
        <div class="user-info">
            <span>Halo, <b><?php echo htmlspecialchars($_SESSION['email']); ?></b></span>
            <a href="asisten.php" class="btn-orders" style="background:#7b1fa2;">💬 Tanya Asisten</a>
            <a href="riwayat_pesanan.php" class="btn-orders">📦 Pesanan Saya</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="katalog-grid">
        <?php
        $query = mysqli_query($conn, "SELECT * FROM products");
        while($row = mysqli_fetch_assoc($query)) {
            $img_src = (strpos($row['gambar'], 'http') === 0) ? $row['gambar'] : 'images/' . $row['gambar'];
            $stok = intval($row['stok']);
            $is_empty = ($stok <= 0);
            ?>

            <div class='produk-card'>
                <div>
                    <img src='<?php echo $img_src; ?>' alt='<?php echo htmlspecialchars($row['nama_produk']); ?>' class='produk-img' referrerpolicy='no-referrer'>
                    <h3 class='produk-nama'><?php echo htmlspecialchars($row['nama_produk']); ?></h3>
                    <div class='produk-harga'>Rp <?php echo number_format($row['harga'], 0, ',', '.'); ?></div>
                </div>

                <div>
                    <?php if($is_empty): ?>
                        <div class='stok-habis'>❌ Stok Habis</div>
                        <button class='btn-beli btn-disabled' disabled>Stok Habis</button>
                    <?php else: ?>
                        <div class='stok-info'>Tersisa: <b><?php echo $stok; ?></b> pcs</div>
                        <form method='POST' action='katalog.php' class='form-beli' onsubmit='tambahKeKeranjang(event, this)'>
                            <input type='hidden' name='id_produk' value='<?php echo $row['product_id']; ?>'>
                            <input type='hidden' name='add_to_cart' value='1'>
                            
                            <div class='qty-box'>
                                <button type='button' class='btn-qty' onclick='kurangiQty(this)'>-</button>
                                <input type='number' name='jumlah' value='1' min='1' max='<?php echo $stok; ?>' class='input-qty' readonly>
                                <button type='button' class='btn-qty' onclick='tambahQty(this)'>+</button>
                            </div>

                            <button type='submit' class='btn-beli'>+ Keranjang</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php
        }
        ?>
    </div>

    <?php $jumlah_item = isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0; ?>
    <a href="keranjang.php" class="cart-float" id="cartBadge" style="<?php echo ($jumlah_item > 0) ? 'display:flex;' : 'display:none;'; ?>">
        🛍️ Checkout (<span id="cartCount"><?php echo $jumlah_item; ?></span> items) ➔
    </a>

    <script>
        function tambahQty(btn) {
            let input = btn.parentElement.querySelector('.input-qty');
            let maxStok = parseInt(input.getAttribute('max'));
            let val = parseInt(input.value);

            if (val < maxStok) {
                input.value = val + 1;
            } else {
                alert('Mencapai batas maksimum stok yang tersedia (' + maxStok + ' pcs)');
            }
        }

        function kurangiQty(btn) {
            let input = btn.parentElement.querySelector('.input-qty');
            let val = parseInt(input.value);
            if (val > 1) {
                input.value = val - 1;
            }
        }

        function tambahKeKeranjang(event, form) {
            event.preventDefault();
            
            let formData = new FormData(form);

            fetch('katalog.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    document.getElementById('cartCount').innerText = data.total_items;
                    document.getElementById('cartBadge').style.display = 'flex';
                    form.querySelector('.input-qty').value = 1;
                } else {
                    alert(data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        }
    </script>
</body>
</html>