# Deploy regionalb.online

Paket ini disiapkan khusus untuk domain `regionalb.online`.

## Cara upload

1. Buat website baru untuk domain `regionalb.online` di panel hosting.
2. Masuk ke folder root domain, biasanya:
   - `domains/regionalb.online/public_html`, atau
   - `public_html`
3. Upload semua isi folder `deploy_regionalb_online` ke root domain.
4. Pastikan hasilnya seperti ini:

```text
public_html/index.php
public_html/regional-b.php
public_html/dashboard.php
public_html/rsm_db.php
public_html/assets/style.css
```

## Config database

Pilihan aman:

```text
domains/regionalb.online/source_activity/config.php
domains/regionalb.online/public_html/index.php
```

Jika hosting tidak memungkinkan folder private, salin:

```text
config.example.php
```

menjadi:

```text
config.php
```

lalu isi credential database hosting. File `.htaccess` sudah mencoba menutup akses langsung ke `config.php`.

## Database wajib ada

Database yang dipakai harus memiliki tabel sumber lama:

```text
users
partner_campuses
user_campuses
activities
```

Tabel RSM akan dibuat otomatis saat halaman dibuka:

```text
rsm_users
rsm_reports
rsm_ad_leads
rsm_activity_logs
```

Jika auto-create gagal karena izin database, import `schema.sql` lewat phpMyAdmin.

## URL

Setelah DNS aktif:

```text
https://regionalb.online/
```

Domain root langsung membuka dashboard Regional B.

## Jangan upload dari lokal

Jangan upload:

```text
runtime/
mysql_data_local/
.git/
file backup SQL produksi
config.php berisi credential ke repo publik
```
