<?php

declare(strict_types=1);

use ProductTrap\Contracts\Factory;
use ProductTrap\DTOs\Product;
use ProductTrap\Enums\Currency;
use ProductTrap\Enums\Status;
use ProductTrap\Exceptions\ApiConnectionFailedException;
use ProductTrap\Facades\ProductTrap as FacadesProductTrap;
use ProductTrap\ProductTrap;
use ProductTrap\Spider;
use ProductTrap\WoolworthsAustralia\WoolworthsAustralia;

function getMockWoolworthsAustralia($app, string $response): void
{
    Spider::fake([
        '*' => $response,
    ]);
}

it('can add the WoolworthsAustralia driver to ProductTrap', function () {
    /** @var ProductTrap $client */
    $client = $this->app->make(Factory::class);

    $client->extend('woolworths_other', fn () => new WoolworthsAustralia(
        cache: $this->app->make('cache.store'),
    ));

    expect($client)->driver(WoolworthsAustralia::IDENTIFIER)->toBeInstanceOf(WoolworthsAustralia::class)
        ->and($client)->driver('woolworths_other')->toBeInstanceOf(WoolworthsAustralia::class);
});

it('can call the ProductTrap facade', function () {
    expect(FacadesProductTrap::driver(WoolworthsAustralia::IDENTIFIER)->getName())->toBe(WoolworthsAustralia::IDENTIFIER);
});

it('can retrieve the WoolworthsAustralia driver from ProductTrap', function () {
    expect($this->app->make(Factory::class)->driver(WoolworthsAustralia::IDENTIFIER))->toBeInstanceOf(WoolworthsAustralia::class);
});

it('can call `find` on the WoolworthsAustralia driver and handle failed connection', function () {
    getMockWoolworthsAustralia($this->app, '');

    $this->app->make(Factory::class)->driver(WoolworthsAustralia::IDENTIFIER)->find('7XX1000');
})->throws(ApiConnectionFailedException::class, 'The connection to https://woolworths.com.au/shop/productdetails/7XX1000 has failed for the WoolworthsAustralia driver');

it('can call `find` on the WoolworthsAustralia driver and handle a successful response', function () {
    $html = file_get_contents(__DIR__.'/../fixtures/successful_response.html');
    getMockWoolworthsAustralia($this->app, $html);

    $data = $this->app->make(Factory::class)->driver(WoolworthsAustralia::IDENTIFIER)->find('257360');
    unset($data->raw);

    expect($this->app->make(Factory::class)->driver(WoolworthsAustralia::IDENTIFIER)->find('257360'))
        ->toBeInstanceOf(Product::class)
        ->identifier->toBe('257360')
        ->status->toEqual(Status::Available)
        ->name->toBe('John West Tuna Olive Oil Blend 95G')
        ->description->toBe('Succulent chunk style tuna in an olive oil blend.')
        ->ingredients->toBe('Purse seine caught skipjack *tuna* (Katsuwonus pelamis) (65%), water, olive oil (10%), sunflower oil, salt. Contains fish.')
        ->price->amount->toBe(2.7)
        ->unitAmount->unit->value->toBe('g')
        ->unitAmount->amount->toBe(95.0)
        ->unitPrice->unitAmount->unit->value->toBe('kg')
        ->unitPrice->unitAmount->amount->toBe(1.0)
        ->price->currency->toBe(Currency::AUD)
        ->unitPrice->price->amount->toBe(28.42)
        ->brand->name->toBe('John West')
        ->images->toBe([
            'https://cdn0.woolworths.media/content/wowproductimages/large/257360.jpg',
            'https://cdn0.woolworths.media/content/wowproductimages/large/257360_1.jpg',
            'https://cdn0.woolworths.media/content/wowproductimages/large/257360_2.jpg',
            'https://cdn0.woolworths.media/content/wowproductimages/large/257360_5.jpg',
            'https://cdn0.woolworths.media/content/wowproductimages/large/257360_6.jpg',
        ]);
});
