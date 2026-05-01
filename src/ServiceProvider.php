<?php

namespace D3Creative\Sentinel;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Utility;
use Illuminate\Console\Scheduling\Schedule;
use D3Creative\Sentinel\Console\Commands\ScanCommand;
use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\HistoryService;

class ServiceProvider extends AddonServiceProvider
{
    protected $widgets = [
        Widgets\SentinelWidget::class,
    ];

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'statamic-sentinel');

        $this->registerCpRoutes(function () {
            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-report',
                [SentinelController::class, 'sendReport']
            )->name('d3-sentinel.send-report');
        });

        Utility::extend(function () {
            Utility::register(
                Utility::make('sentinel')
                    ->title('Sentinel')
                    ->navTitle('Sentinel')
                    ->icon('shield')
                    ->description('Full vulnerability and outdated-package report.')
                    ->view('statamic-sentinel::utilities.sentinel', function () {
                        $service = new AuditService();
                        $data    = request()->has('d3_refresh') ? $service->refresh() : $service->cached();

                        return [
                            'audit'   => $data,
                            'history' => app(HistoryService::class)->all(),
                        ];
                    })
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class]);

            $this->app->booted(function () {
                $this->app->make(Schedule::class)
                    ->command('sentinel:scan')
                    ->dailyAt('10:00')
                    ->withoutOverlapping();
            });
        }
    }
}
