# Bumame Inventory System (Phase 1)

## Setup Instructions

1.  **Database**: Import `database.sql` into your MySQL database (e.g., via phpMyAdmin). Create a database named `bumame_inventory`.
2.  **Config**: Check `config/database.php` and ensure the credentials match your local setup.
    ```php
    $host = 'localhost';
    $user = 'root';
    $pass = '';
    $db   = 'bumame_inventory';
    ```
3.  **Run**: Access the application via your browser (e.g., `http://localhost/bumame_iventory2/`).

## Default Credentials
All default passwords are: `123456`

| Role | Username | Notes |
|Str|Str|Str|
| Super Admin | `superadmin` | Full Access |
| Admin Gudang | `admingudang` | Gudang Utama, Approve Request |
| Admin Klinik | `adminklinik1` | Klinik 1 Access, Request to Gudang |
| Petugas HC | `hc1` | HC Bag (Klinik 1), Request to Klinik |
| CS | `cs1` | View Clinics, Booking (Phase 2) |

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
