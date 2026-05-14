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
            $this->actorId()
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => 'Freeze scheduled.',
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
            'message' => 'Freeze marked as complete. All-clear email sent.',
            'freeze'  => $result['freeze'],
        ], 200);
    }

    /**
     * Record a per-session dismissal of the upcoming-freeze banner. The flag
     * lives in the Laravel session, which is regenerated on login - so the
     * banner reappears for the user's next sign-in even after they dismiss
     * it during the current session.
     *
     * Available to any authenticated CP user (not just super admins) because
     * the banner is shown to every CP user.
     */
    public function dismissBanner(Request $request)
    {
        abort_unless(auth()->check(), 403);

        $id = (string) $request->input('id', '');

        if ($id === '' || ! preg_match('/^freeze_[a-z0-9]+$/i', $id)) {
            return response()->json(['message' => 'Invalid freeze id.'], 422);
        }

        $request->session()->put('sentinel_freeze_dismissed_' . $id, true);

        return response()->json(['message' => 'Dismissed.'], 200);
    }

    public function cancel(Request $request, ContentFreezeService $service)
    {
        abort_unless(auth()->user()?->isSuper(), 403);

        $result = $service->cancel($this->actorId());

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        $message = ! empty($result['was_notified'])
            ? 'Freeze cancelled. Recipients had already been emailed, so you may want to let them know separately.'
            : 'Freeze cancelled.';

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
