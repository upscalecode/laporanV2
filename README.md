# Laporan Produksi ABSH — Laravel + MySQL

Backend operasional berjalan di Laravel 13 dan MySQL. Delapan halaman lama tetap digunakan melalui Blade: dashboard, login, SPK, Filling, Press, APD, laporan, dan pengaturan. Browser menggunakan API satu origin `/api/production`; aplikasi tidak memanggil Google Apps Script/Google Drive lagi.

## Menjalankan dengan Laragon

Gunakan PHP 8.3+, Composer 2, MySQL 8+, dan Node.js hanya untuk tes JavaScript. PHP membutuhkan `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `dom`, `xml`, `curl`, dan `zip`. Laravel 13 mensyaratkan PHP 8.3 menurut [dokumentasi resmi](https://laravel.com/docs/13.x/releases).

1. Nyalakan MySQL di Laragon. Buat database **baru** `laporan_produksi` dengan charset `utf8mb4`.
2. Buka terminal Laragon di folder project, lalu jalankan:

   ```powershell
   composer install
   # Hanya untuk checkout baru yang belum mempunyai .env:
   Copy-Item .env.example .env
   php artisan key:generate
   ```

3. Atur `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di `.env`. Default contoh menggunakan MySQL lokal port 3306. Jangan mengganti `APP_KEY` pada instalasi yang sudah digunakan.
4. Jalankan:

   ```powershell
   php artisan migrate
   php artisan production:admin admin
   php artisan serve
   ```

   Password admin diminta secara tersembunyi, minimal 8 karakter. Tidak ada akun/password bawaan. Buka **http://127.0.0.1:8000/login.html**. Isi Master operator, produk, dan botol sebelum membuat SPK, atau impor snapshot terlebih dahulu.

Jika PHP tidak ada di PATH PowerShell, helper ini mencari PHP pada Laragon:

```powershell
.\tools\php.ps1 artisan migrate
.\tools\php.ps1 artisan production:admin admin
.\tools\php.ps1 artisan serve
```

Pada workspace ini dependensi dan `.env` lokal sudah disiapkan. Konfigurasi MySQL operasional, pembuatan admin, dan impor data asli tetap perlu dilakukan sesuai lingkungan Anda. Database yang digunakan untuk pengujian terpisah dari database operasional.

## Memindahkan data Google Sheets yang sudah ada

`Code.gs` dipertahankan sebagai referensi backend lama. Migrasi kode tidak otomatis memindahkan isi spreadsheet. File data asli belum tersedia dalam repository.

1. Buat cadangan spreadsheet dan hentikan input pada aplikasi lama selama ekspor hingga peralihan selesai.
2. Tambahkan isi `tools/export-laravel.gs` sebagai file baru di project Apps Script lama yang sudah memuat `Code.gs`.
3. Jalankan `exportProductionForLaravel()` dari editor. Unduh JSON privat dari URL file Drive yang muncul di log. Ekspor mencakup Master, pengguna/hak akses, SPK, pengerjaan, ledger penutupan, APD/foto, downtime, audit penghapusan, dan target KPI. Helper backend lama dapat menyelaraskan skema/arsip saat membaca. Ekspor tidak menghapus data sumber.
4. Simpan snapshot di luar `public/`, misalnya `storage/imports/snapshot.json`. Untuk banyak foto, Apps Script dapat mencapai batas waktu/memori; pastikan ekspor selesai dan jangan memakai file parsial.
5. Buat admin lokal terlebih dahulu pada database tujuan yang belum berisi data produksi. Username admin yang sudah ada dipertahankan ketika impor.
6. Validasi dahulu, kemudian impor:

   ```powershell
   php artisan production:import storage/imports/snapshot.json
   php artisan production:import storage/imports/snapshot.json --apply
   ```

Tanpa `--apply`, perintah hanya memeriksa snapshot. Impor menggunakan satu transaksi dan menolak database yang sudah memiliki data produksi/foto; tidak menghapus atau menimpa data produksi yang ada. Saldo Press dihitung ulang dari pengerjaan dan ledger penutupan. Foto dipindahkan dari Drive ke tabel privat MySQL dengan ID baru.

Password dan sesi lama tidak diimpor. Password pengguna hasil impor harus direset oleh admin dari Pengaturan; peran dan hak akses dipertahankan. Bandingkan jumlah SPK/pengerjaan/APD, saldo Press, dan hasil laporan dengan aplikasi lama sebelum mulai input. Preview lokal yang belum disimpan harus diselesaikan pada aplikasi lama sebelum ekspor; cache/preview Laravel memakai namespace baru.

## Struktur implementasi

- `routes/web.php`: halaman Blade dengan URL `.html` yang tetap kompatibel.
- `routes/api.php`: GET/POST `/api/production` dengan kontrak `{ok, ...}` dan action frontend lama.
- `app/Http/Controllers/ProductionController.php`: dispatch API dan transaksi.
- `app/Services/`: akun/hak akses, Master, SPK, produksi, alokasi Press, APD/foto.
- `database/migrations/2026_10_06_000001_create_production_tables.php`: tabel operasional MySQL.
- `database/migrations/2026_10_06_000002_import_existing_master_values.php`: menyalin data dari tabel lama `master_values` ke `production_records` (jenis `master`) satu kali saat migrasi, tanpa menimpa master yang sudah ada. Setelah migrasi, kelola master melalui aplikasi; perubahan langsung pada tabel lama tidak disinkronkan.
- `production_users` dan `production_tokens`: password ter-hash, hash token sesi 12 jam, pencabutan sesi saat password/hak akses berubah.
- `production_records`: koleksi terpisah melalui kolom `kind` dan ID unik, payload JSON mempertahankan kontrak data lama. Ini belum skema relasional terpisah per kolom SPK/pengerjaan. Perhitungan saldo/laporan masih membaca kumpulan data; untuk volume besar, lanjutkan normalisasi/index dan pagination server.
- `production_locks`: penguncian transaksi lintas request agar perubahan saldo dan nomor SPK konsisten.
- `production_photos`: foto privat; pembacaan memerlukan token dan izin APD.

Halaman diedit langsung di `resources/views/pages/*.blade.php`, dengan layout di `resources/views/layouts/app.blade.php` dan head bersama di `resources/views/partials/`. JavaScript utama berada di `public/js/app.js`, CSS di `public/css/app.css`, dan gambar di `public/assets/images/`. Semua referensi aset pada Blade memakai helper `asset()`. Tidak ada salinan HTML di root atau proses sinkronisasi/build frontend; perubahan langsung digunakan Laravel. URL `.html` tetap berupa route Laravel untuk menjaga kompatibilitas navigasi, bukan berkas HTML statis.

## Pengujian

Markup produksi bersama berada di `resources/views/partials/app-content.blade.php`. File dalam `resources/views/pages/` memilih tampilan aktif melalui `layouts/production.blade.php`. Layout utama memuat JavaScript sekali; penanganan kesalahan startup berada di `public/js/startup.js`. Versi URL CSS dan JavaScript mengikuti waktu perubahan berkas agar browser mengambil aset terbaru.

```powershell
php artisan test
node --test tools/security-fixes.test.mjs tools/spk-update.test.mjs
node --check public/js/app.js
```

PHPUnit memakai SQLite in-memory secara default. Untuk verifikasi MySQL gunakan database **khusus tes** karena `RefreshDatabase` dapat membuat ulang tabel:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'laporan_produksi_test'
$env:DB_USERNAME = 'root'
$env:DB_PASSWORD = ''
php artisan test
```

Jalankan perintah tes di terminal tersendiri agar variabel tes tidak terbawa ke aplikasi. Tes JavaScript memeriksa regresi kode lama yang dipertahankan, sedangkan tes PHP memeriksa backend Laravel baru.

## Hosting

Document root Apache/Nginx harus menunjuk ke **`public/`**, bukan root repository. Jalankan melalui PHP/Laravel; membuka `index.html` secara langsung atau memakai Live Server tidak menjalankan backend. Gunakan HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, akun database khusus aplikasi, dan pastikan `storage/` serta `bootstrap/cache/` dapat ditulis. Jalankan `php artisan config:cache` dan `php artisan route:cache` setelah konfigurasi final. Cadangkan seluruh database, termasuk foto dan ledger penutupan.
