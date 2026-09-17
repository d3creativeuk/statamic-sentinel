<?php

namespace D3Creative\Sentinel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeController extends Controller
{
    public function schedule(Request $request, ContentFreezeService $service)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $recipients = $this->parseRecipients($request->input('email', ''));

        $result = $service->schedule(
            $this->stringInput($request, 'notify_at'),
            $this->stringInput($request, 'freeze_at'),
            $recipients,
            $this->actorId(),
            [
                'freeze_ends_at'          => $this->stringInput($request, 'freeze_ends_at'),
                'expected_duration'       => is_scalar($request->input('expected_duration')) ? $request->input('expected_duration') : null,
                'expected_duration_unit'  => $this->stringInput($request, 'expected_duration_unit', 'minutes'),
            ]
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => 'Update scheduled.',
            'freeze'  => $result['freeze'],
        ], 200);
    }

    public function complete(Request $request, ContentFreezeService $service)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $result = $service->complete($this->actorId());

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => 'Update marked as complete. All-clear email sent.',
            'freeze'  => $result['freeze'],
        ], 200);
    }

    public function cancel(Request $request, ContentFreezeService $service)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $result = $service->cancel($this->actorId());

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        $message = ! empty($result['was_notified'])
            ? 'Update cancelled. Recipients had already been emailed, so you may want to let them know separately.'
            : 'Update cancelled.';

        return response()->json([
            'message' => $message,
            'freeze'  => $result['freeze'],
        ], 200);
    }

    /**
     * Statamic user IDs are strings - take whatever the auth user exposes
     * and stringify it. Falls back to email when the id() helper is absent
     * on older Statamic versions.
     */
    protected function actorId(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'id')) {
            $id = $user->id();
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        return $user->email ?? null;
    }

    /**
     * A request field as a string. (string) on an array field (`notify_at[]=`)
     * raised "Array to string conversion" and a 500 instead of a 422.
     */
    protected function stringInput(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    protected function parseRecipients($input): array
    {
        if (is_array($input)) {
            $input = implode(',', array_filter($input, 'is_string'));
        }

        return collect(explode(',', is_string($input) ? $input : ''))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
