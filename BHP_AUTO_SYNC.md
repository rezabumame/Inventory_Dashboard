# BHP Auto Sync — Integrasi Otomatis n8n + Metabase → Inventory

Dokumen ini menjelaskan cara kerja fitur sinkronisasi otomatis Pemakaian BHP (klinik & HC) dari data CIS/HC (lewat Metabase) ke sistem Inventory Bumame, berdampingan dengan input manual/Excel yang sudah ada.

## 1. Kenapa fitur ini ada

Sebelumnya, Pemakaian BHP dicatat manual atau lewat upload Excel oleh admin tiap klinik. Fitur ini menambah jalur **ketiga**, otomatis, berdasarkan data transaksi asli dari sistem CIS (klinik) dan HC — supaya data BHP lebih real-time dan mengurangi kerja manual, tanpa mematikan 2 jalur lama (keduanya tetap bisa dipakai kalau sync otomatis gagal/kelewat).

## 2. Arsitektur

```
n8n (Schedule Trigger, tiap 1 jam)
   │
   ▼
Metabase — native SQL question "BHP Gabungan HC dan Klinik"
   │ (hasil: array baris transaksi BHP klinik + HC)
   ▼
n8n "Code in JavaScript" (gabung jadi { rows: [...] })
   │
   ▼
HTTP Request POST → api/bhp_auto_sync.php  (header X-Api-Key)
   │
   ├─ sukses (HTTP 2xx) ──► If ──► Code in JavaScript1 (bangun 2 card Lark)
   │                                   │
   │                                   ├─► HTTP Request1 → Lark: card "✅ Berhasil Diproses"
   │                                   └─► HTTP Request1 → Lark: card "⚠️ Perlu Dicek" / "✅ Semua Bersih"
   │
   └─ gagal (timeout/connection error, "On Error: Continue using error output")
                                        │
                                        └─► HTTP Request - Error ke Lark → Lark: card "🔴 BHP Auto Sync GAGAL"
```

Endpoint kita (`api/bhp_auto_sync.php`) mencatat hasil akhirnya ke tabel `inventory_pemakaian_bhp` dengan `is_auto = 0` (dianggap data FINAL, bukan perkiraan) dan `change_source = 'sistem_integrasi'` — ini yang membedakannya dari input manual (`change_source = 'manual'`) dan dari estimasi booking (`is_auto = 1`, tab terpisah "Estimasi (Auto)").

## 3. Query Metabase — range waktu & logicnya

Query-nya adalah 1 native SQL question yang menggabungkan 2 sumber data (CIS untuk klinik, HC untuk home care) jadi satu hasil lewat `UNION ALL`.

### 3.1. Range waktu (berapa jam/hari ke belakang)

Query punya 2 parameter: `{{date_from}}` dan `{{date_to}}`. Kalau n8n **tidak mengisi** parameter ini (yang memang jadi standar operasional kita), query otomatis jatuh ke default:

```sql
GREATEST(enc.updated_at, enc.created_at) >= (CURRENT_TIMESTAMP - INTERVAL '1 day')
GREATEST(enc.updated_at, enc.created_at) <= (CURRENT_TIMESTAMP + INTERVAL '1 day')
```

Jadi defaultnya: **H-1 hari sampai H+1 hari** dari waktu query dijalankan (pola `[[({{date_from}}::timestamp - INTERVAL '7 hours') --]] (CURRENT_TIMESTAMP - INTERVAL '1 day')` — kalau parameter diisi dipakai, kalau kosong baris itu "dicoret" dan fallback-nya yang jalan).

**Kenapa H-1 sampai H+1, bukan cuma "hari ini"?** Karena filter tanggalnya bukan berdasarkan **tanggal pemakaian BHP** (`tanggal_pemakaian`), tapi berdasarkan **kapan baris itu terakhir disentuh/diubah** (`GREATEST(updated_at, created_at)`). Artinya: kalau ada data BHP tanggal kemarin yang baru dikoreksi/ditambahkan hari ini, data itu tetap akan ketarik di run berikutnya — selama "sentuhan terakhir"-nya (edit/insert) masih dalam jendela H-1 sampai H+1. Tanggal pemakaian BHP yang sebenarnya (`tanggal_pemakaian`) tetap ditampilkan apa adanya di output, tidak terpengaruh jendela ini.

Karena sync jalan tiap 1 jam, jendela H-1/H+1 ini jauh lebih lebar dari interval sync-nya sendiri — jadi aman kalau 1-2 run sempat gagal/di-skip (misal sistem down), run berikutnya tetap bisa nangkep data yang ketinggalan.

> ⚠️ Operasional kita (klinik): berdasarkan diskusi tim, revisi/koreksi data klinik di CIS **selalu di hari yang sama** (tidak bisa backdate dari sisi CIS-nya). Revisi data HC paling lambat biasa terjadi **H+1**. Jadi jendela H-1/H+1 ini sudah lebih dari cukup untuk operasional normal — bukan berarti sistem tidak bisa menangkap lebih lama dari itu kalau suatu saat dibutuhkan (tinggal perbesar angka interval-nya), tapi default sekarang sengaja dipas dengan pola kerja nyata di lapangan.

### 3.2. Logic per sumber data

**CTE `filtered_encounter_ids`** (gerbang awal buat data klinik/CIS):
- `enc.is_deleted = FALSE`
- `enc.transaction_type != 'b2b'` — B2B dikecualikan total dari sync ini
- `enc.clinic_id IN (1, 2, 3, 5, 8)` — whitelist 5 klinik yang sudah siap datanya di Metabase (Makassar sengaja belum termasuk, kode Odoo-nya masih beda — tetap manual sampai datanya siap)
- filter jendela waktu seperti di atas

**CTE `cis_data`** (hasil akhir baris klinik):
- Join `cis_encounters` → `cis_expendable_item_usages` (eiu, "sesi pemakaian") → `cis_expendable_item_usage_items` (eiui, "baris item per pemakaian")
- `source_row_id` = `eiui.id` — **wajib pakai ini**, bukan kombinasi `transaction_id + kode_barang` (riwayat bug, lihat §5)
- `COALESCE(eiui.is_deleted, FALSE) = FALSE` — baris item yang dibatalkan di sumbernya tidak ikut ketarik
- `channel` di-hardcode `'B2C'` (channel B2X2C tidak ada untuk data CIS)
- `barang_odoo_id` sebenarnya berisi nilai yang cocok dengan `inventory_barang.kode_barang` kita, **BUKAN** `odoo_product_id` (sudah diverifikasi lewat beberapa sample item)

**CTE `filtered_details`** (gerbang awal buat data HC):
- `d.bhp_status = 'filled'` — **sengaja TIDAK** filter `appointment_status` (misal 'done'), karena mayoritas data HC statusnya bukan 'done' walau BHP-nya sudah final/terisi
- `a.code_branch_id IN (1, 2, 3, 5, 8)` — whitelist sama seperti klinik
- B2B dikecualikan (channel_status diseragamkan dulu, `b2d2c` → `b2x2c`, baru dibandingkan `!= 'B2B'`)
- Bound kasar 30 hari (`GREATEST(d.updated_at, d.created_at) >= CURRENT_TIMESTAMP - INTERVAL '30 days'`) — ini cuma gerbang awal yang longgar, filter presisi H-1/H+1 yang sebenarnya ada di CTE `hc_data`

**CTE `hc_data`** (hasil akhir baris HC):
- `source_row_id` = `bhpci.id` (`bumame_bhp_consumable_item.id`) — sudah granular per baris sejak awal, tidak pernah kena bug collision seperti klinik
- Resolusi klinik nakes: dipakai klinik yang tercatat **di baris sumber itu sendiri** (`fd.code_branch_id`), bukan klinik "saat ini" si nakes di sistem kita — ini supaya kalau nakes pindah klinik, histori lamanya tetap benar menunjuk ke klinik lama
- Filter jendela waktu presisi: `GREATEST(bhpci.updated_at/created_at, bhp.updated_at/created_at, fd.detail_updated_at/created_at)` — ngecek 3 level tabel sekaligus (item, BHP, detail appointment), bukan cuma 1 level

**Filter tambahan opsional** (di `SELECT` akhir, pakai sintaks `[[AND ...]]`):
`jenis`, `branch`, `nakes`, `bhp`, `quantity`, `channel` — **semua ini cuma untuk investigasi manual di Metabase**, n8n tidak pernah mengisi parameter-parameter ini (dibiarkan kosong = filter itu tidak aktif sama sekali).

## 4. Logic di endpoint (`api/bhp_auto_sync.php`)

### 4.1. Auth
Header `X-Api-Key`, dibandingkan dengan `hash_equals()` ke key yang tersimpan di `inventory_app_settings` (key `bhp_auto_api_key`). Bukan session/CSRF karena dipanggil mesin (n8n), bukan browser.

### 4.2. Pemetaan klinik (Dinamis by Alamat/Nama/Kode)
Sama seperti pada modul Upload Excel, sistem mencari `id` klinik secara dinamis dari tabel `inventory_klinik` berdasarkan `alamat`, `nama_klinik`, atau `kode_klinik` (bukan mengunci angka id database), sehingga tahan terhadap perbedaan ID database antara local & production:
```php
const BRANCH_PATTERNS = [
    '1' => ['alamat' => 'Cideng',        'nama' => '%Cideng%',     'kode' => 'CDG%', 'default_id' => 3],
    '2' => ['alamat' => 'TBS',           'nama' => '%Simatupang%', 'kode' => 'TBS%', 'default_id' => 4],
    '3' => ['alamat' => 'BSD',           'nama' => '%BSD%',        'kode' => 'BSD%', 'default_id' => 5],
    '5' => ['alamat' => 'Puri',          'nama' => '%Puri%',       'kode' => 'PRI%', 'default_id' => 11],
    '8' => ['alamat' => 'Kelapa Gading', 'nama' => '%Gading%',     'kode' => 'GAD%', 'default_id' => 12],
];
```
`clinic_branch_id` dari sumber (nilai id di `bumame_branch`) dicocokkan ke whitelist di atas lalu di-resolve ke database. Branch yang tidak ada di pola ini (misal Makassar) otomatis di-skip dengan alasan "di luar whitelist klinik yang sudah siap".

### 4.3. Satu nomor BHP per (klinik/nakes + tanggal)
`find_or_create_header()` mencari header `inventory_pemakaian_bhp` yang sudah ada untuk kombinasi `(klinik_id, tanggal, jenis_pemakaian, user_hc_id, change_source='sistem_integrasi', status='active')`. Kalau ketemu, baris baru/berubah ditempelkan ke nomor itu. Kalau belum ada, baru dibuatkan nomor baru (`BHP-{kode_klinik}-{YYMM}-{seq}`, pakai `next_sequence()` yang sama dengan jalur manual/Excel).

- **Klinik**: `user_hc_id` selalu `NULL` untuk jenis klinik → otomatis 1 nomor per klinik per tanggal, tidak peduli berapa nakes yang terlibat di data sumbernya.
- **HC**: `user_hc_id` diisi id nakes hasil resolusi → 1 nomor per (nakes + tanggal), karena stok HC itu per-orang (tas masing-masing nakes), beda dengan stok klinik yang digabung 1 gudang.

### 4.4. Tracking per baris sumber (incremental, bukan delete+rebuild)
Tabel `inventory_bhp_sync_tracking` menyimpan 1 baris per `source_key` (`hc:{bhpci.id}` atau `klinik:{eiui.id}`), beserta `last_qty` dan `pemakaian_bhp_detail_id` yang terkait.

Tiap baris sumber yang datang, dicek ke tabel ini:

| Kondisi | Aksi |
|---|---|
| `source_key` belum pernah ada | Insert baris detail baru + potong stok sejumlah `jumlah` |
| `source_key` sudah ada, qty **sama** | Dilewati total, tidak disentuh (tidak ada query tulis) |
| `source_key` sudah ada, qty **beda** (dikoreksi di sumbernya) | Cuma sesuaikan **selisihnya** ke baris detail yang sama — bukan hapus-bangun-ulang semua |
| `source_key` sudah ada, tapi baris detailnya **sudah dihapus manual** oleh admin | Dilewati, dihormati — tidak dibuat ulang otomatis |

Desain ini sengaja dibuat incremental (bukan "hapus grup lalu bangun ulang dari current batch") supaya: (a) perubahan manual oleh admin (hapus/edit) tidak ditimpa ulang oleh sync berikutnya, dan (b) baris yang tidak berubah tidak diproses ulang terus-menerus tiap 1 jam.

### 4.5. Validasi, Standarisasi UOM & Skip per Baris
- **Standarisasi UOM / Satuan**:
  1. Jika barang terdaftar di tabel **Konversi UOM BHP** (`inventory_barang_uom_conversion`), otomatis menggunakan UOM Operasional (`to_uom`, misal `Vial`, `Btl`, `tube`, `pfs`).
  2. Jika tidak ada konversi, menggunakan `satuan` master barang di `inventory_barang`.
  3. String satuan mentah dari Metabase (seperti `ml` kecil atau varian lain) diabaikan agar konsisten dengan master sistem.
  4. Jika data sumber mengirim angka dalam satuan Odoo (misal mg/mL yang besar $\ge$ ratio konversi), otomatis dinormalisasi dibagi rasio konversi.

Baris yang gagal validasi di-skip **satu per satu**, tidak menggagalkan seluruh request. Alasan skip yang mungkin muncul:
- `jenis tidak valid` — bukan `'klinik'`/`'hc'`
- `tanggal_pemakaian tidak bisa di-parse`
- `di luar whitelist klinik yang sudah siap` — branch tidak ada di `BRANCH_MAP`
- `kode_barang kosong dari sumber` — `barang_odoo_id` kosong di Metabase (perlu dicek ke tim CIS, bukan masalah di endpoint kita)
- `kode_barang '...' tidak ketemu di master barang` — ada kodenya tapi tidak match ke `inventory_barang.kode_barang`
- `jumlah <= 0, dilewati`
- (khusus HC) `nakes '...' belum terdaftar di inventory dashboard` / `nakes '...' ada >1 orang dgn nama sama, perlu mapping manual`
- `source_row_id kosong` — Metabase belum/tidak mengirim id baris granular

Semua baris yang di-skip dikirim ke Lark, dikelompokkan per klinik, dengan alasan yang sudah dirapikan (tanpa `(admin_id ...)`, dengan nama klinik asli bukan angka id).

### 4.6. Laporan ke Lark
2 card terpisah tiap run:
1. **"✅ Berhasil Diproses"** — jumlah nomor BHP baru, item baru, item diupdate, tidak berubah; detail per klinik (nomor BHP, tanggal, 🆕 BARU / ✏️ UPDATE, jumlah item & qty)
2. **"⚠️ Perlu Dicek"** (atau **"✅ Semua Bersih"** kalau tidak ada yang bermasalah) — daftar skip dikelompokkan per klinik + alasan, dan error (kalau ada)

Kalau endpoint-nya sendiri **tidak bisa dihubungi sama sekali** (server/VM down, bukan error di dalam logic PHP-nya), n8n lewat jalur error terpisah (`HTTP Request` node dengan `On Error: Continue using error output`) dan kirim 1 card merah **"🔴 BHP Auto Sync GAGAL"** ke Lark, supaya tetap ada notifikasi walau sistemnya mati total.

## 5. Riwayat bug yang sudah diperbaiki

### Bug 1 — `created_at` di-reset otomatis saat update (sudah dihindari sejak desain awal)
`inventory_pemakaian_bhp.created_at` tidak punya `ON UPDATE CURRENT_TIMESTAMP`, jadi UPDATE biasa tidak pernah mengubahnya. Ini sengaja dijaga karena `stock_sellout_qty()` (`lib/stock.php`) bergantung pada `created_at` untuk menentukan "pending sellout yang belum ke-sync Odoo" — kalau `created_at` ikut berubah tiap update, bisa menyebabkan double-counting stok.

### Bug 2 — Collision source_key untuk data klinik (ditemukan & diperbaiki 23 Okt 2026)
**Gejala:** satu BHP number menunjukkan qty item yang "ganti-ganti" tiap sync dijalankan (misal transaksi `261019-O-0208`, item Alcohol Swab 70%, qty-nya bolak-balik 1↔2 di beberapa run berurutan).

**Akar masalah:** `source_row_id` untuk data klinik sebelumnya di-set `NULL` di query Metabase, jadi endpoint terpaksa bikin kunci sendiri dari `transaction_id + kode_barang`. Padahal 1 `encounter` (transaksi) di CIS bisa punya **lebih dari 1 sesi pemakaian** (`cis_expendable_item_usages`) yang berbeda, masing-masing bisa mengandung item yang sama dengan qty berbeda. Karena Postgres tidak menjamin urutan baris tanpa `ORDER BY` eksplisit, baris mana yang "menang" untuk kunci yang sama itu bisa beda tiap kali query dijalankan ulang.

**Fix:**
1. Query Metabase: `source_row_id` = `CAST(eiui.id AS TEXT)` (id baris `cis_expendable_item_usage_items`, granular per baris item), bukan `NULL` lagi.
2. `api/bhp_auto_sync.php`: `source_key` sekarang seragam pakai `source_row_id` untuk HC **dan** klinik (sebelumnya klinik pakai skema komposit terpisah).
3. Karena format kunci berubah, dilakukan **clean-slate reset** satu kali (balikin stok + hapus semua record `change_source='sistem_integrasi'` + kosongkan tabel tracking) sebelum fix ini jalan — supaya tidak ada data lama berformat kunci lama yang nyangkut dan ter-duplikasi di sync berikutnya. Ini aman dilakukan karena saat itu sistem masih di tahap testing lokal, belum live.

### Bug 3 — `eiui.is_deleted` tidak pernah dicek (ditemukan & diperbaiki 23 Okt 2026, bersamaan dengan Bug 2)
Query `cis_data` cuma mengecek `enc.is_deleted` dan `eiu.is_deleted`, tidak mengecek `eiui.is_deleted` — jadi baris item yang dibatalkan/dikoreksi di level itemnya sendiri (tanpa membatalkan seluruh sesi pemakaian) masih ikut ketarik dan dianggap valid.

**Fix:** tambah `AND COALESCE(eiui.is_deleted, FALSE) = FALSE` ke `WHERE` clause CTE `cis_data`.

### Fitur — Penanganan Pembatalan / Ganti Item via `is_deleted` (Diperbarui Nov 2026)
**Kasus:** Jika di CIS/HC terjadi penggantian item (Item A diganti Item B) atau pembatalan item:
1. Query Metabase mengekspos kolom `is_deleted` (1 jika dibatalkan/dihapus/qty 0, 0 jika aktif).
2. Endpoint `api/bhp_auto_sync.php` mendeteksi `is_deleted = 1`:
   - Jika `source_key` sudah pernah tercatat di inventory:
     - Melakukan **reversal stok penuh** (+`last_qty`) ke gudang/tas nakes.
     - Mencatat audit penyesuaian di `inventory_transaksi_stok`.
     - Menghapus baris detail dari `inventory_pemakaian_bhp_detail`.
     - Mengupdate `last_qty = 0` di tabel tracking agar tidak diproses ulang.
   - Item baru (Item B) otomatis di-insert normal dan memotong stok Item B.

## 6. Hal yang perlu diwaspadai

- **Input manual untuk baris yang di-skip**: kalau item BHP di-skip (misal karena nakes belum terdaftar atau barang belum ada di master), lalu diinput manual duluan — dan BELAKANGAN penyebab skip-nya "kebenerin sendiri" (nakes akhirnya didaftarkan, barang ditambahkan ke master) — auto-sync akan mencoba menarik ulang baris yang sama di run berikutnya (karena baris itu memang belum pernah berhasil ter-track, cuma di-skip), berpotensi **dobel-catat** dengan yang sudah diinput manual. Paling aman: benerin dulu akar masalahnya (daftarkan nakes / tambah barang ke master), biarkan auto-sync yang menangkap otomatis di run berikutnya — baru input manual kalau memang tidak bisa menunggu.
- **Edit manual di header yang berasal dari auto-sync**: kalau admin mengedit langsung record `change_source='sistem_integrasi'` dari UI (edit flow menghapus-lalu-buat-ulang semua baris detail header itu), baris tracking lama yang menunjuk ke detail id yang sudah terhapus akan dianggap "sudah dihapus manual, dihormati" oleh auto-sync — artinya **koreksi lanjutan dari sumber untuk baris itu tidak akan pernah masuk lagi secara otomatis** setelah diedit manual sekali. Ini bukan bug, tapi konsekuensi desain "hormati perubahan manual" — perlu disadari kalau sering mengedit manual record hasil auto-sync.
- **Makassar** masih manual 100% sampai data Odoo/Metabase-nya siap (kode klinik AMC vs MKS belum match).
