{{--
    Sent-emails listing for one report kind. Lives inside the report's tab,
    styled to mirror the audit History tab so the two listings read the same.

    Required vars:
      - $entries  array  records from SentMailService::forKind($kind)
      - $kind     string 'status' or 'update' - used only in copy
--}}
@php
    $kindLabel = $kind === 'status' ? 'status report' : 'update report';

    $triggerLabel = [
        'manual'    => 'Manual',
        'scheduled' => 'Scheduled',
        'forced'    => 'Forced',
    ];

    $triggerColour = [
        'manual'    => '#475569',
        'scheduled' => '#1d4ed8',
        'forced'    => '#f59e0b',
    ];
@endphp

<div style="margin-top:12px;">
    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px;">
        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Sent emails</div>
        @if (! empty($entries))
            <div style="font-size:11px; color:#64748b;">Newest first. Last {{ \D3Creative\Sentinel\Services\SentMailService::MAX_PER_KIND }} retained.</div>
        @endif
    </div>

    @if (empty($entries))

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:32px 24px; text-align:center;">
            <p style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 4px 0;">No {{ $kindLabel }}s sent yet</p>
            <p style="font-size:12px; color:#64748b; margin:0; line-height:1.55; max-width:420px; margin-left:auto; margin-right:auto;">Each delivered (or failed) {{ $kindLabel }} will appear here with a Preview button to re-open the exact email that went out.</p>
        </div>

    @else

        <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Date</th>
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569;">Recipients</th>
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Trigger</th>
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Outcome</th>
                            <th style="text-align:right; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $i => $entry)
                            @php
                                try {
                                    $recordedAt = \Carbon\Carbon::parse($entry['recorded_at'])->format('j M Y, H:i');
                                } catch (\Throwable $e) {
                                    $recordedAt = $entry['recorded_at'] ?? '-';
                                }

                                $recipients = $entry['recipients'] ?? [];
                                $trigger    = $entry['trigger'] ?? 'manual';
                                $outcome    = $entry['outcome'] ?? 'sent';
                                $error      = $entry['error']   ?? null;
                                $tColour    = $triggerColour[$trigger] ?? '#475569';
                                $tLabel     = $triggerLabel[$trigger]  ?? ucfirst($trigger);
                                $isFailed   = $outcome === 'failed';
                                $oColour    = $isFailed ? '#ef4444' : '#047857';
                                $oLabel     = $isFailed ? 'Failed' : 'Sent';
                                $previewUrl = route('statamic.cp.d3-sentinel.preview-sent-report', ['id' => $entry['id']]);
                                $previewTitle = ucfirst($kindLabel) . ' sent ' . $recordedAt;
                            @endphp
                            <tr @if (! $loop->last) style="border-bottom:1px solid #f1f5f9;" @endif>
                                <td style="padding:10px 14px; color:#475569; white-space:nowrap; vertical-align:top;">{{ $recordedAt }}</td>
                                <td style="padding:10px 14px; color:#0f172a; vertical-align:top; line-height:1.45; word-break:break-word;">
                                    @if (empty($recipients))
                                        <span style="color:#94a3b8;">-</span>
                                    @else
                                        @foreach ($recipients as $r)
                                            <div>{{ $r }}</div>
                                        @endforeach
                                    @endif
                                </td>
                                <td style="padding:10px 14px; white-space:nowrap; vertical-align:top;">
                                    <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $tColour }}; background:#fff; border:1px solid {{ $tColour }};">{{ $tLabel }}</span>
                                </td>
                                <td style="padding:10px 14px; white-space:nowrap; vertical-align:top;">
                                    <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $oColour }}; background:#fff; border:1px solid {{ $oColour }};">{{ $oLabel }}</span>
                                    @if ($isFailed && ! empty($error))
                                        <div style="margin-top:4px; font-size:11px; color:#ef4444; line-height:1.45; white-space:normal; max-width:260px;">{{ $error }}</div>
                                    @endif
                                </td>
                                <td style="padding:10px 14px; text-align:right; white-space:nowrap; vertical-align:top;">
                                    <div style="display:inline-flex; align-items:center; gap:6px;">
                                        <button type="button"
                                                x-on:click="$dispatch('sentinel-preview-open', { url: @js($previewUrl), title: @js($previewTitle) })"
                                                style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:4px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                                            Preview
                                        </button>
                                        @if (! empty($entry['id']))
                                            @include('statamic-sentinel::utilities._delete_row_button', [
                                                'url'     => cp_route('d3-sentinel.sent.actions.run'),
                                                'handle'  => 'delete_sent_email',
                                                'id'      => $entry['id'],
                                                'confirm' => 'Delete this sent record? The HTML preview will also be removed.',
                                            ])
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    @endif
</div>
