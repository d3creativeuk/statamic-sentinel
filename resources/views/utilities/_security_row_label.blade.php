{{--
    Icon + package name for a single Security Issues row.
    Shared by the plain, single-issue (link) and expandable row variants so the
    left-hand label markup lives in one place. Expects: $pkg, $isChild, $isHeader.
--}}
<div style="display:flex; align-items:center; gap:6px; min-width:0;">
    @if($isChild)
        <span style="color:#94a3b8; flex-shrink:0; font-size:13px; line-height:1;">&#8627;</span>
        <div style="font-size:13px; font-weight:500; color:#334155; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
    @elseif($isHeader)
        <div style="font-size:13px; font-weight:600; color:#475569; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
    @else
        <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
    @endif
</div>
