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
<html>
<head>
    <title>Login OAS Mawarkuntum</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #e8f5e9; color: #2e7d32; text-align: center; padding-top: 80px; }
        .login-box { background: white; width: 320px; margin: auto; padding: 30px; border-radius: 15px; box-shadow: 0 8px 16px rgba(129, 199, 132, 0.2); border: 2px solid #f8bbd0; }
        h2 { color: #d81b60; margin-bottom: 5px; }
        p { color: #66bb6a; font-weight: bold; margin-top: 0; }
        input { width: 90%; padding: 10px; margin: 8px 0; border: 1px solid #c8e6c9; border-radius: 5px; background-color: #fbfcfa; box-sizing: border-box; }
        button { background-color: #f48fb1; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; width: 90%; margin-top: 10px; }
        button:hover { background-color: #f06292; }
        .alert-error { background-color: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; border: 1px solid #ffcdd2; font-size: 13px; margin-bottom: 12px; }
        .link-reg { display: block; margin-top: 15px; color: #880e4f; font-size: 13px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🌸 Mawarkuntum 🌸</h2>
        <p>Portal Sistem Login</p>

        <?php if(!empty($error)): ?>
            <div class="alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <input type="email" name="email" placeholder="Email Pegawai / Pelanggan" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Masuk</button>
        </form>

        <a href="register.php" class="link-reg">Belum punya akun? Daftar Pelanggan</a>
    </div>
</body>
</html>
 