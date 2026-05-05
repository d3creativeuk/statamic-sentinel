<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Storage;

class ScheduleService
{
    const RELATIVE_PATH = 'statamic-sentinel/schedule.json';
    const TMP_PATH      = 'statamic-sentinel/schedule.json.tmp';

    // Only status_report has a scheduled send. The update report is a
    // post-update verification artifact - it only makes sense after a
    // manual update + scan, so there's nothing to schedule.
    const REPORT_KEYS = ['status_report'];
    const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public function defaults(): array
    {
        return [
            'status_report' => [
                'enabled'      => false,
                'frequency'    => 'daily',
                'day_of_week'  => 1,
                'day_of_month' => 1,
                'time'         => '09:00',
                'recipients'   => [],
            ],
        ];
    }

    /**
     * Saved schedule merged with defaults for any missing slice. Silent on
     * read failure - file-system errors must never break CP rendering.
     */
    public function all(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::RELATIVE_PATH)) {
                return $this->defaults();
            }

            $raw     = Storage::disk('local')->get(self::RELATIVE_PATH);
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                return $this->defaults();
            }

            $defaults = $this->defaults();

            foreach (self::REPORT_KEYS as $key) {
                $decoded[$key] = array_merge($defaults[$key], $decoded[$key] ?? []);
            }

            return $decoded;
        } catch (\Throwable $e) {
            return $this->defaults();
        }
    }

    /**
     * Persist atomically (.tmp → rename). Returns false on failure so the
     * caller can surface a real error in the UI.
     */
    public function save(array $config): bool
    {
        try {
            $disk = Storage::disk('local');
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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

    /**
     * Cron expression for the given report key, or null when the schedule
     * is disabled, has no recipients, or carries invalid time/day values.
     *
     * daily   HH:MM      → "MM HH * * *"
     * weekly  DOW HH:MM  → "MM HH * * DOW"   (DOW: 0=Sun..6=Sat)
     * monthly DOM HH:MM  → "MM HH DOM * *"   (DOM: 1..28)
     */
    public function cronExpression(string $reportKey): ?string
    {
        $cfg = $this->all()[$reportKey] ?? null;

        if (! $cfg || empty($cfg['enabled']) || empty($cfg['recipients'])) {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $cfg['time'] ?? '', $m)) {
            return null;
        }

        $hour   = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        switch ($cfg['frequency'] ?? null) {
            case 'daily':
                return "{$minute} {$hour} * * *";

            case 'weekly':
                $dow = (int) ($cfg['day_of_week'] ?? 1);
                if ($dow < 0 || $dow > 6) {
                    return null;
                }
                return "{$minute} {$hour} * * {$dow}";

            case 'monthly':
                $dom = (int) ($cfg['day_of_month'] ?? 1);
                if ($dom < 1 || $dom > 28) {
                    return null;
                }
                return "{$minute} {$hour} {$dom} * *";
        }

        return null;
    }
}
