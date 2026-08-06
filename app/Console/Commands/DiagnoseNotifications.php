<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class DiagnoseNotifications extends Command
{
    protected $signature = 'notifications:diagnose';
    protected $description = 'Diagnose notification system - check table, test insert/read';

    public function handle()
    {
        $this->info('=== NOTIFICATION SYSTEM DIAGNOSTIC ===');
        $this->newLine();

        // 1. Check if table exists
        $tableExists = Schema::hasTable('user_notification_states');
        if ($tableExists) {
            $this->info('✅ Tabel user_notification_states: ADA');
        } else {
            $this->error('❌ Tabel user_notification_states: TIDAK ADA!');
            $this->warn('Jalankan: php artisan migrate --force');
            return 1;
        }

        // 2. Check table columns
        $columns = Schema::getColumnListing('user_notification_states');
        $this->info('📋 Kolom tabel: ' . implode(', ', $columns));

        // 3. Check row count
        $count = DB::table('user_notification_states')->count();
        $this->info("📊 Jumlah baris: {$count}");

        // 4. Test insert
        $this->info('🧪 Test insert...');
        try {
            $testId = DB::table('user_notification_states')->insertGetId([
                'user_id' => 1,
                'notification_id' => 'TEST_DIAG_' . time(),
                'is_read' => true,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("✅ Insert berhasil, ID: {$testId}");

            // Clean up test row
            DB::table('user_notification_states')->where('id', $testId)->delete();
            $this->info('✅ Cleanup test row berhasil');
        } catch (\Throwable $e) {
            $this->error('❌ Insert GAGAL: ' . $e->getMessage());
            return 1;
        }

        // 5. Check if notifications route is registered
        $this->newLine();
        $this->info('🔍 Mengecek routes...');
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(function ($route) {
            return $route->methods()[0] . ' ' . $route->uri();
        })->filter(function ($r) {
            return str_contains($r, 'notification');
        })->values();

        if ($routes->isEmpty()) {
            $this->error('❌ Tidak ada route notification terdaftar!');
        } else {
            foreach ($routes as $r) {
                $this->info("  ✅ {$r}");
            }
        }

        // 6. Check users table for notification columns (optional)
        $this->newLine();
        $hasReadCol = Schema::hasColumn('users', 'read_notification_ids');
        $hasDeletedCol = Schema::hasColumn('users', 'deleted_notification_ids');
        $this->info('📋 Kolom users.read_notification_ids: ' . ($hasReadCol ? 'ADA' : 'TIDAK ADA'));
        $this->info('📋 Kolom users.deleted_notification_ids: ' . ($hasDeletedCol ? 'ADA' : 'TIDAK ADA'));

        $this->newLine();
        $this->info('=== DIAGNOSTIC SELESAI ===');
        return 0;
    }
}
