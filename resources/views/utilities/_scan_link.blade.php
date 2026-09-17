{{--
    Scan Now / Refresh link (see ManualScan). On click it swaps the label to
    "Scanning…", spins the icon, then navigates on the next frame so the
    change paints before the scan request blocks the page.

    Vars:
      - $label     'Scan Now' or 'Refresh'
      - $style     inline style for the link
      - $title     optional title attribute
      - $iconFirst render the icon before the label
      - $keepHash  append location.hash so the utility returns to the same tab
--}}
@php
    $iconFirst = $iconFirst ?? false;
    $navigate  = ($keepHash ?? false) ? '$el.href + location.hash' : '$el.href';
@endphp
<a x-data
   x-init="if (! document.getElementById('sentinel-keyframes')) { var s = document.createElement('style'); s.id = 'sentinel-keyframes'; s.textContent = '@keyframes sentinel-spin { to { transform: rotate(360deg); } }'; document.head.appendChild(s); }"
   x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = {{ $navigate }}))"
   href="?d3_refresh={{ \D3Creative\Sentinel\Support\ManualScan::token() }}"
   @if (! empty($title)) title="{{ $title }}" @endif
   style="{{ $style }}">
    @if ($iconFirst)
        <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
        <span data-sentinel-label>{{ $label }}</span>
    @else
        <span data-sentinel-label>{{ $label }}</span>
        <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
    @endif
</a>
