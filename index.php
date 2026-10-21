
<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require 'koneksi.php';

$user = $_SESSION['user'];
$role = $user['role'];

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$page = $_GET['page'] ?? 'dashboard';

$allowed = [
    'dashboard', 'ajukan', 'riwayat',
    'persetujuan', 'karyawan', 'laporan'
];

if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$folderBukti = __DIR__ . '/uploads/bukti_cuti/';
if (!is_dir($folderBukti)) {
    mkdir($folderBukti, 0750, true);
}
$bolehKelolaKaryawan = in_array($role, ['HRD', 'Admin'], true);
$editData = null;

if ($page === 'karyawan' && $bolehKelolaKaryawan && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM karyawan WHERE id = ?');
        $st->execute([$editId]);
        $editData = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editData) {
            $_SESSION['flash'] = 'Data karyawan tidak ditemukan.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| ATUR JATAH CUTI KARYAWAN (ADMIN/HRD)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atur_jatah_cuti'])) {
    if (!in_array($role, ['Admin', 'HRD'], true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $idKaryawan = (int)($_POST['id_karyawan'] ?? 0);
    $jatah = filter_var($_POST['jatah_cuti'] ?? null, FILTER_VALIDATE_INT);
    if ($idKaryawan < 1 || $jatah === false || $jatah === null || $jatah < 0 || $jatah > 365) {
        $_SESSION['flash'] = 'Jatah cuti harus berupa angka 0 sampai 365 hari.';
    } else {
        $stmt = $pdo->prepare('UPDATE karyawan SET jatah_cuti = ? WHERE id = ?');
        $stmt->execute([$jatah, $idKaryawan]);
        $_SESSION['flash'] = $stmt->rowCount() >= 0 ? 'Jatah cuti karyawan berhasil diperbarui.' : 'Jatah cuti gagal diperbarui.';
    }
    header('Location: index.php?page=karyawan');
    exit;
}

/*
|--------------------------------------------------------------------------
| TAMBAH KARYAWAN
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['tambah_karyawan'])
) {
    if (!in_array($role, ['HRD', 'Admin'], true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    $nik = trim($_POST['nik'] ?? '');
    $nama = trim($_POST['nama'] ?? '');
    $departemen = trim($_POST['departemen'] ?? '');
    $jabatan = trim($_POST['jabatan'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telepon = trim($_POST['telepon'] ?? '');
    $password = $_POST['password'] ?? '';
    $jatahCuti = filter_var($_POST['jatah_cuti'] ?? 12, FILTER_VALIDATE_INT);

    if (
        !$nik || !$nama || !$departemen ||
        !$jabatan || !$email || !$password
    ) {
        $_SESSION['flash'] = 'Semua kolom wajib harus diisi.';
    } elseif (
        strlen($nik) > 30 ||
        strlen($nama) > 120 ||
        strlen($departemen) > 100 ||
        strlen($jabatan) > 100 ||
        strlen($email) > 150 ||
        strlen($telepon) > 30
    ) {
        $_SESSION['flash'] = 'Ada isian yang melebihi batas karakter.';
    } elseif ($jatahCuti === false || $jatahCuti < 0 || $jatahCuti > 365) {
        $_SESSION['flash'] = 'Jatah cuti harus berupa angka 0 sampai 365 hari.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = 'Format email tidak valid.';
    } elseif (strlen($password) < 8) {
        $_SESSION['flash'] = 'Kata sandi minimal 8 karakter.';
    } else {
        try {
            $pdo->beginTransaction();

            // Periksa NIK dan email yang sudah digunakan.
            $check = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM karyawan WHERE nik = ?) AS nik_ada,
                    (SELECT COUNT(*) FROM users WHERE email = ?) AS email_ada,
                    (SELECT COUNT(*) FROM karyawan WHERE email = ?) AS email_karyawan"
            );

            $check->execute([$nik, $email, $email]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing['nik_ada'] > 0) {
                throw new RuntimeException('NIK sudah terdaftar.');
            }

            if (
                $existing['email_ada'] > 0 ||
                $existing['email_karyawan'] > 0
            ) {
                throw new RuntimeException('Email sudah digunakan.');
            }

            // Simpan profil karyawan.
            $stmt = $pdo->prepare(
                "INSERT INTO karyawan
                    (nik, nama, departemen, jabatan, email, telepon, jatah_cuti)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $nik,
                $nama,
                $departemen,
                $jabatan,
                $email,
                $telepon,
                $jatahCuti
            ]);

            $karyawanId = (int)$pdo->lastInsertId();

            // Buat akun login dengan kata sandi yang di-hash.
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO users
                    (karyawan_id, email, password, role)
                 VALUES (?, ?, ?, 'Karyawan')"
            );

            $stmt->execute([$karyawanId, $email, $hash]);

            $pdo->commit();

            $_SESSION['flash'] =
                'Karyawan dan akun login berhasil ditambahkan.';

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['flash'] = $e->getMessage();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());

            $_SESSION['flash'] =
                'Data gagal disimpan. Periksa data dan database.';
        }
    }

    header('Location: index.php?page=karyawan');
    exit;
}

/*
|--------------------------------------------------------------------------
| PENGAJUAN CUTI
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajukan'])
) {
    $jenis = trim($_POST['jenis_cuti'] ?? '');
    $mulai = $_POST['tanggal_mulai'] ?? '';
    $selesai = $_POST['tanggal_selesai'] ?? '';
    $alasan = trim($_POST['alasan'] ?? '');
    $kontak = trim($_POST['kontak'] ?? '');

    if (
        !$jenis || !$mulai || !$selesai || !$alasan ||
        $selesai < $mulai ||
        strlen($alasan) > 500 ||
        strlen($kontak) > 150
    ) {
        $_SESSION['flash'] =
            'Periksa kembali isian dan tanggal pengajuan cuti.';
        header('Location: index.php?page=ajukan');
        exit;
    }

    try {
        $tanggalMulai = new DateTime($mulai);
        $tanggalSelesai = new DateTime($selesai);

        $hari = $tanggalMulai->diff($tanggalSelesai)->days + 1;
        $buktiNama = null;

        if (isset($_FILES['bukti_file']) && $_FILES['bukti_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['bukti_file'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload bukti gagal. Silakan pilih file kembali.');
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Ukuran bukti maksimal 2 MB.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $jenisMime = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png'
            ];

            if (!isset($jenisMime[$mime])) {
                throw new RuntimeException('File harus berupa PDF, JPG, atau PNG.');
            }

            $buktiNama = bin2hex(random_bytes(16)) . '.' . $jenisMime[$mime];
            if (!move_uploaded_file($file['tmp_name'], $folderBukti . $buktiNama)) {
                throw new RuntimeException('File bukti tidak berhasil disimpan.');
            }
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO pengajuan_cuti
                    (karyawan_id, jenis_cuti, tanggal_mulai, tanggal_selesai,
                     total_hari, alasan, kontak, status, bukti_file)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Menunggu', ?)"
            );
            $stmt->execute([
                $user['id'], $jenis, $mulai, $selesai,
                $hari, $alasan, $kontak, $buktiNama
            ]);
        } catch (Throwable $dbError) {
            if ($buktiNama && is_file($folderBukti . $buktiNama)) {
                unlink($folderBukti . $buktiNama);
            }
            throw $dbError;
        }

        $_SESSION['flash'] = 'Pengajuan cuti berhasil dikirim.';
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $_SESSION['flash'] = 'Pengajuan gagal disimpan.';
    }

    header('Location: index.php?page=riwayat');
    exit;
}

/*
|--------------------------------------------------------------------------
| PERSETUJUAN CUTI
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_status'])
    && in_array($role, ['HRD', 'Kepala Bagian', 'Admin'], true)
) {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $catatan = trim($_POST['catatan'] ?? '');

    if (
        $id > 0 &&
        in_array($status, ['Disetujui', 'Ditolak'], true) &&
        strlen($catatan) <= 500
    ) {
        try {
            $pdo->beginTransaction();
            $get = $pdo->prepare(
                "SELECT p.id, p.karyawan_id, p.total_hari, p.tanggal_mulai, p.status,
                        k.jatah_cuti
                 FROM pengajuan_cuti p
                 JOIN karyawan k ON k.id = p.karyawan_id
                 WHERE p.id = ? FOR UPDATE"
            );
            $get->execute([$id]);
            $pengajuan = $get->fetch(PDO::FETCH_ASSOC);
            if (!$pengajuan || $pengajuan['status'] !== 'Menunggu') {
                throw new RuntimeException('Pengajuan tidak ditemukan atau sudah diproses.');
            }
            if ($status === 'Disetujui') {
                $tahun = (int)date('Y', strtotime($pengajuan['tanggal_mulai']));
                $used = $pdo->prepare(
                    "SELECT COALESCE(SUM(total_hari), 0)
                     FROM pengajuan_cuti
                     WHERE karyawan_id = ? AND status = 'Disetujui'
                       AND YEAR(tanggal_mulai) = ?"
                );
                $used->execute([(int)$pengajuan['karyawan_id'], $tahun]);
                $terpakai = (int)$used->fetchColumn();
                $jatah = (int)$pengajuan['jatah_cuti'];
                $hariDiminta = (int)$pengajuan['total_hari'];
                if ($terpakai + $hariDiminta > $jatah) {
                    throw new RuntimeException(
                        'Jatah cuti tidak cukup. Jatah: ' . $jatah . ' hari; sudah terpakai: ' . $terpakai . ' hari.'
                    );
                }
            }
            $upd = $pdo->prepare(
                "UPDATE pengajuan_cuti
                 SET status = ?, catatan_persetujuan = ?, diperiksa_oleh = ?, updated_at = NOW()
                 WHERE id = ? AND status = 'Menunggu'"
            );
            $upd->execute([$status, $catatan, $user['nama'], $id]);
            if ($upd->rowCount() < 1) {
                throw new RuntimeException('Pengajuan sudah diproses oleh pengguna lain.');
            }
            $pdo->commit();
            $_SESSION['flash'] = $status === 'Disetujui'
                ? 'Pengajuan disetujui. Jatah cuti terpakai diperbarui.'
                : 'Pengajuan ditolak. Jatah cuti tidak berubah.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $_SESSION['flash'] = 'Status gagal diperbarui. Periksa database.';
        }
    } else {
        $_SESSION['flash'] = 'Data persetujuan tidak valid.';
    }

    header('Location: index.php?page=persetujuan');
    exit;
}

/*
|--------------------------------------------------------------------------
| NAVIGASI
|--------------------------------------------------------------------------
*/
$nav = [
    ['dashboard', '⌂', 'Dashboard',
        ['Karyawan', 'HRD', 'Kepala Bagian', 'Admin']],
    ['ajukan', '＋', 'Buat Pengajuan',
        ['Karyawan', 'Admin']],
    ['riwayat', '▤', 'Riwayat Pengajuan',
        ['Karyawan', 'HRD', 'Kepala Bagian', 'Admin']],
    ['persetujuan', '✓', 'Persetujuan Cuti',
        ['HRD', 'Kepala Bagian', 'Admin']],
    ['karyawan', '♙', 'Data Karyawan',
        ['HRD', 'Admin']],
    ['laporan', '▥', 'Laporan Cuti',
        ['HRD', 'Admin']]
];

function countRows($pdo, $sql, $args = []) {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return (int)$s->fetchColumn();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistem Cuti | Cikarang Presisi</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<aside class="sidebar">
    <a class="brand" href="index.php">
        <span class="brand-icon">CP</span>
        <span>
            <b>Cikarang Presisi</b>
            <small>HRIS · Sistem Cuti</small>
        </span>
    </a>

    <div class="nav-label">MENU UTAMA</div>

    <?php foreach ($nav as $n): ?>
        <?php if (in_array($role, $n[3], true)): ?>
            <a
                class="nav-link <?= $page === $n[0] ? 'active' : '' ?>"
                href="index.php?page=<?= e($n[0]) ?>"
            >
                <span><?= e($n[1]) ?></span>
                <?= e($n[2]) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="sidebar-help">
        <b>Butuh bantuan?</b>
        <p>Hubungi tim HRD jika ada pertanyaan mengenai pengajuan cuti.</p>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div>
            <div class="eyebrow">Cikarang Presisi / HRIS</div>
            <h2>
                <?= e([
                    'dashboard' => 'Dashboard',
                    'ajukan' => 'Form Pengajuan Cuti',
                    'riwayat' => 'Riwayat Pengajuan',
                    'persetujuan' => 'Persetujuan Cuti',
                    'karyawan' => 'Data Karyawan',
                    'laporan' => 'Laporan Cuti'
                ][$page]) ?>
            </h2>
        </div>

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($user['nama'], 0, 1))) ?>
            </div>
            <div>
                <b><?= e($user['nama']) ?></b>
                <small><?= e($role) ?></small>
            </div>
            <a class="logout" href="logout.php">Keluar</a>
        </div>
    </header>

    <section class="content">
        <?php if ($flash): ?>
            <div class="alert"><?= e($flash) ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>

            <?php
            $myArgs = ($role === 'Karyawan') ? [$user['id']] : [];
            $where = ($role === 'Karyawan')
                ? ' WHERE karyawan_id = ?'
                : '';

            $total = countRows(
                $pdo,
                "SELECT COUNT(*) FROM pengajuan_cuti" . $where,
                $myArgs
            );

            $wait = countRows(
                $pdo,
                "SELECT COUNT(*) FROM pengajuan_cuti" .
                $where . ($where ? ' AND' : ' WHERE') .
                " status = 'Menunggu'",
                $myArgs
            );

            $approved = countRows(
                $pdo,
                "SELECT COUNT(*) FROM pengajuan_cuti" .
                $where . ($where ? ' AND' : ' WHERE') .
                " status = 'Disetujui'",
                $myArgs
            );

            $rejected = countRows(
                $pdo,
                "SELECT COUNT(*) FROM pengajuan_cuti" .
                $where . ($where ? ' AND' : ' WHERE') .
                " status = 'Ditolak'",
                $myArgs
            );

            $sql = "SELECT p.*, k.nama, k.departemen
                    FROM pengajuan_cuti p
                    JOIN karyawan k ON k.id = p.karyawan_id" .
                    ($role === 'Karyawan'
                        ? " WHERE p.karyawan_id = ?"
                        : "") .
                    " ORDER BY p.created_at DESC LIMIT 5";

            $s = $pdo->prepare($sql);
            $s->execute($myArgs);
            $recent = $s->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="welcome">
                <div>
                    <span class="pill">PORTAL SDM</span>
                    <h1>
                        Selamat datang,
                        <?= e(explode(' ', $user['nama'])[0]) ?> 👋
                    </h1>
                    <p>Pantau pengajuan cuti dan informasi terbaru Anda dari satu tempat.</p>
                </div>
                <a class="btn primary" href="index.php?page=ajukan">
                    ＋ Ajukan Cuti
                </a>
            </div>

            <div class="stats">
                <?php if ($role === 'Karyawan'): ?>
                    <?php
                    $q = $pdo->prepare('SELECT jatah_cuti FROM karyawan WHERE id = ?');
                    $q->execute([$user['id']]);
                    $jatahTahunan = (int)$q->fetchColumn();
                    $q = $pdo->prepare("SELECT COALESCE(SUM(total_hari), 0) FROM pengajuan_cuti WHERE karyawan_id = ? AND status = 'Disetujui' AND YEAR(tanggal_mulai) = YEAR(CURDATE())");
                    $q->execute([$user['id']]);
                    $cutiTerpakai = (int)$q->fetchColumn();
                    $sisaCuti = max(0, $jatahTahunan - $cutiTerpakai);
                    ?>
                    <div class="stat">
                        <span>Total Jatah Cuti Tahun <?= date('Y') ?></span>
                        <strong><?= $jatahTahunan ?> hari</strong>
                        <small>Jatah yang ditetapkan Admin/HRD</small>
                    </div>
                    <div class="stat">
                        <span>Cuti Terpakai</span>
                        <strong><?= $cutiTerpakai ?> hari</strong>
                        <small class="green">Hanya pengajuan disetujui</small>
                    </div>
                    <div class="stat">
                        <span>Sisa Cuti</span>
                        <strong><?= $sisaCuti ?> hari</strong>
                        <small>Pengajuan menunggu/tolak tidak mengurangi</small>
                    </div>
                <?php endif; ?>
                <div class="stat">
                    <span>Total Pengajuan</span>
                    <strong><?= $total ?></strong>
                    <small>Seluruh pengajuan tercatat</small>
                </div>
                <div class="stat">
                    <span>Menunggu</span>
                    <strong><?= $wait ?></strong>
                    <small class="orange">Perlu ditinjau</small>
                </div>
                <div class="stat">
                    <span>Disetujui</span>
                    <strong><?= $approved ?></strong>
                    <small class="green">Pengajuan diterima</small>
                </div>
                <div class="stat">
                    <span>Ditolak</span>
                    <strong><?= $rejected ?></strong>
                    <small>Perlu ditinjau kembali</small>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Pengajuan Terbaru</h3>
                        <p>Ringkasan pengajuan cuti terbaru</p>
                    </div>
                    <a class="text-link" href="index.php?page=riwayat">
                        Lihat semua →
                    </a>
                </div>

                <?php if (!$recent): ?>
                    <div class="empty">Belum ada pengajuan cuti.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Jenis Cuti</th>
                                    <?php if ($role !== 'Karyawan'): ?>
                                        <th>Karyawan</th>
                                    <?php endif; ?>
                                    <th>Tanggal</th>
                                    <th>Durasi</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $r): ?>
                                    <tr>
                                        <td>
                                            <b><?= e($r['jenis_cuti']) ?></b>
                                            <small><?= e($r['alasan']) ?></small>
                                        </td>
                                        <?php if ($role !== 'Karyawan'): ?>
                                            <td>
                                                <?= e($r['nama']) ?>
                                                <small><?= e($r['departemen']) ?></small>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?= e($r['tanggal_mulai']) ?>
                                            <small>s.d. <?= e($r['tanggal_selesai']) ?></small>
                                        </td>
                                        <td><?= (int)$r['total_hari'] ?> hari</td>
                                        <td>
                                            <span class="status <?= e(strtolower(str_replace(' ', '-', $r['status']))) ?>">
                                                <?= e($r['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($page === 'ajukan'): ?>

            <div class="panel form-panel">
                <div class="panel-head">
                    <div>
                        <h3>Form Pengajuan Cuti</h3>
                        <p>Lengkapi informasi berikut dengan benar.</p>
                    </div>
                    <span class="step">FORM 01</span>
                </div>

                <form method="post" id="leaveForm" enctype="multipart/form-data">
                    <h4>01 · Informasi Karyawan</h4>

                    <div class="form-grid three">
                        <label>Nama Lengkap
                            <input value="<?= e($user['nama']) ?>" readonly>
                        </label>
                        <label>NIK
                            <input value="<?= e($user['nik']) ?>" readonly>
                        </label>
                        <label>Departemen
                            <input value="<?= e($user['departemen']) ?>" readonly>
                        </label>
                        <label>Jabatan
                            <input value="<?= e($user['jabatan']) ?>" readonly>
                        </label>
                        <label>Email
                            <input value="<?= e($user['email']) ?>" readonly>
                        </label>
                        <label>No. Telepon
                            <input value="<?= e($user['telepon']) ?>" readonly>
                        </label>
                    </div>

                    <hr>
                    <h4>02 · Detail Pengajuan Cuti</h4>

                    <div class="form-grid two">
                        <label>Jenis Cuti <em>*</em>
                            <select name="jenis_cuti" required>
                                <option value="">Pilih jenis cuti</option>
                                <option>Cuti Tahunan</option>
                                <option>Cuti Sakit</option>
                                <option>Cuti Izin</option>
                                <option>Cuti Melahirkan</option>
                                <option>Cuti Lainnya</option>
                            </select>
                        </label>

                        <label>Total Hari <em>*</em>
                            <input id="totalHari" value="Pilih tanggal terlebih dahulu" readonly>
                        </label>

                        <label>Tanggal Mulai <em>*</em>
                            <input type="date" name="tanggal_mulai" id="mulai" required>
                        </label>

                        <label>Tanggal Selesai <em>*</em>
                            <input type="date" name="tanggal_selesai" id="selesai" required>
                        </label>

                        <label class="full">Keperluan / Alasan <em>*</em>
                            <textarea name="alasan" maxlength="500" rows="4"
                                placeholder="Jelaskan keperluan atau alasan pengajuan cuti..."
                                required></textarea>
                            <small class="muted">Maksimal 500 karakter.</small>
                        </label>

                        <label class="full">Kontak selama cuti
                            <small>(opsional)</small>
                            <input name="kontak" maxlength="150"
                                placeholder="Nama dan nomor telepon yang dapat dihubungi">
                        </label>

                        <label class="full">Bukti Pendukung <small>(opsional)</small>
                            <input type="file" name="bukti_file" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="muted">Format PDF, JPG, atau PNG. Maksimal 2 MB.</small>
                        </label>
                    </div>

                    <div class="notice">
                        ⓘ Pastikan pekerjaan telah dikoordinasikan dengan rekan kerja atau atasan sebelum cuti.
                    </div>

                    <div class="form-actions">
                        <a class="btn secondary" href="index.php">Batal</a>
                        <button class="btn primary" type="submit" name="ajukan">
                            ➤ Kirim Pengajuan
                        </button>
                    </div>
                </form>
            </div>

        <?php elseif ($page === 'riwayat' || $page === 'persetujuan'): ?>

            <?php
            $sql = "SELECT p.*, k.nama, k.nik, k.departemen, k.jabatan
                    FROM pengajuan_cuti p
                    JOIN karyawan k ON k.id = p.karyawan_id";

            $args = [];
            $conds = [];

            if ($role === 'Karyawan') {
                $conds[] = 'p.karyawan_id = ?';
                $args[] = $user['id'];
            }

            if ($page === 'persetujuan') {
                $conds[] = "p.status = 'Menunggu'";
            }

            if ($conds) {
                $sql .= " WHERE " . implode(' AND ', $conds);
            }

            $sql .= " ORDER BY p.created_at DESC";

            $s = $pdo->prepare($sql);
            $s->execute($args);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h3>
                            <?= $page === 'persetujuan'
                                ? 'Pengajuan Menunggu Persetujuan'
                                : 'Riwayat Pengajuan Cuti' ?>
                        </h3>
                        <p>
                            <?= $page === 'persetujuan'
                                ? 'Periksa data dan tentukan status pengajuan.'
                                : 'Daftar pengajuan cuti yang tercatat di sistem.' ?>
                        </p>
                    </div>

                    <?php if ($page === 'riwayat' && $role === 'Karyawan'): ?>
                        <a class="btn primary small" href="index.php?page=ajukan">
                            ＋ Ajukan Cuti
                        </a>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Jenis / Alasan</th>
                                <th>Periode</th>
                                <th>Durasi</th>
                                <th>Status</th>
                                <th>Bukti</th>
                                <?php if ($page === 'persetujuan'): ?>
                                    <th>Keputusan</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <b><?= e($r['nama']) ?></b>
                                        <small><?= e($r['nik']) ?> · <?= e($r['departemen']) ?></small>
                                    </td>
                                    <td>
                                        <?= e($r['jenis_cuti']) ?>
                                        <small><?= e($r['alasan']) ?></small>
                                    </td>
                                    <td>
                                        <?= e($r['tanggal_mulai']) ?>
                                        <small>s.d. <?= e($r['tanggal_selesai']) ?></small>
                                    </td>
                                    <td><?= (int)$r['total_hari'] ?> hari</td>
                                    <td>
                                        <span class="status <?= e(strtolower(str_replace(' ', '-', $r['status']))) ?>">
                                            <?= e($r['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['bukti_file'])): ?>
                                            <a class="text-link" href="lihat_bukti.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">Lihat bukti</a>
                                        <?php else: ?>
                                            <small class="muted">Tidak ada</small>
                                        <?php endif; ?>
                                    </td>

                                    <?php if ($page === 'persetujuan'): ?>
                                        <td>
                                            <form method="post" class="decision">
                                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <input name="catatan" maxlength="500" placeholder="Catatan (opsional)">
                                                <input type="hidden" name="status" value="Disetujui">

                                                <button
                                                    name="update_status"
                                                    value="1"
                                                    class="btn primary small"
                                                    onclick="this.form.status.value='Disetujui'"
                                                >Setujui</button>

                                                <button
                                                    type="submit"
                                                    name="update_status"
                                                    value="1"
                                                    class="btn danger small"
                                                    onclick="this.form.status.value='Ditolak'; return confirm('Tolak pengajuan ini?')"
                                                >Tolak</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!$rows): ?>
                        <div class="empty">Tidak ada data untuk ditampilkan.</div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($page === 'karyawan' && in_array($role, ['HRD', 'Admin'], true)): ?>

            <?php
            $rows = $pdo->query(
                "SELECT * FROM karyawan ORDER BY nama"
            )->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <!-- FORM TAMBAH KARYAWAN -->
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h3><?= $editData ? 'Edit Karyawan' : 'Tambah Karyawan' ?></h3>
                        <p><?= $editData ? 'Perbarui data karyawan.' : 'Masukkan data karyawan baru dan buat akun login.' ?></p>
                    </div>
                </div>

                <form method="post" class="form-panel">
                    <?php if ($editData): ?>
                        <input type="hidden" name="id" value="<?= (int)$editData['id'] ?>">
                    <?php endif; ?>
                    <div class="form-grid two">
                        <label>NIK <em>*</em>
                            <input name="nik" maxlength="30" required value="<?= e($editData['nik'] ?? '') ?>">
                        </label>

                        <label>Nama Lengkap <em>*</em>
                            <input name="nama" maxlength="120" required value="<?= e($editData['nama'] ?? '') ?>">
                        </label>

                        <label>Departemen <em>*</em>
                            <input name="departemen" maxlength="100" required value="<?= e($editData['departemen'] ?? '') ?>">
                        </label>

                        <label>Jabatan <em>*</em>
                            <input name="jabatan" maxlength="100" required value="<?= e($editData['jabatan'] ?? '') ?>">
                        </label>

                        <label>Email Login <em>*</em>
                            <input type="email" name="email" maxlength="150" required value="<?= e($editData['email'] ?? '') ?>">
                        </label>

                        <label>Nomor Telepon
                            <input name="telepon" maxlength="30" value="<?= e($editData['telepon'] ?? '') ?>">
                        </label>

                        <label>Jatah Cuti Tahunan (hari) <em>*</em>
                            <input type="number" name="jatah_cuti" min="0" max="365" required value="<?= e($editData['jatah_cuti'] ?? 12) ?>">
                            <small class="muted">Bisa diatur sesuai kebijakan perusahaan.</small>
                        </label>

                        <?php if (!$editData): ?>
                            <label class="full">Kata Sandi Awal <em>*</em>
                                <input type="password" name="password" minlength="8"
                                    autocomplete="new-password" required>
                                <small class="muted">
                                    Minimal 8 karakter. Akun baru akan memiliki role Karyawan.
                                </small>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <?php if ($editData): ?>
                            <a class="btn secondary" href="index.php?page=karyawan">Batal Edit</a>
                        <?php endif; ?>
                        <button class="btn primary" type="submit"
                            name="<?= $editData ? 'edit_karyawan' : 'tambah_karyawan' ?>" value="1">
                            <?= $editData ? 'Simpan Perubahan' : '＋ Simpan Karyawan' ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- TABEL DATA KARYAWAN -->
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Data Karyawan</h3>
                        <p>Data karyawan yang terdaftar dalam sistem.</p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>NIK</th>
                                <th>Nama</th>
                                <th>Departemen</th>
                                <th>Jabatan</th>
                                <th>Email</th>
                                <th>Telepon</th>
                                <th>Jatah Cuti</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= e($r['nik']) ?></td>
                                    <td><b><?= e($r['nama']) ?></b></td>
                                    <td><?= e($r['departemen']) ?></td>
                                    <td><?= e($r['jabatan']) ?></td>
                                    <td><?= e($r['email']) ?></td>
                                    <td><?= e($r['telepon']) ?></td>
                                    <td>
                                        <form method="post" style="display:flex;gap:5px;align-items:center">
                                            <input type="hidden" name="id_karyawan" value="<?= (int)$r['id'] ?>">
                                            <input type="number" name="jatah_cuti" min="0" max="365" required value="<?= (int)($r['jatah_cuti'] ?? 12) ?>" style="width:78px;padding:7px">
                                            <button class="btn secondary small" type="submit" name="atur_jatah_cuti" value="1">Simpan</button>
                                        </form>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                                            <a class="btn secondary small" href="index.php?page=karyawan&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
                                            <form method="post" onsubmit="return confirm('Yakin ingin menghapus karyawan ini?')">
                                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <button class="btn danger small" type="submit" name="hapus_karyawan" value="1">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!$rows): ?>
                        <div class="empty">Belum ada data karyawan.</div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($page === 'laporan' && in_array($role, ['HRD', 'Admin'], true)): ?>

            <?php
            $rows = $pdo->query(
                "SELECT p.*, k.nama, k.nik, k.departemen
                 FROM pengajuan_cuti p
                 JOIN karyawan k ON k.id = p.karyawan_id
                 ORDER BY p.created_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Laporan Pengajuan Cuti</h3>
                        <p>Data pengajuan dapat dicetak atau disimpan sebagai PDF melalui browser.</p>
                    </div>
                    <button class="btn primary" onclick="window.print()">
                        Cetak Laporan
                    </button>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>NIK</th>
                                <th>Karyawan</th>
                                <th>Jenis Cuti</th>
                                <th>Mulai</th>
                                <th>Selesai</th>
                                <th>Hari</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= e($r['nik']) ?></td>
                                    <td><?= e($r['nama']) ?></td>
                                    <td><?= e($r['jenis_cuti']) ?></td>
                                    <td><?= e($r['tanggal_mulai']) ?></td>
                                    <td><?= e($r['tanggal_selesai']) ?></td>
                                    <td><?= (int)$r['total_hari'] ?></td>
                                    <td><?= e($r['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

        <footer>
            © <?= date('Y') ?> Cikarang Presisi · Sistem Informasi Pengajuan Cuti Karyawan
        </footer>
    </section>
</main>

<script src="assets/app.js"></script>
</body>
</html>
