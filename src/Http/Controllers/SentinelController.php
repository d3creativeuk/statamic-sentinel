<?php

namespace D3Creative\Sentinel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Services\AuditService;

class SentinelController extends Controller
{
    public function sendReport(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $audit = (new AuditService())->run();

            Mail::to($request->input('email'))
                ->send(new SentinelReport($audit));

            return response()->json(['message' => 'Report sent successfully.'], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send report. Please check your mail configuration.'], 500);
        }
    }
}
