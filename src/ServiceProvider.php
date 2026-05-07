<?php

namespace D3Creative\Sentinel;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Utility;
use Illuminate\Support\Facades\View;
use D3Creative\Sentinel\Console\Commands\ScanCommand;
use D3Creative\Sentinel\Console\Commands\SendStatusReportCommand;
use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Services\SentMailService;

class ServiceProvider extends AddonServiceProvider
{
    protected $widgets = [
        Widgets\SentinelWidget::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sentinel.php', 'sentinel');
    }

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'statamic-sentinel');

        View::composer('statamic-sentinel::*', function ($view) {
            $view->with([
                'sentinelDevName'  => config('sentinel.developer.name')  ?: null,
                'sentinelDevUrl'   => config('sentinel.developer.url')   ?: null,
                'sentinelDevEmail' => config('sentinel.developer.email') ?: null,
            ]);
        });

        $this->registerCpRoutes(function () {
            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-report',
                [SentinelController::class, 'sendReport']
            )->middleware('throttle:6,1')->name('d3-sentinel.send-report');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-update-report',
                [SentinelController::class, 'sendUpdateReport']
            )->middleware('throttle:6,1')->name('d3-sentinel.send-update-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-report',
                [SentinelController::class, 'previewReport']
            )->middleware('throttle:30,1')->name('d3-sentinel.preview-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-update-report',
                [SentinelController::class, 'previewUpdateReport']
            )->middleware('throttle:30,1')->name('d3-sentinel.preview-update-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-sent-report/{id}',
                [SentinelController::class, 'previewSentReport']
            )->middleware('throttle:60,1')->where('id', '[A-Za-z0-9]+')->name('d3-sentinel.preview-sent-report');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/save-schedule',
                [SentinelController::class, 'saveSchedule']
            )->middleware('throttle:30,1')->name('d3-sentinel.save-schedule');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/delete-history',
                [SentinelController::class, 'deleteHistoryEntry']
            )->middleware('throttle:30,1')->name('d3-sentinel.delete-history');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/delete-sent',
                [SentinelController::class, 'deleteSentEntry']
            )->middleware('throttle:30,1')->name('d3-sentinel.delete-sent');
        });

        // Auto-register the status-report scheduler entry when the user has
        // saved an enabled schedule. Host only needs the standard
        // `* * * * * php artisan schedule:run` cron entry. Console-only so
        // web requests never resolve the Schedule singleton on our behalf.
        if ($this->app->runningInConsole()) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
                if ($cron = app(ScheduleService::class)->cronExpression('status_report')) {
                    $schedule->command('sentinel:send-status-report')->cron($cron);
                }
            });
        }

        Utility::extend(function () {
            Utility::register(
                Utility::make('sentinel')
                    ->title('Sentinel')
                    ->navTitle('Sentinel')
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>')
                    ->description('Full vulnerability and outdated-package report.')
                    ->view('statamic-sentinel::utilities.sentinel', function () {
                        $service  = new AuditService();
                        $data     = request()->has('d3_refresh') ? $service->refresh() : $service->cached();
                        $sentMail = app(SentMailService::class);

                        return [
                            'audit'        => $data,
                            'history'      => app(HistoryService::class)->all(),
                            'schedule'     => app(ScheduleService::class)->all(),
                            'sent_status'  => $sentMail->forKind(SentMailService::KIND_STATUS),
                            'sent_update'  => $sentMail->forKind(SentMailService::KIND_UPDATE),
                        ];
                    })
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                SendStatusReportCommand::class,
            ]);
        }
    }
}
