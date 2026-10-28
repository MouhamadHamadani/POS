<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recurring expenses. Under NativePHP this only runs when the scheduler loop is switched on
// (nativephp.scheduler.enabled, off by default); the app also books what is due at every launch
// and sign-in (RecurringExpenseService::generateDue), which is what the desktop build relies on.
Schedule::command('pos:generate-recurring-expenses')->daily();
