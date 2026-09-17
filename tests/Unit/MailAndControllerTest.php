<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Services\UpdateReportBuilder;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;

class MailAndControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * `email[]=...` or a JSON null used to hit a string type hint and 500.
     */
    public function test_recipient_input_of_the_wrong_type_is_a_validation_error_not_a_500(): void
    {
        $this->assertSame(422, $this->recipients(null)->getStatusCode());
        $this->assertSame(422, $this->recipients(['nested' => ['x']])->getStatusCode());
        $this->assertSame(['a@example.com', 'b@example.com'], $this->recipients(['a@example.com', 'b@example.com']));
        $this->assertSame(['a@example.com', 'b@example.com'], $this->recipients('a@example.com, b@example.com, a@example.com'));
    }

    public function test_the_invalid_address_message_names_the_address(): void
    {
        $response = $this->recipients('good@example.com, not-an-email');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('Invalid address: not-an-email', $response->getData(true)['message']);
    }

    public function test_a_failed_index_write_removes_the_html_snapshot(): void
    {
        Storage::fake('local');

        $service = Mockery::mock(SentMailService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('writeIndex')->andThrow(new \RuntimeException('disk full'));

        $this->assertNull($service->record(SentMailService::KIND_STATUS, ['a@example.com'], SentMailService::TRIGGER_MANUAL, SentMailService::OUTCOME_SENT, '<html></html>'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * A forced resend replays an older report; it used to be dated today.
     */
    public function test_the_update_report_is_dated_by_its_snapshot(): void
    {
        $this->app['view']->addNamespace('statamic-sentinel', __DIR__ . '/../../resources/views');

        $report = UpdateReportBuilder::build(
            ['recorded_at' => '2026-08-03T14:05:00+00:00', 'statamic' => '6.1.0', 'composer_packages' => ['a/a' => '2.0.0']],
            ['recorded_at' => '2026-08-01T09:00:00+00:00', 'statamic' => '6.0.0', 'composer_packages' => ['a/a' => '1.0.0']]
        );

        $html = (string) view('statamic-sentinel::emails.update-report', [
            'report' => $report, 'host' => 'example.test', 'hosts' => ['example.test'], 'preheader' => 'Update',
        ]);

        $this->assertStringContainsString('3 Aug 2026, 14:05', $html);
    }

    protected function recipients($input)
    {
        $method = new ReflectionMethod(SentinelController::class, 'validateRecipientsInput');
        $method->setAccessible(true);

        return $method->invoke(new SentinelController, $input);
    }
}
