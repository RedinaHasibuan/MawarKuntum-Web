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
<html>
<head>
    <title>Daftar Akun - Mawarkuntum</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #e8f5e9; color: #2e7d32; text-align: center; padding-top: 80px; }
        .reg-box { background: white; width: 320px; margin: auto; padding: 30px; border-radius: 15px; box-shadow: 0 8px 16px rgba(129, 199, 132, 0.2); border: 2px solid #f8bbd0; }
        h2 { color: #d81b60; margin-bottom: 5px; }
        p { color: #66bb6a; font-weight: bold; margin-top: 0; font-size: 14px; }
        input { width: 90%; padding: 10px; margin: 8px 0; border: 1px solid #c8e6c9; border-radius: 5px; background-color: #fbfcfa; box-sizing: border-box; }
        button { background-color: #f48fb1; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; width: 90%; margin-top: 10px; }
        button:hover { background-color: #f06292; }
        .alert-error { background-color: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; border: 1px solid #ffcdd2; font-size: 13px; margin-bottom: 12px; }
        .alert-success { background-color: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 5px; border: 1px solid #a5d6a7; font-size: 13px; margin-bottom: 12px; }
        .link-login { margin-top: 15px; display: block; color: #880e4f; font-size: 13px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="reg-box">
        <h2>🌸 Mawarkuntum 🌸</h2>
        <p>Buat Akun Pelanggan Baru</p>

        <?php echo $pesan; ?>

        <form action="" method="POST">
            <input type="email" name="email" placeholder="Masukkan Email Anda" required>
            <input type="password" name="password" placeholder="Buat Password" required>
            <button type="submit">Daftar Sekarang</button>
        </form>

        <a href="login.php" class="link-login">Sudah punya akun? Login di sini</a>
    </div>
</body>
</html>