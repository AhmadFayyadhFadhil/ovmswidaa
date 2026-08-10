<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_cities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('province')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed comprehensive list of Indonesian Cities & Regencies
        $cities = [
            // Jawa Timur
            ['name' => 'Pasuruan (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Pasuruan (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Surabaya', 'province' => 'Jawa Timur'],
            ['name' => 'Sidoarjo', 'province' => 'Jawa Timur'],
            ['name' => 'Malang (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Malang (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Batu', 'province' => 'Jawa Timur'],
            ['name' => 'Mojokerto (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Mojokerto (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Gresik', 'province' => 'Jawa Timur'],
            ['name' => 'Probolinggo (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Probolinggo (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Jember', 'province' => 'Jawa Timur'],
            ['name' => 'Banyuwangi', 'province' => 'Jawa Timur'],
            ['name' => 'Kediri (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Kediri (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Madiun (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Madiun (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Blitar (Kota)', 'province' => 'Jawa Timur'],
            ['name' => 'Blitar (Kabupaten)', 'province' => 'Jawa Timur'],
            ['name' => 'Tuban', 'province' => 'Jawa Timur'],
            ['name' => 'Lamongan', 'province' => 'Jawa Timur'],
            ['name' => 'Bojonegoro', 'province' => 'Jawa Timur'],
            ['name' => 'Jombang', 'province' => 'Jawa Timur'],
            ['name' => 'Nganjuk', 'province' => 'Jawa Timur'],
            ['name' => 'Tulungagung', 'province' => 'Jawa Timur'],
            ['name' => 'Trenggalek', 'province' => 'Jawa Timur'],
            ['name' => 'Ponorogo', 'province' => 'Jawa Timur'],
            ['name' => 'Pacitan', 'province' => 'Jawa Timur'],
            ['name' => 'Magetan', 'province' => 'Jawa Timur'],
            ['name' => 'Ngawi', 'province' => 'Jawa Timur'],

            // DKI Jakarta & Jabodetabek
            ['name' => 'Jakarta Pusat', 'province' => 'DKI Jakarta'],
            ['name' => 'Jakarta Selatan', 'province' => 'DKI Jakarta'],
            ['name' => 'Jakarta Barat', 'province' => 'DKI Jakarta'],
            ['name' => 'Jakarta Timur', 'province' => 'DKI Jakarta'],
            ['name' => 'Jakarta Utara', 'province' => 'DKI Jakarta'],
            ['name' => 'Bogor (Kota)', 'province' => 'Jawa Barat'],
            ['name' => 'Bogor (Kabupaten)', 'province' => 'Jawa Barat'],
            ['name' => 'Depok', 'province' => 'Jawa Barat'],
            ['name' => 'Tangerang (Kota)', 'province' => 'Banten'],
            ['name' => 'Tangerang Selatan', 'province' => 'Banten'],
            ['name' => 'Tangerang (Kabupaten)', 'province' => 'Banten'],
            ['name' => 'Bekasi (Kota)', 'province' => 'Jawa Barat'],
            ['name' => 'Bekasi (Kabupaten)', 'province' => 'Jawa Barat'],

            // Jawa Barat & Banten
            ['name' => 'Bandung (Kota)', 'province' => 'Jawa Barat'],
            ['name' => 'Bandung (Kabupaten)', 'province' => 'Jawa Barat'],
            ['name' => 'Bandung Barat', 'province' => 'Jawa Barat'],
            ['name' => 'Cimahi', 'province' => 'Jawa Barat'],
            ['name' => 'Cirebon (Kota)', 'province' => 'Jawa Barat'],
            ['name' => 'Cirebon (Kabupaten)', 'province' => 'Jawa Barat'],
            ['name' => 'Karawang', 'province' => 'Jawa Barat'],
            ['name' => 'Purwakarta', 'province' => 'Jawa Barat'],
            ['name' => 'Subang', 'province' => 'Jawa Barat'],
            ['name' => 'Sukabumi (Kota)', 'province' => 'Jawa Barat'],
            ['name' => 'Serang (Kota)', 'province' => 'Banten'],
            ['name' => 'Cilegon', 'province' => 'Banten'],

            // Jawa Tengah & DIY
            ['name' => 'Semarang (Kota)', 'province' => 'Jawa Tengah'],
            ['name' => 'Semarang (Kabupaten)', 'province' => 'Jawa Tengah'],
            ['name' => 'Surakarta (Solo)', 'province' => 'Jawa Tengah'],
            ['name' => 'Yogyakarta', 'province' => 'DI Yogyakarta'],
            ['name' => 'Sleman', 'province' => 'DI Yogyakarta'],
            ['name' => 'Bantul', 'province' => 'DI Yogyakarta'],
            ['name' => 'Klaten', 'province' => 'Jawa Tengah'],
            ['name' => 'Magelang (Kota)', 'province' => 'Jawa Tengah'],
            ['name' => 'Salatiga', 'province' => 'Jawa Tengah'],
            ['name' => 'Kudus', 'province' => 'Jawa Tengah'],
            ['name' => 'Pekalongan (Kota)', 'province' => 'Jawa Tengah'],
            ['name' => 'Tegal (Kota)', 'province' => 'Jawa Tengah'],
            ['name' => 'Purwokerto / Banyumas', 'province' => 'Jawa Tengah'],

            // Bali & Nusa Tenggara
            ['name' => 'Denpasar', 'province' => 'Bali'],
            ['name' => 'Badung', 'province' => 'Bali'],
            ['name' => 'Gianyar', 'province' => 'Bali'],
            ['name' => 'Tabanan', 'province' => 'Bali'],
            ['name' => 'Mataram', 'province' => 'Nusa Tenggara Barat'],
            ['name' => 'Kupang', 'province' => 'Nusa Tenggara Timur'],

            // Sumatra
            ['name' => 'Medan', 'province' => 'Sumatra Utara'],
            ['name' => 'Palembang', 'province' => 'Sumatra Selatan'],
            ['name' => 'Pekanbaru', 'province' => 'Riau'],
            ['name' => 'Batam', 'province' => 'Kepulauan Riau'],
            ['name' => 'Padang', 'province' => 'Sumatra Barat'],
            ['name' => 'Bandar Lampung', 'province' => 'Lampung'],
            ['name' => 'Banda Aceh', 'province' => 'Aceh'],
            ['name' => 'Jambi', 'province' => 'Jambi'],
            ['name' => 'Bengkulu', 'province' => 'Bengkulu'],

            // Kalimantan
            ['name' => 'Balikpapan', 'province' => 'Kalimantan Timur'],
            ['name' => 'Samarinda', 'province' => 'Kalimantan Timur'],
            ['name' => 'Banjarmasin', 'province' => 'Kalimantan Selatan'],
            ['name' => 'Pontianak', 'province' => 'Kalimantan Barat'],
            ['name' => 'Palangkaraya', 'province' => 'Kalimantan Tengah'],
            ['name' => 'Ibu Kota Nusantara (IKN)', 'province' => 'Kalimantan Timur'],

            // Sulawesi, Maluku, Papua
            ['name' => 'Makassar', 'province' => 'Sulawesi Selatan'],
            ['name' => 'Manado', 'province' => 'Sulawesi Utara'],
            ['name' => 'Palu', 'province' => 'Sulawesi Tengah'],
            ['name' => 'Kendari', 'province' => 'Sulawesi Tenggara'],
            ['name' => 'Gorontalo', 'province' => 'Gorontalo'],
            ['name' => 'Ambon', 'province' => 'Maluku'],
            ['name' => 'Jayapura', 'province' => 'Papua'],
        ];

        foreach ($cities as $c) {
            DB::table('destination_cities')->insert([
                'name'       => $c['name'],
                'province'   => $c['province'],
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_cities');
    }
};
