<?php
session_start();
include 'koneksi.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {$email = mysqli_real_escape_string($conn,$_POST['email']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);

    // Query login mencari user aktif
    $sql = "SELECT u.*, sr.nama_sub_role 
            FROM users u 
            LEFT JOIN sub_roles sr ON u.id_sub_role = sr.id_sub_role 
            WHERE u.email = '$email' AND u.password = '$password' AND u.status = 'active'";

    $result = mysqli_query($conn,$sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);

        // PERBAIKAN: Cek kolom role utama (admin) terlebih dahulu
        if ($user['role'] === 'admin') {$role = 'admin';
        } else {
            $role =$user['nama_sub_role'] ?? 'Pelanggan';
        }

        // Mencegah NULL jika nama kolom ID berbeda
        $_SESSION['user_id']       =$user['user_id'] ?? $user['id_user'] ?? $user['id'];
        $_SESSION['email']         =$user['email'];
        $_SESSION['nama_sub_role'] =$role;
        $_SESSION['role']          =$role; 

        // Redirect sesuai Jabatan / Sub-Role
        switch ($role) {
            case 'admin':
                header("Location: index.php"); // Admin langsung masuk ke KMS
                break;
            case 'Supervisor Operasional':
            case 'Manager':
                header("Location: dashboard_manager.php");
                break;
            case 'Staff Gudang':
                header("Location: dashboard_gudang.php");
                break;
            case 'Staff Pesanan':
                header("Location: dashboard_pesanan.php");
                break;
            default:
                header("Location: katalog.php");
                break;
        }
        exit();
    } else {
        $error = "Email atau password salah, atau status akun belum 'active'!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - Mawarkuntum</title>
    <link rel="stylesheet" href="assets/mawarkuntum-ui.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <a class="auth-brand" href="login.php" aria-label="Mawarkuntum beranda">
            <span class="auth-brand-mark">🌸</span><span>Mawarkuntum</span>
        </a>
        <div class="auth-layout">
            <section class="auth-story">
                <div>
                    <div class="auth-kicker">BEAUTY CARE • EVERYDAY</div>
                    <h1>Rawat diri, dengan cara yang terasa seperti kamu.</h1>
                    <p>Temukan produk kecantikan, rekomendasi sesuai kebutuhan kulit, dan pengalaman belanja yang lebih personal bersama Mawarkuntum.</p>
                </div>
                <div class="auth-points">
                    <div class="auth-point"><span>💗</span> Pilihan beauty essentials</div>
                    <div class="auth-point"><span>🌿</span> Rekomendasi lebih personal</div>
                    <div class="auth-point"><span>💄</span> Temukan tampilan favoritmu</div>
                    <div class="auth-point"><span>📦</span> Pantau pesanan dengan mudah</div>
                </div>
                <div class="auth-decoration" aria-hidden="true"><span>💄</span><span>🪞</span><span>🌷</span></div>
            </section>
            <section class="auth-panel">
                <div class="auth-symbol">🌷</div>
                <div class="auth-eyebrow">SELAMAT DATANG KEMBALI</div>
                <h2>Masuk ke akunmu</h2>
                <p class="auth-subtitle">Lanjutkan perjalanan beauty care bersama Mawarkuntum.</p>

                <?php if (!empty($error)): ?>
                    <div class="auth-alert alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form action="" method="POST" class="auth-form" autocomplete="on">
                    <div class="auth-field">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" placeholder="nama@email.com" autocomplete="username" required>
                    </div>
                    <div class="auth-field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" placeholder="Masukkan password" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="auth-submit">Masuk ke Mawarkuntum <span aria-hidden="true">→</span></button>
                </form>
                <div class="auth-switch">Belum punya akun? <a href="register.php">Daftar sebagai pelanggan</a></div>
            </section>
        </div>
        <div class="auth-footnote">🌸 Mawarkuntum — beauty care, made personal.</div>
    </main>
</body>
</html>
