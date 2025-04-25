<?php

namespace Lamb;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\Post\parse_matter;

/**
 * Retrieves the tags from the given HTML.
 *
 * @param string $html The HTML content to search for tags.
 *
 * @return array An array of tags found in the HTML.
 */
function get_tags(string $html): array
{
    preg_match_all('/(^|[\s>])#(\w+)/', $html, $matches);

    return $matches[2];
}

/**
 * Parses tags in the given HTML string and converts them into links.
 *
 * This method replaces all occurrences of the "#" symbol followed by an alphanumeric word with
 * a hyperlink to the corresponding tag page. The replacement is done using regular expressions.
 * The resulting HTML string is returned.
 *
 * @param string $html The HTML string to parse tags from.
 *
 * @return string The modified HTML string with tags converted into links.
 */
function parse_tags(string $html): string
{
    return preg_replace_callback('/(^|[\s>])#(\w+)/', function ($matches) {
        return $matches[1] . '<a href="/tag/' . strtolower($matches[2]) . '">#' . $matches[2] . '</a>';
    }, $html);
}

/**
 * Generates a permalink for the given item.
 *
 * This method creates a permalink for the given item based on its slug or ID.
 * If the item has a slug, it appends it to the root URL. Otherwise, it appends
 * the item's ID to the root URL with the "status" path.
 *
 * @param OODBBean $bean The item for which the permalink is generated.
 *                    It should have the 'slug' and 'id' properties.
 *
 * @return string The generated permalink URL.
 */
function permalink(OODBBean $bean): string
{
    if ($bean->slug) {
        return ROOT_URL . "/$bean->slug";
    }

    return ROOT_URL . '/status/' . $bean->id;
}


/**
 * Parses the given bean to extract and transform its content.
 *
 * This method processes the content of an OODBBean object, typically containing
 * a body with markdown text separated by '---'. It processes the markdown, extracts
 * front matter, and updates the bean with the parsed and transformed content.
 *
 * @param OODBBean $bean The item whose body content is parsed and transformed.
 *                       It must have a 'body' property containing the text to be processed.
 *
 * @return void This method does not return any value. It modifies the input bean directly.
 */
function parse_bean(OODBBean $bean): void
{
    $parser = new LambDown();
    $parser->setSafeMode(true);

    $front_matter = parse_matter($bean->body);

    $parts = explode('---', $bean->body);
    $md_text = trim($parts[count($parts) - 1]);

    if (!isset($front_matter['title'])) {
        $md_text = "### Status " . PHP_EOL . $md_text;
    }

    $markdown = $parser->text($md_text);

    # Use the first linebreak as the description.
    $front_matter['description'] = strtok(strip_tags($markdown), "\n");
    $front_matter['transformed'] = parse_tags($markdown);

    foreach ($front_matter as $key => $value) {
        /*
        Posts should not change their slug one they have one. Only from ID to title is allowed.
        Changing slugs breaks URLs as long as there are no redirects.
        Also it breaks the after edit redirect.
        TODO: remove check after redirects.
        */
        if ($key !== 'slug' || empty($bean->slug)) {
            $bean->$key = $value;
        }
    }
}

/**
 * Checks if a post with the given slug exists in the database.
 *
 * @param string $lookup The slug of the post to look up.
 *
 * @return string|null The slug of the post if it exists, otherwise null.
 */
function post_has_slug(string $lookup): string|null
{
    $post = R::findOne('post', ' slug = ? ', [$lookup]);
    if (is_null($post) || $post->id === 0) {
        return '';
    }

    return $post->slug;
}
