<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Request as VehicleRequest;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\Log;

class CheckPendingEndKmCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ovms:check-pending-end-km';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim email pengingat kepada driver jika kendaraan sudah masuk gerbang > 5 menit namun KM Akhir belum diisi.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Memeriksa permohonan yang belum mengisi KM Akhir setelah lewat 5 menit...');

        $fiveMinutesAgo = now()->subMinutes(5);

        // Permohonan internal yang gate masuknya sudah tercatat >= 5 menit yang lalu,
        // tetapi end_km belum diisi dan belum pernah dikirimi email pengingat ini.
        $pendingRequests = VehicleRequest::where('is_external', false)
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->whereNull('end_km_reminder_sent_at')
            ->where(function ($q) use ($fiveMinutesAgo) {
                // Skenario 1: Regular trip atau split trip sesi kepulangan
                $q->where(function ($sub) use ($fiveMinutesAgo) {
                    $sub->whereNotNull('security_checked_in_at')
                        ->where('security_checked_in_at', '<=', $fiveMinutesAgo)
                        ->whereNull('end_km');
                })
                // Skenario 2: Split trip sesi penjemputan
                ->orWhere(function ($sub) use ($fiveMinutesAgo) {
                    $sub->where('trip_mode', 'split_drop_pickup')
                        ->whereNotNull('return_checked_in_at')
                        ->where('return_checked_in_at', '<=', $fiveMinutesAgo)
                        ->whereNull('return_end_km');
                });
            })
            ->get();

        $count = 0;
        foreach ($pendingRequests as $req) {
            try {
                // Multi-Day awareness: verify active segment for today
                if ($req->itineraries()->exists()) {
                    $todayStr = now()->toDateString();
                    $todayItinerary = $req->itineraries()->where('date', $todayStr)->first();
                    
                    if (!$todayItinerary) {
                        continue; // No scheduled segment for today
                    }

                    $hasGateCheckin = !empty($todayItinerary->security_checked_in_at)
                        || !empty($todayItinerary->morning_checked_in_at)
                        || !empty($todayItinerary->afternoon_checked_in_at);

                    $hasEndKm = !empty($todayItinerary->end_km) && (float)$todayItinerary->end_km > 0;

                    // If today segment has not checked in at gate or has already filled end_km, do not send reminder
                    if (!$hasGateCheckin || $hasEndKm) {
                        continue;
                    }
                }

                EmailNotificationService::sendPendingEndKmReminder($req);
                $req->update(['end_km_reminder_sent_at' => now()]);
                $count++;
                $this->line("Email pengingat berhasil dikirim untuk Request #{$req->id}");
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim email pengingat KM Akhir untuk Request #{$req->id}: " . $e->getMessage());
                $this->error("Gagal mengirim email untuk Request #{$req->id}: " . $e->getMessage());
            }
        }

        $this->info("Selesai. Total {$count} email pengingat berhasil dikirim.");
        return 0;
    }
}
