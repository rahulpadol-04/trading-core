<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:expire')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
