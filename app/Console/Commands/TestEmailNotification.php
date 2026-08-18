<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Request as VehicleRequest;
use App\Services\EmailNotificationService;
use App\Mail\RequestNotificationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class TestEmailNotification extends Command
{
    protected $signature   = 'ovms:test-email {email_or_id : Destination email address OR Request ID to test real workflow}';
    protected $description = 'Send a test corporate OVMS email notification via configured Gmail SMTP';

    public function handle(): int
    {
        $arg = $this->argument('email_or_id');

        // Check if numeric (Request ID)
        if (is_numeric($arg)) {
            $reqId = (int)$arg;
            $request = VehicleRequest::with(['user', 'department'])->find($reqId);
            if (!$request) {
                $this->error("Request #{$reqId} not found in database.");
                return 1;
            }

            $this->info("Triggering real submitted email workflow for Request #{$reqId}...");
            $this->line("Requester: " . ($request->user ? $request->user->name . " ({$request->user->email})" : 'No user'));
            $this->line("Department: " . ($request->department ? $request->department->name : 'No dept'));

            try {
                EmailNotificationService::sendRequestSubmitted($request);
                $this->info("✅ Successfully processed email dispatch for Request #{$reqId}!");
                return 0;
            } catch (\Throwable $e) {
                $this->error("Failed: " . $e->getMessage());
                return 1;
            }
        }

        $destinationEmail = $arg;

        if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: '{$destinationEmail}'");
            return 1;
        }

        $this->info('====================================================');
        $this->info('   OVMS GMAIL SMTP TEST NOTIFICATION');
        $this->info('====================================================');
        $this->line("Mailer:    " . config('mail.default'));
        $this->line("Host:      " . config('mail.mailers.smtp.host'));
        $this->line("Port:      " . config('mail.mailers.smtp.port'));
        $this->line("Username:  " . config('mail.mailers.smtp.username'));
        $this->line("From:      " . config('mail.from.address') . " (" . config('mail.from.name') . ")");
        $this->line("Target:    {$destinationEmail}");
        $this->newLine();

        $this->info("Sending test notification to {$destinationEmail}...");

        $dummyData = [
            'subjectTitle'   => '[TEST OVMS] Uji Coba Integrasi Gmail SMTP — PT Widatra Bhakti',
            'badgeText'      => 'UJI COBA SMTP BERHASIL',
            'badgeColor'     => '#059669',
            'recipientName'  => 'Rekan Kerja PT Widatra Bhakti',
            'messageBody'    => 'Selamat! Integrasi sistem notifikasi email OVMS dengan Google Gmail SMTP telah BERHASIL terhubung dan berjalan dengan sempurna.',
            'requestId'      => '999',
            'requesterName'  => 'Melodi Bella Astria',
            'departmentName' => 'HRD & GA',
            'destination'    => 'Surabaya — Kantor Pusat / Vendor',
            'purpose'        => 'Uji Coba Pengiriman Notifikasi Email Otomatis',
            'scheduleStr'    => now()->format('d M Y, H:i') . ' WIB',
            'priority'       => 'NORMAL',
            'tripType'       => 'Same Day (Satu Hari)',
            'passengersList' => 'Melodi Bella Astria, Tim IT',
            'assignmentInfo' => 'Toyota Avanza [N 1234 WB] • Driver: Pak Winaryo (HP: 08123456789)',
            'extraNote'      => 'Email ini merupakan pesan uji coba untuk memvalidasi konfigurasi Gmail SMTP.',
            'actionUrl'      => EmailNotificationService::getFrontendUrl(),
        ];

        try {
            Mail::to($destinationEmail)->send(new RequestNotificationMail($dummyData));

            $this->newLine();
            $this->info("====================================================");
            $this->info(" ✅ EMAIL TEST BERHASIL TERKIRIM KE {$destinationEmail}!");
            $this->info("====================================================");
            $this->line("Silakan periksa kotak masuk (Inbox) email tujuan Anda.");
            $this->newLine();

            return 0;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("====================================================");
            $this->error(" ❌ GAGAL MENGIRIM EMAIL:");
            $this->error(" " . $e->getMessage());
            $this->error("====================================================");
            $this->warn("Tips Perbaikan:");
            $this->line("1. Pastikan MAIL_USERNAME berisi email Gmail Anda.");
            $this->line("2. Pastikan MAIL_PASSWORD berisi 16-Digit Google App Password (Sandi Aplikasi).");
            $this->line("3. Pastikan 2-Step Verification aktif di Akun Google.");
            $this->newLine();

            return 1;
        }
    }
}
