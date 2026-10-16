<?php
session_start();

// ==========================================
// 1. KONFIGURASI DATABASE & AUTO-INSTALLER
// ==========================================
$db_host = 'localhost';
$db_user = 'root'; // Sesuaikan dengan user database Anda
$db_pass = '';     // Sesuaikan dengan password database Anda
$db_name = 'db_jurnal_guru_pro';

// Koneksi awal tanpa database untuk mengecek/membuat database
$conn = new mysqli($db_host, $db_user, $db_pass);
if ($conn->connect_error) {
    die("Koneksi Server Database Gagal: " . $conn->connect_error);
}

// Cek apakah database sudah ada
$db_selected = $conn->select_db($db_name);
if (!$db_selected) {
    // Buat Database jika belum ada
    $sql_create_db = "CREATE DATABASE IF NOT EXISTS `$db_name`";
    if ($conn->query($sql_create_db)) {
        $conn->select_db($db_name);
        
        // Buat Tabel Users
        $conn->query("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            nama_lengkap VARCHAR(100) NOT NULL,
            role ENUM('admin', 'guru') NOT NULL
        )");
        
        // Buat Tabel Kelas
        $conn->query("CREATE TABLE kelas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_kelas VARCHAR(50) NOT NULL
        )");
        
        // Buat Tabel Mapel
        $conn->query("CREATE TABLE mapel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_mapel VARCHAR(100) NOT NULL
        )");
        
        // Buat Tabel Siswa
        $conn->query("CREATE TABLE siswa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nis VARCHAR(20) UNIQUE NOT NULL,
            nama_siswa VARCHAR(100) NOT NULL,
            kelas_id INT NOT NULL,
            qrcode VARCHAR(100) NOT NULL,
            FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE
        )");
        
        // Buat Tabel Jurnal
        $conn->query("CREATE TABLE jurnal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guru_id INT NOT NULL,
            kelas_id INT NOT NULL,
            mapel_id INT NOT NULL,
            tanggal DATE NOT NULL,
            materi TEXT NOT NULL,
            FOREIGN KEY (guru_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
            FOREIGN KEY (mapel_id) REFERENCES mapel(id) ON DELETE CASCADE
        )");
        
        // Buat Tabel Absensi
        $conn->query("CREATE TABLE absensi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jurnal_id INT NOT NULL,
            siswa_id INT NOT NULL,
            status ENUM('H', 'I', 'S', 'A') NOT NULL DEFAULT 'A',
            FOREIGN KEY (jurnal_id) REFERENCES jurnal(id) ON DELETE CASCADE,
            FOREIGN KEY (siswa_id) REFERENCES siswa(id) ON DELETE CASCADE
        )");
        
        // Buat Tabel Penilaian
        $conn->query("CREATE TABLE penilaian (
            id INT AUTO_INCREMENT PRIMARY KEY,
            siswa_id INT NOT NULL,
            mapel_id INT NOT NULL,
            jenis_nilai ENUM('Formatif', 'Sumatif') NOT NULL,
            nilai DECIMAL(5,2) NOT NULL,
            tanggal DATE NOT NULL,
            FOREIGN KEY (siswa_id) REFERENCES siswa(id) ON DELETE CASCADE,
            FOREIGN KEY (mapel_id) REFERENCES mapel(id) ON DELETE CASCADE
        )");

        // Insert Dummy Data
        $pass_admin = password_hash('admin', PASSWORD_DEFAULT);
        $pass_guru = password_hash('guru', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO users (username, password, nama_lengkap, role) VALUES 
            ('admin', '$pass_admin', 'Administrator System', 'admin'),
            ('guru1', '$pass_guru', 'Budi Santoso, S.Pd', 'guru')");
        
        $conn->query("INSERT INTO kelas (nama_kelas) VALUES ('X RPL 1'), ('X RPL 2')");
        $conn->query("INSERT INTO mapel (nama_mapel) VALUES ('Pemrograman Web'), ('Basis Data')");
        
        // Generate QR Code untuk siswa: format (SISWA-NIS-RANDOM)
        $qr1 = "QR-1001-" . bin2hex(random_bytes(4));
        $qr2 = "QR-1002-" . bin2hex(random_bytes(4));
        $conn->query("INSERT INTO siswa (nis, nama_siswa, kelas_id, qrcode) VALUES 
            ('1001', 'Ahmad Dahlan', 1, '$qr1'),
            ('1002', 'Siti Aminah', 1, '$qr2')");
    } else {
        die("Gagal membuat database: " . $conn->error);
    }
}

// ==========================================
// 2. ROUTING & CONTROLLER (AJAX & ACTIONS)
// ==========================================
$action = $_GET['action'] ?? '';
$api = $_GET['api'] ?? '';

// --- FUNGSI LOGIN & LOGOUT ---
if ($action === 'login') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $result = $conn->query("SELECT * FROM users WHERE username='$username'");
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['nama'] = $user['nama_lengkap'];
            echo json_encode(['status' => 'success', 'msg' => 'Login Berhasil!']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Password salah!']);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Username tidak ditemukan!']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Cek Sesi (Perlindungan Halaman)
$is_logged_in = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';

// --- API DATATABLES SERVER-SIDE ---
if ($api === 'dt_siswa' && $is_logged_in) {
    $draw = $_POST['draw'];
    $row = $_POST['start'];
    $rowperpage = $_POST['length'];
    $searchValue = $_POST['search']['value'] ?? '';

    $searchQuery = " ";
    if($searchValue != ''){
        $searchQuery = " AND (s.nis LIKE '%".$conn->real_escape_string($searchValue)."%' OR s.nama_siswa LIKE '%".$conn->real_escape_string($searchValue)."%' OR k.nama_kelas LIKE '%".$conn->real_escape_string($searchValue)."%') ";
    }

    $sel = $conn->query("SELECT count(*) as allcount FROM siswa s INNER JOIN kelas k ON s.kelas_id = k.id WHERE 1=1 ".$searchQuery);
    $records = $sel->fetch_assoc();
    $totalRecordwithFilter = $records['allcount'];
    
    $sel2 = $conn->query("SELECT count(*) as allcount FROM siswa");
    $records2 = $sel2->fetch_assoc();
    $totalRecords = $records2['allcount'];

    $empQuery = "SELECT s.*, k.nama_kelas FROM siswa s INNER JOIN kelas k ON s.kelas_id = k.id WHERE 1=1 ".$searchQuery." ORDER BY s.id DESC LIMIT ".$row.",".$rowperpage;
    $empRecords = $conn->query($empQuery);
    
    $data = [];
    $no = $row + 1;
    while ($row = $empRecords->fetch_assoc()) {
        $btn_print = "<button onclick='printQR(\"".$row['qrcode']."\", \"".$row['nama_siswa']."\")' class='bg-green-500 text-white px-2 py-1 rounded text-sm hover:bg-green-600'><i class='fas fa-print'></i> Cetak QR</button>";
        $data[] = [
            $no++,
            $row['nis'],
            $row['nama_siswa'],
            $row['nama_kelas'],
            $row['qrcode'],
            $btn_print
        ];
    }
    
    echo json_encode([
        "draw" => intval($draw),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "aaData" => $data
    ]);
    exit;
}

if ($api === 'dt_jurnal' && $is_logged_in) {
    // Server side untuk jurnal
    $draw = $_POST['draw'];
    $row = $_POST['start'];
    $rowperpage = $_POST['length'];
    $searchValue = $_POST['search']['value'] ?? '';

    $filterRole = ($role == 'guru') ? " AND j.guru_id = '".$_SESSION['user_id']."' " : "";
    
    $searchQuery = " ";
    if($searchValue != ''){
        $searchQuery = " AND (j.materi LIKE '%".$conn->real_escape_string($searchValue)."%' OR k.nama_kelas LIKE '%".$conn->real_escape_string($searchValue)."%') ";
    }

    $baseQuery = "FROM jurnal j JOIN users u ON j.guru_id=u.id JOIN kelas k ON j.kelas_id=k.id JOIN mapel m ON j.mapel_id=m.id WHERE 1=1 $filterRole $searchQuery";
    
    $totalRecordwithFilter = $conn->query("SELECT count(*) as allcount $baseQuery")->fetch_assoc()['allcount'];
    $totalRecords = $conn->query("SELECT count(*) as allcount FROM jurnal j WHERE 1=1 $filterRole")->fetch_assoc()['allcount'];

    $empQuery = "SELECT j.*, u.nama_lengkap, k.nama_kelas, m.nama_mapel $baseQuery ORDER BY j.tanggal DESC LIMIT $row, $rowperpage";
    $empRecords = $conn->query($empQuery);
    
    $data = [];
    $no = $row + 1;
    while ($r = $empRecords->fetch_assoc()) {
        $btn_absen = "<a href='?page=absensi&id=".$r['id']."' class='bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600'><i class='fas fa-users'></i> Input Absensi</a>";
        $data[] = [
            $no++,
            $r['tanggal'],
            $r['nama_lengkap'],
            $r['nama_kelas'],
            $r['nama_mapel'],
            $r['materi'],
            $btn_absen
        ];
    }
    
    echo json_encode(["draw" => intval($draw), "iTotalRecords" => $totalRecords, "iTotalDisplayRecords" => $totalRecordwithFilter, "aaData" => $data]);
    exit;
}

// --- API CRUD ---
if ($action === 'save_siswa' && $role == 'admin') {
    $nis = $conn->real_escape_string($_POST['nis']);
    $nama = $conn->real_escape_string($_POST['nama']);
    $kelas = intval($_POST['kelas_id']);
    $qrcode = "QR-" . $nis . "-" . strtoupper(bin2hex(random_bytes(3))); // Generate unik QR string

    $conn->query("INSERT INTO siswa (nis, nama_siswa, kelas_id, qrcode) VALUES ('$nis', '$nama', $kelas, '$qrcode')");
    echo json_encode(['status' => 'success', 'msg' => 'Siswa berhasil ditambahkan']);
    exit;
}

if ($action === 'save_jurnal' && $is_logged_in) {
    $guru_id = $_SESSION['user_id'];
    $kelas = intval($_POST['kelas_id']);
    $mapel = intval($_POST['mapel_id']);
    $materi = $conn->real_escape_string($_POST['materi']);
    $tgl = date('Y-m-d');

    $conn->query("INSERT INTO jurnal (guru_id, kelas_id, mapel_id, tanggal, materi) VALUES ($guru_id, $kelas, $mapel, '$tgl', '$materi')");
    $jurnal_id = $conn->insert_id;
    
    // Auto insert default absensi (Alpa) untuk semua siswa di kelas tersebut
    $siswas = $conn->query("SELECT id FROM siswa WHERE kelas_id = $kelas");
    while($s = $siswas->fetch_assoc()){
        $conn->query("INSERT INTO absensi (jurnal_id, siswa_id, status) VALUES ($jurnal_id, ".$s['id'].", 'A')");
    }

    echo json_encode(['status' => 'success', 'msg' => 'Jurnal berhasil dibuat, silakan isi absensi.', 'jurnal_id' => $jurnal_id]);
    exit;
}

if ($action === 'scan_absen' && $is_logged_in) {
    $qrcode = $conn->real_escape_string($_POST['qrcode']);
    $jurnal_id = intval($_POST['jurnal_id']);
    
    // Cari siswa
    $q_siswa = $conn->query("SELECT id, nama_siswa FROM siswa WHERE qrcode = '$qrcode'");
    if($q_siswa->num_rows > 0) {
        $siswa = $q_siswa->fetch_assoc();
        // Update Absensi ke Hadir (H)
        $conn->query("UPDATE absensi SET status = 'H' WHERE jurnal_id = $jurnal_id AND siswa_id = ".$siswa['id']);
        echo json_encode(['status' => 'success', 'msg' => $siswa['nama_siswa'] . ' berhasil diabsen (Hadir)!']);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'QR Code tidak terdaftar!']);
    }
    exit;
}

if ($action === 'update_absen_manual' && $is_logged_in) {
    $jurnal_id = intval($_POST['jurnal_id']);
    $siswa_id = intval($_POST['siswa_id']);
    $status = $conn->real_escape_string($_POST['status']);
    
    $conn->query("UPDATE absensi SET status = '$status' WHERE jurnal_id = $jurnal_id AND siswa_id = $siswa_id");
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'save_nilai' && $is_logged_in) {
    $siswa_id = intval($_POST['siswa_id']);
    $mapel_id = intval($_POST['mapel_id']);
    $jenis = $conn->real_escape_string($_POST['jenis']);
    $nilai = floatval($_POST['nilai']);
    $tgl = date('Y-m-d');
    
    $conn->query("INSERT INTO penilaian (siswa_id, mapel_id, jenis_nilai, nilai, tanggal) VALUES ($siswa_id, $mapel_id, '$jenis', $nilai, '$tgl')");
    echo json_encode(['status' => 'success', 'msg' => 'Nilai berhasil disimpan!']);
    exit;
}

// ==========================================
// 3. LAYOUT & VIEWS (HTML, CSS, JS)
// ==========================================
$page = $_GET['page'] ?? 'dashboard';

// View Login
if (!$is_logged_in) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Jurnal Guru Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-xl shadow-xl w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-600"><i class="fas fa-school"></i> EduJurnal</h1>
            <p class="text-gray-500 mt-2">Sistem Jurnal, Absensi QR & Penilaian</p>
        </div>
        <form id="loginForm" autocomplete="off">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Username</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-3 top-3 text-gray-400"></i>
                    <input type="text" name="username" class="w-full pl-10 pr-3 py-2 rounded-lg border border-gray-300 focus:outline-none focus:border-blue-500" required>
                </div>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <div class="relative">
                    <i class="fas fa-lock absolute left-3 top-3 text-gray-400"></i>
                    <input type="password" name="password" class="w-full pl-10 pr-3 py-2 rounded-lg border border-gray-300 focus:outline-none focus:border-blue-500" required>
                </div>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-200">LOGIN</button>
        </form>
        <div class="mt-4 text-xs text-gray-400 text-center">
            Default Admin: admin / admin <br> Default Guru: guru1 / guru
        </div>
    </div>
    <script>
        $('#loginForm').submit(function(e){
            e.preventDefault();
            $.post('index.php?action=login', $(this).serialize(), function(res){
                let data = JSON.parse(res);
                if(data.status == 'success'){
                    Swal.fire({icon: 'success', title: data.msg, showConfirmButton: false, timer: 1500}).then(()=> location.reload());
                } else {
                    Swal.fire({icon: 'error', title: 'Oops...', text: data.msg});
                }
            });
        });
    </script>
</body>
</html>
<?php 
    exit; 
} 
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Jurnal Guru Pro</title>
    <!-- CSS & Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- QR Code Libraries -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        .dataTables_wrapper .dataTables_paginate .paginate_button { padding: 0.2em 0.8em; margin-left: 2px; border-radius: 4px; }
        .dataTables_wrapper .dataTables_filter input { border: 1px solid #d1d5db; border-radius: 4px; padding: 2px 8px; margin-left: 8px;}
        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; }
        @media print {
            body * { visibility: hidden; }
            #print-area, #print-area * { visibility: visible; }
            #print-area { position: absolute; left: 0; top: 0; width: 100%; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-slate-800 text-white flex flex-col transition-all duration-300">
            <div class="p-5 flex items-center justify-center border-b border-slate-700">
                <i class="fas fa-book-open text-2xl text-blue-400 mr-2"></i>
                <h2 class="text-xl font-bold tracking-wider">EduJurnal</h2>
            </div>
            <div class="p-4 border-b border-slate-700">
                <p class="text-xs text-slate-400">Selamat datang,</p>
                <p class="font-semibold truncate"><?= $_SESSION['nama'] ?></p>
                <span class="inline-block px-2 py-1 mt-1 text-xs font-semibold bg-blue-500 rounded-full uppercase"><?= $_SESSION['role'] ?></span>
            </div>
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <a href="?page=dashboard" class="flex items-center px-4 py-3 text-gray-300 hover:bg-slate-700 hover:text-white rounded-lg <?= $page=='dashboard'?'bg-slate-700 text-white':'' ?>">
                    <i class="fas fa-tachometer-alt w-6"></i> Dashboard
                </a>
                
                <?php if($role == 'admin'): ?>
                <div class="pt-4 pb-1 text-xs font-semibold text-slate-500 uppercase tracking-wider">Master Data</div>
                <a href="?page=siswa" class="flex items-center px-4 py-3 text-gray-300 hover:bg-slate-700 hover:text-white rounded-lg <?= $page=='siswa'?'bg-slate-700 text-white':'' ?>">
                    <i class="fas fa-user-graduate w-6"></i> Data Siswa & QR
                </a>
                <!-- Simplifikasi Master lain untuk demo 1 file -->
                <?php endif; ?>

                <div class="pt-4 pb-1 text-xs font-semibold text-slate-500 uppercase tracking-wider">Akademik</div>
                <a href="?page=jurnal" class="flex items-center px-4 py-3 text-gray-300 hover:bg-slate-700 hover:text-white rounded-lg <?= $page=='jurnal'||$page=='absensi'?'bg-slate-700 text-white':'' ?>">
                    <i class="fas fa-book w-6"></i> Jurnal & Absensi
                </a>
                <a href="?page=penilaian" class="flex items-center px-4 py-3 text-gray-300 hover:bg-slate-700 hover:text-white rounded-lg <?= $page=='penilaian'?'bg-slate-700 text-white':'' ?>">
                    <i class="fas fa-star w-6"></i> Input Penilaian
                </a>
                <a href="?page=rekap" class="flex items-center px-4 py-3 text-gray-300 hover:bg-slate-700 hover:text-white rounded-lg <?= $page=='rekap'?'bg-slate-700 text-white':'' ?>">
                    <i class="fas fa-chart-bar w-6"></i> Rekap Nilai & Absen
                </a>
            </nav>
            <div class="p-4 border-t border-slate-700">
                <a href="?action=logout" class="flex items-center px-4 py-2 text-red-400 hover:bg-red-500 hover:text-white rounded-lg transition-colors">
                    <i class="fas fa-sign-out-alt w-6"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50">
            <!-- Header -->
            <header class="bg-white shadow-sm h-16 flex items-center px-6 justify-between">
                <h1 class="text-xl font-semibold text-gray-800 capitalize"><?= str_replace('_', ' ', $page) ?></h1>
                <div class="text-sm text-gray-500"><?= date('l, d F Y') ?></div>
            </header>

            <!-- Content Area -->
            <div class="flex-1 overflow-auto p-6">
                
                <?php
                // ==========================================
                // VIEW: DASHBOARD
                // ==========================================
                if ($page === 'dashboard'): 
                    $c_siswa = $conn->query("SELECT count(*) as c FROM siswa")->fetch_assoc()['c'];
                    $c_jurnal = $conn->query("SELECT count(*) as c FROM jurnal " . ($role=='guru' ? "WHERE guru_id=".$_SESSION['user_id'] : ""))->fetch_assoc()['c'];
                ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-blue-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Siswa</p>
                                    <p class="text-3xl font-bold text-gray-800"><?= $c_siswa ?></p>
                                </div>
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center text-blue-500 text-xl"><i class="fas fa-users"></i></div>
                            </div>
                        </div>
                        <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-green-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Jurnal Anda</p>
                                    <p class="text-3xl font-bold text-gray-800"><?= $c_jurnal ?></p>
                                </div>
                                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center text-green-500 text-xl"><i class="fas fa-book"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm p-6">
                        <h2 class="text-lg font-bold mb-4">Selamat Datang di Sistem Terpadu Jurnal, Absensi & Penilaian</h2>
                        <p class="text-gray-600">Sistem ini memfasilitasi guru untuk mendata kegiatan belajar mengajar (Jurnal), melakukan absensi secara digital dengan cepat melalui <strong>Pemindai QR Code</strong> atau manual, serta mencatat Penilaian Formatif dan Sumatif siswa secara real-time.</p>
                    </div>
                
                <?php
                // ==========================================
                // VIEW: SISWA (Hanya Admin)
                // ==========================================
                elseif ($page === 'siswa' && $role === 'admin'): 
                ?>
                    <div class="bg-white rounded-xl shadow-sm p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-bold">Data Siswa & QR Code</h2>
                            <button onclick="document.getElementById('modalTambahSiswa').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 shadow"><i class="fas fa-plus mr-2"></i> Tambah Siswa</button>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="tableSiswa" class="w-full whitespace-nowrap bg-white border border-gray-200">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="py-3 px-4 border-b text-left">No</th>
                                        <th class="py-3 px-4 border-b text-left">NIS</th>
                                        <th class="py-3 px-4 border-b text-left">Nama Siswa</th>
                                        <th class="py-3 px-4 border-b text-left">Kelas</th>
                                        <th class="py-3 px-4 border-b text-left">Kode QR</th>
                                        <th class="py-3 px-4 border-b text-left">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Modal Tambah -->
                    <div id="modalTambahSiswa" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
                        <div class="bg-white p-6 rounded-xl w-full max-w-md">
                            <h3 class="text-xl font-bold mb-4">Tambah Siswa</h3>
                            <form id="formTambahSiswa">
                                <div class="mb-3">
                                    <label class="block text-sm font-medium mb-1">NIS</label>
                                    <input type="text" name="nis" required class="w-full border p-2 rounded">
                                </div>
                                <div class="mb-3">
                                    <label class="block text-sm font-medium mb-1">Nama Lengkap</label>
                                    <input type="text" name="nama" required class="w-full border p-2 rounded">
                                </div>
                                <div class="mb-4">
                                    <label class="block text-sm font-medium mb-1">Kelas</label>
                                    <select name="kelas_id" required class="w-full border p-2 rounded">
                                        <?php 
                                        $kls = $conn->query("SELECT * FROM kelas");
                                        while($k = $kls->fetch_assoc()) echo "<option value='{$k['id']}'>{$k['nama_kelas']}</option>";
                                        ?>
                                    </select>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" onclick="document.getElementById('modalTambahSiswa').classList.add('hidden')" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Batal</button>
                                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Print Area QR -->
                    <div id="print-area" class="hidden">
                        <div class="p-8 text-center max-w-sm mx-auto border-2 border-gray-800 rounded-xl mt-10">
                            <h2 class="font-bold text-xl mb-2">KARTU ABSENSI SISWA</h2>
                            <h3 class="font-semibold text-lg mb-4" id="print-nama"></h3>
                            <div id="print-qrcode" class="flex justify-center mb-4"></div>
                            <p class="text-sm text-gray-500">Gunakan QR ini untuk presensi di kelas.</p>
                        </div>
                    </div>

                    <script>
                        $(document).ready(function() {
                            var tSiswa = $('#tableSiswa').DataTable({
                                'processing': true,
                                'serverSide': true,
                                'ajax': { 'url': 'index.php?api=dt_siswa', 'type': 'POST' },
                                'columns': [ { data: 0 }, { data: 1 }, { data: 2 }, { data: 3 }, { data: 4 }, { data: 5, orderable: false } ]
                            });

                            $('#formTambahSiswa').submit(function(e){
                                e.preventDefault();
                                $.post('index.php?action=save_siswa', $(this).serialize(), function(res){
                                    let data = JSON.parse(res);
                                    if(data.status == 'success'){
                                        Swal.fire('Berhasil!', data.msg, 'success');
                                        document.getElementById('modalTambahSiswa').classList.add('hidden');
                                        tSiswa.ajax.reload();
                                        $('#formTambahSiswa')[0].reset();
                                    }
                                });
                            });
                        });

                        function printQR(qrString, nama) {
                            document.getElementById('print-nama').innerText = nama;
                            document.getElementById('print-qrcode').innerHTML = ""; // clear old
                            new QRCode(document.getElementById("print-qrcode"), {
                                text: qrString, width: 200, height: 200
                            });
                            
                            setTimeout(() => {
                                window.print();
                            }, 500);
                        }
                    </script>

                <?php
                // ==========================================
                // VIEW: JURNAL & ABSENSI
                // ==========================================
                elseif ($page === 'jurnal'): 
                ?>
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 border-t-4 border-indigo-500">
                        <h2 class="text-lg font-bold mb-4">Buat Jurnal Mengajar Baru</h2>
                        <form id="formJurnal" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Kelas</label>
                                <select name="kelas_id" required class="w-full border-gray-300 border rounded-lg p-2 focus:ring-indigo-500 focus:border-indigo-500">
                                    <?php 
                                    $kls = $conn->query("SELECT * FROM kelas");
                                    while($k = $kls->fetch_assoc()) echo "<option value='{$k['id']}'>{$k['nama_kelas']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mata Pelajaran</label>
                                <select name="mapel_id" required class="w-full border-gray-300 border rounded-lg p-2 focus:ring-indigo-500 focus:border-indigo-500">
                                    <?php 
                                    $mpl = $conn->query("SELECT * FROM mapel");
                                    while($m = $mpl->fetch_assoc()) echo "<option value='{$m['id']}'>{$m['nama_mapel']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Materi / Pokok Bahasan</label>
                                <div class="flex gap-2">
                                    <input type="text" name="materi" required class="w-full border-gray-300 border rounded-lg p-2" placeholder="Contoh: Pengenalan HTML & CSS">
                                    <button type="submit" class="bg-indigo-600 text-white px-6 rounded-lg hover:bg-indigo-700 whitespace-nowrap font-medium shadow-sm"><i class="fas fa-save mr-1"></i> Mulai Kelas</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm p-6">
                        <h2 class="text-lg font-bold mb-4 text-gray-800">Riwayat Jurnal Mengajar</h2>
                        <div class="overflow-x-auto">
                            <table id="tableJurnal" class="w-full whitespace-nowrap bg-white border border-gray-200 text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="py-3 px-4 border-b text-left">No</th>
                                        <th class="py-3 px-4 border-b text-left">Tanggal</th>
                                        <th class="py-3 px-4 border-b text-left">Guru</th>
                                        <th class="py-3 px-4 border-b text-left">Kelas</th>
                                        <th class="py-3 px-4 border-b text-left">Mapel</th>
                                        <th class="py-3 px-4 border-b text-left">Materi</th>
                                        <th class="py-3 px-4 border-b text-center">Absensi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <script>
                        $(document).ready(function() {
                            var tJurnal = $('#tableJurnal').DataTable({
                                'processing': true,
                                'serverSide': true,
                                'ajax': { 'url': 'index.php?api=dt_jurnal', 'type': 'POST' },
                                'columns': [ { data: 0 }, { data: 1 }, { data: 2 }, { data: 3 }, { data: 4 }, { data: 5 }, { data: 6, orderable: false, className: 'text-center' } ]
                            });

                            $('#formJurnal').submit(function(e){
                                e.preventDefault();
                                $.post('index.php?action=save_jurnal', $(this).serialize(), function(res){
                                    let data = JSON.parse(res);
                                    if(data.status == 'success'){
                                        Swal.fire('Berhasil!', data.msg, 'success').then(() => {
                                            window.location = '?page=absensi&id=' + data.jurnal_id;
                                        });
                                    }
                                });
                            });
                        });
                    </script>

                <?php
                // ==========================================
                // VIEW: DETAIL ABSENSI (QR Scanner & Manual)
                // ==========================================
                elseif ($page === 'absensi' && isset($_GET['id'])): 
                    $j_id = intval($_GET['id']);
                    // Get Jurnal Info
                    $jInfo = $conn->query("SELECT j.*, k.nama_kelas, m.nama_mapel FROM jurnal j JOIN kelas k ON j.kelas_id=k.id JOIN mapel m ON j.mapel_id=m.id WHERE j.id=$j_id")->fetch_assoc();
                ?>
                    <div class="mb-4 flex items-center justify-between">
                        <a href="?page=jurnal" class="text-blue-600 hover:underline"><i class="fas fa-arrow-left"></i> Kembali ke Jurnal</a>
                        <h2 class="text-xl font-bold">Absensi Kelas: <?= $jInfo['nama_kelas'] ?> | Mapel: <?= $jInfo['nama_mapel'] ?></h2>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Scanner Area -->
                        <div class="bg-white rounded-xl shadow p-4 lg:col-span-1 text-center">
                            <h3 class="font-bold text-lg mb-3 text-gray-800"><i class="fas fa-qrcode text-indigo-500"></i> Scanner QR Siswa</h3>
                            <div id="qr-reader" class="overflow-hidden rounded-lg border-2 border-dashed border-gray-300"></div>
                            <p class="text-xs text-gray-500 mt-3">Arahkan kartu QR siswa ke kamera.</p>
                            
                            <div class="mt-4 p-3 bg-indigo-50 rounded-lg hidden" id="scan-result-box">
                                <span class="block text-sm text-indigo-600 font-semibold mb-1">Status Scan Terakhir:</span>
                                <span id="scan-result-text" class="font-bold text-lg text-indigo-800"></span>
                            </div>
                        </div>

                        <!-- Manual List Area -->
                        <div class="bg-white rounded-xl shadow p-4 lg:col-span-2">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="font-bold text-lg text-gray-800"><i class="fas fa-list text-green-500"></i> Daftar Absensi Manual</h3>
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">Tgl: <?= $jInfo['tanggal'] ?></span>
                            </div>
                            <div class="overflow-y-auto max-h-[500px]">
                                <table class="w-full text-left border-collapse text-sm">
                                    <thead>
                                        <tr class="bg-gray-100 sticky top-0 shadow-sm">
                                            <th class="p-3 border-b font-medium">NIS</th>
                                            <th class="p-3 border-b font-medium">Nama Siswa</th>
                                            <th class="p-3 border-b font-medium text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="absen-tbody">
                                        <?php
                                        // Fetch absensi
                                        $absen_q = $conn->query("SELECT a.siswa_id, a.status, s.nis, s.nama_siswa FROM absensi a JOIN siswa s ON a.siswa_id = s.id WHERE a.jurnal_id = $j_id ORDER BY s.nama_siswa ASC");
                                        while($a = $absen_q->fetch_assoc()){
                                            $st = $a['status'];
                                        ?>
                                        <tr class="border-b hover:bg-gray-50 transition">
                                            <td class="p-3"><?= $a['nis'] ?></td>
                                            <td class="p-3 font-medium text-gray-700"><?= $a['nama_siswa'] ?></td>
                                            <td class="p-3 text-center">
                                                <div class="inline-flex rounded-md shadow-sm" role="group">
                                                    <button type="button" onclick="updateAbsen(<?= $j_id ?>, <?= $a['siswa_id'] ?>, 'H', this)" class="px-3 py-1 text-xs font-medium border border-gray-200 rounded-s-lg <?= $st=='H'?'bg-green-500 text-white':'bg-white text-gray-900 hover:bg-gray-100' ?>">H</button>
                                                    <button type="button" onclick="updateAbsen(<?= $j_id ?>, <?= $a['siswa_id'] ?>, 'I', this)" class="px-3 py-1 text-xs font-medium border-t border-b border-gray-200 <?= $st=='I'?'bg-blue-500 text-white':'bg-white text-gray-900 hover:bg-gray-100' ?>">I</button>
                                                    <button type="button" onclick="updateAbsen(<?= $j_id ?>, <?= $a['siswa_id'] ?>, 'S', this)" class="px-3 py-1 text-xs font-medium border-t border-b border-l border-gray-200 <?= $st=='S'?'bg-yellow-400 text-white':'bg-white text-gray-900 hover:bg-gray-100' ?>">S</button>
                                                    <button type="button" onclick="updateAbsen(<?= $j_id ?>, <?= $a['siswa_id'] ?>, 'A', this)" class="px-3 py-1 text-xs font-medium border border-gray-200 rounded-e-lg <?= $st=='A'?'bg-red-500 text-white':'bg-white text-gray-900 hover:bg-gray-100' ?>">A</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <script>
                        // Fungsi Update Absen Manual via AJAX
                        function updateAbsen(jurnal_id, siswa_id, status, btnElement) {
                            $.post('index.php?action=update_absen_manual', {jurnal_id: jurnal_id, siswa_id: siswa_id, status: status}, function(res){
                                let data = JSON.parse(res);
                                if(data.status == 'success'){
                                    // Reset color class for siblings
                                    $(btnElement).siblings().removeClass('bg-green-500 bg-blue-500 bg-yellow-400 bg-red-500 text-white').addClass('bg-white text-gray-900');
                                    // Set color for clicked button
                                    $(btnElement).removeClass('bg-white text-gray-900 text-white');
                                    if(status == 'H') $(btnElement).addClass('bg-green-500 text-white');
                                    if(status == 'I') $(btnElement).addClass('bg-blue-500 text-white');
                                    if(status == 'S') $(btnElement).addClass('bg-yellow-400 text-white');
                                    if(status == 'A') $(btnElement).addClass('bg-red-500 text-white');
                                }
                            });
                        }

                        // Inisialisasi HTML5 QR Scanner
                        let html5QrcodeScanner = new Html5QrcodeScanner("qr-reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
                        let lastScan = "";

                        function onScanSuccess(decodedText, decodedResult) {
                            // Cegah spam scan untuk kode yang sama dalam waktu singkat
                            if(decodedText !== lastScan) {
                                lastScan = decodedText;
                                
                                // Proses AJAX Absen
                                $.post('index.php?action=scan_absen', { qrcode: decodedText, jurnal_id: <?= $j_id ?> }, function(res){
                                    let data = JSON.parse(res);
                                    $('#scan-result-box').removeClass('hidden');
                                    if(data.status == 'success'){
                                        $('#scan-result-text').text(data.msg).removeClass('text-red-600').addClass('text-green-600');
                                        // Suara Bip Sukses (opsional, using simple HTML5 audio if provided)
                                        setTimeout(() => { location.reload(); }, 1500); // Reload untuk update UI warna manual (simplifikasi)
                                    } else {
                                        $('#scan-result-text').text(data.msg).removeClass('text-green-600').addClass('text-red-600');
                                    }
                                });

                                // Reset anti-spam
                                setTimeout(() => { lastScan = ""; }, 3000);
                            }
                        }

                        function onScanFailure(error) {
                            // handle scan failure, usually better to ignore and keep scanning
                        }
                        
                        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
                    </script>

                <?php
                // ==========================================
                // VIEW: PENILAIAN (Formatif & Sumatif)
                // ==========================================
                elseif ($page === 'penilaian'): 
                ?>
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                        <h2 class="text-lg font-bold mb-4">Input Nilai Formatif & Sumatif</h2>
                        <form id="formPenilaian" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                            <div class="md:col-span-1">
                                <label class="block text-sm font-medium mb-1">Mata Pelajaran</label>
                                <select name="mapel_id" required class="w-full border p-2 rounded focus:ring-blue-500">
                                    <?php 
                                    $mpl = $conn->query("SELECT * FROM mapel");
                                    while($m = $mpl->fetch_assoc()) echo "<option value='{$m['id']}'>{$m['nama_mapel']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium mb-1">Siswa</label>
                                <select name="siswa_id" required class="w-full border p-2 rounded focus:ring-blue-500">
                                    <?php 
                                    $ssw = $conn->query("SELECT s.id, s.nama_siswa, k.nama_kelas FROM siswa s JOIN kelas k ON s.kelas_id=k.id ORDER BY k.nama_kelas, s.nama_siswa");
                                    while($s = $ssw->fetch_assoc()) echo "<option value='{$s['id']}'>{$s['nama_kelas']} - {$s['nama_siswa']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="md:col-span-1">
                                <label class="block text-sm font-medium mb-1">Jenis Nilai</label>
                                <select name="jenis" required class="w-full border p-2 rounded focus:ring-blue-500">
                                    <option value="Formatif">Formatif (Harian/Tugas)</option>
                                    <option value="Sumatif">Sumatif (UTS/UAS)</option>
                                </select>
                            </div>
                            <div class="md:col-span-1">
                                <label class="block text-sm font-medium mb-1">Nilai (0-100)</label>
                                <div class="flex gap-2">
                                    <input type="number" name="nilai" min="0" max="100" step="0.01" required class="w-full border p-2 rounded">
                                    <button type="submit" class="bg-blue-600 text-white px-4 rounded hover:bg-blue-700 shadow"><i class="fas fa-save"></i></button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-700 mb-3">10 Entri Nilai Terakhir Anda</h3>
                        <table class="w-full text-sm text-left text-gray-500 border border-gray-200">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 border-b">Tanggal</th>
                                    <th class="px-4 py-3 border-b">Nama Siswa</th>
                                    <th class="px-4 py-3 border-b">Mata Pelajaran</th>
                                    <th class="px-4 py-3 border-b">Jenis</th>
                                    <th class="px-4 py-3 border-b text-center">Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $q_nilai = $conn->query("SELECT p.*, s.nama_siswa, m.nama_mapel FROM penilaian p JOIN siswa s ON p.siswa_id=s.id JOIN mapel m ON p.mapel_id=m.id ORDER BY p.id DESC LIMIT 10");
                                if($q_nilai->num_rows > 0) {
                                    while($n = $q_nilai->fetch_assoc()) {
                                        $badgeClass = $n['jenis_nilai'] == 'Formatif' ? 'bg-purple-100 text-purple-800' : 'bg-pink-100 text-pink-800';
                                        echo "<tr class='border-b hover:bg-gray-50'>
                                                <td class='px-4 py-2'>{$n['tanggal']}</td>
                                                <td class='px-4 py-2 font-medium text-gray-900'>{$n['nama_siswa']}</td>
                                                <td class='px-4 py-2'>{$n['nama_mapel']}</td>
                                                <td class='px-4 py-2'><span class='text-xs font-semibold px-2 py-0.5 rounded $badgeClass'>{$n['jenis_nilai']}</span></td>
                                                <td class='px-4 py-2 text-center font-bold text-gray-700'>{$n['nilai']}</td>
                                              </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center py-4'>Belum ada data nilai.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <script>
                        $('#formPenilaian').submit(function(e){
                            e.preventDefault();
                            $.post('index.php?action=save_nilai', $(this).serialize(), function(res){
                                let data = JSON.parse(res);
                                if(data.status == 'success'){
                                    Swal.fire({
                                        icon: 'success', title: 'Tersimpan', text: data.msg, showConfirmButton: false, timer: 1000
                                    }).then(()=> location.reload());
                                }
                            });
                        });
                    </script>

                <?php 
                // ==========================================
                // VIEW: REKAPITULASI (Cetak/Print)
                // ==========================================
                elseif ($page === 'rekap'):
                ?>
                    <div class="bg-white p-6 rounded-xl shadow-sm">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xl font-bold">Rekapitulasi Kehadiran & Nilai Global</h2>
                            <button onclick="window.print()" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-900"><i class="fas fa-print mr-2"></i> Cetak Laporan</button>
                        </div>
                        <div class="overflow-x-auto" id="print-area">
                            <h2 class="hidden print:block text-2xl font-bold text-center mb-4">Laporan Kehadiran & Penilaian Siswa</h2>
                            <table class="w-full text-sm text-left border-collapse border border-gray-300">
                                <thead class="bg-gray-100 print:bg-white text-gray-700 uppercase">
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-center" rowspan="2">No</th>
                                        <th class="border border-gray-300 p-2" rowspan="2">NIS - Nama Siswa</th>
                                        <th class="border border-gray-300 p-2" rowspan="2">Kelas</th>
                                        <th class="border border-gray-300 p-2 text-center" colspan="4">Total Kehadiran</th>
                                        <th class="border border-gray-300 p-2 text-center" colspan="2">Rata-rata Nilai</th>
                                    </tr>
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-center text-green-600">H</th>
                                        <th class="border border-gray-300 p-2 text-center text-blue-600">I</th>
                                        <th class="border border-gray-300 p-2 text-center text-yellow-600">S</th>
                                        <th class="border border-gray-300 p-2 text-center text-red-600">A</th>
                                        <th class="border border-gray-300 p-2 text-center text-purple-600">Formatif</th>
                                        <th class="border border-gray-300 p-2 text-center text-pink-600">Sumatif</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_rekap = $conn->query("
                                        SELECT s.id, s.nis, s.nama_siswa, k.nama_kelas,
                                            (SELECT COUNT(*) FROM absensi a WHERE a.siswa_id = s.id AND a.status='H') as H,
                                            (SELECT COUNT(*) FROM absensi a WHERE a.siswa_id = s.id AND a.status='I') as I,
                                            (SELECT COUNT(*) FROM absensi a WHERE a.siswa_id = s.id AND a.status='S') as S,
                                            (SELECT COUNT(*) FROM absensi a WHERE a.siswa_id = s.id AND a.status='A') as A,
                                            (SELECT AVG(nilai) FROM penilaian p WHERE p.siswa_id = s.id AND p.jenis_nilai='Formatif') as avg_formatif,
                                            (SELECT AVG(nilai) FROM penilaian p WHERE p.siswa_id = s.id AND p.jenis_nilai='Sumatif') as avg_sumatif
                                        FROM siswa s JOIN kelas k ON s.kelas_id = k.id
                                        ORDER BY k.nama_kelas, s.nama_siswa
                                    ");
                                    $no=1;
                                    while($r = $q_rekap->fetch_assoc()){
                                        $fmt = $r['avg_formatif'] ? number_format($r['avg_formatif'],1) : '-';
                                        $smt = $r['avg_sumatif'] ? number_format($r['avg_sumatif'],1) : '-';
                                        echo "<tr>
                                                <td class='border border-gray-300 p-2 text-center'>{$no}</td>
                                                <td class='border border-gray-300 p-2 font-medium'>{$r['nis']} - {$r['nama_siswa']}</td>
                                                <td class='border border-gray-300 p-2'>{$r['nama_kelas']}</td>
                                                <td class='border border-gray-300 p-2 text-center'>{$r['H']}</td>
                                                <td class='border border-gray-300 p-2 text-center'>{$r['I']}</td>
                                                <td class='border border-gray-300 p-2 text-center'>{$r['S']}</td>
                                                <td class='border border-gray-300 p-2 text-center'>{$r['A']}</td>
                                                <td class='border border-gray-300 p-2 text-center font-bold'>{$fmt}</td>
                                                <td class='border border-gray-300 p-2 text-center font-bold'>{$smt}</td>
                                              </tr>";
                                        $no++;
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <style>
                        @media print {
                            body * { visibility: hidden; }
                            #print-area, #print-area * { visibility: visible; }
                            #print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 20px; }
                            aside, header { display: none; }
                        }
                    </style>
                <?php endif; ?>

            </div>
        </main>
    </div>
</body>
</html>