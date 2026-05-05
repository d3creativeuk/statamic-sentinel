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

        $this->publishes([
            __DIR__ . '/../config/sentinel.php' => config_path('sentinel.php'),
        ], 'sentinel-config');

        View::composer('statamic-sentinel::*', function ($view) {
            $view->with([
                'sentinelDevName' => config('sentinel.developer.name') ?: null,
                'sentinelDevUrl'  => config('sentinel.developer.url') ?: null,
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
        // `* * * * * php artisan schedule:run` cron entry.
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            if ($cron = app(ScheduleService::class)->cronExpression('status_report')) {
                $schedule->command('sentinel:send-status-report')->cron($cron);
            }
        });

        Utility::extend(function () {
            Utility::register(
                Utility::make('sentinel')
                    ->title('Sentinel')
                    ->navTitle('Sentinel')
                    ->icon('shield')
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
