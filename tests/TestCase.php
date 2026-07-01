<?php

namespace D3Creative\Sentinel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case. Boots a bare Laravel app via Testbench so the Http / Cache
 * facades and base_path() resolve. Statamic is not booted - the units under
 * test (AuditService) are plain, new-able classes with no CP dependencies.
 */
abstract class TestCase extends Orchestra
{
}
