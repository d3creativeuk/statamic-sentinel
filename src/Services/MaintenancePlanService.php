<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Persists the client's maintenance-plan details (name, start/expiry dates,
 * and the renewal-reminder copy toggle) entered once in the CP. Mirrors
 * ScheduleService's storage pattern. Silent on failure - file-system errors
 * must never break CP rendering.
 */
class MaintenancePlanService
{
    const RELATIVE_PATH = 'statamic-sentinel/maintenance-plan.json';
    const TMP_PATH      = 'statamic-sentinel/maintenance-plan.json.tmp';

    public function defaults(): array
    {
        return [
            'plan_name'     => null,   // free-text label, e.g. "Gold Care Plan"
            'start_date'    => null,   // Y-m-d or null
            'expiry_date'   => null,   // Y-m-d or null
            'show_reminder' => true,   // include the "you'll get a reminder" line in the email
            'reminder_days' => 30,     // number quoted in that line
        ];
    }

    /**
     * Saved plan merged over defaults for any missing key. Returns defaults on
     * any read failure.
     */
    public function all(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::RELATIVE_PATH)) {
                return $this->defaults();
            }

            $decoded = json_decode(Storage::disk('local')->get(self::RELATIVE_PATH), true);

            if (! is_array($decoded)) {
                return $this->defaults();
            }

            return array_merge($this->defaults(), $decoded);
        } catch (\Throwable $e) {
            return $this->defaults();
        }
    }

    /**
     * Persist atomically (.tmp -> rename). Returns false on failure so the
     * caller can surface a real error in the UI.
     */
    public function save(array $config): bool
    {
        try {
            $disk   = Storage::disk('local');
            $config = array_merge($this->defaults(), $config);
            $json   = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $disk->put(self::TMP_PATH, $json);

            if (! $disk->move(self::TMP_PATH, self::RELATIVE_PATH)) {
                $disk->delete(self::RELATIVE_PATH);
                $disk->move(self::TMP_PATH, self::RELATIVE_PATH);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
