-- Bumame Inventory Database Schema Dump
-- Generated on 2026-04-04 08:22:46

CREATE TABLE `app_counters` (
  `k` varchar(50) NOT NULL,
  `d` char(8) NOT NULL,
  `seq` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`,`d`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `app_settings` (
  `k` varchar(100) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `barang` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode_barang` varchar(50) NOT NULL,
  `nama_barang` varchar(100) NOT NULL,
  `satuan` varchar(20) NOT NULL,
  `stok_minimum` int(11) DEFAULT 0,
  `kategori` varchar(50) DEFAULT NULL,
  `odoo_product_id` varchar(64) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `uom` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode_barang` (`kode_barang`),
  UNIQUE KEY `uniq_kode_barang` (`kode_barang`),
  UNIQUE KEY `uniq_odoo_product_id` (`odoo_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7850 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `barang_uom_conversion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barang_id` int(11) NOT NULL,
  `from_uom` varchar(20) DEFAULT NULL,
  `to_uom` varchar(20) DEFAULT NULL,
  `multiplier` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `note` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_barang` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `booking_pasien_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty_gantung` int(11) NOT NULL,
  `qty_reserved_onsite` int(11) DEFAULT 0,
  `qty_reserved_hc` int(11) DEFAULT 0,
  `qty_done_onsite` int(11) DEFAULT 0,
  `qty_adjust` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_booking_id` (`booking_id`),
  KEY `idx_booking_barang` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking_pasien` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `nama_pasien` varchar(100) NOT NULL,
  `pemeriksaan_grup_id` int(11) NOT NULL,
  `nomor_tlp` varchar(30) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking_pemeriksaan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nomor_booking` varchar(50) NOT NULL,
  `order_id` varchar(100) DEFAULT NULL,
  `klinik_id` int(11) NOT NULL,
  `status_booking` varchar(50) DEFAULT 'Reserved - Clinic',
  `nama_pemesan` varchar(200) DEFAULT NULL,
  `jumlah_pax` int(11) DEFAULT 1,
  `nakes_hc` varchar(200) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `tanggal_pemeriksaan` date NOT NULL,
  `status` enum('booked','completed','cancelled') DEFAULT 'booked',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `booking_type` varchar(10) DEFAULT NULL,
  `jam_layanan` varchar(10) DEFAULT NULL,
  `jotform_submitted` tinyint(1) NOT NULL DEFAULT 0,
  `cs_name` varchar(100) DEFAULT NULL,
  `nomor_tlp` varchar(30) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `butuh_fu` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_booking` (`nomor_booking`),
  KEY `idx_bp_klinik_status_tgl` (`klinik_id`,`status`,`tanggal_pemeriksaan`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking_request_dedup` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_request_id` varchar(64) NOT NULL,
  `created_by` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client` (`client_request_id`,`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `hc_petugas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `klinik_id` int(11) NOT NULL,
  `nama_petugas` varchar(120) NOT NULL,
  `location_code` varchar(120) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_klinik` (`klinik_id`),
  KEY `idx_loc` (`location_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `hc_petugas_transfer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `klinik_id` int(11) NOT NULL,
  `user_hc_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_klinik` (`klinik_id`),
  KEY `idx_user` (`user_hc_id`),
  KEY `idx_barang` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `hc_tas_allocation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `klinik_id` int(11) NOT NULL,
  `user_hc_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_klinik` (`klinik_id`),
  KEY `idx_user` (`user_hc_id`),
  KEY `idx_barang` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=458 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `klinik` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode_klinik` varchar(20) NOT NULL,
  `kode_homecare` varchar(50) DEFAULT NULL,
  `nama_klinik` varchar(100) NOT NULL,
  `alamat` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode_klinik` (`kode_klinik`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `odoo_format_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internal_reference` varchar(100) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `uom` varchar(50) DEFAULT NULL,
  `product_category` varchar(255) DEFAULT NULL,
  `income_account` varchar(255) DEFAULT NULL,
  `valuation_account` varchar(255) DEFAULT NULL,
  `expense_account` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=607 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `odoo_support_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key_name` varchar(100) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pemakaian_bhp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nomor_pemakaian` varchar(50) NOT NULL,
  `tanggal` date NOT NULL,
  `jenis_pemakaian` enum('klinik','hc') NOT NULL COMMENT 'Pemakaian Klinik atau HC',
  `klinik_id` int(11) DEFAULT NULL COMMENT 'Klinik yang melakukan pemakaian',
  `user_hc_id` int(11) DEFAULT NULL,
  `catatan_transaksi` text DEFAULT NULL COMMENT 'Catatan untuk keseluruhan transaksi',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_pemakaian` (`nomor_pemakaian`),
  KEY `klinik_id` (`klinik_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_pbh_klinik_jenis_created` (`klinik_id`,`jenis_pemakaian`,`created_at`),
  CONSTRAINT `fk_pemakaian_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_pemakaian_klinik` FOREIGN KEY (`klinik_id`) REFERENCES `klinik` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pemakaian_bhp_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pemakaian_bhp_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `satuan` varchar(20) NOT NULL,
  `catatan_item` text DEFAULT NULL COMMENT 'Catatan untuk item ini',
  PRIMARY KEY (`id`),
  KEY `pemakaian_bhp_id` (`pemakaian_bhp_id`),
  KEY `barang_id` (`barang_id`),
  CONSTRAINT `fk_pemakaian_detail_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`),
  CONSTRAINT `fk_pemakaian_detail_header` FOREIGN KEY (`pemakaian_bhp_id`) REFERENCES `pemakaian_bhp` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pemeriksaan_grup` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_pemeriksaan` varchar(100) NOT NULL,
  `keterangan` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pemeriksaan_grup_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pemeriksaan_grup_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty_per_pemeriksaan` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `request_barang` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nomor_request` varchar(50) NOT NULL,
  `dari_level` enum('klinik','hc') NOT NULL,
  `dari_id` int(11) NOT NULL,
  `ke_level` enum('gudang_utama','klinik') NOT NULL,
  `ke_id` int(11) NOT NULL,
  `status` enum('pending','approved','partial','rejected','completed','pending_gudang','pending_spv','rejected_spv','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dokumen_path` varchar(255) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `dokumen_name` varchar(255) DEFAULT NULL,
  `spv_approved_by` int(11) DEFAULT NULL,
  `spv_approved_at` datetime DEFAULT NULL,
  `spv_qr_token` varchar(80) DEFAULT NULL,
  `spv_rejected_by` int(11) DEFAULT NULL,
  `spv_rejected_at` datetime DEFAULT NULL,
  `request_qr_token` varchar(80) DEFAULT NULL,
  `request_qr_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_request` (`nomor_request`),
  UNIQUE KEY `idx_spv_token` (`spv_qr_token`),
  UNIQUE KEY `idx_request_token` (`request_qr_token`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `request_barang_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_barang_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty_request` int(11) NOT NULL,
  `qty_approved` int(11) DEFAULT 0,
  `qty_received` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_req_detail_req` (`request_barang_id`),
  KEY `idx_req_detail_barang` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `request_barang_dokumen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_barang_id` int(11) NOT NULL,
  `dokumen_path` varchar(255) NOT NULL,
  `dokumen_name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `stock_mirror` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `odoo_product_id` varchar(64) NOT NULL,
  `kode_barang` varchar(64) NOT NULL,
  `location_code` varchar(100) NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_loc_prod` (`odoo_product_id`,`location_code`),
  KEY `idx_loc_code` (`location_code`,`kode_barang`)
) ENGINE=InnoDB AUTO_INCREMENT=6313 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `stok_gudang_klinik` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barang_id` int(11) NOT NULL,
  `klinik_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `qty_gantung` int(11) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barang_klinik` (`barang_id`,`klinik_id`),
  KEY `idx_sgk_klinik_barang` (`klinik_id`,`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `stok_gudang_utama` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `reserved_qty` int(11) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barang_id` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `stok_tas_hc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barang_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `klinik_id` int(11) NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barang_user` (`barang_id`,`user_id`),
  KEY `idx_sth_user_barang` (`user_id`,`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=367 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transaksi_stok` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barang_id` int(11) NOT NULL,
  `level` enum('gudang_utama','klinik','hc') NOT NULL,
  `level_id` int(11) NOT NULL,
  `tipe_transaksi` enum('in','out','adjust') NOT NULL,
  `qty` int(11) NOT NULL,
  `qty_sebelum` int(11) NOT NULL,
  `qty_sesudah` int(11) NOT NULL,
  `referensi_tipe` varchar(50) NOT NULL,
  `referensi_id` int(11) NOT NULL,
  `catatan` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ts_level_created_barang` (`level`,`level_id`,`created_at`,`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transfer_barang` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nomor_transfer` varchar(50) NOT NULL,
  `request_barang_id` int(11) DEFAULT NULL,
  `dari_level` enum('gudang_utama','klinik') NOT NULL,
  `dari_id` int(11) NOT NULL,
  `ke_level` enum('klinik','hc') NOT NULL,
  `ke_id` int(11) NOT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'completed',
  `transfer_by` int(11) NOT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_transfer` (`nomor_transfer`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transfer_barang_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_barang_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_trf_detail_trf` (`transfer_barang_id`),
  KEY `idx_trf_detail_barang` (`barang_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `upload_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `filename` varchar(255) DEFAULT NULL,
  `status` enum('success','failed') DEFAULT NULL,
  `rows_success` int(11) DEFAULT NULL,
  `rows_failed` int(11) DEFAULT NULL,
  `error_details` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama_lengkap` varchar(100) NOT NULL,
  `role` enum('super_admin','admin_gudang','admin_klinik','cs','b2b_ops','petugas_hc','spv_klinik','spv_manager','manager_klinik') NOT NULL,
  `klinik_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `photo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

