<?php

namespace D3Creative\Sentinel\Support;

/**
 * Adds UTM parameters to the developer link (D3 Creative's by default, or a
 * white-label SENTINEL_DEV_URL), so the developer's analytics can tell which
 * visits came from Sentinel, from where (the widget, the utility or which
 * email) and from which site. Nothing is sent anywhere unless someone clicks.
 */
class DevLink
{
    /**
     * @param  string  $medium    'cp' or 'email'
     * @param  string  $campaign  where the link appears, e.g. 'widget' or 'status-report'
     */
    public static function tag(?string $url, string $medium, string $campaign): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return $url;
        }

        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url      = substr($url, 0, $hash);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $existing);

        // Parameters already on a configured URL win.
        $params = array_diff_key([
            'utm_source'   => 'sentinel',
            'utm_medium'   => $medium,
            'utm_campaign' => $campaign,
            'utm_content'  => ReportHosts::all()[0] ?? null,
        ], $existing);

        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        if (empty($params)) {
            return $url . $fragment;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params) . $fragment;
    }
}
