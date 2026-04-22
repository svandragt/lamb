<?php

namespace Lamb;

use RedBeanPHP\OODBBean;

// SQL fragments for common post visibility filters.
const SQL_NOT_DRAFT  = ' (draft IS NULL OR draft != 1) ';
const SQL_NOT_DELETED = ' (deleted IS NULL OR deleted != 1) ';
const SQL_PUBLISHED  = ' (draft IS NULL OR draft != 1) AND (deleted IS NULL OR deleted != 1) ';
const SQL_IS_DRAFT   = ' draft = 1 AND (deleted IS NULL OR deleted != 1) ';
const SQL_IS_DELETED = ' deleted = 1 ';
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

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
    preg_match_all('/(^|[\s>])#([^\s#&.,!?;:()\[\]{}<]+)/u', $html, $matches);

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
    return preg_replace_callback('/(^|[\s>])#([^\s#&.,!?;:()\[\]{}<]+)/u', function ($matches) {
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
    $parts = explode('---', $bean->body);
    $md_text = trim($parts[count($parts) - 1]);
    $parser = new LambDown();
    $parser->setSafeMode(true);
    $markdown = $parser->text($md_text);

    $front_matter = parse_matter($bean->body);
    $front_matter['description'] = strtok(strip_tags($markdown), "\n");

    $front_matter['transformed'] = (parse_tags($markdown));

    foreach ($front_matter as $key => $value) {
        $bean->$key = $value;
    }

    // Explicitly normalise draft: 1 if truthy in frontmatter, 0 otherwise.
    // This ensures removing "draft: true" from frontmatter publishes the post on next save.
    $bean->draft = !empty($front_matter['draft']) ? 1 : 0;
}

/**
 * Retrieves a named option bean from the database, dispensing a new one with the
 * given default value when the key does not yet exist.
 *
 * @param string $name          The option name (key).
 * @param mixed  $default_value Value to use when the option does not exist.
 * @return OODBBean             Existing or freshly dispensed (unsaved) bean.
 */
function get_option(string $name, mixed $default_value): OODBBean
{
    $bean = R::findOneOrDispense('option', ' name = ? ', [$name]);
    $bean->name = $name;
    if ($bean->id === 0) {
        $bean->value = $default_value;
    }

    return $bean;
}

/**
 * Persists the given value into an option bean.
 *
 * @param OODBBean $bean  The option bean to update.
 * @param mixed    $value New value to store.
 * @return void
 */
function set_option(OODBBean $bean, mixed $value): void
{
    $bean->value = $value;
    try {
        R::store($bean);
    } catch (SQL $e) {
        user_error($e->getMessage(), E_USER_ERROR);
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
    if ($post === null || $post->id === 0 || $post->draft == 1) {
        return '';
    }

    return $post->slug;
}

/**
 * Deletes any redirect stored in the DB for the given slug.
 *
 * @param string $slug The from_slug to remove.
 * @return void
 */
function delete_redirect_for_slug(string $slug): void
{
    $existing = R::findOne('redirect', ' from_slug = ? ', [$slug]);
    if ($existing !== null) {
        R::trash($existing);
    }
}

/**
 * Returns the redirect destination for a given slug, or null if none exists.
 *
 * Checks manual redirections from config first, then automatic redirects stored in the DB.
 *
 * @param string $slug The URL path segment to look up.
 * @return string|null The destination URL, or null if no redirect is configured.
 */
function find_redirect(string $slug): ?string
{
    global $config;

    $redirections = $config['redirections'] ?? [];
    if (isset($redirections[$slug])) {
        $dest = $redirections[$slug];
        if (str_starts_with($dest, 'http') || str_starts_with($dest, '/')) {
            return $dest;
        }
        return '/' . $dest;
    }

    $redirect = R::findOne('redirect', ' from_slug = ? ', [$slug]);
    if ($redirect !== null) {
        return $redirect->to_url;
    }

    return null;
}
