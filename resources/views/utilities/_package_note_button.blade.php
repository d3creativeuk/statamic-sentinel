{{--
    "Add note" / "Edit note" toggle on a Security Issues row. Runs inside the
    row's x-data (`note`, `draft`, `editing`). Expects: $noteText.
--}}
<button type="button"
        x-show="!editing"
        x-on:click="draft = note; editing = true"
        x-text="note ? 'Edit note' : 'Add note'"
        style="font-size:11px; font-weight:500; color:#64748b; background:none; border:none; padding:0; cursor:pointer; font-family:inherit; text-decoration:underline; white-space:nowrap;">{{ $noteText !== '' ? 'Edit note' : 'Add note' }}</button>
