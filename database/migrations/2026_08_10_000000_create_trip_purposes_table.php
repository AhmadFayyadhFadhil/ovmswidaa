<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_purposes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed initial common trip purposes
        $purposes = [
            'Kunjungan Klien / Site Visit',
            'Dinas Luar Kota',
            'Pengiriman Dokumen / Barang',
            'Meeting Inter-Office',
            'Operasional Pabrik',
            'Inspeksi Lapangan',
            'Penjemputan / Pengantaran Tamu',
            'Lainnya',
        ];

        foreach ($purposes as $name) {
            DB::table('trip_purposes')->insert([
                'name'       => $name,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_purposes');
    }
};
