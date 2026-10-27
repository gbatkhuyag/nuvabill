<?php

namespace App\Marketplace\Store;

/**
 * The longest texts and highest prices a marketplace listing may have, the same in the developer
 * forms, the staff form and nuvabill:marketplace-publish. The text limits are the columns of
 * marketplace_items and marketplace_versions (name 120, slug 64, category 40, summary 200, links
 * 255, version 40), so a text that is too long gets a clear message instead of a database error.
 * Descriptions and changelogs are kept to a length buyers still read.
 */
final class ListingLimits
{
    public const NAME = 120;

    public const SLUG = 64;

    public const CATEGORY = 40;

    public const SUMMARY = 200;

    public const DESCRIPTION = 5000;

    public const CHANGELOG = 2000;

    public const URL = 255;

    public const VERSION = 40;

    /** The highest price and update price, in the store's currency (not minor units). */
    public const MAX_PRICE = 10000;

    public const SCREENSHOTS = 6;

    /** The largest screenshot, in KB. */
    public const SCREENSHOT_KB = 3072;

    /** @var list<string> */
    public const SCREENSHOT_TYPES = ['png', 'jpg', 'jpeg', 'webp'];

    /** The longest screenshot file name a listing file may give, with its folder. */
    public const SCREENSHOT_PATH = 255;

    /** The longest icon glyph: an SVG path on a 24 × 24 grid. */
    public const GLYPH = 1000;

    /** The characters of an SVG path, as Listing reads the icon glyph from the catalog. */
    public const GLYPH_PATTERN = '/^[MmLlHhVvCcSsQqTtAaZz0-9 .,\-]+$/D';

    public const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/D';

    /**
     * What is wrong with a listing file for nuvabill:marketplace-publish: one message per problem,
     * naming the field, its length and the limit. Empty when nothing is wrong. Nothing is saved
     * or copied here.
     *
     * @param  array<mixed>  $listing  The decoded listing file.
     * @param  array{slug: string, name: string, version: string}  $package  What the package's manifest says.
     * @param  string  $base  The listing file's folder: screenshots must be in it.
     * @return list<string>
     */
    public static function problems(array $listing, array $package, string $base): array
    {
        $problems = [];
        $text = function (string $field, mixed $value, int $limit, bool $required = false) use (&$problems): void {
            if ($value === null || $value === '') {
                if ($required) {
                    $problems[] = "{$field} is empty.";
                }

                return;
            }

            if (! is_string($value)) {
                $problems[] = "{$field} must be text.";
            } elseif (($length = mb_strlen($value)) > $limit) {
                $problems[] = "{$field} is {$length} characters long; the limit is {$limit}.";
            }
        };

        $text('slug (from the package)', $package['slug'], self::SLUG, required: true);
        $text('version (from the package)', $package['version'], self::VERSION, required: true);
        $text(array_key_exists('name', $listing) ? 'name' : 'name (from the package)', $listing['name'] ?? $package['name'], self::NAME, required: true);
        $text('category', $listing['category'] ?? null, self::CATEGORY);
        $text('summary', $listing['summary'] ?? null, self::SUMMARY, required: true);
        $text('description', $listing['description'] ?? null, self::DESCRIPTION);
        $text('changelog', $listing['changelog'] ?? null, self::CHANGELOG);

        foreach (['demo_url', 'docs_url'] as $field) {
            $url = $listing[$field] ?? null;
            $text($field, $url, self::URL);

            if (is_string($url) && $url !== '' && mb_strlen($url) <= self::URL && (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false)) {
                $problems[] = "{$field} must be a link that starts with https://.";
            }
        }

        $prices = [];

        foreach (['price', 'update_price'] as $field) {
            $value = $listing[$field] ?? null;

            if ($value === null || $value === '') {
                $prices[$field] = 0.0;
            } elseif ((! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) || ! is_finite((float) $value)) {
                $problems[] = "{$field} must be a number.";
            } elseif ((float) $value < 0 || (float) $value > self::MAX_PRICE) {
                $problems[] = "{$field} is {$value}; it must be from 0 to ".self::MAX_PRICE.'.';
            } else {
                $prices[$field] = (float) $value;
            }
        }

        if (isset($prices['price'], $prices['update_price']) && $prices['update_price'] > $prices['price']) {
            $problems[] = "update_price ({$prices['update_price']}) is more than price ({$prices['price']}).";
        }

        array_push($problems, ...self::iconProblems($listing['icon'] ?? null), ...self::screenshotProblems($listing['screenshots'] ?? null, $base));

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function iconProblems(mixed $icon): array
    {
        if ($icon === null) {
            return [];
        }

        if (! is_array($icon) || array_is_list($icon)) {
            return ['icon must be an object with glyph, bg and fg.'];
        }

        $problems = [];

        foreach (array_diff(array_map('strval', array_keys($icon)), ['glyph', 'bg', 'fg']) as $key) {
            $problems[] = "icon.{$key} is not an icon part (use glyph, bg and fg).";
        }

        $glyph = $icon['glyph'] ?? '';

        if (! is_string($glyph)) {
            $problems[] = 'icon.glyph must be text.';
        } elseif (($length = mb_strlen($glyph)) > self::GLYPH) {
            $problems[] = "icon.glyph is {$length} characters long; the limit is ".self::GLYPH.'.';
        } elseif ($glyph !== '' && preg_match(self::GLYPH_PATTERN, $glyph) !== 1) {
            $problems[] = 'icon.glyph must be an SVG path (letters, numbers, spaces, dots, commas and minus signs).';
        }

        foreach (['bg', 'fg'] as $part) {
            $color = $icon[$part] ?? null;

            if ($color !== null && (! is_string($color) || preg_match(self::COLOR_PATTERN, $color) !== 1)) {
                $problems[] = "icon.{$part} must be a colour such as #0F1B2D.";
            }
        }

        return $problems;
    }

    /**
     * Screenshots must be PNG, JPEG or WebP images of at most SCREENSHOT_KB in the listing's
     * folder, so a listing file can never copy another file of the server into the public media.
     *
     * @return list<string>
     */
    private static function screenshotProblems(mixed $screenshots, string $base): array
    {
        if ($screenshots === null) {
            return [];
        }

        if (! is_array($screenshots) || ! array_is_list($screenshots)) {
            return ['screenshots must be a list of file names.'];
        }

        if (count($screenshots) > self::SCREENSHOTS) {
            return ['screenshots has '.count($screenshots).' files; the limit is '.self::SCREENSHOTS.'.'];
        }

        $problems = [];
        $folder = realpath($base);

        foreach ($screenshots as $index => $shot) {
            $field = 'screenshots['.$index.']';

            if (! is_string($shot) || $shot === '') {
                $problems[] = "{$field} must be a file name.";

                continue;
            }

            if (($length = mb_strlen($shot)) > self::SCREENSHOT_PATH) {
                $problems[] = "{$field} is {$length} characters long; the limit is ".self::SCREENSHOT_PATH.'.';

                continue;
            }

            if (! in_array(strtolower(pathinfo($shot, PATHINFO_EXTENSION)), self::SCREENSHOT_TYPES, true)) {
                $problems[] = "{$field} ({$shot}) must be a PNG, JPEG or WebP file.";

                continue;
            }

            $path = realpath($base.DIRECTORY_SEPARATOR.$shot);

            if ($folder === false || $path === false || ! is_file($path) || ! str_starts_with($path, rtrim($folder, '\\/').DIRECTORY_SEPARATOR)) {
                $problems[] = "{$field} ({$shot}) was not found next to the listing file.";
            } elseif (($size = (int) ceil(filesize($path) / 1024)) > self::SCREENSHOT_KB) {
                $problems[] = "{$field} ({$shot}) is {$size} KB; the limit is ".self::SCREENSHOT_KB.' KB.';
            } elseif (@getimagesize($path) === false) {
                $problems[] = "{$field} ({$shot}) is not an image.";
            }
        }

        return $problems;
    }
}
