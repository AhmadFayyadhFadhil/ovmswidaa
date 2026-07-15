<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_guards', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Insert default guards
        $now = now();
        $guards = ['SBY', 'LORD LUGOWO', 'Joni', 'Sutrisno', 'Supriadi', 'Bambang'];
        foreach ($guards as $guard) {
            \App\Models\SecurityGuard::updateOrCreate(
                ['name' => $guard],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_guards');
    }
};
