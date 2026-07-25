# BIS — Barcode Internal System v2
> Living doc. Update setiap ada feedback atau keputusan baru.

---

## Apa yang sedang dibangun

BIS adalah modul tambahan di dalam sistem inventory Bumame, fokus pada 3 halaman operasional:

| Halaman | Route | Fungsi |
|---|---|---|
| Inbound Barang | `qr_inbound` | Scan/cari item → update barcode vendor → generate & print barcode internal BIS-XXXX |
| Stok Opname | `qr_stock_opname` | SO per klinik → scan/hitung fisik → bandingkan dengan stok sistem → export hasil |
| Database Barang | `qr_master_barcode` | Lihat & kelola master item + barcode internal |

**BIS bukan sistem stok baru.** Sumber kebenaran stok tetap Odoo. BIS adalah alat operasional lapangan (scan, label, opname).

---

## Status Frontend (Dummy Data)

- [x] Sidebar BIS terpisah (`sidebar_bis.php`) — 3 menu + Kembali ke Sistem
- [x] `qr_inbound` — scan barcode, cari by nama/kode, ED tracking popup, print label
  - Mapping modal: dropdown pilih item + toggle Track ED + input **Jumlah Cetak** (muncul otomatis saat `label_print === 'physical'`, default 1, diteruskan ke print popup)
  - Print label: buka **popup window** terpisah (bukan `window.print()` dari dalam modal) — 1 label per halaman `@page { size: 50mm 30mm }`, tidak ada duplikasi
  - [x] Tab ke-3 **Tarik Odoo**: input no. referensi picking → tarik list produk via RPC (real, read-only) — lihat detail di bawah
- [x] `qr_stock_opname` — pilih klinik, scan/hitung fisik, import stok Excel (single & bulk), export hasil
- [x] `qr_master_barcode` — gabungan `page=barang` + BIS config:
  - Tab **Barang Odoo** (DataTable): Kode, Nama, UOM+Ratio, Tipe, Min Stok, BIS Barcode, Vendor, Track ED, Label Config, Aksi
  - Tab **Barang Non-Odoo** (DataTable): Kode Item, Nama, UOM, BIS Barcode, Track ED, Label Config, Aksi
  - Filter chips (aktif & fungsional via `data-*` attributes + `$.fn.dataTable.ext.search.push`): Semua / Belum Ada BIS / Label Belum Diset / Belum Ada Vendor / Track ED / Min Stok = 0
  - Tombol **Kelola** membuka modal gabungan: Info Dasar (Tipe + Min Stok) → QR Internal → Track ED → Barcode Vendor → Konfigurasi Label
    - Garis pemisah antar seksi: solid `border-top: 1.5px solid #d0d7de`
    - Setelah **Simpan Konfigurasi**: SweetAlert 1.6 dtk → modal tutup otomatis → baris di DataTable terupdate langsung di DOM (chip Label Config + `data-label-set`) tanpa reload
  - Import Min Stok (popup): Step 1 download template + panduan kolom, Step 2 upload Excel
  - Export template (`api/export_template_min_stok.php`): deteksi kolom BIS via `SHOW COLUMNS` — siap setelah DB migration
- [ ] Semua masih dummy data — belum konek ke DB

---

## Rencana Implementasi DB (v2)

### Prinsip
- **Pakai tabel yang sudah ada** sebisa mungkin, jangan buat duplikat
- Prefix tabel tetap `inventory_` sesuai sistem yang ada
- Tidak ada tabel stok baru — stok dari `inventory_stok_gudang_klinik`

---

### 1. Tambahan kolom di tabel existing

```sql
-- inventory_barang sudah ada: id, kode_barang, nama_barang, satuan, barcode (kosong/unused), odoo_product_id, uom, dll.

ALTER TABLE inventory_barang
  ADD COLUMN barcode_internal  VARCHAR(30)                              NULL UNIQUE AFTER barcode,
  -- format: BIS-XXXX (generate otomatis dari kode_barang, padded 4 digit)
  ADD COLUMN track_ed          TINYINT(1)                   NOT NULL DEFAULT 0 AFTER barcode_internal,
  -- 0 = tidak track ED, 1 = track ED (ada batch expiry date)
  ADD COLUMN label_print       ENUM('none','physical')      NOT NULL DEFAULT 'none' AFTER track_ed,
  -- 'none'     = label cukup di sistem saja, tidak perlu cetak fisik
  -- 'physical' = perlu cetak label fisik dan ditempel/disimpan
  ADD COLUMN label_placement   ENUM('unit','box','outer','catalogue') NULL AFTER label_print,
  -- hint untuk admin gudang: tempel di mana / simpan di katalog
  -- NULL jika label_print = 'none'
  ADD COLUMN label_config_set  TINYINT(1)                   NOT NULL DEFAULT 0 AFTER label_placement;
  -- 0 = belum dikonfigurasi (item baru dari Odoo), 1 = sudah diset via import Excel atau manual

-- Kolom `barcode` yang sudah ada (selama ini kosong) → dipakai sebagai barcode vendor utama
-- Barcode vendor tambahan → tabel baru di bawah
```

**Cara pengisian awal:** upload Excel → inject massal ke `label_print`, `label_placement`, `label_config_set`.
Item baru yang belum ada di Excel → `label_config_set = 0`, admin gudang set sendiri pertama kali inbound.

---

### 2. Tabel baru yang perlu dibuat

#### `inventory_barcode_vendor`
Satu item bisa punya banyak barcode vendor (kardus berbeda, supplier berbeda).

```sql
CREATE TABLE inventory_barcode_vendor (
  id           INT(11) AUTO_INCREMENT PRIMARY KEY,
  barang_id    INT(11) NOT NULL,
  barcode      VARCHAR(100) NOT NULL,
  keterangan   VARCHAR(100) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (barang_id) REFERENCES inventory_barang(id) ON DELETE CASCADE,
  UNIQUE KEY uq_barcode (barcode)
);
```

#### `inventory_barang_ed`
Batch ED per item per klinik. Bisa banyak baris per item (multi-batch).

```sql
CREATE TABLE inventory_barang_ed (
  id           INT(11) AUTO_INCREMENT PRIMARY KEY,
  barang_id    INT(11) NOT NULL,
  klinik_id    INT(11) NOT NULL,
  ed_month     VARCHAR(7) NOT NULL,   -- format: YYYY-MM
  keterangan   VARCHAR(100) NULL,
  created_by   INT(11) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (barang_id)  REFERENCES inventory_barang(id) ON DELETE CASCADE,
  FOREIGN KEY (klinik_id)  REFERENCES inventory_klinik(id) ON DELETE CASCADE
);
```

#### `inventory_stok_opname`
Header sesi SO per klinik.

```sql
CREATE TABLE inventory_stok_opname (
  id              INT(11) AUTO_INCREMENT PRIMARY KEY,
  klinik_id       INT(11) NOT NULL,
  user_id         INT(11) NOT NULL,
  tanggal_mulai   DATETIME NOT NULL,
  tanggal_selesai DATETIME NULL,
  status          ENUM('draft','selesai','batal') DEFAULT 'draft',
  catatan         TEXT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (klinik_id) REFERENCES inventory_klinik(id),
  FOREIGN KEY (user_id)   REFERENCES inventory_users(id)
);
```

#### `inventory_stok_opname_detail`
Detail per item dalam satu sesi SO.

```sql
CREATE TABLE inventory_stok_opname_detail (
  id            INT(11) AUTO_INCREMENT PRIMARY KEY,
  opname_id     INT(11) NOT NULL,
  barang_id     INT(11) NOT NULL,
  stok_sistem   DECIMAL(18,4) NOT NULL DEFAULT 0,  -- snapshot dari inventory_stok_gudang_klinik saat SO dimulai
  qty_fisik     DECIMAL(18,4) NULL,                -- hasil hitung fisik
  selisih       DECIMAL(18,4) NULL,                -- qty_fisik - stok_sistem (computed/stored)
  status        ENUM('pending','ok','selisih') DEFAULT 'pending',
  FOREIGN KEY (opname_id) REFERENCES inventory_stok_opname(id) ON DELETE CASCADE,
  FOREIGN KEY (barang_id) REFERENCES inventory_barang(id)
);
```

---

### 3. Tabel yang TIDAK perlu dibuat

| Yang dipikir perlu | Alasan tidak perlu |
|---|---|
| `bis_barang` / master barang baru | Sudah ada `inventory_barang` (703 rows) |
| `bis_lokasi` | Sudah ada `inventory_klinik` |
| `bis_stok_lokasi` | Sudah ada `inventory_stok_gudang_klinik` |
| Tabel inbound/log print | Inbound hanya update barcode vendor + generate/print label, tidak ada pencatatan stok masuk |
| Tabel transaksi BIS baru | Pakai `inventory_transaksi_stok` yang sudah ada jika nanti ada pergerakan stok |

---

## Flow per Halaman (saat go live)

### Inbound Barang
1. Scan barcode vendor atau cari by nama/kode barang
2. Item ketemu di `inventory_barang` → tampilkan info
3. Jika `track_ed = 1` → input ED batch → simpan ke `inventory_barang_ed`
4. Simpan/update barcode vendor ke `inventory_barcode_vendor`
5. Generate `barcode_internal = BIS-{kode_barang:04d}` → update `inventory_barang.barcode_internal`
6. Print label

#### Tarik referensi Odoo langsung (sudah diimplementasi — real RPC, read-only)
- Tab **Tarik Odoo** di `qr_inbound`: input nomor referensi picking Odoo (contoh: `WHS01/IN/00031`) → sistem tarik daftar produk dari picking tersebut via RPC, tanpa perlu scan satu-satu.
- **Read-only** — hanya query ke Odoo, tidak menulis ke DB lokal. Aman dipakai meski `qr_inbound` masih dummy data untuk fitur lain.
- Endpoint baru: `api/odoo_picking_lookup.php?ref=...` — pakai kredensial RPC dari `page=settings_integrasi` (`odoo_rpc_url/db/username/password` via `get_setting()`), auth lewat `odoo_rpc_authenticate()` → query `odoo_rpc_execute_kw()`.
- Model Odoo: `stock.picking` (search by `name`, exact lalu fallback `ilike`) → `stock.move` terkait (field: `product_id`, `product_uom_qty` (demand), `quantity`, `product_uom`).
- Hasil list ditampilkan dengan tombol **Proses** per baris:
  - Nama produk Odoo berformat `[KODE] Nama Barang` — KODE adalah `default_code` Odoo yang sama dengan `kode_barang` lokal. Matching diutamakan by **kode** (regex `/^\[([^\]]+)\]/`), fallback ke substring nama kalau kode tidak ketemu.
  - Tidak match → buka modal mapping seperti barcode tak dikenal, label item Odoo ditampilkan sebagai teks (bukan barcode asli)
  - Match ditemukan → cek konfigurasi label item (`label_print` dari Kelola Barcode di `qr_master_barcode`):
    - `label_print === 'physical'` (dikonfigurasi cetak) → langsung tawarkan popup cetak label (qty + print)
    - selain itu (pakai barcode vendor existing, tidak perlu label baru) → buka popup yang sama dengan print (`modalPrintPopup`), tapi row "Jumlah Cetak" diganti row **"Scan barcode vendor"** (`scanMode=true` di `showPrintPopup()`) — input auto-focus, Enter langsung konfirmasi, tombol footer berubah jadi "Konfirmasi Penerimaan"
- Contoh dummy untuk testing kedua skenario (`qr_inbound.php` → `$dummy_all_items` id 9 & 10, mengikuti data real picking `WHS01/IN/00050`):
  - `[489] Tabung Vaccutainer Biru` — `label_print = physical` → trigger popup cetak
  - `[492] Tabung Vaccutainer Hijau` — `label_print = none` → trigger dialog scan barcode vendor
- **Penanda baris sudah diproses**: tiap baris hasil tarikan diberi `id="odooRow_<idx>"`. Setelah aksi selesai (print dikonfirmasi, barcode vendor dikonfirmasi, atau mapping modal ditutup), tombol "Proses" diganti badge hijau "Sudah Diproses" + baris jadi pudar (`markOdooItemProcessed()`). State idx aktif dilacak via `window._activeOdooRowIdx`, direset ke `null` di flow Scan/Cari Item lain supaya tidak salah menandai baris.
- **Badge konfigurasi label** ditampilkan langsung di tiap baris hasil (sebelum diproses), hasil matching `matchLocalItem()` (logika sama dipakai ulang dari `processOdooItem`):
  - Tidak match ke master lokal → badge merah "Belum ada di master lokal"
  - Match tapi `label_config_set = false` → badge kuning "Label Belum Diset"
  - `label_print = physical` → badge hijau "Cetak Fisik · {placement}"
  - `label_print = none` → badge abu "Sistem Saja · Pakai Barcode Vendor"
- Belum ada penyimpanan log referensi yang sudah diproses — risiko reprocessing referensi sama 2x. Tabel `inventory_odoo_inbound_log` (lihat di bawah) direncanakan untuk menutup celah ini, **belum dibuat**.

**Apakah butuh tabel DB untuk fitur ini?**
- Untuk **lookup-nya sendiri: tidak perlu.** Murni read-only RPC → tampilkan hasil di browser, tidak ada yang disimpan.
- **Tapi perlu 1 tabel log kecil** untuk mencegah referensi yang sama diproses 2x (risiko: barcode internal / label tercetak dobel kalau admin gudang tidak sadar referensi sudah diinbound sebelumnya):

```sql
CREATE TABLE inventory_odoo_inbound_log (
  id              INT(11) AUTO_INCREMENT PRIMARY KEY,
  picking_ref     VARCHAR(50) NOT NULL,        -- contoh: WHS01/IN/00031
  picking_id_odoo INT(11) NULL,                -- id stock.picking di Odoo
  items_count     INT(11) NOT NULL DEFAULT 0,
  processed_by    INT(11) NULL,
  processed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_picking_ref (picking_ref),
  FOREIGN KEY (processed_by) REFERENCES inventory_users(id)
);
```
- Saat referensi ditarik: cek dulu apakah `picking_ref` sudah ada di tabel ini → kalau ada, tampilkan warning "Referensi ini sudah pernah diproses pada [tanggal] oleh [user]" sebelum lanjut (tetap bisa override manual kalau memang perlu reprint).
- Insert ke tabel ini terjadi **setelah** admin gudang menyelesaikan proses inbound dari referensi tsb (bukan saat lookup awal).

### Stok Opname
1. Pilih klinik → query `inventory_klinik`
2. Load item + stok sistem dari `inventory_stok_gudang_klinik JOIN inventory_barang`
3. *(Opsional)* Import stok sistem dari Excel jika data Odoo tidak sinkron
4. Scan / hitung fisik → update `qty_fisik` di `inventory_stok_opname_detail`
5. Simpan hasil → `inventory_stok_opname` + `inventory_stok_opname_detail`
6. Export Excel hasil SO

### Database Barang
1. List dari `inventory_barang` + join `inventory_barcode_vendor`
2. Bisa generate barcode internal untuk item yang belum punya
3. Print barcode internal

---

## Keputusan yang sudah dikunci

- Prefix tabel: `inventory_` (bukan `bis_`)
- Barcode internal format: `BIS-{kode_barang padded 4 digit}` — contoh: `BIS-0015`
- Stok sistem SO: dari `inventory_stok_gudang_klinik`, bukan import wajib
- Import Excel SO: tetap ada sebagai fallback jika data Odoo tidak sinkron
- Inbound: tidak mencatat stok masuk — hanya update barcode & print label
- Sidebar BIS terpisah dari sidebar utama
- ED tracking: flag di `inventory_barang.track_ed`, data batch di `inventory_barang_ed`
- Label config: 3 kolom di `inventory_barang` — `label_print`, `label_placement`, `label_config_set`
  - Jumlah cetak tetap ditentukan admin gudang (tidak di-lock sistem)
  - Config hanya sebagai **hint** referensi: "Cetak Fisik — tempel di Unit → sesuaikan qty per unit masuk"
  - `label_placement` options: `unit` / `box` / `outer` / `catalogue` (semua tetap print biasa, beda penempatan fisik)
  - Pengisian awal via upload Excel massal; item baru → admin set sendiri saat inbound pertama kali

---

## Pending / Belum Diputuskan

- [ ] Role akses BIS: siapa saja yang boleh akses inbound / SO / database barang?
- [ ] Apakah SO bisa di-pause dan dilanjut sesi lain (status `draft` disimpan), atau harus selesai dalam satu sesi?
- [ ] Generate `barcode_internal` otomatis saat item baru masuk Odoo, atau manual saat pertama kali inbound?
- [ ] Export hasil SO — apakah perlu dikirim ke email / Odoo, atau cukup download Excel?

---

*Last updated: 2026-06-30*
