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
use D3Creative\Sentinel\Services\PackageNoteService;
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
        // The manual API is plain Laravel and behaves the same on every
        // supported Statamic version (5 and 6), so the registration doesn't
        // depend on how each version's addon boot chain handles
        // `$middlewareGroups`.
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

        // Each route's throttle has its own prefix. Without one, Laravel keys
        // the counter on the user id alone, so every throttled route (and any
        // in the host app) shares one counter per user, checked against each
        // route's own limit: a few previews and a save were enough to 429 the
        // next Send.
        $this->registerCpRoutes(function () {
            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-report',
                [SentinelController::class, 'sendReport']
            )->middleware('throttle:6,1,sentinel.send-report')->name('d3-sentinel.send-report');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-update-report',
                [SentinelController::class, 'sendUpdateReport']
            )->middleware('throttle:6,1,sentinel.send-update-report')->name('d3-sentinel.send-update-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-report',
                [SentinelController::class, 'previewReport']
            )->middleware('throttle:30,1,sentinel.preview-report')->name('d3-sentinel.preview-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-update-report',
                [SentinelController::class, 'previewUpdateReport']
            )->middleware('throttle:30,1,sentinel.preview-update-report')->name('d3-sentinel.preview-update-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-sent-report/{id}',
                [SentinelController::class, 'previewSentReport']
            )->middleware('throttle:60,1,sentinel.preview-sent-report')->where('id', '[A-Za-z0-9]+')->name('d3-sentinel.preview-sent-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-freeze-notification',
                [SentinelController::class, 'previewFreezeNotification']
            )->middleware('throttle:30,1,sentinel.preview-freeze-notification')->name('d3-sentinel.preview-freeze-notification');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-freeze-completion',
                [SentinelController::class, 'previewFreezeCompletion']
            )->middleware('throttle:30,1,sentinel.preview-freeze-completion')->name('d3-sentinel.preview-freeze-completion');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/save-schedule',
                [SentinelController::class, 'saveSchedule']
            )->middleware('throttle:30,1,sentinel.save-schedule')->name('d3-sentinel.save-schedule');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/send-maintenance-report',
                [SentinelController::class, 'sendMaintenanceReport']
            )->middleware('throttle:6,1,sentinel.send-maintenance-report')->name('d3-sentinel.send-maintenance-report');

            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/preview-maintenance-report',
                [SentinelController::class, 'previewMaintenanceReport']
            )->middleware('throttle:30,1,sentinel.preview-maintenance-report')->name('d3-sentinel.preview-maintenance-report');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/save-maintenance-plan',
                [SentinelController::class, 'saveMaintenancePlan']
            )->middleware('throttle:30,1,sentinel.save-maintenance-plan')->name('d3-sentinel.save-maintenance-plan');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/save-package-note',
                [SentinelController::class, 'savePackageNote']
            )->middleware('throttle:30,1,sentinel.save-package-note')->name('d3-sentinel.save-package-note');

            // Per-resource Action endpoints. Statamic-native contract:
            // POST /actions       runs an action against {action, selections, context}
            // POST /actions/list  returns the bulk-action list for the current selection
            // Per-row delete in the addon's UI uses /actions with a single id;
            // /actions/list is exposed for free (no UI consumer today, future-proofs bulk).
            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/history/actions',
                [HistoryActionController::class, 'run']
            )->middleware('throttle:30,1,sentinel.history.actions.run')->name('d3-sentinel.history.actions.run');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/history/actions/list',
                [HistoryActionController::class, 'bulkActions']
            )->middleware('throttle:30,1,sentinel.history.actions.bulk')->name('d3-sentinel.history.actions.bulk');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/sent/actions',
                [SentMailActionController::class, 'run']
            )->middleware('throttle:30,1,sentinel.sent.actions.run')->name('d3-sentinel.sent.actions.run');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/sent/actions/list',
                [SentMailActionController::class, 'bulkActions']
            )->middleware('throttle:30,1,sentinel.sent.actions.bulk')->name('d3-sentinel.sent.actions.bulk');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freezes/actions',
                [FreezeHistoryActionController::class, 'run']
            )->middleware('throttle:30,1,sentinel.freezes.actions.run')->name('d3-sentinel.freezes.actions.run');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freezes/actions/list',
                [FreezeHistoryActionController::class, 'bulkActions']
            )->middleware('throttle:30,1,sentinel.freezes.actions.bulk')->name('d3-sentinel.freezes.actions.bulk');

            // Read by the Statamic 6 CP to keep the freeze banner current
            // between full page loads; any CP user (see FreezeController).
            \Illuminate\Support\Facades\Route::get(
                'd3-sentinel/freeze/banner',
                [FreezeController::class, 'banner']
            )->middleware('throttle:30,1,sentinel.freeze.banner')->name('d3-sentinel.freeze.banner');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freeze/schedule',
                [FreezeController::class, 'schedule']
            )->middleware('throttle:6,1,sentinel.freeze.schedule')->name('d3-sentinel.freeze.schedule');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freeze/complete',
                [FreezeController::class, 'complete']
            )->middleware('throttle:6,1,sentinel.freeze.complete')->name('d3-sentinel.freeze.complete');

            \Illuminate\Support\Facades\Route::post(
                'd3-sentinel/freeze/cancel',
                [FreezeController::class, 'cancel']
            )->middleware('throttle:6,1,sentinel.freeze.cancel')->name('d3-sentinel.freeze.cancel');
        });

        // Auto-register the status-report scheduler entry when the user has
        // saved an enabled schedule. Host only needs the standard
        // `* * * * * php artisan schedule:run` cron entry. Console-only so
        // web requests never resolve the Schedule singleton on our behalf.
        if ($this->app->runningInConsole()) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, fn ($schedule) => $this->registerSchedule($schedule));
        }

        Utility::extend(function () {
            Utility::register(
                Utility::make('sentinel')
                    ->title('Sentinel')
                    ->navTitle('Sentinel')
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>')
                    ->description('Full vulnerability and outdated-package report.')
                    ->view('statamic-sentinel::utilities.sentinel', fn () => $this->utilityData())
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
     * View data for the Sentinel utility. Runs a manual scan first when the
     * request asks for one (that redirects), then reads the stores; the
     * super-only ones are left empty for everyone else.
     */
    public function utilityData(): array
    {
        $service = new AuditService();

        // Run the refresh then redirect to the same URL with
        // `d3_refresh` stripped, so a manual F5 doesn't
        // re-trigger the audit. The exception bubbles out of
        // the utility render pipeline; Laravel turns it back
        // into the redirect response.
        (new \D3Creative\Sentinel\Support\ManualScan)->handle(request(), $service);

        $data     = $service->cached();
        $sentMail = app(SentMailService::class);
        $freeze   = app(ContentFreezeService::class);

        // Everything but the Current tab is super-only, so
        // don't read those stores (history can run to MBs)
        // for anyone else.
        $isSuper = \D3Creative\Sentinel\Support\CurrentUser::isSuper();

        return [
            'audit'           => $data,
            'history'         => $isSuper ? app(HistoryService::class)->all() : [],
            'schedule'        => $isSuper ? app(ScheduleService::class)->all() : [],
            'sent_status'     => $isSuper ? $sentMail->forKind(SentMailService::KIND_STATUS) : [],
            'sent_update'     => $isSuper ? $sentMail->forKind(SentMailService::KIND_UPDATE) : [],
            'sent_maintenance' => $isSuper ? $sentMail->forKind(SentMailService::KIND_MAINTENANCE) : [],
            'last_status_recipients' => $isSuper ? $sentMail->lastManualRecipients(SentMailService::KIND_STATUS) : [],
            'last_update_recipients' => $isSuper ? $sentMail->lastManualRecipients(SentMailService::KIND_UPDATE) : [],
            'last_maintenance_recipients' => $isSuper ? $sentMail->lastManualRecipients(SentMailService::KIND_MAINTENANCE) : [],
            'maintenance_plan' => $isSuper ? app(MaintenancePlanService::class)->all() : [],
            // The Current tab shows notes to everyone.
            'package_notes'   => app(PackageNoteService::class)->all(),
            // Who's-online list - super-only (it exposes every CP
            // user's activity), so don't even build it otherwise.
            'users'           => $isSuper ? $this->buildUserActivity() : [],
            'online_window'   => (int) config('statamic-sentinel.users.online_window', 5),
            'track_activity'  => self::tracksActivity(),
            'freeze'          => $freeze,
            'freeze_current'  => $freeze->current(),
            'freeze_history'  => $isSuper ? $freeze->history() : [],
        ];
    }

    /**
     * Sentinel's scheduled tasks. Statamic resolves the Schedule while the
     * app boots in the console, so anything thrown here would stop every
     * artisan command (schedule:run, queue:work, migrate) on the host, not
     * just Sentinel's: report it and carry on.
     *
     * withoutOverlapping() gets explicit expiries. Laravel's default is 24
     * hours, so a schedule:run killed mid-task (deploy, OOM, reboot) left a
     * mutex that skipped the task for a day.
     */
    public function registerSchedule($schedule): void
    {
        try {
            $schedules = app(ScheduleService::class);

            if ($cron = $schedules->cronExpression('status_report')) {
                // One send per slot even when several servers run schedule:run.
                $schedule->command('sentinel:send-status-report')->cron($cron)->onOneServer()->withoutOverlapping(120);
            }

            // Opt-in unattended scans (SENTINEL_SCAN_SCHEDULE).
            if ($scanCron = $schedules->scanCronExpression()) {
                $schedule->command('sentinel:scan')->cron($scanCron)->onOneServer()->withoutOverlapping(120);
            }

            // Drive the freeze state machine, at most a minute behind the
            // configured times. Each tick is a separate artisan process, so
            // only start one when a transition is actually due (a cheap file
            // read) rather than booting the app twice a minute for nothing.
            $due = fn () => app(ContentFreezeService::class)->hasDueTransition();

            $schedule->command('sentinel:freeze:tick-notifications')->everyMinute()->when($due)->withoutOverlapping(10);
            $schedule->command('sentinel:freeze:tick-activations')->everyMinute()->when($due)->withoutOverlapping(10);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Join every CP user to their recorded last-active time (from
     * LastActiveService) and their last login (from Statamic), shaped for the
     * utility's Users tab and sorted most-recently-active first, then by name.
     * Guarded so an older/absent User API can never break utility rendering.
     */
    /**
     * Above this many users, the tab lists recently active CP users only.
     */
    const USERS_TAB_SCAN_LIMIT = 500;

    /**
     * Control Panel users only: the tab used to list every Statamic user,
     * front-end members included, calling lastLogin() and isSuper() on each
     * (two queries per user with database users, a YAML read each with
     * file users) on every super's utility load. On a site with more than
     * USERS_TAB_SCAN_LIMIT users it starts from the users recorded as active
     * (always CP users) plus whoever is viewing, rather than loading them all.
     */
    protected function cpUsers(array $active)
    {
        $repository = \Statamic\Facades\User::getFacadeRoot();

        if ($repository->query()->count() > self::USERS_TAB_SCAN_LIMIT) {
            $users = collect(array_keys($active))
                ->push(\D3Creative\Sentinel\Support\CurrentUser::id())
                ->filter()
                ->unique()
                ->map(fn ($id) => $repository->find((string) $id))
                ->filter();
        } else {
            $users = $repository->all();
        }

        return $users->filter(fn ($user) => $user->isSuper() || $user->hasPermission('access cp'))->values();
    }

    /**
     * SENTINEL_TRACK_ACTIVITY. filter_var so 'false', '0' and 'off' all
     * switch it off, however the value reaches config.
     */
    public static function tracksActivity(): bool
    {
        return filter_var(config('statamic-sentinel.users.track_activity', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    protected function buildUserActivity(): array
    {
        try {
            // Switching tracking off stops showing activity already recorded,
            // and removes it rather than keeping it indefinitely.
            if (self::tracksActivity()) {
                $active = app(LastActiveService::class)->all();
            } else {
                app(LastActiveService::class)->clear();
                $active = [];
            }

            $users = $this->cpUsers($active)->map(function ($user) use ($active) {
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
