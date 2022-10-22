<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use ProductTrap\Browser\Browser;
use ProductTrap\Contracts\BrowserDriver;
use ProductTrap\Contracts\BrowserFactory;
use ProductTrap\Contracts\Factory;
use ProductTrap\Drivers\NullBrowserDriver;
use ProductTrap\DTOs\Product;
use ProductTrap\DTOs\Query;
use ProductTrap\DTOs\Results;
use ProductTrap\Enums\Currency;
use ProductTrap\Enums\Status;
use ProductTrap\Exceptions\ApiConnectionFailedException;
use ProductTrap\Facades\ProductTrap as FacadesProductTrap;
use ProductTrap\WoolworthsAustralia\WoolworthsAustralia;
use ProductTrap\ProductTrap;

function getMockWoolworthsAustralia(Container $app, string $response = null): WoolworthsAustralia
{
    // Replace with faker
    $browser = Browser::fake(($response !== null) ? [
        '*' => $response,
    ] : []);

    /** @var ProductTrap $client */
    $client = $app->make(Factory::class);
    $client->forgetDrivers();

    $client->extend(WoolworthsAustralia::IDENTIFIER, fn () => new WoolworthsAustralia(
        cache: $app->make('cache.store'),
        browser: $browser,
    ));

    /** @var WoolworthsAustralia $woolworths */
    $woolworths = $app->make(Factory::class)->driver(WoolworthsAustralia::IDENTIFIER);

    return $woolworths;
}

function useNullAsDefaultBrowser(Container $app) {
    /** @var Repository $config */
    $config = $app->make(Repository::class);
    $config->set('producttrap.browsers.default', 'null');
}

it('can add the WoolworthsAustralia driver to ProductTrap', function () {
    useNullAsDefaultBrowser($this->app);

    /** @var ProductTrap $client */
    $client = $this->app->make(Factory::class);

    $client->extend('woolworths_other', fn () => new WoolworthsAustralia(
        cache: $this->app->make('cache.store'),
        browser: $this->app->make(BrowserFactory::class)->driver(),
    ));

    expect($client)->driver(WoolworthsAustralia::IDENTIFIER)->toBeInstanceOf(WoolworthsAustralia::class)
        ->and($client)->driver('woolworths_other')->toBeInstanceOf(WoolworthsAustralia::class);
});

it('can call the ProductTrap facade', function () {
    useNullAsDefaultBrowser($this->app);

    expect(FacadesProductTrap::driver(WoolworthsAustralia::IDENTIFIER)->getName())->toBe('Woolworths Australia');
});

it('can retrieve the WoolworthsAustralia driver from ProductTrap', function () {
    useNullAsDefaultBrowser($this->app);

    expect($this->app->make(Factory::class)->driver(WoolworthsAustralia::IDENTIFIER))->toBeInstanceOf(WoolworthsAustralia::class);
});

it('can call `find` on the WoolworthsAustralia driver and handle failed connection', function () {
    $woolworths = getMockWoolworthsAustralia($this->app, '');
    $woolworths->find('7XX1000');
})->throws(ApiConnectionFailedException::class, 'The connection to https://www.woolworths.com.au/shop/productdetails/7XX1000 has failed for the Woolworths Australia driver');

it('can call `find` on the WoolworthsAustralia driver and handle a successful response', function () {
    $html = file_get_contents(__DIR__.'/../fixtures/successful_response.html');
    $woolworths = getMockWoolworthsAustralia($this->app, $html);

    $product = $woolworths->find('257360');

    expect($product)
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

it('can perform a search query for keywords and traverse all pages', function () {
    $paths = [
        1 => __DIR__.'/../fixtures/successful_response_search_1.html',
        2 => __DIR__.'/../fixtures/successful_response_search_2.html',
        3 => __DIR__.'/../fixtures/successful_response_search_3.html',
        4 => __DIR__.'/../fixtures/successful_response_search_4.html',
        5 => __DIR__.'/../fixtures/successful_response_search_5.html',
        6 => __DIR__.'/../fixtures/successful_response_search_6.html',
    ];

    $responses = [
        1 => file_get_contents($paths[1]),
        2 => file_get_contents($paths[2]),
        3 => file_get_contents($paths[3]),
        4 => file_get_contents($paths[4]),
        5 => file_get_contents($paths[5]),
        6 => file_get_contents($paths[6]),
    ];

    $query = Query::fromKeywords('tuna');
    $results = [];

    $page = 1;
    $woolworths = getMockWoolworthsAustralia($this->app, $responses[$page]);
    $results[1] = $woolworths->setPage($page)->search($query);
    expect($woolworths)->toBeInstanceOf(WoolworthsAustralia::class)
        ->page()->toBe($page)
        ->lastPage()->toBe(6)
        ->and($results[1]->products)->toHaveCount(32);

    $actual = $results[1]->collectProducts()->pluck('name', 'identifier')->toArray();
    $expected = [
        '103716' => "Devour Parmesan Crusted Tuna Melt Pasta Frozen 400g",
        '236124' => "John West Protein+ Omega-3 Tuna Tomato Salsa With Crackers 94g",
        '236188' => "John West Protein+ Vitamin B12 Tuna Capsicum & Basil With Crackers 94g",
        '234660' => "John West Protein+ Calcium Tuna Sea Salt Lemon & Parsley With Crackers 94g",
        '340411' => "John West Tuna Tempter Chunky Springwater 95g",
        '178886' => "Essentials Tuna Chunks In Spring Water 425g",
        '38609' => "Sirena Tuna In Oil Italian Style 95g",
        '476701' => "Woolworths Tuna In Oil 95g",
        '476700' => "Woolworths Tuna In Springwater 95g",
        '27570' => "John West Tempters Tuna Lemon & Cracked Pepper 95g",
        '263411' => "John West Tuna In Olive Oil 425g",
        '157954' => "John West Calcium Rich Tuna Olive Oil Blend 90g",
        '98518' => "John West Tuna Slices In Olive Oil 125g",
        '238638' => "Applaws Cat Food Mixed Selection 12 Pack 840g",
        '57053' => "John West Tempters Tuna Chilli 95g",
        '7648' => "Greenseas Tuna Tomato & Onion 95g",
        '19736' => "John West Tuna Light In Springwater 425g",
        '257360' => "John West Tuna Olive Oil Blend 95g",
        '708381' => "Safcol Responsibly Fished Tuna In Spring Water 425g",
        '827218' => 'Woolworths Tuna Sweet Chilli 95g',
        '827213' => "Woolworths Tuna Mayonnaise & Corn 95g",
        '91981' => "Sirena Tuna In Oil Italian Style 425g",
        '37250' => "Greenseas Tuna Chunks In Springwater 95g",
        '21568' => "Greenseas Tuna In Extra Virgin Olive Oil Blend 95g",
        '178887' => "Essentials Tuna Chunks In Spring Water 185g",
        '57070' => "John West Tempters Tuna Onion & Tomato Savoury Sauce 95g",
        '144821' => "Sirena Tuna In Springwater 95g",
        '827217' => "Woolworths Tuna Tomato And Onion 95g",
        '827211' => "Woolworths Lemon And Pepper Tuna 95g",
        '85488' => "Sirena Tuna In Chilli Oil 95g",
        '85719' => "Greenseas Tuna Sweet Chilli 95g",
        '7733' => "Greenseas Tuna Lemon Pepper 95g",
    ];

    expect($actual)->toBe($expected);

    $page = 2;
    $woolworths = getMockWoolworthsAustralia($this->app, $responses[$page]);
    $results[2] = $woolworths->setPage($page)->search($query);
    expect($woolworths)->toBeInstanceOf(WoolworthsAustralia::class)
        ->page()->toBe($page)
        ->lastPage()->toBe(6)
        ->and($results[2]->products)->toHaveCount(24);

    $actual = $results[2]->collectProducts()->pluck('name', 'identifier')->toArray();
    $expected = [
        '476702' => "Woolworths Tuna Chilli 95g",
        '476704' => "Woolworths Tuna Tomato & Basil 95g",
        '38610' => "Sirena Tuna In Oil Italian Style 185g",
        '27574' => "John West Tempters Tuna Oven Dried Tomato Basil 95g",
        '30438' => "Essentials Tuna In Oil 425g",
        '310694' => "John West Tuna Sweet Corn & Mayonnaise 95g",
        '94681' => "Essentials Tuna In Oil 185g",
        '263411' => "John West Tuna In Olive Oil 425g",
        '476705' => "Woolworths Tuna Mexican Style 95g",
        '211299' => "John West Tempters Tuna Smoked 95g",
        '21569' => "Greenseas Sandwich Tuna Flakes 95g",
        '92589' => "John West Tuna Tempters Zesty Vinaigrette 95g",
        '7707' => "Greenseas Tuna Smoke Flavour 95g",
        '75363' => "Sirena Tuna In Springwater 185g",
        '663986' => "John West Tuna Tempters Sweet Chilli 95g",
        '92591' => "Essentials Tuna In Brine 425g",
        '64837' => "Woolworths Tuna Laksa 95g",
        '252264' => "John West Tuna Tempters Mango Chilli 95g",
        '135984' => "John West Tempters Tuna Mild Indian Curry 95g",
        '941588' => "Woolworths Yellowfin Tuna In Springwater 185g",
        '29372' => "Greenseas Tuna Chunks In Springwater 425g",
        '429224' => "John West Fiery Jalapeno Tuna 95g",
        '54883' => "Sirena Tuna In Oil Chilli 185g",
        '941590' => "Woolworths Yellowfin Tuna In Oil 185g",
    ];

    expect($actual)->toBe($expected);

    $page = 3;
    $woolworths = getMockWoolworthsAustralia($this->app, $responses[$page]);
    $results[3] = $woolworths->setPage($page)->search($query);
    expect($woolworths)->toBeInstanceOf(WoolworthsAustralia::class)
        ->page()->toBe($page)
        ->lastPage()->toBe(6)
        ->and($results[3]->products)->toHaveCount(24);

    $actual = $results[3]->collectProducts()->pluck('name', 'identifier')->toArray();
    $expected = [
        '541542' => "John West Street Asian Tuna Malaysian Curry 95g",
        '708309' => "Safcol Responsibly Fished Tuna In Oil Italian Style 425g",
        '157954' => "John West Calcium Rich Tuna Olive Oil Blend 90g",
        '801522' => "Sirena Brown Rice & Quinoa With Tuna 170g",
        '211318' => "Sirena Tuna In Oil With Lemon Pepper 95g",
        '941584' => "Woolworths Yellowfin Tuna In Oil 95g",
        '340431' => "Sirena Tuna Basil Infused Oil 95g",
        '157659' => "John West Calcium Rich Tuna Springwater 90g",
        '802544' => "Sirena Italian Style Salad With Tuna 170g",
        '263409' => "Sirena Tuna La Vita Lite In Oil 95g",
        '319476' => "John West Tuna With Roast Capsicum & 3 Beans 185g",
        '98518' => "John West Tuna Slices In Olive Oil 125g",
        '167239' => "John West Protein+ Vitamin B12 Tuna Bowl Couscous Rice Tomato & Onion 170g",
        '941586' => "Woolworths Yellowfin Tuna In Chilli & Oil 95g",
        '218570' => "Sirena Tuna In Puttanesca 95g",
        '167165' => "John West Protein+ Iron Tuna Bowl With Roasted Capsicum & Beans 170g",
        '672828' => "Woolworths Tuna Mayo & Corn With Crackers 112g",
        '157597' => "John West Calcium Rich Tuna Chilli 90g",
        '941593' => "Woolworths Yellowfin Tuna In Oil 425g",
        '64169' => "Sirena Tuna In Garlic 95g",
        '801493' => "Sirena Sicilian Style Pasta With Tuna 170g",
        '30023' => "John West Tuna Light In Brine 425g",
        '170152' => "Sirena Tuna Soy & Ginger 95g",
        '676655' => "Woolworths Yellowfin Tuna Slices In Olive Oil 125g",
    ];
    expect($actual)->toBe($expected);

    $page = 4;
    $woolworths = getMockWoolworthsAustralia($this->app, $responses[$page]);
    $results[4] = $woolworths->setPage($page)->search($query);
    expect($woolworths)->toBeInstanceOf(WoolworthsAustralia::class)
        ->page()->toBe($page)
        ->lastPage()->toBe(6)
        ->and($results[4]->products)->toHaveCount(24);

    $actual = $results[4]->collectProducts()->pluck('name', 'identifier')->toArray();
    $expected = [
        '26137' => "Sirena Tuna Triple Chilli 95g",
        '765751' => "Sirena Tuna Springwater In Springwater 425g",
        '257375' => "Sirena Tuna Slices In Oil Italian Style 125g",
        '319493' => "Sirena Tuna La Vita Lite Chilli 95g",
        '69345' => "John West Tuna Slices In Springwater 125g",
        '64139' => "Sirena Tuna Tomato & Basil 95g",
        '672826' => "Woolworths Tuna Sweet Chilli With Crackers 112g",
        '941583' => "Woolworths Yellowfin Tuna In Springwater 95g",
        '87017' => "Woolworths Tuna & Crackers Snack Pack Thousand Island 112g",
        '24053' => "John West Lunch Kit With Crackers Tuna With Sweetcorn In Mayonnaise 108g",
        '98520' => "John West Tuna Slices Smoked In Olive Oil 125g",
        '257374' => "Sirena Tuna Slices Chilli & Oil 125g",
        '194216' => "Woolworths Tuna Tikka Masala 95g",
        '329004' => "John West Tuna Beans Capsicum Corn & Chilli 185g",
        '302155' => "Sirena Tuna With Beans 185g",
        '167376' => "John West Protein Plus Magnesium Tuna With Lime & Chilli 170g",
        '820408' => "Woolworths Asian Style Tuna Quinoa Salad 185g",
        '820407' => "Woolworths Mexican Style Tuna Salad 185g",
        '87014' => "Woolworths Tuna & Crackers Snack Pack Tomato & Spinach 112g",
        '167166' => "Jw Protein Plus Tuna Chickpeas Lemongrass & Lime 170g",
        '676656' => "Woolworths Yellowfin Tuna Slices In Olive Oil With Chilli 125g",
        '816079' => "Woolworths Yellowfin Tuna With Mild Gherkin 95g",
        '63967' => "Safcol Tuna Pouch Red Beans & Quinoa 160g",
        '157450' => "John West Calcium Rich Tuna Lemon & Cracked Pepper 90g",
    ];
    expect($actual)->toBe($expected);

    $page = 5;
    $woolworths = getMockWoolworthsAustralia($this->app, $responses[$page]);
    $results[5] = $woolworths->setPage($page)->search($query);
    expect($woolworths)->toBeInstanceOf(WoolworthsAustralia::class)
        ->page()->toBe($page)
        ->lastPage()->toBe(6)
        ->and($results[5]->products)->toHaveCount(24);

    $actual = $results[5]->collectProducts()->pluck('name', 'identifier')->toArray();
    $expected = [
        '157450' => "John West Calcium Rich Tuna Lemon & Cracked Pepper 90g",
        '319475' => "John West Tuna & 3 Beans 185g",
        '157955' => "John West Calcium Rich Tuna Tomato & Onion 90g",
        '66605' => "Woolworths Yellowfin Tuna & Rice Yellow Curry Fried Rice 190g",
        '234660' => "John West Protein+ Calcium Tuna Sea Salt Lemon & Parsley With Crackers 94g",
        '66591' => "Woolworths Tuna Pasta Sicilian 170g",
        '236124' => "John West Protein+ Omega-3 Tuna Tomato Salsa With Crackers 94g",
        '236188' => "John West Protein+ Vitamin B12 Tuna Capsicum & Basil With Crackers 94g",
        '659877' => "Woolworths Tuna And Rice Mexican Style 190g",
        '237392' => "Sirena Tuna Garlic & Chilli In Oil 95g",
        '237206' => "Sirena La Vita Tuna In Oil 185g",
        '236624' => "Safcol No Nets Tuna In Springwater 185g",
        '238181' => "Sirena Tuna & Marinated Vegetables Vegetables 185g",
        '194215' => "Woolworths Yellowfin Tuna Olive Oil With Garlic & Herbs 160g",
        '194212' => "Woolworths Yellow Fin Tuna In 160g",
        '194213' => "Woolworths Yellowfin Tuna Olive Oil With Red Chilli 160g",
        '236763' => "Safcol No Nets Tuna In Oil 185g",
        '113305' => "Continental Recipe Base Creamy Tuna Mornay 30g",
        '235599' => "Safcol No Nets Tuna Lemon & Pepper 185g",
        '239636' => "Safcol No Nets Tuna In Chilli Oil 185g",
        '476706' => "Woolworths Tuna Lime & Cracked Pepper 95g",
        '237480' => "Applaws Cat Food Tuna With Salmon 70g",
        '238550' => "Farmers Market Cat Food Tuna Loaf With Quail Egg & Peas 55g",
        '1073846210' => "Whiskas 1+ Years Adult Dry Cat Food Tuna 6.5kg Bag",
    ];
    expect($actual)->toBe($expected);

    $page = 6;
    $woolworths = getMockWoolworthsAustralia($this->app, $responses[$page]);
    $results[6] = $woolworths->setPage($page)->search($query);
    expect($woolworths)->toBeInstanceOf(WoolworthsAustralia::class)
        ->page()->toBe($page)
        ->lastPage()->toBe(6)
        ->and($results[6]->products)->toHaveCount(14);

    $actual = $results[6]->collectProducts()->pluck('name', 'identifier')->toArray();
    $expected = [
        '239515' => "Farmers Market Cat Food Chicken Tuna Vegetables 80g",
        '241512' => "Ultimates Indulge Cat Food Natural Tuna 80g",
        '238423' => "Applaws Kitten Food Tuna Fillet 70g",
        '237391' => "Applaws Cat Food Tuna Fillet With Crab 70g",
        '239287' => "Dine Fresh & Fine Gravy Salmon & Tuna 50g X 6 Pack",
        '1073898968' => "Inaba Churu Tuna Cat Treat Tubes 14g 6x 4PK",
        '241668' => "Ultimates Indulge Cat Food Tuna Ethically Sourced 85g",
        '1073906646' => "Inaba Churu Pops Tuna Cat Treat Tubes 15g 24PK",
        '1073898935' => "Inaba Churu Pops Tuna With Chicken Cat Treat Tubes 15g 6x 4PK",
        '238543' => "Applaws Cat Food Tuna Fillet In Broth 8 Pack 560g",
        '240440' => "Ultimates Indulge Cat Food Tuna Chicken Ethically Sourced 70g",
        '238209' => "Pretty Wild Cat Food Creamy Tuna Puree 15g X 4 Pack",
        '239623' => "Pretty Wild Cat Food Shredded Chicken & Tuna 80g",
        '239178' => "Fussy Cat Chicken With Tuna & Salmon Gravy Cat Food 400g",
    ];
    expect($actual)->toBe($expected);

    /** @var array<int,Results> $results */

    $all = $results[1]->merge($results[2])->merge($results[3])->merge($results[4])->merge($results[5])->merge($results[6]);

    expect($all->collectProducts()->count())->toBe(135);
});
