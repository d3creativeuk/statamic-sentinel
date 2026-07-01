<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Statamic update complete</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#1e293b;">

<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f1f5f9;">
    {{ $preheader }}
</div>
<div style="display:none; max-height:0; overflow:hidden;">
    &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; background:#f1f5f9;">
<tr><td align="center" style="padding:32px 16px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
<tr><td style="padding:0;">

    {{-- Header --}}
    <div style="background:#047857; padding:24px 32px;">
        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#bbf7d0; margin-bottom:6px;">All clear</div>
        <h1 style="font-size:20px; font-weight:600; color:#ffffff; margin:0; line-height:1.3;">Update complete</h1>
        <div style="font-size:14px; font-weight:500; color:#d1fae5; margin-top:4px;">
            {{-- Wrap in an explicit anchor so Gmail's auto-linker doesn't recolour the bare host to blue. --}}
            <a href="{{ config('app.url') }}" style="color:#d1fae5; text-decoration:none;">{{ $hostSentence ?? $host }}</a>
        </div>
    </div>

    {{-- Body --}}
    <div style="padding:24px 32px;">

        <p style="font-size:14px; color:#1e293b; margin:0 0 16px 0; line-height:1.55;">
            The scheduled update for <strong>{{ $hostSentence ?? $host }}</strong> has finished. You can resume editing content as normal.
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin:0 0 16px 0;">
            <tr>
                <td style="padding:14px 16px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; margin-bottom:4px;">Completed at</div>
                    <div style="font-size:15px; font-weight:600; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $completedAtDisplay }}</div>
                </td>
            </tr>
        </table>

        <p style="font-size:14px; color:#475569; margin:0; line-height:1.55;">
            Thanks for holding off while we got the work done. If you spot anything unusual, please get in touch.
        </p>

    </div>

    {{-- Footer --}}
    @php
        $_devName = $sentinelDevName ?? null;
        $_devUrl  = $sentinelDevUrl ?? null;
        $sentinelFooterAttribution = $_devName
            ? ($_devUrl
                ? '<a href="' . e($_devUrl) . '" style="color:#64748b; text-decoration:underline;">' . e($_devName) . '</a>'
                : e($_devName))
            : 'Sentinel for Statamic';
    @endphp
    <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:20px 32px;">
        <div style="font-size:12px; color:#64748b;">
            This notification was sent by {!! $sentinelFooterAttribution !!}.
        </div>
    </div>

</td></tr>
</table>
</td>
</tr>
</table>

</body>
</html>
