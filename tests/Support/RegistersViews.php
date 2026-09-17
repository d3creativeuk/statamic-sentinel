<?php

namespace D3Creative\Sentinel\Tests\Support;

use Illuminate\Support\Facades\Route;

/**
 * Make the addon's views renderable without booting Statamic: register the
 * view namespace, a stub statamic::layout, and placeholder routes for every
 * name the views link to.
 */
trait RegistersViews
{
    protected function registerViews(): void
    {
        $this->app['view']->addNamespace('statamic-sentinel', __DIR__ . '/../../resources/views');
        $this->app['view']->addNamespace('statamic', __DIR__ . '/../Fixtures/views');

        preg_match_all("/->name\\('(d3-sentinel\\.[a-z0-9.\\-]+)'\\)/", file_get_contents(__DIR__ . '/../../src/ServiceProvider.php'), $names);

        foreach (array_merge($names[1], ['utilities.sentinel']) as $i => $name) {
            Route::any("/_view-test/{$i}/{id?}", fn () => '')->name('statamic.cp.' . $name);
            Route::any("/_view-test-bare/{$i}/{id?}", fn () => '')->name($name);
        }

        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
