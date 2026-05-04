<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

check_role(['super_admin']);

$header = [
    'Username' => 'string',
    'Password' => 'string',
    'Nama Lengkap' => 'string',
    'Role' => 'string',
    'Klinik ID' => 'number'
];

$data = [
    ['user1', 'pass123', 'User Pertama', 'admin_klinik', 1],
    ['user2', 'pass456', 'User Kedua', 'spv_klinik', 1],
    ['user3', 'pass789', 'User Ketiga', 'petugas_hc', 2]
];

$roles_info = [
    ['Catatan Role yang tersedia:'],
    ['super_admin'],
    ['admin_gudang'],
    ['admin_klinik'],
    ['spv_klinik'],
    ['petugas_hc'],
    ['cs'],
    ['b2b_ops'],
    ['spv_manager'],
    ['manager_klinik'],
    [''],
    ['Klinik ID bisa dilihat di menu Master > Klinik']
];

$xlsx = SimpleXLSXGen::fromArray(array_merge([array_keys($header)], $data, [['']], $roles_info));
$xlsx->downloadAs('template_bulk_user.xlsx');
