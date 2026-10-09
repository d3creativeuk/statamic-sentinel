<?php

namespace D3Creative\Sentinel\Support;

/**
 * The Statamic favicon for the reports' Statamic row, taken from the site's
 * installed statamic/cms (Sentinel doesn't ship a copy of Statamic's logo).
 *
 * A sent email embeds it as an inline attachment (`cid:`), which Gmail,
 * Outlook and Apple Mail all show without fetching anything; Gmail drops
 * `data:` images. A render that isn't being sent (a CP preview, the Sent
 * archive snapshot) has no message to attach to, so it gets a `data:` URI,
 * which a browser shows. Null when the file can't be found.
 */
class StatamicIcon
{
    const FILE = 'resources/img/favicon-32x32.png';

    /**
     * @param  mixed  $message  the `$message` a mail view gets while sending, if any
     */
    public static function src($message = null): ?string
    {
        try {
            $path = self::path();

            if ($path === null) {
                return null;
            }

            if (is_object($message) && method_exists($message, 'embed')) {
                return $message->embed($path);
            }

            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function path(): ?string
    {
        $root = null;

        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('statamic/cms')) {
            $root = \Composer\InstalledVersions::getInstallPath('statamic/cms');
        }

        $path = rtrim((string) ($root ?? base_path('vendor/statamic/cms')), '/') . '/' . self::FILE;

        return is_file($path) ? $path : null;
    }
}
