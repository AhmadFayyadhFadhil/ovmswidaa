<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Request as VehicleRequest;
use App\Enums\RequestStatus;

class ClearRequestHistory extends Command
{
    protected $signature   = 'ovms:clean-history {--force : Skip confirmation prompt}';
    protected $aliases     = ['requests:clear-history'];
    protected $description = 'Safely clear all vehicle request transaction history across all roles while preserving master data';

    public function handle(): int
    {
        $this->info('====================================================');
        $this->info('   OVMS SAFE HISTORY CLEANING & SYSTEM RESET');
        $this->info('====================================================');
        $this->line('Master Data Protected: Users, Roles, Departments, Vehicles, Cities, Settings.');
        $this->line('Targeted for Cleansing: Requests, Itineraries, Trips, Assignments, Approvals, Passengers, Notification States, Audit Logs.');
        $this->newLine();

        $totalRequests = DB::table('requests')->count();
        $this->warn("Total {$totalRequests} request(s) and all linked historical transactions will be permanently cleaned.");
        $this->warn("All Driver statuses will be reset to 'available' (Tersedia).");
        $this->warn("All Vehicle statuses will be reset to 'Available' (Tersedia).");
        $this->warn("Next Request ID will start fresh at #REQ-1.");

        if (!$this->option('force') && !$this->confirm('Are you sure you want to proceed with safe history cleaning?')) {
            $this->info('Action cancelled.');
            return 0;
        }

        $this->info('Starting atomic safe cleaning process...');

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');

            // 1. Truncate child & relation transaction tables
            if (DB::getSchemaBuilder()->hasTable('request_itineraries')) {
                DB::table('request_itineraries')->truncate();
                $this->line('  ✓ Table [request_itineraries] truncated');
            }

            if (DB::getSchemaBuilder()->hasTable('operational_trips')) {
                DB::table('operational_trips')->truncate();
                $this->line('  ✓ Table [operational_trips] truncated');
            }

            if (DB::getSchemaBuilder()->hasTable('assignments')) {
                DB::table('assignments')->truncate();
                $this->line('  ✓ Table [assignments] truncated');
            }

            if (DB::getSchemaBuilder()->hasTable('request_approvals')) {
                DB::table('request_approvals')->truncate();
                $this->line('  ✓ Table [request_approvals] truncated');
            }

            if (DB::getSchemaBuilder()->hasTable('passengers')) {
                DB::table('passengers')->truncate();
                $this->line('  ✓ Table [passengers] truncated');
            }

            if (DB::getSchemaBuilder()->hasTable('user_notification_states')) {
                DB::table('user_notification_states')->truncate();
                $this->line('  ✓ Table [user_notification_states] truncated (Notification badges reset to 0)');
            }

            if (DB::getSchemaBuilder()->hasTable('audit_logs')) {
                DB::table('audit_logs')->truncate();
                $this->line('  ✓ Table [audit_logs] truncated');
            }

            // 2. Truncate main requests table
            if (DB::getSchemaBuilder()->hasTable('requests')) {
                DB::table('requests')->truncate();
                $this->line('  ✓ Table [requests] truncated (Auto-Increment reset to 1)');
            }

            // 3. Reset driver availability statuses
            if (DB::getSchemaBuilder()->hasTable('users')) {
                DB::table('users')->whereNotNull('id')->update(['availability_status' => 'available']);
                $this->line("  ✓ All driver availability statuses reset to 'available'");
            }

            // 4. Reset vehicle operational statuses
            if (DB::getSchemaBuilder()->hasTable('vehicles')) {
                DB::table('vehicles')->whereNotNull('id')->update(['status' => 'Available']);
                $this->line("  ✓ All vehicle statuses reset to 'Available'");
            }

            // 5. Create initial clean audit log entry
            if (DB::getSchemaBuilder()->hasTable('audit_logs')) {
                DB::table('audit_logs')->insert([
                    'user_id'     => null,
                    'action'      => 'SYSTEM_CLEAN_HISTORY',
                    'description' => 'Seluruh riwayat transaksi pengujian lama telah dibersihkan secara aman. Sistem siap digunakan.',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $this->line('  ✓ Initialized fresh audit log entry');
            }

            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');

            $this->newLine();
            $this->info('====================================================');
            $this->info(' ✅ SAFE HISTORY CLEANING COMPLETED SUCCESSFULLY!');
            $this->info('====================================================');
            $this->info(' - All User accounts, roles, and credentials remain intact.');
            $this->info(' - All Vehicles, Departments, Cities, and Settings remain intact.');
            $this->info(' - All Drivers and Vehicles are now Available.');
            $this->info(' - New Requests will start from #REQ-1.');
            $this->newLine();

            return 0;
        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
            $this->error('Cleaning failed: ' . $e->getMessage());
            return 1;
        }
    }
}
