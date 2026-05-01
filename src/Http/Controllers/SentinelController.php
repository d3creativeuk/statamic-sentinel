<?php

namespace D3Creative\Sentinel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Services\AuditService;

class SentinelController extends Controller
{
    private const MAX_RECIPIENTS = 10;

    public function sendReport(Request $request)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $recipients = collect(explode(',', (string) $request->input('email', '')))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->unique()
            ->values()
            ->all();

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
}
