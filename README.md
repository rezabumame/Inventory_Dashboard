# Bumame Inventory System (Phase 1)

## Setup Instructions (Vercel & Remote DB)

1.  **Database**: Import [database_schema.sql](file:///c:/xampp/htdocs/bumame_iventory2/database_schema.sql) into your remote MySQL database (e.g., TiDB Cloud).
2.  **Environment Variables**: Di dashboard Vercel (Settings > Environment Variables), tambahkan:
    - `DB_HOST`: Alamat host database (contoh: `gateway01.ap-southeast-1.prod.aws.tidbcloud.com:4000`)
    - `DB_USER`: Username database
    - `DB_PASS`: Password database
    - `DB_NAME`: Nama database (contoh: `test`)
    - `DB_PORT`: `4000` (untuk TiDB) atau `3306`
    - `DB_SSL`: `true` (wajib untuk TiDB Cloud)

## Default Credentials
**Semua password default adalah: `123456`**

| Role | Username | Notes |
| :--- | :--- | :--- |
| **Super Admin** | `superadmin` | Akses penuh ke seluruh sistem |
| **Admin Gudang** | `admingudang` | Manajemen Gudang Utama & Approve Request |
| **Admin Klinik** | `admin_[namaklinik]` | Akses per klinik (contoh: `admin_klinikpondokindah`) |
| **SPV Klinik** | `spv_[namaklinik]` | Supervisor per klinik (contoh: `spv_klinikpondokindah`) |
| **Petugas HC** | `[username_hc]` | Akses stok tas HC (menggunakan data real dari lokal) |
| **CS** | `cs_bumame` | View Klinik & Booking (Phase 2) |

> **Note**: Username Admin & SPV Klinik dibuat otomatis berdasarkan data klinik yang ada saat export database. Gunakan huruf kecil tanpa spasi untuk `[namaklinik]`.

## Features Implemented (Phase 1)
- **Authentication**: Login, Logout, Role-based Access Control.
- **Master Data**: Users, Kliniks, Barang (CRUD).
- **Inventory**:
  - **Gudang Utama**: View Stock, Manual Input (+/-).
  - **Stok Klinik**: View Stock (Filterable for Admin).
  - **Stok HC**: View Personal Bag Stock.
- **Request & Transfer**:
  - Create Request (HC -> Klinik, Klinik -> Gudang).
  - Approve Request (triggers automatic Stock Transfer).
  - View Request Status & History.
- **Dashboard**: Overview of stock and pending requests.

## Technology Stack
- PHP (Native)
- MySQL
- Bootstrap 5
- DataTables (jQuery)
