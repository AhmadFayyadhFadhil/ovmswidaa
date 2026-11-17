---
name: code-review-verification
description: Guides pre-commit verification, linting, build checks, and regression audit following the 5 mandatory protocol steps.
---

# Code Review & 5-Point Protocol Verification

## 1. Overview & Core Mission
Skill ini adalah penjaga gerbang mutu (*quality gatekeeper*) sebelum setiap perubahan kode dicommit atau dideploy.
Skill ini mengoperasionalkan **5 Protokol Wajib** user ke dalam checklist teknis konkret.

---

## 2. Checklist Operasional 5 Protokol

### Protokol 1: Verifikasi Keberhasilan (Verification of Success)
- **Bukan hanya "tidak error"**, tapi apakah perilaku dan output sesuai tujuan?
  - Pada perubahan UI: Periksa apakah elemen baru muncul, apakah styling responsive, apakah event klik memicu aksi yang benar.
  - Pada perubahan API: Periksa apakah payload JSON mengembalikan data yang valid dan status code yang tepat (`200 OK`, `201 Created`, `422 Unprocessable`).
  - Pada perubahan database: Periksa apakah migrasi berjalan mulus dan field baru terisi benar.

### Protokol 2: Bebas dari Error (Zero Unhandled Errors)
- Jalankan verifikasi sintaks:
  - Frontend: `npm run build` di `D:\OVMS` (wajib 0 compile/type errors).
  - Backend: `php -l <nama_file.php>` pada setiap file PHP yang dimodifikasi di `d:\ovmswida2`.
- Periksa runtime warnings (misal: React missing keys, undefined object access, unhandled promise rejections).

### Protokol 3: Tidak Merusak Sistem Lain (Non-Regression Guarantee)
- Jalankan `git diff` untuk memeriksa setiap baris yang dihapus atau diubah.
- Pastikan tidak ada penghapusan fungsi utility atau props komponen yang masih dipakai oleh halaman lain.
- Pastikan route yang sudah ada tidak tergeser atau mengalami konflik URI.

### Protokol 4: Sesuaikan (Adjust as Needed)
- Uji skenario data ekstrem (*edge cases*):
  - Data kosong: Apakah empty state tampil ramah dan rapi?
  - Data loading: Apakah ada spinner atau skeleton loader?
  - Viewport ekstrem: Uji pada resolusi mobile 360px dan desktop 1440px.
  - Nilai null / undefined: Gunakan optional chaining `?.` dan fallback default.

### Protokol 5: Laporkan Hasil Akhir (Transparent Reporting)
- Sajikan laporan penutupan tugas dengan format terstruktur:
  1. **Ringkasan Perubahan:** Apa saja file dan baris kunci yang diperbaiki.
  2. **Hasil Verifikasi:** Bukti konkret pengujian (`build pass`, response code, visual alignment).
  3. **Catatan / Risiko:** Hal yang perlu diperhatikan user (misal: perlu hard refresh cache atau migrasi baru).
  4. **Tautan Berkas:** Selalu sertakan link clickable `file://...` ke file terkait.

---

## 3. Checklist Pre-Commit Standar
Sebelum melakukan `git commit`:
```bash
# Frontend Check
cd D:\OVMS && npm run build

# Backend Check
cd d:\ovmswida2 && php -l app/Http/Controllers/...

# Git Diff Review
git diff --stat
```
