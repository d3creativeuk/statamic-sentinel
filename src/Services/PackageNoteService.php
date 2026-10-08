<?php

namespace D3Creative\Sentinel\Services;

use D3Creative\Sentinel\Support\AtomicFile;
use D3Creative\Sentinel\Support\JsonStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Free-text notes on packages, entered in the utility's Security list and
 * shown in the update report next to the vulnerabilities they explain (e.g.
 * why braces can't be updated until Tailwind moves to v4). A note on a direct
 * dependency also covers the transitive packages it pulls in.
 *
 * Stored per ecosystem: `['npm' => ['tailwindcss' => ['note', 'updated_at',
 * 'updated_by']], 'composer' => [...]]`. Silent on failure - file-system
 * errors must never break CP rendering.
 */
class PackageNoteService
{
    const RELATIVE_PATH = 'statamic-sentinel/package-notes.json';

    const ECOSYSTEMS = ['composer', 'npm'];

    /**
     * Saved notes, or an empty list per ecosystem on any read failure.
     */
    public function all(): array
    {
        $notes = array_fill_keys(self::ECOSYSTEMS, []);

        try {
            if (! Storage::disk('local')->exists(self::RELATIVE_PATH)) {
                return $notes;
            }

            $decoded = json_decode(Storage::disk('local')->get(self::RELATIVE_PATH), true);

            if (! is_array($decoded)) {
                return $notes;
            }

            foreach (self::ECOSYSTEMS as $eco) {
                foreach ((array) ($decoded[$eco] ?? []) as $package => $entry) {
                    if (is_array($entry) && is_string($entry['note'] ?? null) && $entry['note'] !== '') {
                        $notes[$eco][(string) $package] = $entry;
                    }
                }
            }

            return $notes;
        } catch (\Throwable $e) {
            return array_fill_keys(self::ECOSYSTEMS, []);
        }
    }

    /**
     * Save, replace or (with an empty note) remove a package's note. Returns
     * false on failure so the caller can surface a real error in the UI.
     */
    public function set(string $ecosystem, string $package, ?string $note, ?string $updatedBy = null): bool
    {
        try {
            // Fail rather than overwrite notes we couldn't read.
            JsonStore::read(self::RELATIVE_PATH, true);
            $notes = $this->all();
            $note  = trim((string) $note);

            if ($note === '') {
                unset($notes[$ecosystem][$package]);
            } else {
                $notes[$ecosystem][$package] = [
                    'note'       => $note,
                    'updated_at' => Carbon::now()->utc()->toIso8601String(),
                    'updated_by' => $updatedBy,
                ];
                ksort($notes[$ecosystem]);
            }

            // Encode empty ecosystems as {} rather than [].
            AtomicFile::putJson(self::RELATIVE_PATH, array_map(fn ($eco) => (object) $eco, $notes), JSON_UNESCAPED_UNICODE);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The notes that apply to a vulnerability entry from UpdateReportBuilder
     * (`name`, `parent`, `ecosystem`): the parent's note first, then the
     * package's own. Returned as `[package => note text]`.
     */
    public static function forEntry(array $notes, array $entry): array
    {
        $forEco = $notes[$entry['ecosystem'] ?? ''] ?? [];
        $found  = [];

        foreach ([$entry['parent'] ?? null, $entry['name'] ?? null] as $package) {
            if (is_string($package) && isset($forEco[$package]['note'])) {
                $found[$package] = $forEco[$package]['note'];
            }
        }

        return $found;
    }
}
