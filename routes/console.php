<?php

use App\Services\Shared\DeadlineNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:deadlines', function (DeadlineNotificationService $service) {
    $this->info(json_encode($service->run()));
})->purpose('Kirim notifikasi tenggat: surat keringanan, target revisi WO, WO lewat target');

// Dijalankan container `scheduler` (compose.yml, `php artisan schedule:work`). Tiap jam,
// karena target_date dan tenggat surat keringanan berjam, bukan cuma bertanggal.
Schedule::command('notifications:deadlines')->hourly()->withoutOverlapping();
