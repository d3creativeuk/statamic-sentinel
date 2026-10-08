<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Http\Controllers\FreezeController;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Tests\Support\ActsAsStatamicUser;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * On a real queue the all-clear is only handed to a worker when Complete is
 * clicked, so it mustn't be reported as sent.
 */
class FreezeQueuedWordingTest extends TestCase
{
    use ActsAsStatamicUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
        Mail::fake();
        $this->actingAsStatamicUser(true);
    }

    public function test_complete_says_queued_on_an_async_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->activeFreeze();

        $this->assertStringEndsWith('All-clear email queued.', $this->complete());
    }

    public function test_complete_says_sent_on_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        $this->activeFreeze();

        $this->assertStringEndsWith('All-clear email sent.', $this->complete());
    }

    protected function complete(): string
    {
        return (new FreezeController)->complete(new Request, new ContentFreezeService)->getData(true)['message'];
    }

    protected function activeFreeze(): void
    {
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'         => 'freeze_q',
            'status'     => ContentFreezeService::STATUS_ACTIVE,
            'notify_at'  => Carbon::now()->subHours(2)->toIso8601String(),
            'freeze_at'  => Carbon::now()->subHour()->toIso8601String(),
            'recipients' => ['editor@example.com'],
        ]));
    }
}
