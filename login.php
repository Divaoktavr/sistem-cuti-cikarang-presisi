
<?php
session_start();
require 'koneksi.php';

if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $s = $pdo->prepare("
        SELECT k.*, u.password, u.role, u.email
        FROM users u
        JOIN karyawan k ON k.id = u.karyawan_id
        WHERE u.email = ?
        LIMIT 1
    ");

    $s->execute([$email]);
    $u = $s->fetch(PDO::FETCH_ASSOC);

    $passwordValid = false;

    if ($u) {
        // Memeriksa password yang tersimpan dalam bentuk hash
        if (password_verify($password, $u['password'])) {
            $passwordValid = true;
        }

        // Kompatibilitas sementara untuk password teks biasa
        elseif (hash_equals((string) $u['password'], (string) $password)) {
            $passwordValid = true;

            // Langsung ubah password menjadi hash setelah login berhasil
            $newHash = password_hash($password, PASSWORD_DEFAULT);

            $update = $pdo->prepare(
                "UPDATE users SET password = ? WHERE email = ?"
            );
            $update->execute([$newHash, $email]);
        }
    }

    if ($u && $passwordValid) {
        session_regenerate_id(true);

        // Jangan simpan password ke dalam session
        unset($u['password']);
        $_SESSION['user'] = $u;

        header('Location: index.php');
        exit;
    }

    $error = 'Email atau kata sandi tidak sesuai.';
}
?>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Cikarang Presisi</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="login-body">
    <div class="login-card">
        <div class="brand-icon large">CP</div>
        <span class="pill">HRIS PORTAL</span>

        <h1>Selamat datang kembali</h1>
        <p>Masuk untuk mengelola pengajuan cuti karyawan.</p>

        <?php if ($error): ?>
            <div class="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <label>
                Email
                <input
                    type="email"
                    name="email"
                    placeholder="nama@perusahaan.co.id"
                    required
                >
            </label>

            <label>
                Kata Sandi
                <input
                    type="password"
                    name="password"
                    placeholder="Masukkan kata sandi"
                    required
                >
            </label>

            <button class="btn primary full-btn" type="submit">
                Masuk ke Sistem →
            </button>
        </form>

</body>
</html>
