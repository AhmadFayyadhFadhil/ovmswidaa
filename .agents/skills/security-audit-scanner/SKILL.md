---
name: security-audit-scanner
description: Audits role-based access control (RBAC), input sanitization, CSRF/Sanctum protection, and security log integrity in Laravel & React.
---

# Security Audit Scanner

## 1. Overview & Core Standards
Skill ini memberikan pedoman audit keamanan sistem OVMS untuk mencegah eskalasi hak akses (*privilege escalation*), kebocoran token, SQL injection, XSS, dan perusakan data log gerbang.

Prinsip utama: **Zero Trust, Strict Role Enforcement, and Tamper-Proof Audit Logging.**

---

## 2. Role-Based Access Control (RBAC) & Middleware
- Setiap endpoint API di `routes/api.php` wajib dikelompokkan dalam middleware auth Sanctum dan role check:
  - `superadmin` / `administrator`: Akses penuh manajemen user, armada, dan bypass darurat.
  - `admin`: Manajemen permohonan, alokasi armada, dan laporan operasional.
  - `approver` / `atasan`: Khusus persetujuan permohonan bawahan/divisi.
  - `security`: Khusus portal gerbang (verifikasi QR, check-out keluar, check-in masuk, riwayat scan).
  - `driver`: Khusus melihat jadwal penugasan dan status perjalanan pribadi.
  - `user`: Khusus pengajuan permohonan kendaraan operasional pribadi/divisi.
- Di frontend React: Jangan hanya menyembunyikan tombol aksi secara visual; pastikan backend menolak request jika role user tidak berwenang (`403 Forbidden`).

---

## 3. Pencegahan SQL Injection & Sanitasi Parameter
- Selalu gunakan Eloquent ORM atau Query Builder dengan parameter binding standar:
  ```php
  // BENAR:
  Vehicle::where('license_plate', $plate)->first();
  DB::select('SELECT * FROM operational_trips WHERE status = ?', [$status]);

  // SALAH (Rentan SQL Injection):
  DB::select("SELECT * FROM operational_trips WHERE status = '$status'");
  ```
- Hindari penggunaan `DB::raw()` dengan variabel yang belum disanitasi.

---

## 4. Keamanan Log Gerbang & Integritas Audit
- Portal Security bertugas mencatat fisik kendaraan keluar-masuk.
- Data yang wajib dicatat dan diverifikasi pada setiap aksi gerbang:
  1. `security_id`: ID petugas jaga yang login (bukan input manual).
  2. `checked_at`: Timestamp server aktual `now()`.
  3. `odometer_out` / `odometer_in`: Validasi bahwa kilometer kembali tidak boleh lebih kecil dari kilometer berangkat.
  4. `fuel_level`: Status BBM saat keluar/kembali.
- Data riwayat yang sudah selesai (`completed`) tidak boleh diedit atau dihapus oleh role non-admin.

---

## 5. Proteksi Data Sensitif (Data Exposure Prevention)
- Jangan pernah menyertakan `password`, `remember_token`, atau kunci rahasia pada respons JSON API.
- Gunakan `$hidden = ['password', 'remember_token'];` di Model `User`.
- Gunakan Laravel `JsonResource` untuk memfilter hanya kolom yang relevan ke frontend.

---

## 6. Checklist Audit Keamanan
1. [ ] Apakah endpoint dilindungi oleh `auth:sanctum` dan middleware role yang tepat?
2. [ ] Apakah Model memiliki `$fillable` eksplisit?
3. [ ] Apakah input form divalidasi tipe data, string length, dan batasan numerik?
4. [ ] Apakah security gate action mencatat user ID petugas yang sah?
