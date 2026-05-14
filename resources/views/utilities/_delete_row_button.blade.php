{{--
    Inline delete button for a table row. Posts the standard Statamic Action
    payload to $url, confirms via the page-level `sentinel-confirm-open` modal,
    and on success removes the enclosing <tr>.

    Required vars:
      - $url     string       POST endpoint (the resource's /actions route)
      - $handle  string       Action handle (e.g. 'delete-history-entry')
      - $id      string       Selection id passed in `selections[]`
      - $confirm string       Confirm-modal message
      - $context array|null   Optional context map sent as `context[key]=value`
--}}
@php($context = $context ?? [])
<button type="button"
        x-data="{ busy: false }"
        x-bind:disabled="busy"
        x-bind:aria-busy="busy ? 'true' : 'false'"
        data-url="{{ $url }}"
        data-handle="{{ $handle }}"
        data-id="{{ $id }}"
        data-confirm="{{ $confirm }}"
        data-context='@json($context)'
        data-token="{{ csrf_token() }}"
        x-on:click="
            $dispatch('sentinel-confirm-open', {
                message: $el.dataset.confirm,
                confirmLabel: 'Delete',
                onConfirm: () => {
                    busy = true;
                    const fd = new FormData();
                    fd.append('action', $el.dataset.handle);
                    fd.append('selections[]', $el.dataset.id);
                    // Statamic's ActionController calls $request->replace($request->values)
                    // and rejects null; force `values` to parse as an empty array.
                    fd.append('values[_]', '');
                    try {
                        const ctx = JSON.parse($el.dataset.context || '{}');
                        Object.entries(ctx).forEach(([k, v]) => fd.append('context[' + k + ']', v));
                    } catch (e) {}
                    fd.append('_token', $el.dataset.token);
                    fetch($el.dataset.url, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    })
                    .then(res => res.json().then(body => ({ ok: res.ok, body })).catch(() => ({ ok: res.ok, body: {} })))
                    .then(({ ok, body }) => {
                        busy = false;
                        if (ok && body.success !== false) {
                            const row = $el.closest('tr');
                            if (row) row.remove();
                        } else {
                            alert((body && body.message) || 'Failed to delete.');
                        }
                    })
                    .catch(() => {
                        busy = false;
                        alert('Something went wrong. Please try again.');
                    });
                }
            });
        "
        title="Delete"
        aria-label="Delete"
        style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; font-size:12px; color:#dc2626; background:#fff; border:1px solid #e2e8f0; border-radius:5px; cursor:pointer; font-family:inherit; vertical-align:middle;">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 5h10M6.5 5V3.5A1 1 0 0 1 7.5 2.5h1A1 1 0 0 1 9.5 3.5V5M4 5l.7 8.1a1 1 0 0 0 1 .9h4.6a1 1 0 0 0 1-.9L12 5M6.5 7.5v4M9.5 7.5v4"></path>
    </svg>
</button>
