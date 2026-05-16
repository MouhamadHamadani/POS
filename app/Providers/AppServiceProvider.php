<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA journal_mode=WAL;');
            DB::statement('PRAGMA foreign_keys=ON;');
            DB::statement('PRAGMA synchronous=NORMAL;');
            DB::statement('PRAGMA busy_timeout=5000;');
        }
    }
}
