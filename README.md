# Bumame Inventory Management System

A comprehensive web-based inventory and booking management system designed for healthcare operations, featuring multi-role access control, automated stock synchronization, and professional reporting.

## 🚀 Key Features

### 👤 Role-Based Access Control (RBAC)
- **Super Admin**: Full system access, including configuration and user management.
- **Admin Gudang**: Manages the Main Warehouse, approves inventory requests, and monitors Odoo synchronization.
- **Admin & SPV Klinik**: Manages stock and requests at the clinic level.
- **Petugas HC (Home Care)**: Manages personal bag stock for home visits and field operations.
- **CS (Customer Service)**: Handles patient bookings and views stock availability.

### 📦 Inventory & Stock Management
- **Main Warehouse**: Synchronized with **Odoo ERP** for real-time stock accuracy.
- **Clinic Stock**: Real-time visibility and management of items across different clinic locations.
- **HC Personal Bag**: Specialized tracking for items carried by field officers.
- **Request & Transfer**: Automated workflow for requesting items (HC -> Klinik -> Gudang) with instant stock movement upon approval.
- **UoM Conversion**: Built-in tools for converting units (e.g., Box to Pieces) to maintain accurate counts.

### 📅 Booking & Healthcare Workflow
- **Booking Management**: Create and track patient bookings for various medical examinations.
- **Stock Reservation**: Automatically reserves stock needed for upcoming bookings to ensure availability.
- **Pemakaian BHP (Bahan Habis Pakai)**: Track the actual consumption of medical supplies, automatically deducting stock upon use.

### 🔗 Integrations
- **Odoo (RPC/JSON-RPC)**: Automated and manual synchronization of stock levels and warehouse data.
- **Lark/Feishu Webhook**: Real-time notifications for synchronization success or failure reports.
- **Google Sheets**: Integration via Web Apps Script for real-time reporting of booking data.

---

## 🛠️ System Architecture & Modules

### Master Data
- **Users**: Manage credentials and roles.
- **Klinik**: Database of clinic locations.
- **Barang (Products)**: Detailed catalog with UoM management.
- **Pemeriksaan (Examinations)**: List of medical services and their associated items.
- **Petugas HC**: Profile management for field staff.

### Inventory Modules
- **Stok Klinik / Stok HC**: Location-specific stock monitoring.
- **Permintaan Barang**: End-to-end request lifecycle with QR code verification.
- **Pemakaian BHP**: Operational usage tracking.
- **Booking List**: Integrated scheduling and stock allocation.

### Configuration
- **Integrasi Odoo**: RPC settings, scheduler (Interval, Daily, Weekly), and location mapping.
- **Webhook Settings**: Lark and Google Sheets endpoint configuration.

---

## 💻 Technology Stack
- **Backend**: Native PHP 8.x
- **Database**: MySQL / TiDB
- **Frontend**: Bootstrap 5, DataTables (jQuery), FontAwesome 6
- **Integrations**: XML-RPC (Odoo), Webhooks (Lark, Google Sheets)
- **Reports**: FPDF, PHPQRCode

---

## ⚙️ Setup Instructions

1.  **Database**: Import `database_schema.sql` into your MySQL/TiDB database.
2.  **Environment Variables**: Configure the following in your environment:
    - `DB_HOST`: Database host
    - `DB_USER`: Database user
    - `DB_PASS`: Database password
    - `DB_NAME`: Database name
    - `DB_SSL`: `true` for secure connections (required for TiDB Cloud)
3.  **Permissions**: Ensure the `assets/uploads/` directory is writable.

---

## 🔑 Default Credentials
**Standard password for all accounts: `123456`**

| Role | Username Example |
| :--- | :--- |
| **Super Admin** | `superadmin` |
| **Admin Gudang** | `admingudang` |
| **Admin Klinik** | `admin_[namaklinik]` |
| **SPV Klinik** | `spv_[namaklinik]` |
| **Petugas HC** | `[username_hc]` |
