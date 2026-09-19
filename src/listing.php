<?php

/** @noinspection PhpUnused */

namespace Lamb\Listing;

use RedBeanPHP\OODBBean;

use function Lamb\get_tags;
use function Lamb\permalink;
use function Lamb\Post\matter_string;
use function Lamb\Post\parse_matter;

/**
 * The `post_type` value that marks a post as a for-sale listing.
 */
const POST_TYPE_LISTING = 'listing';

/**
 * Every value `post_type` may hold besides the empty string.
 *
 * An allowlist rather than a free-text column: `post_type` decides which posts
 * the /listings route and its feed select, and a typo'd or client-supplied
 * value silently creating a third kind of post is worse than it being rejected
 * back to an ordinary post.
 */
const POST_TYPES = [POST_TYPE_LISTING];

/**
 * The listing detail keys read out of a listing's front matter.
 *
 * They stay in the body rather than becoming columns: only `post_type` is
 * queried (by the listings route and feed), and everything here is read once
 * when a single listing is rendered, where the body is already in hand.
 */
const LISTING_FIELDS = ['price', 'currency', 'condition', 'contact'];

/**
 * The item conditions a listing may declare, mapped to their schema.org URLs.
 */
const CONDITIONS = [
    'new'          => 'https://schema.org/NewCondition',
    'used'         => 'https://schema.org/UsedCondition',
    'refurbished'  => 'https://schema.org/RefurbishedCondition',
    'damaged'      => 'https://schema.org/DamagedCondition',
];

/**
 * Normalises a front-matter `post-type` value to a recognised post type.
 *
 * @param mixed $value The raw front-matter value.
 * @return string The post type, or '' when the value names none.
 */
function normalize_post_type(mixed $value): string
{
    $type = strtolower(trim(matter_string($value) ?? ''));

    return in_array($type, POST_TYPES, true) ? $type : '';
}

/**
 * Returns true when the post is a for-sale listing.
 *
 * @param OODBBean $post The post bean.
 * @return bool
 */
function is_listing(OODBBean $post): bool
{
    return ($post->post_type ?? '') === POST_TYPE_LISTING;
}

/**
 * Reads a listing's detail fields out of its front matter.
 *
 * Only well-formed values survive: a price that is not a number, a currency
 * that is not a three-letter code and a condition outside CONDITIONS are
 * dropped rather than passed on, because each one ends up in structured data
 * an aggregator parses. A dropped field is simply absent from the result.
 *
 * @param OODBBean $post The post bean.
 * @return array{price?: string, currency?: string, condition?: string, contact?: string}
 */
function listing_fields(OODBBean $post): array
{
    $matter = parse_matter((string) ($post->body ?? ''));

    $fields = [];
    foreach (LISTING_FIELDS as $key) {
        $value = matter_string($matter[$key] ?? null);
        if ($value === null) {
            continue;
        }
        $value = normalize_field($key, trim($value));
        if ($value !== null) {
            $fields[$key] = $value;
        }
    }

    return $fields;
}

/**
 * Validates and canonicalises one listing detail field.
 *
 * @param string $key   The field name (one of LISTING_FIELDS).
 * @param string $value The trimmed front-matter value.
 * @return string|null The canonical value, or null when it is not well-formed.
 */
function normalize_field(string $key, string $value): ?string
{
    if ($value === '') {
        return null;
    }

    if ($key === 'price') {
        // schema.org `price` is a number, so "£25 or best offer" is not one.
        // The separator is normalised to a point: YAML hands back whatever the
        // author typed, and "25,00" reads as twenty-five in JSON-LD.
        if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', $value) !== 1) {
            return null;
        }
        return str_replace(',', '.', $value);
    }

    if ($key === 'currency') {
        if (preg_match('/^[A-Za-z]{3}$/', $value) !== 1) {
            return null;
        }
        return strtoupper($value);
    }

    if ($key === 'condition') {
        $condition = strtolower($value);
        return isset(CONDITIONS[$condition]) ? $condition : null;
    }

    return $value;
}

/**
 * Builds the schema.org Product description of a listing.
 *
 * This is what makes a listing readable as commerce rather than as another
 * blog post: a search engine or a listings aggregator reads the Product (and
 * its Offer) and gets the price, currency and condition without having to
 * guess at the prose. The shape is deliberately small — no availability
 * beyond "on offer while the post exists", no seller reputation, no shipping
 * — matching what Lamb actually knows.
 *
 * @param OODBBean $post   The listing post bean.
 * @param array<string, mixed> $config The site configuration.
 * @return array<string, mixed>|null The JSON-LD structure, or null when the post is not a listing.
 */
function schema_org(OODBBean $post, array $config): ?array
{
    if (!is_listing($post)) {
        return null;
    }

    $fields = listing_fields($post);
    $url    = permalink($post);
    $name   = (string) ($post->title ?? '');
    if ($name === '') {
        $name = (string) ($post->description ?? '');
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'Product',
        'name'     => $name,
        'url'      => $url,
    ];

    if (!empty($post->description)) {
        $schema['description'] = (string) $post->description;
    }

    $image = \Lamb\Theme\first_embedded_image((string) ($post->transformed ?? ''));
    if ($image !== null) {
        $schema['image'] = \Lamb\absolute_url($image);
    }

    // The post's hashtags are the only categorisation a listing has; they are
    // what a seller already writes, so there is no second taxonomy to maintain.
    $categories = get_tags((string) ($post->body ?? ''));
    if ($categories !== []) {
        $schema['category'] = $categories;
    }

    if (isset($fields['condition'])) {
        $schema['itemCondition'] = CONDITIONS[$fields['condition']];
    }

    $offer = offer($fields, $url, $config);
    if ($offer !== null) {
        $schema['offers'] = $offer;
    }

    return $schema;
}

/**
 * Builds the Offer node of a listing's Product, or null when there is no price.
 *
 * An Offer without a price says nothing a consumer can act on, so a listing
 * that names no price is published as a bare Product instead.
 *
 * Availability is always InStock: v1 has no sold/reserved status, so a listing
 * is on offer for exactly as long as its post exists (see docs/listings.md).
 *
 * @param array<string, string> $fields The normalised listing fields.
 * @param string $url The listing's permalink.
 * @param array<string, mixed> $config The site configuration.
 * @return array<string, mixed>|null
 */
function offer(array $fields, string $url, array $config): ?array
{
    if (!isset($fields['price'])) {
        return null;
    }

    $offer = [
        '@type'        => 'Offer',
        'price'        => $fields['price'],
        'url'          => $url,
        'availability' => 'https://schema.org/InStock',
    ];

    if (isset($fields['currency'])) {
        $offer['priceCurrency'] = $fields['currency'];
    }

    $seller = (string) ($config['author_name'] ?? '');
    if ($seller !== '') {
        $offer['seller'] = ['@type' => 'Person', 'name' => $seller];
    }

    return $offer;
}
