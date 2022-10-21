<?php

declare(strict_types=1);

namespace ProductTrap\WoolworthsAustralia;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use LDAP\Result;
use ProductTrap\Contracts\BrowserDriver;
use ProductTrap\Contracts\Driver;
use ProductTrap\Contracts\RequiresBrowser;
use ProductTrap\Contracts\RequiresCrawler;
use ProductTrap\Contracts\SupportsPagination;
use ProductTrap\Contracts\SupportsSearches;
use ProductTrap\DTOs\Brand;
use ProductTrap\DTOs\ScrapeResult;
use ProductTrap\DTOs\Price;
use ProductTrap\DTOs\Product;
use ProductTrap\DTOs\Query;
use ProductTrap\DTOs\Results;
use ProductTrap\DTOs\UnitAmount;
use ProductTrap\DTOs\UnitPrice;
use ProductTrap\Enums\Currency;
use ProductTrap\Enums\Status;
use ProductTrap\Exceptions\ApiConnectionFailedException;
use ProductTrap\Exceptions\ProductTrapDriverException;
use ProductTrap\Traits\DriverCache;
use ProductTrap\Traits\DriverCrawler;

class WoolworthsAustralia implements Driver, RequiresBrowser, RequiresCrawler, SupportsSearches, SupportsPagination
{
    use DriverCache;
    use DriverCrawler;

    public const IDENTIFIER = 'woolworths_australia';

    public const BASE_URI = 'https://woolworths.com.au';

    protected int $page = 1;

    protected int $lastPage = 1;

    public function __construct(protected CacheRepository $cache, protected BrowserDriver $browser)
    {
    }

    public function getName(): string
    {
        return 'Woolworths Australia';
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws ProductTrapDriverException
     */
    public function find(string $identifier, array $parameters = []): Product
    {
        $url = $this->url($identifier);

        /** @var ScrapeResult $result */
        $result = $this->remember($identifier, now()->addDay(), fn () => $this->browser->crawl($url));

        if (empty($result->result)) {
            throw new ApiConnectionFailedException($this, $url);
        }

        // Get the crawler
        $crawler = $this->crawl($html = $result->result);

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

        return new Product(
            identifier: $identifier,
            sku: $identifier,
            name: $title,
            description: $description,
            url: $url,
            price: $price,
            status: $status,
            brand: $brand,
            gtin: $gtin,
            unitAmount: $unitAmount,
            unitPrice: $unitPrice,
            ingredients: $ingredients,
            images: $images,
            raw: [
                'html' => $html,
            ],
        );
    }

    public function url(string $identifier): string
    {
        return self::BASE_URI.'/shop/productdetails/'.$identifier;
    }

    public function searchUrl(string $keywords, array $parameters = []): string
    {
        return static::BASE_URI . '/shop/search/products?searchTerm=' . urlencode($keywords) . '&pageNumber=' . $this->page;
    }

    public function search(Query $query, array $parameters = []): Results
    {
        $url = $this->searchUrl((string) $query, $parameters);

        /** @var ScrapeResult $result */
        $result = $this->remember($query->cacheKey(), now()->addDay(), fn () => $this->browser->crawl($url));

        // file_put_contents(__DIR__ . '/searchpage_' . $this->page . '.html', $result->result);

        if (empty($result->result)) {
            throw new ApiConnectionFailedException($this, $url);
        }

        // Get the crawler
        $crawler = $this->crawl($html = $result->result);

        $products = $crawler->filter('.shelfProductTile.tile')->each(fn ($node) => $node);

        $products = array_map(
            function ($node) {
                try {
                    $title = $node->filter('.shelfProductTile-descriptionLink')->first()->text();
                    $url = static::BASE_URI . $node->filter('.shelfProductTile-descriptionLink')->first()->attr('href');
                    $identifier = Str::of($url)->after('/shop/productdetails/')->before('/')->toInteger();

                    $priceDollars = Str::of($node->filter('.price .price-dollars')->first()->text())->trim()->toInteger();
                    $priceCents = Str::of($node->filter('.price .price-cents')->first()->text())->trim()->toInteger();
                    $price = (float) ($priceDollars . '.' . $priceCents);
                    $wasPrice = null;
                    try {
                        $wasPrice = Str::of($node->filter('.price-was')->first()->text())->trim()->match('/\$(\d+\.\d+)/')->toFloat();
                    } catch (\Exception $e) {
                    }

                    $price = new Price(
                        amount: $price,
                        wasAmount: $wasPrice,
                    );

                    $unitPrice = null;
                    try {
                        $unitPrice = Str::of($node->filter('.shelfProductTile-cupPrice')->first()->text())->trim();
                        $unitPrice = UnitPrice::determine(unitPrice: (string) $unitPrice);
                    } catch (\Exception $e) {
                    }

                    $images = [];
                    $images[] = $node->filter('.shelfProductTile-image')->first()->attr('src');

                    $status = Status::Available;
                } catch (\Exception $e) {
                    // Probably not a product; maay be a recipe, advert or other non-product
                    return;
                }

                return new Product(
                    name: $title,
                    url: $url,
                    sku: $identifier,
                    identifier: $identifier,
                    price: $price,
                    unitPrice: $unitPrice,
                    images: $images,
                    status: $status,
                    raw: [
                        'html' => $node->html(),
                    ]
                );
            },
            $products,
        );

        $products = array_filter($products);

        $page = 1;
        $lastPage = 1;
        try {
            $page = Str::of($crawler->filter('.page-indicator .current-page')->first()->text())->trim()->toInteger();
            $lastPage = Str::of($crawler->filter('.page-indicator .page-count')->first()->text())->trim()->toInteger();
        } catch (\Exception $e) {
        }

        // set page should have already covered this
        $this->setPage($page)->lastPage = $lastPage;

        return new Results(
            query: $query,
            products: $products,
            raw: [
                'html' => $html,
            ],
        );
    }

    public function setPage(int $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }
}
