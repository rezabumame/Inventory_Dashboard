# 🩺 Bumame Inventory Dashboard (Pro Version)

A high-performance, premium web-based inventory and booking management system tailored for healthcare operations. Built with **Native PHP 8.x**, this system ensures precise stock tracking, automated ERP synchronization, and seamless team collaboration via Lark.

---

## ✨ What's New (Premium Update)
The system has been upgraded with a **Modern Premium UI** featuring:
- **Outfit Typography**: Sleek and modern font for enhanced readability.
- **Bumame Blue Branding**: Consistent corporate identity (#204EAB).
- **Soft UI Components**: Elegant cards, refined badges (IN/OUT), and smooth hover effects.
- **Responsive Layout**: Optimized for both desktop and tablet monitoring.

---

## 🚀 Key Modules & Features

### 👤 Role-Based Access Control (RBAC)
| Role | Access Level | Responsibilities |
| :--- | :--- | :--- |
| **Super Admin** | Full | System config, user management, and security settings. |
| **Admin Gudang** | Warehouse | Odoo sync management, stock approval, and main storage tracking. |
| **Admin & SPV** | Clinic | Local stock management, request items, and BHP tracking. |
| **Petugas HC** | Personal Bag | Field operation stock, transfers, and inventory returns. |
| **CS** | Operational | Patient booking, inventory reservation, and follow-up tagging. |

### 📦 Smart Inventory System
- **Odoo ERP Sync**: Seamlessly sync main warehouse stock using JSON-RPC. Supports manual, interval, daily, or weekly schedules.
- **Dual-POV Monitoring**: View stock from both Warehouse (Odoo) and Local Clinic perspective.
- **Reservation -> Deduction**: Automatic stock reservation upon booking and final deduction upon completion.
- **Public Stock View**: Generate a **Read-Only Public Token** to share real-time stock visibility with external teams without needing a login.

### 💬 Lark Integration & Automation
- **Custom Bot Webhook**: Automated reports sent directly to Lark Groups.
- **Smart Mentions**: System tags staff (e.g., `@Reza Mahendra`) for items needing follow-up (FU), ensuring no request is missed.
- **Sync Reporting**: Real-time notification if Odoo sync fails or succeeds.

### 📅 Booking Workflow
- **Granular Completion**: Complete patient bookings per-patient to maintain accurate inventory movement.
- **BHP Mapping**: Automatic calculation of medical supplies needed per-examination.

---

## ⚙️ System Configuration

### Environment Setup
1.  **Database**: Import `database_schema.sql` (MySQL/TiDB).
2.  **Config**: Update `.env` with your credentials:
    - `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
    - `DB_SSL=true` (Required for TiDB Cloud)
    - `LARK_WEBHOOK_URL` (For notifications)

### Settings Menu
Navigate to **Pengaturan Sistem** to configure:
- **RPC Odoo**: Server URL, Database, and User credentials.
- **Scheduler**: Set the frequency of auto-sync (e.g., every 15 mins).
- **Danger Zone**: One-click cleanup for transaction data (Master data remains safe).

---

## 🛠️ Technology Stack
- **Backend**: Native PHP 8.2+
- **Database**: MySQL / TiDB
- **Frontend**: Bootstrap 5, Outfit Google Font, FontAwesome 6, DataTables.
- **Integrations**: Lark API (Custom Bot), Odoo XML-RPC, Google Sheets Apps Script.
- **Security**: CSRF Protection, Password Hashing (Bcrypt), Token-based Public Access.

---

## 🔑 Access Cheat Sheet
**Standard password for all accounts: `123456`**

- **Super Admin**: `superadmin`
- **Admin Gudang**: `admingudang`
- **Admin Klinik**: `admin_[klinik_name]`
- **CS**: `cs_pusat`

---

*Developed with ❤️ for Bumame Health Operations.*
