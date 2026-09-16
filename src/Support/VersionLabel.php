<?php

namespace D3Creative\Sentinel\Support;

/**
 * Text for a Statamic / Laravel / PHP version row, shared by the widget and
 * the utility so the two can't drift.
 *
 * End of life is shown alongside an available update rather than instead of
 * it. PHP's `latest` is the newest release across all branches, so an EOL
 * PHP is always "outdated" too, and checking outdated first used to hide the
 * EOL label entirely.
 */
class VersionLabel
{
    public static function text(string $version, ?string $latest, ?string $status, bool $security = false): string
    {
        $outdated = ! empty($latest) && version_compare($version, $latest, '<');
        $text     = $outdated ? "{$version} → {$latest}" : $version;

        if ($status === 'eol') {
            $text .= ' (EOL)';
        }

        return $security && $outdated ? "Security: {$text}" : $text;
    }
}
