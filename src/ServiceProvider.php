<?php

namespace D3Creative\Sentinel;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Utility;
use Illuminate\Support\Facades\View;
use D3Creative\Sentinel\Console\Commands\FreezeCompleteCommand;
use D3Creative\Sentinel\Console\Commands\FreezeStartCommand;
use D3Creative\Sentinel\Console\Commands\FreezeTickActivationsCommand;
use D3Creative\Sentinel\Console\Commands\FreezeTickNotificationsCommand;
use D3Creative\Sentinel\Console\Commands\ScanCommand;
use D3Creative\Sentinel\Console\Commands\SendStatusReportCommand;
use D3Creative\Sentinel\Http\Controllers\FreezeController;
use D3Creative\Sentinel\Http\Controllers\FreezeHistoryActionController;
use D3Creative\Sentinel\Http\Controllers\HistoryActionController;
use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Http\Controllers\SentMailActionController;
use D3Creative\Sentinel\Http\Middleware\AdvanceFreezeState;
use D3Creative\Sentinel\Http\Middleware\InjectFreezeBanner;
use D3Creative\Sentinel\Http\Middleware\RecordLastActive;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\LastActiveService;
use D3Creative\Sentinel\Services\MaintenancePlanService;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Services\SentMailService;

class ServiceProvider extends AddonServiceProvider
{
    protected $widgets = [
        Widgets\SentinelWidget::class,
    ];


    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'statamic-sentinel');

        View::composer('statamic-sentinel::*', function ($view) {
            $branded = (bool) config('statamic-sentinel.branding.enabled', true);
            $view->with([
                'sentinelDevName'  => $branded ? (config('statamic-sentinel.developer.name')  ?: null) : null,
                'sentinelDevUrl'   => $branded ? (config('statamic-sentinel.developer.url')   ?: null) : null,
                'sentinelDevEmail' => $branded ? (config('statamic-sentinel.developer.email') ?: null) : null,
            ]);
        });

        // Register two middlewares on every CP request via the manual router
        // API rather than AddonServiceProvider's `$middlewareGroups` property.
        // The manual API is plain Laravel, available identically on every
        // supported Statamic version (3.3 -> 6.x), whereas `$middlewareGroups`
        // depends on Statamic's `bootMiddleware()` being part of the addon
        // boot chain - which is true in 6 but not verified for 3.3.
        //
        //  - AdvanceFreezeState: ticks the freeze state machine forward if
        //    any timestamps have passed. Runs on every request (HTML and
        //    Inertia JSON in Statamic 6) so navigation keeps the state
        //    moving in dev environments without `schedule:run` cron.
        //    Production should still rely on the registered cron schedule;
        //    this is the fallback.
        //
        //  - InjectFreezeBanner: injects the banner markup into HTML
        //    responses. Filters non-HTML / unauthenticated requests itself.
        //
        // Pushed to `statamic.cp` (not `web`) because Statamic CP routes
        // are wrapped in their own middleware group.
        $this->app['router']->pushMiddlewareToGroup('statamic.cp', AdvanceFreezeState::class);
        $this->app['router']->pushMiddlewareToGroup('statamic.cp', InjectFreezeBanner::class);

        // Record each authenticated CP user's last-active time (throttled to
        // ~1 write/min per user), so the utility's Users tab can show who's
        // recently online. Appended after Statamic's AuthGuard, so the CP user
        // is resolvable inside the middleware.
        $this->app['router']->pushMiddlewareToGroup('statamic.cp', RecordLastActive::class);

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

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-freeze-notification',
                [SentinelController::class, 'previewFreezeNotification']
            )->middleware('throttle:30,1')->name('d3-sentinel.preview-freeze-notification');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-freeze-completion',
                [SentinelController::class, 'previewFreezeCompletion']
            )->middleware('throttle:30,1')->name('d3-sentinel.preview-freeze-completion');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/save-schedule',
                [SentinelController::class, 'saveSchedule']
            )->middleware('throttle:30,1')->name('d3-sentinel.save-schedule');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-maintenance-report',
                [SentinelController::class, 'sendMaintenanceReport']
            )->middleware('throttle:6,1')->name('d3-sentinel.send-maintenance-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-maintenance-report',
                [SentinelController::class, 'previewMaintenanceReport']
            )->middleware('throttle:30,1')->name('d3-sentinel.preview-maintenance-report');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/save-maintenance-plan',
                [SentinelController::class, 'saveMaintenancePlan']
            )->middleware('throttle:30,1')->name('d3-sentinel.save-maintenance-plan');

            // Per-resource Action endpoints. Statamic-native contract:
            // POST /actions       runs an action against {action, selections, context}
            // POST /actions/list  returns the bulk-action list for the current selection
            // Per-row delete in the addon's UI uses /actions with a single id;
            // /actions/list is exposed for free (no UI consumer today, future-proofs bulk).
            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/history/actions',
                [HistoryActionController::class, 'run']
            )->middleware('throttle:30,1')->name('d3-sentinel.history.actions.run');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/history/actions/list',
                [HistoryActionController::class, 'bulkActions']
            )->middleware('throttle:30,1')->name('d3-sentinel.history.actions.bulk');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/sent/actions',
                [SentMailActionController::class, 'run']
            )->middleware('throttle:30,1')->name('d3-sentinel.sent.actions.run');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/sent/actions/list',
                [SentMailActionController::class, 'bulkActions']
            )->middleware('throttle:30,1')->name('d3-sentinel.sent.actions.bulk');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freezes/actions',
                [FreezeHistoryActionController::class, 'run']
            )->middleware('throttle:30,1')->name('d3-sentinel.freezes.actions.run');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freezes/actions/list',
                [FreezeHistoryActionController::class, 'bulkActions']
            )->middleware('throttle:30,1')->name('d3-sentinel.freezes.actions.bulk');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freeze/schedule',
                [FreezeController::class, 'schedule']
            )->middleware('throttle:6,1')->name('d3-sentinel.freeze.schedule');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freeze/complete',
                [FreezeController::class, 'complete']
            )->middleware('throttle:6,1')->name('d3-sentinel.freeze.complete');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freeze/cancel',
                [FreezeController::class, 'cancel']
            )->middleware('throttle:6,1')->name('d3-sentinel.freeze.cancel');
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

                // Drive the freeze state machine. Every-minute ticks are
                // cheap (no-op when there's no scheduled / notified freeze)
                // and give us at-most-one-minute lag between the configured
                // time and the user-visible effect.
                $schedule->command('sentinel:freeze:tick-notifications')->everyMinute()->withoutOverlapping();
                $schedule->command('sentinel:freeze:tick-activations')->everyMinute()->withoutOverlapping();
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
                        $service = new AuditService();

                        // Run the refresh then redirect to the same URL with
                        // `d3_refresh` stripped, so a manual F5 doesn't
                        // re-trigger the audit. The exception bubbles out of
                        // the utility render pipeline; Laravel turns it back
                        // into the redirect response.
                        if (request()->has('d3_refresh')) {
                            $service->refresh();
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                                redirect()->to(request()->fullUrlWithoutQuery('d3_refresh'))
                            );
                        }

                        $data     = $service->cached();
                        $sentMail = app(SentMailService::class);
                        $freeze   = app(ContentFreezeService::class);

                        return [
                            'audit'           => $data,
                            'history'         => app(HistoryService::class)->all(),
                            'schedule'        => app(ScheduleService::class)->all(),
                            'sent_status'     => $sentMail->forKind(SentMailService::KIND_STATUS),
                            'sent_update'     => $sentMail->forKind(SentMailService::KIND_UPDATE),
                            'sent_maintenance' => $sentMail->forKind(SentMailService::KIND_MAINTENANCE),
                            'last_status_recipients' => $sentMail->lastManualRecipients(SentMailService::KIND_STATUS),
                            'last_update_recipients' => $sentMail->lastManualRecipients(SentMailService::KIND_UPDATE),
                            'last_maintenance_recipients' => $sentMail->lastManualRecipients(SentMailService::KIND_MAINTENANCE),
                            'maintenance_plan' => app(MaintenancePlanService::class)->all(),
                            // Who's-online list - super-only (it exposes every CP
                            // user's activity), so don't even build it otherwise.
                            'users'           => auth()->user()?->isSuper() === true ? $this->buildUserActivity() : [],
                            'online_window'   => (int) config('statamic-sentinel.users.online_window', 5),
                            'freeze'          => $freeze,
                            'freeze_current'  => $freeze->current(),
                            'freeze_history'  => $freeze->history(),
                        ];
                    })
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                SendStatusReportCommand::class,
                FreezeStartCommand::class,
                FreezeCompleteCommand::class,
                FreezeTickNotificationsCommand::class,
                FreezeTickActivationsCommand::class,
            ]);
        }
    }

    /**
     * Join every CP user to their recorded last-active time (from
     * LastActiveService) and their last login (from Statamic), shaped for the
     * utility's Users tab and sorted most-recently-active first, then by name.
     * Guarded so an older/absent User API can never break utility rendering.
     */
    protected function buildUserActivity(): array
    {
        try {
            $active = app(LastActiveService::class)->all();

            $users = \Statamic\Facades\User::all()->map(function ($user) use ($active) {
                $id        = (string) $user->id();
                $lastLogin = $user->lastLogin();

                return [
                    'id'          => $id,
                    'name'        => $user->name(),
                    'email'       => $user->email(),
                    'initials'    => method_exists($user, 'initials') ? $user->initials() : '',
                    'is_super'    => (bool) $user->isSuper(),
                    'last_active' => $active[$id] ?? null,
                    'last_login'  => $lastLogin ? $lastLogin->toIso8601String() : null,
                ];
            })->all();

            usort($users, function ($a, $b) {
                $aa = $a['last_active'];
                $bb = $b['last_active'];
                if ($aa === null && $bb === null) {
                    return strcasecmp((string) $a['name'], (string) $b['name']);
                }
                if ($aa === null) return 1;   // no activity sorts last
                if ($bb === null) return -1;
                if ($aa !== $bb) return strcmp($bb, $aa); // ISO-8601, descending
                return strcasecmp((string) $a['name'], (string) $b['name']);
            });

            return $users;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
