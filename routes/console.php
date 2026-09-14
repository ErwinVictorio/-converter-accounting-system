<?php

use App\Services\PendingPurchaseUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('uploads:cleanup-pending-purchases', function () {
    $count = app(PendingPurchaseUploadService::class)->cleanupExpired();
    $this->info("Cleaned {$count} expired pending Purchase upload(s).");
})->purpose('Delete expired retained Purchase workbooks');

Schedule::command('uploads:cleanup-pending-purchases')->hourly();
