<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\PackageNoteService;
use D3Creative\Sentinel\ServiceProvider;
use D3Creative\Sentinel\Tests\Support\ActsAsStatamicUser;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * What the utility loads, and how much history it keeps.
 */
class UtilityDataTest extends TestCase
{
    use ActsAsStatamicUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
    }

    /**
     * Non-supers only ever see the Current tab, so the super-only stores
     * (history can run to MBs) aren't read for them.
     */
    public function test_non_supers_get_no_super_only_data(): void
    {
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode([['recorded_at' => Carbon::now()->toIso8601String()]]));
        (new PackageNoteService)->set('npm', 'braces', 'Waiting on Tailwind 4.', 'u1');

        $this->actingAsStatamicUser(false);
        $data = (new ServiceProvider($this->app))->utilityData();

        foreach (['history', 'schedule', 'sent_status', 'sent_update', 'sent_maintenance', 'maintenance_plan', 'freeze_history', 'users'] as $key) {
            $this->assertSame([], $data[$key], $key);
        }
        $this->assertSame('Waiting on Tailwind 4.', $data['package_notes']['npm']['braces']['note']);

        $this->actingAsStatamicUser(true);
        $this->assertCount(1, (new ServiceProvider($this->app))->utilityData()['history']);
    }

    public function test_history_keeps_at_most_the_newest_500_entries(): void
    {
        $entries = [];
        for ($i = 0; $i < 600; $i++) {
            $entries[] = ['recorded_at' => Carbon::now()->subMinutes($i)->toIso8601String(), 'statamic' => "6.0.{$i}"];
        }
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode($entries));

        (new HistoryService)->recordIfChanged($this->audit('ok', '6.9.9'));

        $all = (new HistoryService)->all();
        $this->assertCount(HistoryService::MAX_ENTRIES, $all);
        $this->assertSame('6.9.9', $all[0]['statamic']);
    }

    /**
     * A failed statamic.com licence check used to add an entry, and another
     * when it recovered.
     */
    public function test_an_unknown_licence_status_does_not_record_a_change(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit('ok'));
        $history->recordIfChanged($this->audit('unknown'));
        $history->recordIfChanged($this->audit('ok'));

        $this->assertCount(1, $history->all());
    }

    protected function audit(string $licence, string $statamic = '6.0.0'): array
    {
        return [
            'statamic' => ['current' => $statamic],
            'laravel'  => ['version' => '12.0.0'],
            'php'      => ['version' => '8.4.0'],
            'license'  => ['status' => $licence],
            'composer' => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
            'npm'      => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
        ];
    }
}
