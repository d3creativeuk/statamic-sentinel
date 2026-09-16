<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Support\CvssScore;
use D3Creative\Sentinel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Advisories without `database_specific.severity` are bucketed from their
 * CVSS vector. The old fallback looked for a trailing "/9.8" that OSV vectors
 * never have, so they all landed in UNKNOWN.
 */
class SeverityExtractionTest extends TestCase
{
    /**
     * Reference scores from the FIRST CVSS calculators.
     *
     * @dataProvider vectorProvider
     */
    #[DataProvider('vectorProvider')]
    public function test_base_scores_match_the_reference_calculator(string $vector, ?float $expected): void
    {
        $this->assertSame($expected, CvssScore::baseScore($vector));
    }

    public static function vectorProvider(): array
    {
        return [
            'v3.1 critical'          => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 9.8],
            'v3.1 scope changed'     => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N', 6.1],
            'v3.1 PR low changed'    => ['CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:C/C:L/I:L/A:N', 6.4],
            'v3.1 local'             => ['CVSS:3.1/AV:L/AC:L/PR:L/UI:N/S:U/C:H/I:N/A:N', 5.5],
            'v3.1 low'               => ['CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N', 3.7],
            'v3.0 capped at 10'      => ['CVSS:3.0/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H', 10.0],
            'v3.1 no impact'         => ['CVSS:3.1/AV:P/AC:H/PR:H/UI:R/S:U/C:N/I:N/A:N', 0.0],
            'v2 partial'             => ['AV:N/AC:L/Au:N/C:P/I:P/A:P', 7.5],
            'v2 medium'              => ['AV:N/AC:M/Au:N/C:N/I:P/A:N', 4.3],
            'v2 complete'            => ['AV:N/AC:L/Au:N/C:C/I:C/A:C', 10.0],
            'v4 not scored'          => ['CVSS:4.0/AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N', null],
            'v3 missing metric'      => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/C:H/I:H/A:H', null],
            'garbage'                => ['not a vector', null],
        ];
    }

    public function test_advisories_without_a_database_severity_use_their_cvss_vector(): void
    {
        $this->assertSame('CRITICAL', $this->severity(['severity' => [
            ['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H'],
        ]]));

        // v4 first is skipped in favour of a scoreable v3.
        $this->assertSame('MEDIUM', $this->severity(['severity' => [
            ['type' => 'CVSS_V4', 'score' => 'CVSS:4.0/AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N'],
            ['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N'],
        ]]));

        // v2 has no Critical band.
        $this->assertSame('HIGH', $this->severity(['severity' => [
            ['type' => 'CVSS_V2', 'score' => 'AV:N/AC:L/Au:N/C:C/I:C/A:C'],
        ]]));

        $this->assertSame('HIGH', $this->severity([
            'database_specific' => ['severity' => 'HIGH'],
            'severity'          => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N']],
        ]));

        $this->assertSame('UNKNOWN', $this->severity(['severity' => [
            ['type' => 'CVSS_V4', 'score' => 'CVSS:4.0/AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N'],
        ]]));
    }

    protected function severity(array $vuln): string
    {
        $service = new AuditService;
        $method  = new ReflectionMethod($service, 'extractSeverity');
        $method->setAccessible(true);

        return $method->invoke($service, $vuln);
    }
}
