# Backend MySQL — Laporan Produksi

Backend ini mempertahankan kontrak `action` yang dipakai frontend lama, sehingga UI dapat diuji tanpa migrasi serentak.

## Impor melalui phpMyAdmin

Gunakan **MySQL 8.0.16+** atau **MariaDB 10.4+** (termasuk MariaDB pada XAMPP).

1. Jalankan MySQL/MariaDB, lalu buka phpMyAdmin.
2. Buat database kosong bernama `laporan_produksi` dengan collation `utf8mb4_bin`.
3. Pilih database tersebut, buka **Import**, pilih `backend/sql/001_schema.sql`, lalu klik **Go/Kirim**.

Skema ini membuat tabel dan nilai awal target KPI. Impor ulang tidak menghapus data atau menimpa target KPI yang sudah diubah. Gunakan database kosong jika sebelumnya ada tabel dari skema lain; `CREATE TABLE IF NOT EXISTS` tidak mengubah struktur tabel lama.

## Menjalankan lokal

1. Buat database MySQL/MariaDB kosong bernama `laporan_produksi` seperti di atas.
2. Salin `.env.example` menjadi `.env`, lalu sesuaikan `DATABASE_URL` dan `CORS_ORIGIN`.
3. Jalankan:

```powershell
npm install
npm run db:init
npm run db:seed-dev
npm start
```

API tersedia di `http://localhost:3000/api`.

Contoh `DATABASE_URL` untuk XAMPP lokal dengan user `root` tanpa password:

```dotenv
DATABASE_URL=mysql://root@127.0.0.1:3306/laporan_produksi
DATABASE_SSL=false
```

Jika akun database memakai password, gunakan `mysql://USER:PASSWORD@HOST:3306/laporan_produksi` dan lakukan URL-encoding pada karakter khusus di username/password. Sesuaikan `DEV_ADMIN_USERNAME` dan `DEV_ADMIN_PASSWORD` sebelum menjalankan `db:seed-dev`; perintah ini membuat atau memperbarui akun admin aplikasi.

`npm run db:init` merupakan alternatif impor phpMyAdmin dan aman dijalankan setelah impor. Backend tetap dijalankan dengan Node.js; phpMyAdmin digunakan untuk mengelola database. Frontend sudah memakai `API_MODE: "mysql"` dan `MYSQL_API_URL: "http://localhost:3000/api"` di `script.js`. Waktu kejadian disimpan dalam UTC; kolom tanggal produksi tetap berupa tanggal.

Jalankan unit test dengan `npm test`. Untuk tes integrasi, arahkan backend ke database uji terpisah, jalankan `db:init`, `db:seed-dev`, dan API. Di terminal tes PowerShell, jalankan `$env:E2E_ALLOW_CLEAR="true"`, lalu `npm run test:e2e`. Tes membutuhkan database transaksi kosong dan mencakup penghapusan data melalui endpoint maintenance; jangan arahkan ke database produksi.

## Memindahkan data Spreadsheet

Di Google Spreadsheet pilih **File → Download → Microsoft Excel (.xlsx)**, lalu jalankan:

```powershell
npm run db:import -- "C:\path\Laporan Produksi.xlsx"
```

Importer menggunakan `ON DUPLICATE KEY UPDATE`, sehingga dapat dijalankan kembali. Lakukan backup database sebelum mengulang import setelah aplikasi MySQL sudah menerima data produksi baru.

Foto APD tidak tertanam di XLSX karena file aslinya berada di Google Drive. Baris APD akan tetap diimpor, tetapi pemindahan file foto perlu dilakukan terpisah melalui Google Drive API.

## Cutover aman

1. Jalankan backend dan import pada database uji.
2. Buka aplikasi dengan konfigurasi `API_MODE: "mysql"` dan `MYSQL_API_URL` menuju backend.
3. Uji login, simpan/edit/hapus data, SPK, APD, Down Time, Master, user, dan laporan.
4. Bekukan input di Spreadsheet, lakukan import final, lalu arahkan deployment frontend ke MySQL.

Penutupan/hapus Sisa Press dicatat sebagai ledger `press_adjustments`; data Filling tidak dihapus. Saldo dihitung ulang dari ledger Filling, Press, dan penutupan setiap kali data aplikasi dimuat.
