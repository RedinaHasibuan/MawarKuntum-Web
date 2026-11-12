<?php
session_start();
include 'koneksi.php';

$pesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $cek_email = mysqli_query($conn, "SELECT email FROM users WHERE email = '$email'");
    
    if (mysqli_num_rows($cek_email) > 0) {
        $pesan = "<div class='alert-error'>Email sudah terdaftar! Gunakan email lain.</div>";
    } else {
        $id_sub_role_pelanggan = 5; // Default: Pelanggan Reguler
        
        $sql_register = "INSERT INTO users (email, password, id_sub_role, status) 
                        VALUES ('$email', '$password', '$id_sub_role_pelanggan', 'active')";
        
        if (mysqli_query($conn, $sql_register)) {
            $pesan = "<div class='alert-success'>✅ Akun berhasil dibuat! Silakan <a href='login.php'>Login di sini</a>.</div>";
        } else {
            $pesan = "<div class='alert-error'>Gagal mendaftar, coba lagi.</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - Mawarkuntum</title>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css?v=20261112-fix4">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <a class="auth-brand" href="login.php" aria-label="Mawarkuntum beranda">
            <span class="auth-brand-mark" aria-hidden="true">🌸</span><span class="auth-brand-name">Mawarkuntum</span>
        </a>
        <div class="auth-layout">
            <section class="auth-story">
                <div>
                    <div class="auth-kicker">YOUR BEAUTY JOURNEY STARTS HERE</div>
                    <h1>Ruang kecil untuk merayakan dirimu.</h1>
                    <p>Buat akun Mawarkuntum agar kamu bisa menjelajahi katalog, menyimpan keranjang, memantau pesanan, dan bertanya kepada Asisten Virtual.</p>
                </div>
                <div class="auth-points">
                    <div class="auth-point"><span>🌷</span> Jelajahi katalog pilihan</div>
                    <div class="auth-point"><span>💬</span> Tanya Asisten Virtual</div>
                    <div class="auth-point"><span>🛍️</span> Belanja lebih praktis</div>
                    <div class="auth-point"><span>✨</span> Temukan produk favorit</div>
                </div>
                <div class="auth-decoration" aria-hidden="true"><span>🧴</span><span>💋</span><span>🌸</span></div>
            </section>
            <section class="auth-panel">
                <div class="auth-symbol">💗</div>
                <div class="auth-eyebrow">MEMBER MAWARKUNTUM</div>
                <h2>Buat akun baru</h2>
                <p class="auth-subtitle">Daftar sebagai pelanggan dan mulai temukan beauty essentials favoritmu.</p>

                <?php if (!empty($pesan)): ?>
                    <div class="auth-alert" role="status"><?php echo $pesan; ?></div>
                <?php endif; ?>

                <form action="" method="POST" class="auth-form" autocomplete="on">
                    <div class="auth-field">
                        <label for="email">Alamat email</label>
                        <input id="email" type="email" name="email" placeholder="nama@email.com" autocomplete="email" required>
                    </div>
                    <div class="auth-field">
                        <label for="password">Buat password</label>
                        <input id="password" type="password" name="password" placeholder="Buat password" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="auth-submit">Buat Akun <span aria-hidden="true">→</span></button>
                </form>
                <div class="auth-switch">Sudah memiliki akun? <a href="login.php">Masuk di sini</a></div>
            </section>
        </div>
        <div class="auth-footnote">🌸 Dengan bergabung, kamu menjadi bagian dari komunitas Mawarkuntum.</div>
    </main>
</body>
</html>
