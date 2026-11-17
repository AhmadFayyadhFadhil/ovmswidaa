<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal pengecekan permohonan yang belum mengisi KM Akhir setelah > 5 menit masuk gate pabrik
Schedule::command('ovms:check-pending-end-km')->everyFiveMinutes();
