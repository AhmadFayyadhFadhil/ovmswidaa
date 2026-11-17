---
name: laravel-eloquent-guardian
description: Enforces clean MVC architecture, skinny controllers, FormRequest validation, eager loading anti-N+1, and database transactions in Laravel.
---

# Laravel Eloquent & Architecture Guardian

## 1. Overview & Core Standards
Skill ini mengatur standar baku pengembangan backend PHP Laravel pada sistem OVMS.
Prinsip utama: **Clean MVC, Skinny Controllers, Atomic Transactions, and High-Performance Eloquent Queries.**

---

## 2. Aturan Controller & Arsitektur (Skinny Controller)
- **Controller Ramping:** Controller hanya bertanggung jawab mengorkestrasi request:
  1. Menerima request yang sudah tervalidasi.
  2. Memanggil Service / Action / Model Method.
  3. Mengembalikan `JsonResponse` (menggunakan `JsonResource`) atau view.
- **Larangan:** Jangan menaruh query raw berbelit-belit atau logika bisnis multi-step langsung di method controller.

---

## 3. Validasi FormRequest (Anti-Inline Validation)
- Setiap mutasi data (POST, PUT, PATCH, DELETE) yang melibatkan logika validasi bisnis **WAJIB** menggunakan FormRequest terpisah (`php artisan make:request ...`).
- Selalu pisahkan method `authorize()` dan `rules()`.
- Berikan pesan error bahasa Indonesia yang jelas pada method `messages()`.

---

## 4. Pencegahan N+1 Query (Eager Loading Rules)
- **Aturan Wajib:** Setiap pemanggilan koleksi model yang relasinya akan ditampilkan di response JSON/view **WAJIB** menggunakan `with([...])`.
  ```php
  // SALAH (Berpotensi N+1 Query pada driver & vehicle):
  $trips = OperationalTrip::where('status', 'active')->get();

  // BENAR (Eager Loading optimal):
  $trips = OperationalTrip::with([
      'driver:id,name,phone,license_number',
      'vehicle:id,license_plate,model,capacity',
      'request:id,letter_number,destination,user_id',
      'request.user:id,name,division'
  ])->where('status', 'active')->get();
  ```
- Batasi kolom relasi yang diambil (`relation:id,col1,col2`) untuk menghemat memory server aaPanel.

---

## 5. Transaksi Atomik Database (`DB::transaction`)
- Untuk setiap proses mutasi yang melibatkan lebih dari satu tabel (misal: pencatatan gate check-out + update status trip + update armada + pembuatan security log), **WAJIB** dibungkus dalam `DB::transaction()`:
  ```php
  DB::transaction(function () use ($validated, $trip) {
      $trip->update([
          'security_checked_out_at' => now(),
          'status' => 'in_progress',
      ]);

      SecurityLog::create([
          'operational_trip_id' => $trip->id,
          'action' => 'CHECK_OUT',
          'security_id' => auth()->id(),
          'checked_at' => now(),
          'notes' => $validated['notes'] ?? null,
      ]);
  });
  ```

---

## 6. Standar Kontrak API JSON
Gunakan format respons seragam:
```json
{
  "success": true,
  "message": "Pesan status operasional yang jelas",
  "data": { ... }
}
```
Untuk respon error:
```json
{
  "success": false,
  "message": "Penyebab error atau kegagalan validasi",
  "errors": { ... }
}
```

---

## 7. Model Mass Assignment
- Setiap Model Eloquent **WAJIB** mendeklarasikan array `$fillable` secara eksplisit.
- **DILARANG KERAS** menggunakan `protected $guarded = [];` demi keamanan mass-assignment injection.
