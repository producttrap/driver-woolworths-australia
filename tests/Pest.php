<?php

declare(strict_types=1);

use ProductTrap\DTOs\Product;
use ProductTrap\WoolworthsAustralia\Tests\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

uses(TestCase::class)->in('Feature');

function writeSampleProduct(Product $product)
{
    // Dump to string
    $product->raw['html'] = '/* HTML OF PAGE HERE */';
    $varCloner = new VarCloner();
    $dumper = new CliDumper();
    $string = $dumper->dump($varCloner->cloneVar($product), true);

    // Strip HTML from the output
    $string = preg_replace('/<script.*?<\/script>/is', '', $string);
    $string = preg_replace('/<style.*?<\/style>/is', '', $string);
    $string = strip_tags($string);
    $lines = explode("\n", $string);

    foreach ($lines as $row => $line) {
        // Remove source of dump
        if ($row === 0) {
            $line = preg_replace('/ \/\/.+/', '', $lines[0]);
        }

        // Strip class instance IDs
        $lines[$row] = preg_replace('/#\d+$/', '', rtrim($line));

        if (preg_match('/^\s+#./', $line)) {
            unset($lines[$row]);
        }
    }

    // Write to file
    $string = implode("\n", $lines);
    $string = <<<EOL
# Sample Product

```
{$string}
```
EOL;
    file_put_contents(__DIR__.'/../docs/SAMPLE_PRODUCT.md', $string);
}
