# PRODUCT REQUIREMENTS & CONTINUITY DOCUMENT (PRD)
## OPERATIONAL VEHICLE MANAGEMENT SYSTEM (OVMS) - PT WIDATRA BHAKTI

---

> **Dokumen Kontinuitas Sistem, Arsitektur, Hak Akses, Riwayat Fitur, dan SOP Pengembangan**  
> **Terakhir Diperbarui:** 9 November 2026  
> **Target Branch Aktif:** `dev` (Local & Remote)

---

## 1. Informasi Arsitektur & Lingkungan Server (DEV vs LIVE)

| Komponen / Konfigurasi | Server DEV (Development) | Server LIVE (Production) |
| :--- | :--- | :--- |
| **Domain Frontend** | `https://ovmsdev.widatra.com` (Port 8282 / 443 SSL) | `https://ovms.widatra.com` (Port 80 / 443 SSL) |
| **Path Root Frontend** | `/www/wwwroot/ovmsdev.widatra.com/frontend` | `/www/wwwroot/OVMS/frontend` |
| **Path Dist Frontend** | `/www/wwwroot/ovmsdev.widatra.com/frontend/dist` | `/www/wwwroot/OVMS/frontend/dist` |
| **Backend REST API Local** | `http://127.0.0.1:8383` | `http://127.0.0.1:8080` |
| **Path Backend Server** | `/www/wwwroot/api.ovmsdev.widatra.com/backend` | `/www/wwwroot/OVMS/backend` |
| **Nginx Reverse Proxy** | `/api -> 8383`, `/storage -> 8383` | `/api -> 8080`, `/storage -> 8080` |
| **Penanganan Build Dist** | Folder `dist/` dikompilasi secara lokal dan ikut di-commit ke Git. Cukup `git pull origin dev` di server. | `git pull origin main` |

---

## 2. Status Repositori & Git Branching

1. **Repositori Backend**: `AhmadFayyadhFadhil/ovmswidaa`
   * **Path Lokal**: `d:\ovmswida2`
   * **Branch Aktif**: `dev`
   * **Aturan**: Seluruh modifikasi backend dimulai dan diuji di branch `dev`. Merge ke `main` hanya saat siap rilis produksi.
2. **Repositori Frontend**: `AhmadFayyadhFadhil/ovmswidaafe`
   * **Path Lokal**: `D:\OVMS`
   * **Branch Aktif**: `dev`
   * **Aturan**: Seluruh modifikasi UI/UX diuji via `npx tsc --noEmit` dan `npm run build` sebelum di-push ke remote `origin/dev`.

---

## 3. Matriks Peran, Akun Utama, & Hak Akses Khusus

### 1. Akun GA Lead (Bu Melodi)
* **Identitas**: `Melodi Bella Astria` (Email/Nama mengandung keyword `melodi`).
* **Otoritas Khusus**:
  * Mengelola Master Data Pejabat Penyetujui GA (tombol *"Kelola Master Penyetujui"* aktif di header).
  * Bypass validasi H-1 untuk membuat permohonan kendaraan mendadak hari-H.
  * Penerima notifikasi hasil evaluasi bintang/rating permohonan.

### 2. Akun GA Team Backup ("GATEAM")
* **Identitas**: `gateam@widatra.com` (NIK: `GATEAM`, Nama: `GA Team`).
* **Fungsi**: Akun operasional bersama GA saat Bu Melodi berhalangan.
* **Aturan Otoritas**:
  * Saat menyetujui jadwal di modal, **wajib** memilih nama pejabat yang diwakili (*"Atas Nama"*) melalui dropdown/input manual.
  * **DILARANG dan TERKUNCI**: Tidak dapat mengakses *"Kelola Master Data"* (tombol kelola master disembunyikan total).

### 3. Akun Koordinator Driver (Bp Winaryo)
* **Identitas**: Bp Winaryo (Koordinator Pengemudi).
* **Fungsi & Hak Akses**:
  * Mengelola penugasan supir (*driver assignment*), plotting armada internal, dan pengalokasian rental sewa eksternal.
  * Mengisi rincian biaya rental eksternal (`third_party_cost`).
  * Memiliki modul **Buat Permohonan** dan **Permohonan Saya** langsung dari akun Koordinator.
  * Bypass validasi pengajuan H-1 (dapat mengajukan hari-H).
  * Penerima notifikasi evaluasi/rating dari pemohon setelah perjalanan selesai.

### 4. Akun Bersama Pos Security Gerbang
* **Identitas**: Akun pos gerbang (`security` / NIK: `SECURITY`).
* **Fungsi**:
  * Melakukan scan QR Code / input ID saat check-out (keluar gerbang) dan check-in (kembali gerbang).
  * Verifikasi nama petugas security jaga (disimpan otomatis di localStorage perangkat pos).
  * Pencatatan angka spidometer KM awal dan akhir (khusus armada internal perusahaan).
  * Pencatatan catatan penumpang tambahan hari-H (*hybrid text & search karyawan internal*).
  * Bypass validasi KM untuk armada sewa eksternal (langsung menandai status `COMPLETED` saat kembali).
  * Tampilan Buku Log Riwayat Scan Security secara default langsung menyaring data **Hari Ini (Today)**.

### 5. Akun Pemohon / Employee
* Mengajukan permohonan kendaraan dinas operasional dan muatan logistik/barang.
* Opsi *"Tanpa Penumpang"* untuk pengiriman khusus kargo logistik tanpa membawa orang.
* Mengisi penilaian ulasan bintang (rating 1–5 & catatan feedback) setelah permohonan berstatus `COMPLETED`.

---

## 4. Log Fitur & Perbaikan Besar yang Telah Tuntas

### A. Fitur Fundamental & Stabilitas Sistem
1. **Pembatasan Hak Akses Master Penyetujui Akun GATEAM**: Menghapus tombol kelola master dari modal alokasi untuk akun GATEAM; akses dikunci khusus Bu Melodi dan Administrator.
2. **Master Data Departemen Khusus**: Menambahkan departemen *"Outsource / Tamu"* dan *"Plant Management"* pada database, filtering audit log, user management, form permohonan, dan layout.
3. **Standarisasi Cetak & Unduh Surat Tugas (PDF Clean Printing)**: 1 tombol resmi *"Unduh Surat Tugas (PDF)"* dengan QR code verifikasi pos security dan penghapusan margin/header default browser.
4. **Pencegahan Error 500 Polling Notifikasi**: Integrasi enum `RequestStatus` pada NotificationController dan VehicleController backend.
5. **Modal Log Keamanan Pos Gerbang yang Akurat**: Menghilangkan teks otomatis palsu; menampilkan riwayat satpam riil, log multi-trip, dan catatan pos yang akurat.
6. **Sistem Upload Avatar & Berkas Anti-Finfo**: Menggunakan Pure Native PHP Binary Stream ganda (`storage/app/public/` & `public/storage/`) untuk server tanpa ekstensi PHP `fileinfo`.
7. **Sistem Notifikasi Enterprise**: Polling latar belakang senyap (SWR), persistent read/delete state berbasis event token `req-{id}-{status}`, dan role-aware filtering.

### B. 12 Poin Revisi Operasional OVMS (Status: 10 Selesai, 2 Pending)

| No | Poin Revisi | Status | Keterangan Implementasi |
| :---: | :--- | :---: | :--- |
| **1** | Modul *Buat Permohonan* & *Permohonan Saya* untuk Driver Coordinator | **SUDAH** | Bp Winaryo dapat mengajukan permohonan langsung dan memantau statusnya di sidebar/dashboard layout. |
| **2** | Tanda bintang merah mandatory (`*`) pada form pengajuan | **SUDAH** | Indikator visual field wajib (`*` merah) di seluruh input pengajuan [createrequest](file:///D:/OVMS/src/pages/employee/createrequest/index.tsx). |
| **3** | Rekapitulasi riwayat & sinkronisasi status trip gabungan (*merged trip*) | *PENDING* | Penyesuaian tampilan riwayat permohonan dan alur status untuk permohonan gabungan. |
| **4** | Catatan Penumpang Tambahan Hari-H di Pos Security | **SUDAH** | Sistem hybrid: input teks nama bebas dan/atau pencarian autocomplete karyawan internal dari `/users-search` di modal scan checkout security. |
| **5** | Penjadwalan & Multi-trip Driver | *PENDING* | Fleksibilitas penugasan 1 driver untuk lebih dari 1 permohonan/itinerary pada hari yang sama. |
| **6** | Bypass validasi H-1 untuk GA & Driver Coordinator | **SUDAH** | Tim GA dan Koordinator Driver dapat membuat permohonan armada untuk hari-H (tanpa terblokir validasi H-1). |
| **7** | Akun bersama Pos Security & penyesuaian login | **SUDAH** | Label login diubah menjadi *"NIK / Username / Email"* dan mendukung akun pos security bersama (`security` / NIK: `SECURITY`). |
| **8** | Opsi *"Tanpa Penumpang"* untuk muatan logistik / barang / dokumen | **SUDAH** | Checkbox *"Tanpa Penumpang"* menonaktifkan validasi wajib daftar penumpang saat pengajuan khusus kargo barang. |
| **9** | Penanganan Armada Pihak Ketiga (Sewa Rental Eksternal) | **SUDAH** | Form input biaya rental (`third_party_cost`) untuk Koordinator Driver, tanpa validasi KM di Security check-out/check-in, dan otomatis completed saat check-in. |
| **10** | Export Laporan (Excel & PDF) Biaya Rental & Filter Departemen | **SUDAH** | Menambahkan rincian biaya rental eksternal, vendor sewa, penumpang tambahan hari-H, Dropdown Filter Departemen pemohon, serta modul cetak **PDF Tabular Landscape resmi** (`exportReportToPDF`). |
| **11** | Filter tanggal riwayat security default Hari Ini (*Today*) | **SUDAH** | Riwayat Security default menyaring data **Hari Ini (Today)**, dilengkapi pilihan periode (Minggu Ini, Bulan Ini, Semua, Kustom), sinkronisasi kartu statistik, dan auto-expand handling. |
| **12** | Notifikasi Evaluasi Bintang / Rating Pemohon ke Koordinator & GA | **SUDAH** | Ulasan bintang (1–5) dan catatan pemohon mengirim notifikasi evaluasi berstatus `rated` (`req-{$id}-rated`) ke Bp Winaryo dan Tim GA. |

---

## 5. Standar Operasional Prosedur (SOP) Development & Deployment

### A. Aturan Git & Branching
1. Seluruh pekerjaan pengembangan **WAJIB** berada di branch `dev`.
2. Jangan melakukan commit langsung ke branch `main`.
3. Sebelum melakukan commit frontend, pastikan lolos type-check:
   ```bash
   npx tsc --noEmit
   npm run build
   ```
4. Sebelum melakukan commit backend, pastikan sintaks bebas dari error:
   ```bash
   php -l <nama-file-yang-diubah>
   ```

### B. Prosedur Deploy ke Server DEV
* **Backend DEV**:
  ```bash
  cd /www/wwwroot/api.ovmsdev.widatra.com/backend && git reset --hard && git pull origin dev && php artisan optimize:clear
  # Jika ada migration baru:
  php artisan migrate --force
  ```
* **Frontend DEV**:
  ```bash
  cd /www/wwwroot/ovmsdev.widatra.com/frontend && git reset --hard && git pull origin dev
  ```
  *(Catatan: folder `dist/` sudah otomatis ter-compile dan ter-push dari lokal, tidak perlu menjalankan build di server).*

### C. Prosedur Rilis ke Server LIVE (Production)
Setelah fitur diuji tuntas di lingkungan DEV:
1. Merge branch `dev` ke branch `main` pada Git lokal:
   ```bash
   git checkout main
   git merge dev
   git push origin main
   git checkout dev
   ```
2. **Backend LIVE**:
   ```bash
   cd /www/wwwroot/OVMS/backend && git reset --hard && git pull origin main && php artisan optimize:clear
   # Jika ada migration:
   php artisan migrate --force
   ```
3. **Frontend LIVE**:
   ```bash
   cd /www/wwwroot/OVMS/frontend && git reset --hard && git pull origin main
   ```
