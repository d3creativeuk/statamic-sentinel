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
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 20 20" fill="#94a3b8" style="flex-shrink:0;"><title>Parent dependency</title><path d="M10.362 1.093a.75.75 0 0 0-.724 0L2.523 5.018 10 9.143l7.477-4.125-7.115-3.925ZM18 6.443l-7.25 4v8.25l6.862-3.786A.75.75 0 0 0 18 14.25V6.443ZM9.25 18.693v-8.25l-7.25-4v7.807a.75.75 0 0 0 .388.657l6.862 3.786Z"></path></svg>
        <div style="font-size:13px; font-weight:600; color:#475569; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
    @else
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 20 20" fill="#94a3b8" style="flex-shrink:0;"><title>Security issue</title><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1zm3 8V5.5a3 3 0 1 0-6 0V9h6z" clip-rule="evenodd"></path></svg>
        <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
    @endif
</div>
