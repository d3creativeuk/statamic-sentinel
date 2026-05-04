<?php

namespace D3Creative\Sentinel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Mail\SentinelUpdateReport;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\UpdateReportBuilder;

class SentinelController extends Controller
{
    private const MAX_RECIPIENTS = 10;

    public function sendReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $recipients = $this->parseRecipients($request->input('email', ''));

        if (empty($recipients)) {
            return response()->json(['message' => 'Please enter at least one email address.'], 422);
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            return response()->json([
                'message' => 'Too many recipients (max ' . self::MAX_RECIPIENTS . ').',
            ], 422);
        }

        $validator = Validator::make(['recipients' => $recipients], [
            'recipients.*' => ['email'],
        ]);

        if ($validator->fails()) {
            $invalid = array_values(array_filter($recipients, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL)));
            return response()->json([
                'message' => 'Invalid address: ' . implode(', ', $invalid),
            ], 422);
        }

        try {
            $audit = (new AuditService())->run();

            Mail::to($recipients)->send(new SentinelReport($audit));

            return response()->json(['message' => 'Report sent successfully.'], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send report. Please check your mail configuration.'], 500);
        }
    }

    public function sendUpdateReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $recipients = $this->parseRecipients($request->input('email', ''));

        if (empty($recipients)) {
            return response()->json(['message' => 'Please enter at least one email address.'], 422);
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            return response()->json([
                'message' => 'Too many recipients (max ' . self::MAX_RECIPIENTS . ').',
            ], 422);
        }

        $validator = Validator::make(['recipients' => $recipients], [
            'recipients.*' => ['email'],
        ]);

        if ($validator->fails()) {
            $invalid = array_values(array_filter($recipients, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL)));
            return response()->json([
                'message' => 'Invalid address: ' . implode(', ', $invalid),
            ], 422);
        }

        // Use the cached audit (cheap). recordIfChanged() runs inside refresh(),
        // not run(), so capturing a post-update snapshot is the explicit job of
        // the Refresh button. run() falls through to refresh() automatically
        // when the cache is empty (e.g. fresh install), so first-ever sends
        // still warm the cache and seed history.
        (new AuditService())->run();

        $history = app(HistoryService::class)->all();

        if (count($history) < 2) {
            return response()->json([
                'message' => 'No earlier snapshot to compare against yet. Apply your updates, then click Send Update Report again.',
            ], 422);
        }

        $report = UpdateReportBuilder::build($history[0], $history[1]);
        $store  = app(HistoryService::class);

        if (! $report['has_changes']) {
            if (! $request->boolean('force')) {
                return response()->json([
                    'message'   => 'No changes detected since the previous snapshot.',
                    'can_force' => true,
                ], 422);
            }

            // Forced resend: replay the last stored non-empty report so the
            // recipient sees the previous meaningful update rather than a wall
            // of "No change" rows. Falls back to the empty current diff if
            // nothing has been remembered yet.
            $report = $store->lastReport() ?? $report;
        }

        try {
            Mail::to($recipients)->send(new SentinelUpdateReport($report));

            // Remember the report only when it carries real content, so a
            // future force-resend has something meaningful to replay.
            if ($report['has_changes']) {
                $store->rememberLastReport($report);
            }

            return response()->json(['message' => 'Update report sent successfully.'], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send update report. Please check your mail configuration.'], 500);
        }
    }

    public function previewReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $audit = (new AuditService())->run();

        return $this->previewResponse(view('statamic-sentinel::emails.report', [
            'audit'       => $audit,
            'host'        => parse_url(config('app.url'), PHP_URL_HOST) ?: 'site',
            'utility_url' => cp_route('utilities.sentinel'),
        ])->render());
    }

    public function previewUpdateReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        (new AuditService())->run();

        $history = app(HistoryService::class)->all();

        if (count($history) < 2) {
            return $this->previewResponse(
                "<!DOCTYPE html><html><head></head><body style='margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;color:#64748b;font-size:14px;'>No earlier snapshot to compare against yet. Apply your updates, then come back to preview.</body></html>"
            );
        }

        $report = UpdateReportBuilder::build($history[0], $history[1]);

        // Mirror the "Send anyway" replay: when nothing has changed and the
        // user previews with ?force=1, show the last meaningful report so the
        // preview matches what the forced send would actually deliver.
        if (! $report['has_changes'] && $request->boolean('force')) {
            $report = app(HistoryService::class)->lastReport() ?? $report;
        }

        return $this->previewResponse(view('statamic-sentinel::emails.update-report', [
            'report'      => $report,
            'host'        => parse_url(config('app.url'), PHP_URL_HOST) ?: 'site',
            'utility_url' => cp_route('utilities.sentinel'),
        ])->render());
    }

    /**
     * Wrap rendered email HTML for in-iframe preview: disable link
     * interaction so clicks inside the iframe do nothing (the preview is
     * read-only — the iframe shouldn't navigate, and we shouldn't open
     * stray tabs to the CP or external sites).
     */
    protected function previewResponse(string $html)
    {
        $html = str_replace(
            '<head>',
            '<head><style>a{pointer-events:none !important;cursor:default !important;}</style>',
            $html
        );

        return response($html, 200, [
            'Content-Type'    => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    protected function parseRecipients(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
