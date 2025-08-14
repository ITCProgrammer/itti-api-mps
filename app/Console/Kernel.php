<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('files:copy-multimedia')
             ->everyMinute()
             ->before(function () {
                 Log::channel('file_copy')->info('Starting scheduled file copy process');
             })
             ->onSuccess(function () {
                 Log::channel('file_copy')->info('File copy completed successfully');
             })
             ->onFailure(function () {
                 Log::channel('file_copy')->error('File copy process failed');
             });
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
