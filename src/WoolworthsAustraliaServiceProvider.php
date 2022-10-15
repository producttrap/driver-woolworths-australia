<?php

declare(strict_types=1);

namespace ProductTrap\WoolworthsAustralia;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use ProductTrap\Contracts\BrowserDriver;
use ProductTrap\Contracts\BrowserFactory;
use ProductTrap\Contracts\Factory;
use ProductTrap\ProductTrap;

class WoolworthsAustraliaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var ProductTrap $factory */
        $factory = $this->app->make(Factory::class);

        $factory->extend(WoolworthsAustralia::IDENTIFIER, function () {
            /** @var CacheRepository $cache */
            $cache = $this->app->make(CacheRepository::class);

            /** @var BrowserDriver $browser */
            $browser = $this->app->make(BrowserFactory::class)->driver();

            return new WoolworthsAustralia(
                cache: $cache,
                browser: $browser,
            );
        });
    }
}
