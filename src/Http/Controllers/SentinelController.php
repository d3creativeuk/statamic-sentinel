<?php

namespace D3Creative\Sentinel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\ReportSender;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Services\UpdateReportBuilder;

class SentinelController extends Controller
{
    private const MAX_RECIPIENTS = 10;

    public function sendReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $recipientResult = $this->validateRecipientsInput($request->input('email', ''));
        if ($recipientResult instanceof \Illuminate\Http\JsonResponse) {
            return $recipientResult;
        }

        $result = (new ReportSender())->sendStatus($recipientResult);

        $status = match ($result['kind']) {
            ReportSender::KIND_SENT        => 200,
            ReportSender::KIND_MAIL_FAILED => 500,
            default                        => 422,
        };

        return response()->json(['message' => $result['message']], $status);
    }

    public function sendUpdateReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $recipientResult = $this->validateRecipientsInput($request->input('email', ''));
        if ($recipientResult instanceof \Illuminate\Http\JsonResponse) {
            return $recipientResult;
        }

        $result = (new ReportSender())->sendUpdate($recipientResult, $request->boolean('force'));

        $status = match ($result['kind']) {
            ReportSender::KIND_SENT        => 200,
            ReportSender::KIND_MAIL_FAILED => 500,
            default                        => 422,
        };

        $payload = ['message' => $result['message']];
        if (! empty($result['can_force'])) {
            $payload['can_force'] = true;
        }

        return response()->json($payload, $status);
    }

    public function saveSchedule(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $store    = app(ScheduleService::class);
        $config   = $store->all();
        $defaults = $store->defaults();
        $touched  = false;

        // Each tab posts only its own report key; the other report's saved
        // config stays untouched. Skip keys not present in the request so
        // saving the Status Report schedule doesn't wipe the Update Report
        // schedule (and vice versa).
        foreach (ScheduleService::REPORT_KEYS as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $touched    = true;
            $input      = $request->input($key, []);
            $recipients = $this->parseRecipients($input['recipients'] ?? '');

            $validator = Validator::make([
                'enabled'      => isset($input['enabled']) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) : false,
                'frequency'    => $input['frequency']    ?? null,
                'time'         => $input['time']         ?? null,
                'day_of_week'  => $input['day_of_week']  ?? null,
                'day_of_month' => $input['day_of_month'] ?? null,
                'recipients'   => $recipients,
            ], [
                'enabled'      => ['required', 'boolean'],
                'frequency'    => ['required', 'in:' . implode(',', ScheduleService::FREQUENCIES)],
                'time'         => ['required', 'date_format:H:i'],
                'day_of_week'  => ['required', 'integer', 'between:0,6'],
                'day_of_month' => ['required', 'integer', 'between:1,28'],
                'recipients'   => ['array', 'max:' . self::MAX_RECIPIENTS],
                'recipients.*' => ['email'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $validated = $validator->validated();

            if ($validated['enabled'] && empty($recipients)) {
                return response()->json([
                    'message' => 'Add at least one recipient before enabling scheduled sends.',
                ], 422);
            }

            $config[$key] = array_merge($defaults[$key], $validated, [
                'recipients' => $recipients,
            ]);
        }

        if (! $touched) {
            return response()->json(['message' => 'No schedule data submitted.'], 422);
        }

        if (! $store->save($config)) {
            return response()->json(['message' => 'Failed to save schedule. Check storage permissions.'], 500);
        }

        return response()->json(['message' => 'Schedule saved.'], 200);
    }

    /**
     * Returns either the parsed recipient array or a JsonResponse to short-
     * circuit the caller with a 422.
     */
    protected function validateRecipientsInput(string $raw)
    {
        $recipients = $this->parseRecipients($raw);

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

        return $recipients;
    }

    public function previewReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $audit = (new AuditService())->run();

        return $this->previewResponse(view('statamic-sentinel::emails.report', [
            'audit'     => $audit,
            'host'      => parse_url(config('app.url'), PHP_URL_HOST) ?: 'site',
            'preheader' => 'Statamic Package Status Report',
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
            'report'    => $report,
            'host'      => parse_url(config('app.url'), PHP_URL_HOST) ?: 'site',
            'preheader' => 'Statamic Package Update Report',
        ])->render());
    }

    public function deleteHistoryEntry(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $key = (string) $request->input('key', '');

        if ($key === '') {
            return response()->json(['message' => 'Missing history key.'], 422);
        }

        $deleted = app(HistoryService::class)->delete($key);

        if (! $deleted) {
            return response()->json(['message' => 'History entry not found.'], 404);
        }

        return response()->json(['message' => 'History entry deleted.'], 200);
    }

    public function deleteSentEntry(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $id = (string) $request->input('id', '');

        if (! preg_match(SentMailService::ID_PATTERN, $id)) {
            return response()->json(['message' => 'Invalid record id.'], 422);
        }

        $deleted = app(SentMailService::class)->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Sent record not found.'], 404);
        }

        return response()->json(['message' => 'Sent record deleted.'], 200);
    }

    public function previewSentReport(Request $request, string $id)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $store = app(SentMailService::class);
        $entry = $store->find($id);

        if (! $entry) {
            abort(404);
        }

        $html = $store->html($id);

        if ($html === null || $html === '') {
            return $this->previewResponse(
                "<!DOCTYPE html><html><head></head><body style='margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;color:#64748b;font-size:14px;'>The HTML snapshot for this send is no longer available.</body></html>"
            );
        }

        return $this->previewResponse($html);
    }

    /**
     * Wrap rendered email HTML for in-iframe preview: disable link
     * interaction so clicks inside the iframe do nothing (the preview is
     * read-only - the iframe shouldn't navigate, and we shouldn't open
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
