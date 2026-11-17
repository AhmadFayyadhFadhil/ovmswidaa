---
name: ponytail-anti-bloat
description: Prevents AI over-engineering, unnecessary dependencies, and code bloat using the 7-step minimization ladder and YAGNI principle.
---

# Ponytail Anti-Bloat & Anti-Overengineering

## 1. Overview & Core Philosophy
Diadopsi dari konsep open-source **`DietrichGebert/ponytail`**.  
Skill ini memandu AI agent agar selalu berpikir layaknya seorang **"lazy senior developer"** yang cerdas dan hemat kode.

> *"The best code is the code you never wrote. The easiest code to maintain is the code that does not exist."*

---

## 2. The 7-Step Minimization Ladder (Tangga Evaluasi 7 Langkah)
Sebelum menulis baris kode custom baru atau menyarankan instalasi dependency, AI **WAJIB** menaiki tangga evaluasi ini dari langkah 1 ke 7:

```text
[1. YAGNI] ──> Apakah ini beneran perlu ada sekarang? (Kalau tidak, STOP!)
    │
[2. Codebase Reuse] ──> Apakah sudah ada helper/komponen serupa di project?
    │
[3. Standard Library] ──> Apakah fungsi bawaan PHP 8.2+ / JS modern sudah bisa?
    │
[4. Native Platform] ──> Apakah ada tag bawaan HTML5 / browser API?
    │
[5. Existing Dependencies] ──> Maksimalkan library yang sudah terpasang!
    │
[6. One-Liner Simplicity] ──> Bisa diselesaikan 1 baris bersih tanpa helper baru?
    │
[7. Minimal Custom Code] ──> Tulis kode sesedikit & seringkas mungkin.
```

---

## 3. Rincian Penerapan pada Stack OVMS

### A. YAGNI (You Aren't Gonna Need It)
- Jangan membuat interface, class, repository, atau helper baru "hanya untuk berjaga-jaga siapa tahu nanti butuh".
- Jika sebuah fungsi hanya dipanggil di satu tempat, pertahankan kedekatannya secara lokal (*colocation*), jangan buru-buru diekstraksi jadi global module.

### B. Codebase Reuse
- Di frontend React: Periksa `src/components/`, `src/utils/`, dan hooks yang sudah ada sebelum membuat fungsi baru.
- Di backend Laravel: Periksa apakah model sudah punya relationship, mutator, accessor, atau scope yang bisa dimanfaatkan.

### C. Standard Library (PHP 8.2+ & Modern JS)
- Gunakan fitur bawaan modern:
  - PHP: `str_contains()`, `str_starts_with()`, `array_map()`, `Carbon::parse()`, match expressions.
  - JS: `Array.prototype.find()`, `flatMap()`, `URLSearchParams`, `Intl.DateTimeFormat`.
- Jangan menulis fungsi looping manual 15 baris jika satu method koleksi bawaan sudah menyelesaikannya.

### D. Native Browser / HTML5
- Gunakan fitur bawaan browser daripada menambah dependensi:
  - `<input type="date">` daripada memasang library date-picker berat.
  - `<details>` & `<summary>` untuk accordion sederhana.
  - CSS `overflow-x-auto` daripada custom scroll plugins.

### E. Existing Dependencies
- Dilarang mengusulkan `npm install` atau `composer require` baru kecuali benar-benar disetujui oleh user dan tidak ada alternatif lain.
- Gunakan Tailwind utility classes yang sudah terkonfigurasi.

### F. One-Liner Simplicity
- Sederhanakan ekspresi kondisional:
  ```tsx
  // KODE BLOAT (20 baris):
  function getBadgeColor(status) {
    if (status === 'completed') {
      return 'bg-emerald-50 text-emerald-700';
    } else if (status === 'in_progress') {
      return 'bg-amber-50 text-amber-700';
    } else {
      return 'bg-slate-100 text-slate-700';
    }
  }

  // SATU BARIS BERSIH (Mapping Object):
  const STATUS_COLORS: Record<string, string> = {
    completed: 'bg-emerald-50 text-emerald-700',
    in_progress: 'bg-amber-50 text-amber-700',
  };
  const color = STATUS_COLORS[status] || 'bg-slate-100 text-slate-700';
  ```

---

## 4. Batasan Mutlak (Non-Negotiables)
Prinsip Ponytail **TIDAK BOLEH** digunakan untuk memotong atau mengabaikan:
1. Validasi input dan tipe data.
2. Penanganan error (*error handling & boundary*).
3. Otorisasi dan pengecekan role middleware.
4. Aksesibilitas dan responsivitas layar sentuh mobile.
