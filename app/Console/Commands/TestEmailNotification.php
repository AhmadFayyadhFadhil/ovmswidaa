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

        // Check if numeric (Request ID) — trigger the real workflow
        if (is_numeric($arg)) {
            $reqId = (int)$arg;
            $request = VehicleRequest::with(['user', 'department', 'passengers.user'])->find($reqId);
            if (!$request) {
                $this->error("Request #{$reqId} not found in database.");
                return 1;
            }

            $this->info("====================================================");
            $this->info("  REAL WORKFLOW EMAIL TEST FOR REQUEST #{$reqId}");
            $this->info("====================================================");
            $this->line("Requester: " . ($request->user ? $request->user->name . " <{$request->user->email}>" : 'No user'));
            $this->line("Department: " . ($request->department ? $request->department->name : 'No dept'));

            // Safely display priority
            $priorityStr = 'N/A';
            if ($request->priority) {
                $priorityStr = is_object($request->priority) && property_exists($request->priority, 'value')
                    ? $request->priority->value
                    : (string)$request->priority;
            }
            $this->line("Priority: " . $priorityStr);

            // Safely display status
            $statusStr = 'N/A';
            if ($request->status) {
                $statusStr = is_object($request->status) && property_exists($request->status, 'value')
                    ? $request->status->value
                    : (string)$request->status;
            }
            $this->line("Status: " . $statusStr);
            $this->line("Purpose: " . ($request->purpose ?? 'N/A'));
            $this->newLine();

            try {
                $this->info("Dispatching sendRequestSubmitted()...");
                EmailNotificationService::sendRequestSubmitted($request);
                $this->newLine();
                $this->info("====================================================");
                $this->info(" ✅ Email dispatch completed for Request #{$reqId}!");
                $this->info("====================================================");
                $this->line("Check the Laravel log for per-recipient success/failure details:");
                $this->line("  tail -30 storage/logs/laravel.log");
                $this->newLine();
                return 0;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("====================================================");
                $this->error(" ❌ FATAL ERROR during email dispatch:");
                $this->error(" " . $e->getMessage());
                $this->error(" File: " . $e->getFile() . ":" . $e->getLine());
                $this->error("====================================================");
                $this->newLine();
                $this->warn("Stack trace (last 5 frames):");
                $frames = array_slice($e->getTrace(), 0, 5);
                foreach ($frames as $i => $frame) {
                    $file = $frame['file'] ?? '?';
                    $line = $frame['line'] ?? '?';
                    $func = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?');
                    $this->line("  #{$i} {$file}:{$line} → {$func}()");
                }
                $this->newLine();
                return 1;
            }
        }

        // --- Normal mode: send a dummy test email to the given address ---
        $destinationEmail = $arg;

        if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: '{$destinationEmail}'");
            return 1;
        }

        $this->info('====================================================');
        $this->info('   OVMS CORPORATE SMTP TEST NOTIFICATION');
        $this->info('====================================================');
        $this->line("Mailer:     " . config('mail.default'));
        $this->line("Host:       " . config('mail.mailers.smtp.host'));
        $this->line("Port:       " . config('mail.mailers.smtp.port'));
        $this->line("Encryption: " . (config('mail.mailers.smtp.encryption') ?? 'none'));
        $this->line("Username:   " . config('mail.mailers.smtp.username'));
        $this->line("From:       " . config('mail.from.address') . " (" . config('mail.from.name') . ")");
        $this->line("Target:     {$destinationEmail}");
        $this->newLine();

        $this->info("Sending test notification to {$destinationEmail}...");

        // Direct SMTP Diagnostic Test
        try {
            $host = config('mail.mailers.smtp.host');
            $port = (int)config('mail.mailers.smtp.port');
            $this->line("→ Testing direct socket connect to {$host}:{$port}...");
            $socket = @fsockopen($host, $port, $errno, $errstr, 5);
            if ($socket) {
                $banner = fgets($socket, 512);
                $this->line("  [Server Greeting] " . trim($banner));
                fputs($socket, "EHLO ovmsdev.widatra.com\r\n");
                $ehloCaps = [];
                while ($resp = fgets($socket, 512)) {
                    $ehloCaps[] = trim($resp);
                    if (substr($resp, 3, 1) === ' ') break;
                }
                $this->line("  [Server Capabilities]: " . implode(' | ', $ehloCaps));
                fclose($socket);
            } else {
                $this->warn("  [Socket Failed]: ({$errno}) {$errstr}");
            }
        } catch (\Throwable $diagEx) {
            $this->line("  [Diagnostic Exception]: " . $diagEx->getMessage());
        }
        $this->newLine();

        $dummyData = [
            'subjectTitle'   => '[TEST OVMS] Uji Coba Integrasi Mail Server — PT Widatra Bhakti',
            'badgeText'      => 'UJI COBA SMTP BERHASIL',
            'badgeColor'     => '#059669',
            'recipientName'  => 'Rekan Kerja PT Widatra Bhakti',
            'messageBody'    => 'Selamat! Integrasi sistem notifikasi email OVMS dengan Mail Server Perusahaan (mail.widatra.com) telah BERHASIL terhubung dan berjalan dengan sempurna.',
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
            'extraNote'      => 'Email ini merupakan pesan uji coba untuk memvalidasi konfigurasi Corporate SMTP.',
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
            $this->warn("Panduan Pemeriksaan:");
            $this->line("1. Pastikan MAIL_HOST dan MAIL_PORT sesuai (Port 25 dengan MAIL_ENCRYPTION=tls).");
            $this->line("2. Pastikan MAIL_USERNAME berisi username akun (gais@widatra.com atau gais).");
            $this->line("3. Pastikan MAIL_PASSWORD dibungkus tanda petik tunggal '...' jika mengandung karakter $.");
            $this->newLine();

            return 1;
        }
    }
}
