---
name: react-mobile-responsive-guardian
description: Enforces mobile-first responsive design, flex-wrap resilience, grid containment, and compact touch-friendly UI for React & Tailwind CSS.
---

# React Mobile Responsive Guardian

## 1. Overview & Core Philosophy
Skill ini memandu perancangan dan refactoring antarmuka React + Tailwind CSS agar tampil sempurna di seluruh perangkat, dari ponsel compact (360px - 390px) hingga layar desktop ultra-wide (1440px+).

Prinsip utama: **Mobile-First, Zero Content Squishing, and Robust Layout Containment.**

---

## 2. Standar Breakpoint & Viewport Target
- **Ultra-Compact Mobile:** `360px` (Ponsel Android compact, Galaxy A series, browser dalam webview).
- **Standard Mobile:** `390px - 414px` (iPhone 12/13/14/15/16, Pixel).
- **Tablet / Small Laptop:** `768px - 1024px` (`md:`, `lg:`).
- **Desktop:** `1280px+` (`xl:`).

Setiap layout baru wajib dipastikan tidak mengalami horizontal scrolling liar di `360px`.

---

## 3. Aturan Ketat Layout (Layout Rules)

### A. Grid Containment (Anti-Squish Banner & Alerts)
- **Larangan Keras:** Jangan pernah menempatkan elemen full-width (seperti alert banner, informasi panduan, search bar, atau tabel) di dalam container `grid grid-cols-2` atau `grid grid-cols-3` tanpa mendeklarasikan `col-span-full` atau menutup tag grid terlebih dahulu.
- **Pola Benar:**
  ```tsx
  {/* Tutup grid statistik terlebih dahulu */}
  <div className="grid grid-cols-3 gap-2 sm:gap-4">
    <StatCard title="Total" value={total} />
    <StatCard title="Di Luar" value={active} />
    <StatCard title="Selesai" value={done} />
  </div>

  {/* Banner ditempatkan mandiri di luar grid agar 100% full width */}
  {showBanner && (
    <div className="p-3.5 sm:p-4 bg-blue-50 border border-blue-200 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
      <div>Informasi banner lengkap...</div>
      <div className="flex items-center gap-2 flex-wrap shrink-0">
        <button className="px-3.5 py-2 bg-blue-600 text-white rounded-xl">Aksi</button>
      </div>
    </div>
  )}
  ```

### B. Status Pills, Badges, & Action Buttons
- Setiap badge, label status, atau action chip kecil **WAJIB** memiliki kelas `shrink-0 whitespace-nowrap` jika berada di dalam parent `flex`.
- Cegah teks status seperti "Selesai (Semua Sesi)" atau "Sedang Antar" terpotong menjadi 3 baris vertikal sempit.
- Contoh:
  ```tsx
  <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10.5px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/80 shrink-0 whitespace-nowrap">
    <Icon name="check_circle" className="text-xs text-emerald-600" />
    <span>Selesai (Semua Sesi)</span>
  </span>
  ```

### C. Flex Truncation & Text Overflow
- Pada baris yang memiliki teks dinamis (seperti nama driver, tujuan, atau judul permohonan) berdampingan dengan badge atau ikon:
  1. Berikan `min-w-0` pada container teks atau flex item.
  2. Berikan `truncate` atau `line-clamp-1` pada elemen teks agar tidak mendorong badge keluar layar.
  3. Berikan `shrink-0` pada ikon atau badge di sebelahnya.

### D. Tabel Data & Horizontal Scroll
- Tabel tabular data wajib dibungkus:
  ```tsx
  <div className="w-full overflow-x-auto scrollbar-thin">
    <table className="w-full text-left min-w-[640px] sm:min-w-full">
      {/* ... */}
    </table>
  </div>
  ```
- Di layar mobile (`< 640px`), pertimbangkan menyajikan ringkasan berbentuk card list daripada tabel lebar jika data memiliki banyak kolom detail.

### E. Touch Targets & Spacing
- Target sentuh tombol aksi minimal `36px x 36px` (atau `h-9 px-3`) untuk kenyamanan jari di ponsel.
- Gunakan gap proporsional: `gap-1.5 sm:gap-3` dan padding `p-3 sm:p-5`.

---

## 4. Checklist Pengujian Cepat
Sebelum menandai pekerjaan UI selesai, periksa:
1. [ ] Apakah ada teks yang bertumpuk vertikal secara tidak wajar di layar 360px?
2. [ ] Apakah semua tombol aksi dan badge tetap utuh satu baris (`whitespace-nowrap`)?
3. [ ] Apakah tidak ada overflow horizontal yang tidak disengaja pada halaman utama?
4. [ ] Apakah `npm run build` berhasil tanpa TypeScript / JSX compile errors?
