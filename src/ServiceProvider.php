<?php

namespace D3Creative\Sentinel;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Utility;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Services\AuditService;

class ServiceProvider extends AddonServiceProvider
{
    protected $widgets = [
        Widgets\SentinelWidget::class,
    ];

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'd3creative-sentinel');

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
                    ->view('d3creative-sentinel::utilities.sentinel', function () {
                        $service = new AuditService();
                        return request()->has('d3_refresh') ? $service->refresh() : $service->run();
                    })
            );
        });

        if (! app()->environment('local')) {
            $this->phoneHomeOnce();
        }
    }

    protected function phoneHomeOnce(): void
    {
        $cacheKey = 'd3creative_sentinel_installed';

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            Http::timeout(3)->post('https://d3creative.uk/api/sentinel/install', [
                'domain'       => request()->getHost(),
                'statamic'     => \Statamic\Statamic::version(),
                'php'          => PHP_VERSION,
                'installed_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            // Silently fail — never block the CP loading
        }

        Cache::forever($cacheKey, true);
    }
}
