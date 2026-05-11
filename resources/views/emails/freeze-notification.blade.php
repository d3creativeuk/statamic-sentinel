<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Statamic update scheduled</title>
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
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#94a3b8; margin-bottom:6px;">Heads up</div>
        <h1 style="font-size:20px; font-weight:600; color:#ffffff; margin:0; line-height:1.3;">A Statamic update is scheduled for {{ $host }}</h1>
    </div>

    {{-- Body --}}
    <div style="padding:24px 32px;">

        <p style="font-size:14px; color:#1e293b; margin:0 0 16px 0; line-height:1.55;">
            We're going to apply an update to <strong>{{ $host }}</strong> shortly. While the update is in progress, the Statamic control panel will display a banner asking everyone to hold off on editing content.
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin:0 0 16px 0;">
            <tr>
                <td style="padding:14px 16px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; margin-bottom:4px;">Update window starts</div>
                    <div style="font-size:15px; font-weight:600; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $freezeAtDisplay }}</div>
                </td>
            </tr>
        </table>

        <p style="font-size:14px; color:#1e293b; margin:0 0 12px 0; line-height:1.55;">
            <strong>What you need to do:</strong>
        </p>

        <ul style="font-size:14px; color:#1e293b; margin:0 0 16px 0; padding-left:20px; line-height:1.65;">
            <li style="margin-bottom:6px;">Wrap up any in-flight content edits before the start time above.</li>
            <li style="margin-bottom:6px;">Once the banner appears, please pause editing content in the control panel.</li>
            <li>You'll get an "all clear" email from us once the update is complete and it's safe to resume.</li>
        </ul>

        <p style="font-size:14px; color:#475569; margin:0; line-height:1.55;">
            The website itself stays online for visitors throughout. This only affects content editing in the control panel.
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
