<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('logs:clean')
    ->daily()
    ->at('00:00')
    ->timezone('UTC')
    ->onSuccess(function () {
        Log::info('Log cleanup task completed successfully');
    })
    ->onFailure(function () {
        Log::error('Log cleanup task failed');
    });
