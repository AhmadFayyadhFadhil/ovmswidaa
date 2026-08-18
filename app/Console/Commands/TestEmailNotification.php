<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Request as VehicleRequest;
use App\Services\EmailNotificationService;
use App\Mail\RequestNotificationMail;
use Illuminate\Support\Facades\Mail;

class TestEmailNotification extends Command
{
    protected $signature   = 'ovms:test-email {email : The destination email address to test}';
    protected $description = 'Send a test corporate OVMS email notification via configured Gmail SMTP';

    public function handle(): int
    {
        $destinationEmail = $this->argument('email');

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
