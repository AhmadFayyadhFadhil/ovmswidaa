<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Request as VehicleRequest;
use App\Enums\RequestStatus;

class ClearRequestHistory extends Command
{
    protected $signature   = 'requests:clear-history {--force : Skip confirmation prompt}';
    protected $description = 'Delete all completed, on_going, and scheduled (driver_assigned/waiting_driver) requests along with their related data';

    public function handle(): int
    {
        // Statuses to delete:
        // - completed    (selesai)
        // - on_going     (sedang berjalan)
        // - driver_assigned / waiting_driver (terjadwal)
        // - approved_hrd / approved_hrd_ga / assigned_by_ga / approved_department (pipeline yang sudah lewat approval)
        $targetStatuses = [
            'completed',
            'on_going',
            'driver_assigned',
            'waiting_driver',
            'approved_hrd',
            'approved_hrd_ga',
            'assigned_by_ga',
            'approved_department',
            'submitted',
            'rejected',
            'cancelled',
        ];

        // Count before deletion
        $counts = DB::table('requests')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($counts->isEmpty()) {
            $this->info('No requests found. Nothing to delete.');
            return 0;
        }

        $this->table(['Status', 'Count'], $counts->map(fn($c, $s) => [$s, $c])->values()->toArray());
        $total = $counts->sum();
        $this->warn("Total {$total} request(s) will be permanently deleted along with all related data.");
        $this->warn('This action CANNOT be undone!');

        if (!$this->option('force') && !$this->confirm('Proceed with deletion?')) {
            $this->info('Cancelled.');
            return 0;
        }

        // Get all request IDs
        $requestIds = DB::table('requests')
            ->whereIn('status', $targetStatuses)
            ->pluck('id');

        if ($requestIds->isEmpty()) {
            $this->info('No matching requests found.');
            return 0;
        }

        $this->info("Deleting " . $requestIds->count() . " request(s) and all related records...");

        DB::transaction(function () use ($requestIds) {
            // Delete all child/related records first (to avoid FK constraint errors)
            DB::table('request_itineraries')->whereIn('request_id', $requestIds)->delete();
            $this->line('  ✓ Itineraries deleted');

            DB::table('operational_trips')->whereIn('request_id', $requestIds)->delete();
            $this->line('  ✓ Operational trips deleted');

            DB::table('assignments')->whereIn('request_id', $requestIds)->delete();
            $this->line('  ✓ Assignments deleted');

            DB::table('request_approvals')->whereIn('request_id', $requestIds)->delete();
            $this->line('  ✓ Approvals deleted');

            DB::table('passengers')->whereIn('request_id', $requestIds)->delete();
            $this->line('  ✓ Passengers deleted');

            // Also reset vehicle status to AVAILABLE if any were tied to these requests
            DB::table('vehicles')
                ->whereIn('id', function ($q) use ($requestIds) {
                    $q->select('vehicle_id')->from('requests')
                      ->whereIn('id', $requestIds)
                      ->whereNotNull('vehicle_id');
                })
                ->where('status', 'in_use')
                ->update(['status' => 'available', 'updated_at' => now()]);
            $this->line('  ✓ Vehicle statuses reset to available');

            // Finally delete the requests themselves
            DB::table('requests')->whereIn('id', $requestIds)->delete();
            $this->line('  ✓ Requests deleted');
        });

        $this->newLine();
        $this->info('✅ All request history has been cleared successfully!');
        $this->info('User accounts and roles are untouched.');

        return 0;
    }
}
