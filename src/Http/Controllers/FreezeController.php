<?php

namespace D3Creative\Sentinel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use D3Creative\Sentinel\Http\Controllers\Concerns\IdentifiesActor;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Support\CpAccess;

class FreezeController extends Controller
{
    use IdentifiesActor;

    public function schedule(Request $request, ContentFreezeService $service)
    {
        abort_unless(\D3Creative\Sentinel\Support\CurrentUser::isSuper(), 403);

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
        abort_unless(\D3Creative\Sentinel\Support\CurrentUser::isSuper(), 403);

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
        abort_unless(\D3Creative\Sentinel\Support\CurrentUser::isSuper(), 403);

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
     * The current freeze banner for the Statamic 6 CP, which navigates
     * without reloading the page. Fetched only when the banner key shared on
     * a navigation differs from the one on screen, or at an upcoming
     * freeze's start time, never on a timer: every CP request refreshes the
     * session, so polling would keep an idle tab signed in indefinitely.
     *
     * For any CP user, like the banner itself.
     */
    public function banner(ContentFreezeService $service)
    {
        abort_unless(app(CpAccess::class)->allows(), 403);

        // At freeze_at the scheduler may not have ticked yet. Activating is
        // due anyway and sends no email, so do it before answering.
        try {
            if ($service->hasDueTransition()) {
                $service->tickActivations();
            }
        } catch (\Throwable $e) {
            // The banner below still reflects the stored state.
        }

        return response()
            ->json($service->bannerState() + ['html' => view('statamic-sentinel::cp.freeze-injector')->render()])
            ->header('Cache-Control', 'no-store');
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
