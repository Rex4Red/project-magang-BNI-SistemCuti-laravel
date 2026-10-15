<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('cuti:mail --limit=20')->everyMinute()->withoutOverlapping(5);
Schedule::call(function () {
    DB::table('cuti_sessions')->where('expires_at', '<', now('UTC'))->delete();
})->name('cuti:expire-sessions')->daily()->withoutOverlapping();
