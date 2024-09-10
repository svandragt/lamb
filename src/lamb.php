<?php

namespace Lamb;

use RedBeanPHP\R;

/**
 * Retrieves the tags from the given HTML.
 *
 * @param string $html The HTML content to search for tags.
 *
 * @return array An array of tags found in the HTML.
 */
function get_tags( $html ) : array {
	preg_match_all( '/(^|[\s>])#(\w+)/', $html, $matches );

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
function parse_tags( $html ) : string {
	return (string) preg_replace( '/(^|[\s>])#(\w+)/', '$1<a href="/tag/$2">#$2</a>', $html );
}

/**
 * Generates a permalink for the given item.
 *
 * This method creates a permalink for the given item based on its slug or ID.
 * If the item has a slug, it appends it to the root URL. Otherwise, it appends
 * the item's ID to the root URL with the "status" path.
 *
 * @param array $item The item for which the permalink is generated.
 *                    It should have the 'slug' and 'id' keys.
 *
 * @return string The generated permalink URL.
 */
function permalink( $item ) : string {
	if ( $item['slug'] ) {
		return ROOT_URL . "/{$item['slug']}";
	}

	return ROOT_URL . '/status/' . $item['id'];
}

/**
 * Renders a post.
 *
 * @param string $post The post to render.
 *
 * @return array The rendered post, including the front matter and the body.
 */
function render( $post ) : array {
	$parts = explode( '---', $post );
	$front_matter = Config\parse_matter( $post );

	$md_text = trim( $parts[ count( $parts ) - 1 ] );
	$parser = new LambDown();
	$parser->setSafeMode( true );
	$markdown = $parser->text( $md_text );

	$front_matter['description'] = strtok( strip_tags( $markdown ), "\n" );

	if ( isset( $front_matter['title'] ) ) {
		# Only posts have titles
		$markdown = $parser->text( "## {$front_matter['title']}" ) . PHP_EOL . $markdown;
	}

	return array_merge( $front_matter, [ 'body' => $markdown ] );
}

/**
 * Transforms an array of posts into a specific data format.
 *
 * @param array $posts The array of posts to transform.
 *
 * @return array The transformed data.
 */
function transform( $posts ) : array {
	if ( empty( $posts ) ) {
		return [];
	}

	$data = [];

	foreach ( $posts as $post ) {
		if ( is_null( $post ) || $post->id === 0 ) {
			continue;
		}
		$data['items'][] = array_merge( render( $post->body ), [
			'created' => $post->created,
			'id' => $post->id,
			'slug' => $post->slug,
			'updated' => $post->updated,
		] );
	}

	return $data;
}

/**
 * Checks if a post with the given slug exists in the database.
 *
 * @param string $lookup The slug of the post to look up.
 *
 * @return string|null The slug of the post if it exists, otherwise null.
 */
function post_has_slug( string $lookup ) : string|null {
	$post = R::findOne( 'post', ' slug = ? ', [ $lookup ] );
	if ( is_null( $post ) || $post->id === 0 ) {
		return '';
	}

	return $post->slug;
}
