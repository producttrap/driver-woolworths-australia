<?php

declare(strict_types=1);

namespace ProductTrap\WoolworthsAustralia;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use ProductTrap\Contracts\Driver;
use ProductTrap\DTOs\Brand;
use ProductTrap\DTOs\Price;
use ProductTrap\DTOs\Product;
use ProductTrap\DTOs\Results;
use ProductTrap\DTOs\UnitAmount;
use ProductTrap\DTOs\UnitPrice;
use ProductTrap\Enums\Currency;
use ProductTrap\Enums\Status;
use ProductTrap\Exceptions\ProductTrapDriverException;
use ProductTrap\Traits\DriverCache;
use ProductTrap\Traits\DriverCrawler;

class WoolworthsAustralia implements Driver
{
    use DriverCache;
    use DriverCrawler;

    public const IDENTIFIER = 'woolworths_australia';

    public const BASE_URI = 'https://woolworths.com.au';

    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    public function getName(): string
    {
        return static::IDENTIFIER;
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws ProductTrapDriverException
     */
    public function find(string $identifier, array $parameters = []): Product
    {
        $html = $this->remember($identifier, now()->addDay(), fn () => $this->scrape($this->url($identifier)));
        $crawler = $this->crawl($html);

        // Extract product JSON as possible source of information
        preg_match_all('/<script type="application\/ld\+json">({"@context":"http:\/\/schema\.org",.+)<\/script>/', $crawler->html(), $matches);
        $jsonld = null;
        foreach ($matches[1] as $json) {
            $json = (array) json_decode($json, true);

            if (isset($json['@type']) && (is_string($json['@type'])) && strtolower($json['@type']) === 'product') {
                $jsonld = $json;
            }
        }
        /** @var array|null $jsonld */

        // Title
        $title = $jsonld['name'] ?? Str::of(
            $crawler->filter('title')->first()->html()
        )->trim()->before(' | ');

        // Description
        try {
            $description = $jsonld['description'] ?? $crawler->filter('.ar-product-detail-container .viewMore-content')->first()->text();
        } catch (\Exception $e) {
            $description = null;
        }

        //SKU
        $sku = $jsonld['sku'] ?? null;

        // Gtin
        $gtin = $jsonld['gtin13'] ?? null;

        // Brand
        $brand = isset($jsonld['brand']['name']) ? new Brand(
            name: $brandName = $jsonld['brand']['name'],
            identifier: $brandName,
        ) : null;

        // Currency
        $currency = Currency::tryFrom($jsonld['offers']['priceCurrency'] ?? null) ?? Currency::AUD;

        // Price
        $price = null;
        $wasPrice = null;
        try {
            $price = $jsonld['offers']['price'] ?? Str::of(
                $crawler->filter('.ar-product-price .price.price--large')->first()->text()
            )->replace(['$', ',', ' '], '')->toFloat();

            $wasPrice = $crawler->filter('.shelfProductTile-price .price-was')->count()
                ? Str::of($crawler->filter('.shelfProductTile-price .price-was')->first()->text())->lower()->trim()->replace(['$', ',', ' ', 'was'], '')->trim()->toFloat()
                : null;
        } catch (\Exception $e) {
        }
        $price = ($price !== null)
            ? new Price(
                amount: $price,
                wasAmount: $wasPrice,
                currency: $currency,
            )
            : null;

        // Images
        $images = [];
        $crawler->filter('.image-gallery img.main-image')->each(function ($node) use (&$images) {
            $images[] = $node->attr('src');
        });
        $crawler->filter('.image-gallery .thumbnails .thumbnail .thumbnail-image')->each(function ($node) use (&$images) {
            $images[] = str_replace('/medium/', '/large/', $node->attr('src'));
        });
        $images = array_values(array_unique($images));

        // Status
        $status = null;
        if (isset($jsonld['offers']['availability'])) {
            $availableMap = [
                'BackOrder' => Status::Unavailable,
                'Discontinued' => Status::Cancelled,
                'InStock' => Status::Available,
                'InStoreOnly' => Status::Available,
                'LimitedAvailability' => Status::Available,
                'OnlineOnly' => Status::Available,
                'OutOfStock' => Status::Unavailable,
                'PreOrder' => Status::Unavailable,
                'PreSale' => Status::Available,
                'SoldOut' => Status::Unavailable,
            ];

            /** @var string $schemaCode */
            $schemaCode = str_replace('http://schemma.org/', '', $jsonld['offers']['availability']);
            $status = $availableMap[$schemaCode] ?? null;
        }
        $status ??= ($crawler->filter('.ar-add-to-cart .hide a.cartControls-addButton')->count() === 0) ? Status::Available : Status::Unavailable;

        // Ingredients
        $ingredients = $crawler->filter('.ingredients .viewMore .viewMore-content')->count()
            ? Str::of(
                $crawler->filter('.ingredients .viewMore .viewMore-content')->first()->text()
            )->trim()->toString()
            : null;

        // Unit Amount (e.g. 85g or 1kg)
        $unitAmount = UnitAmount::parse($title);

        // Unit Price (e.g. $2 per kg)
        $unitPrice = $crawler->filter('.shelfProductTile-cupPrice')->count()
            ? Str::of(
                $crawler->filter('.shelfProductTile-cupPrice')->first()->text()
            )->trim()->toString()
            : null;
        $unitPrice = UnitPrice::determine(
            price: $price,
            unitAmount: $unitAmount,
            unitPrice: $unitPrice,
            currency: $currency,
        );

        // URL
        $prefix = '/shop/productdetails/'.$identifier.'/';
        $slugs = array_filter(
            array_map(
                fn (string $url) => substr($url, strlen($prefix)),
                $crawler->filter('[href^="'.$prefix.'"]')->extract(['href']),
            ),
            fn (string $segment) => strlen($segment) > 1,
        );
        $slug = $slugs[0] ?? null;
        $url = 'https://www.woolworths.com.au/shop/productdetails/'.$identifier.'/'.($slug ?? '');

        $product = new Product([
            'identifier' => $identifier,
            'sku' => $identifier,
            'name' => $title,
            'description' => $description,
            'url' => $url,
            'price' => $price,
            'status' => $status,
            'brand' => $brand,
            'gtin' => $gtin,
            'unitAmount' => $unitAmount,
            'unitPrice' => $unitPrice,
            'ingredients' => $ingredients,
            'images' => $images,
            'raw' => [
                'html' => $html,
            ],
        ]);

        return $product;
    }

    public function url(string $identifier): string
    {
        return self::BASE_URI.'/shop/productdetails/'.$identifier;
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws ProductTrapDriverException
     */
    public function search(string $keywords, array $parameters = []): Results
    {
        return new Results();
    }
}
