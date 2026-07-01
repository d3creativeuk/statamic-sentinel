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

        $recipients = $this->parseRecipients((string) $request->input('email', ''));

        $result = $service->schedule(
            (string) $request->input('notify_at', ''),
            (string) $request->input('freeze_at', ''),
            $recipients,
            $this->actorId(),
            [
                'freeze_ends_at'          => (string) $request->input('freeze_ends_at', ''),
                'expected_duration'       => $request->input('expected_duration'),
                'expected_duration_unit'  => (string) $request->input('expected_duration_unit', 'minutes'),
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

    protected function parseRecipients(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
