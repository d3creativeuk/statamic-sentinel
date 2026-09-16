<?php

namespace D3Creative\Sentinel\Support;

/**
 * Base score from a CVSS vector string. OSV's `severity[].score` holds the
 * vector (e.g. `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H`), not a number,
 * so the score has to be computed to bucket advisories that carry no
 * `database_specific.severity`.
 *
 * Implements the CVSS 3.0/3.1 and 2.0 base equations from the FIRST
 * specifications. CVSS 4.0 scoring needs FIRST's large macro-vector lookup
 * tables and isn't implemented; those vectors return null.
 */
class CvssScore
{
    public static function baseScore(string $vector): ?float
    {
        $vector = trim($vector);

        if (preg_match('#^CVSS:3\.[01]/#', $vector)) {
            return static::v3($vector);
        }

        if (str_starts_with($vector, 'CVSS:')) {
            return null;
        }

        return static::v2($vector);
    }

    protected static function metrics(string $vector): array
    {
        $metrics = [];

        foreach (explode('/', $vector) as $part) {
            if (str_contains($part, ':')) {
                [$key, $value] = explode(':', $part, 2);
                $metrics[$key] = $value;
            }
        }

        return $metrics;
    }

    protected static function v3(string $vector): ?float
    {
        $m = static::metrics($vector);

        $scope = $m['S'] ?? null;
        $av    = ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2][$m['AV'] ?? ''] ?? null;
        $ac    = ['L' => 0.77, 'H' => 0.44][$m['AC'] ?? ''] ?? null;
        $pr    = ($scope === 'C'
            ? ['N' => 0.85, 'L' => 0.68, 'H' => 0.5]
            : ['N' => 0.85, 'L' => 0.62, 'H' => 0.27])[$m['PR'] ?? ''] ?? null;
        $ui    = ['N' => 0.85, 'R' => 0.62][$m['UI'] ?? ''] ?? null;
        $cia   = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0];
        $c     = $cia[$m['C'] ?? ''] ?? null;
        $i     = $cia[$m['I'] ?? ''] ?? null;
        $a     = $cia[$m['A'] ?? ''] ?? null;

        if (! in_array($scope, ['U', 'C'], true) || in_array(null, [$av, $ac, $pr, $ui, $c, $i, $a], true)) {
            return null;
        }

        $iss    = 1 - ((1 - $c) * (1 - $i) * (1 - $a));
        $impact = $scope === 'U'
            ? 6.42 * $iss
            : 7.52 * ($iss - 0.029) - 3.25 * pow($iss - 0.02, 15);

        if ($impact <= 0) {
            return 0.0;
        }

        $exploitability = 8.22 * $av * $ac * $pr * $ui;
        $total          = $scope === 'U' ? $impact + $exploitability : 1.08 * ($impact + $exploitability);

        return static::roundUp(min($total, 10));
    }

    /**
     * CVSS 3.1 Roundup: the smallest one-decimal number >= the input, done in
     * integers to avoid float artefacts (e.g. 4.000000001 stays 4.0).
     */
    protected static function roundUp(float $value): float
    {
        $int = (int) round($value * 100000);

        return $int % 10000 === 0
            ? $int / 100000
            : (floor($int / 10000) + 1) / 10;
    }

    protected static function v2(string $vector): ?float
    {
        $m = static::metrics($vector);

        $av  = ['L' => 0.395, 'A' => 0.646, 'N' => 1.0][$m['AV'] ?? ''] ?? null;
        $ac  = ['H' => 0.35, 'M' => 0.61, 'L' => 0.71][$m['AC'] ?? ''] ?? null;
        $au  = ['M' => 0.45, 'S' => 0.56, 'N' => 0.704][$m['Au'] ?? ''] ?? null;
        $cia = ['N' => 0.0, 'P' => 0.275, 'C' => 0.660];
        $c   = $cia[$m['C'] ?? ''] ?? null;
        $i   = $cia[$m['I'] ?? ''] ?? null;
        $a   = $cia[$m['A'] ?? ''] ?? null;

        if (in_array(null, [$av, $ac, $au, $c, $i, $a], true)) {
            return null;
        }

        $impact         = 10.41 * (1 - (1 - $c) * (1 - $i) * (1 - $a));
        $exploitability = 20 * $av * $ac * $au;
        $f              = $impact == 0 ? 0 : 1.176;

        return round(((0.6 * $impact) + (0.4 * $exploitability) - 1.5) * $f, 1);
    }
}
