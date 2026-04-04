<?php
require_once 'config/database.php';

/**
 * Script Seeder untuk membuat akun-akun dummy sesuai permintaan user.
 * Password default: 123456
 */

$password_hash = password_hash('123456', PASSWORD_DEFAULT);

$users = [
    [
        'username' => 'superadmin',
        'nama_lengkap' => 'Super Administrator',
        'role' => 'super_admin',
        'klinik_id' => 0,
        'status' => 'active'
    ],
    [
        'username' => 'admingudang',
        'nama_lengkap' => 'Admin Gudang Utama',
        'role' => 'admin_gudang',
        'klinik_id' => 0,
        'status' => 'active'
    ],
    [
        'username' => 'adminklinik1',
        'nama_lengkap' => 'Admin Klinik Pratama',
        'role' => 'admin_klinik',
        'klinik_id' => 3, // Menggunakan ID 3 (Cideng) sebagai contoh
        'status' => 'active'
    ],
    [
        'username' => 'hc1',
        'nama_lengkap' => 'Petugas HC Lapangan',
        'role' => 'petugas_hc',
        'klinik_id' => 3,
        'status' => 'active'
    ],
    [
        'username' => 'cs1',
        'nama_lengkap' => 'Customer Service',
        'role' => 'cs',
        'klinik_id' => 0,
        'status' => 'active'
    ]
];

echo "Memulai seeding akun dummy...\n";

foreach ($users as $u) {
    $username = $u['username'];
    
    // Cek apakah user sudah ada
    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $res = $check->get_result();
    
    if ($res->num_rows > 0) {
        // Update password jika sudah ada
        $stmt = $conn->prepare("UPDATE users SET password = ?, role = ?, nama_lengkap = ?, klinik_id = ?, status = ? WHERE username = ?");
        $stmt->bind_param("sssiss", $password_hash, $u['role'], $u['nama_lengkap'], $u['klinik_id'], $u['status'], $username);
        $stmt->execute();
        echo "[UPDATE] User '$username' berhasil diperbarui.\n";
    } else {
        // Insert baru
        $stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, klinik_id, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssis", $username, $password_hash, $u['nama_lengkap'], $u['role'], $u['klinik_id'], $u['status']);
        $stmt->execute();
        echo "[INSERT] User '$username' berhasil dibuat.\n";
    }
}

echo "\nSeeding selesai! Semua password adalah: 123456\n";
$conn->close();
?>
