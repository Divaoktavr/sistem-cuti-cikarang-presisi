<?php
session_start();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    exit('Silakan login terlebih dahulu.');
}

require 'koneksi.php';
$user = $_SESSION['user'];
$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    exit('Permintaan tidak valid.');
}

$stmt = $pdo->prepare('SELECT id, karyawan_id, bukti_file FROM pengajuan_cuti WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['bukti_file'])) {
    http_response_code(404);
    exit('Bukti tidak ditemukan.');
}

$role = $user['role'] ?? '';
if (($role === 'Karyawan') && (int)$row['karyawan_id'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Akses ditolak.');
}
if (!in_array($role, ['Karyawan', 'HRD', 'Kepala Bagian', 'Admin'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$filename = basename($row['bukti_file']);
$path = __DIR__ . '/uploads/bukti_cuti/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('File bukti tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
if (!isset($types[$ext])) {
    http_response_code(415);
    exit('Jenis file tidak didukung.');
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="bukti-' . (int)$row['id'] . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
