<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Suspende restaurantes inadimplentes uma vez por dia.
Schedule::command('billing:check-overdue')->dailyAt('03:00');
