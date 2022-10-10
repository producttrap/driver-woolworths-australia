<?php

declare(strict_types=1);

namespace ProductTrap\WoolworthsAustralia\Tests;

use ProductTrap\ProductTrapServiceProvider;
use ProductTrap\WoolworthsAustralia\WoolworthsAustraliaServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ProductTrapServiceProvider::class, WoolworthsAustraliaServiceProvider::class];
    }
}
