<?php

global $config;
global $action;

use Svandragt\Lamb;

function action_delete( $bleat ) : string {
	if ( ! isset( $bleat['id'] ) || ! isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		return '';
	}

	return sprintf( '<form data-id="%s" class="form-delete" action="/delete/%s" method="post"><input type="submit" value="Delete…"/><input type="hidden" name="csrf" value="%s" />
</form>', $bleat['id'], $bleat['id'], csrf_token() );
}

function action_edit( $bleat ) : string {
	if ( ! isset( $bleat['id'] ) || ! isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		return '';
	}

	return sprintf( '<button class="button-edit" data-id="%s" type="button">Edit</button>', $bleat['id'] );
}

function csrf_token() : string {
	$_SESSION[ HIDDEN_CSRF_NAME ] = $_SESSION[ HIDDEN_CSRF_NAME ] ?? hash( 'sha256', uniqid( mt_rand(), true ) );

	return $_SESSION[ HIDDEN_CSRF_NAME ];
}

function date_created( $bleat ) : string {
	if ( ! isset( $bleat['created'] ) ) {
		return '';
	}

	$human_created = human_time( strtotime( $bleat['created'] ) );

	$slug = "/status/{$bleat['id']}";
	if ( ! empty( $bleat['slug'] ) ) {
		$slug = $bleat['slug'];
	}

	return sprintf( '<a href="%s" title="%s">%s</a>', $slug, $bleat['created'], $human_created );
}

function parse_tags( $html ) : string {
	return (string) preg_replace( '/(^|[\s>])#(\w+)/', '$1<a href="/tag/$2">#$2</a>', $html );
}

function site_title() : string {
	global $config;

	return sprintf( '<h1>%s</h1>', $config['site_title'] );
}

function page_title() : string {
	global $data;
	if ( ! isset( $data['title'] ) ) {
		return '';
	}

	return sprintf( '<h1>%s</h1>', $data['title'] );
}

function page_intro() : string {
	global $data;
	if ( ! isset( $data['intro'] ) ) {
		return '';
	}

	return sprintf( '<p>%s</p>', $data['intro'] );
}

function the_opengraph() {
	global $action;
	global $config;
	global $data;
	if ( $action !== 'status' ) {
		return;
	}
	$item = $data['items'][0];
	$description = $item['description'];
	$og_tags = [
		'og:description' => $description,
		'og:image' => ROOT_URL . '/images/og-image-lamb.jpg',
		'og:image:height' => '630',
		'og:image:type' => 'image/jpeg',
		'og:image:width' => '1200',
		'og:locale' => 'en_GB',
		'og:modified_time' => $item['created'],
		'og:published_time' => $item['updated'],
		'og:publisher' => ROOT_URL,
		'og:site_name' => $config['site_title'],
		'og:type' => 'article',
		'og:url' => Lamb\permalink( $item ),
		'twitter:card' => 'summary',
		'twitter:description' => $description,
		'twitter:domain' => $_SERVER["HTTP_HOST"],
		'twitter:image' => ROOT_URL . '/images/og-image-lamb.jpg',
		'twitter:url' => Lamb\permalink( $item ),
	];
	if ( isset( $item['title'] ) ) {
		$og_tags['og:title'] = $item['title'];
		$og_tags['twitter:title'] = $item['title'];
	}
	foreach ( $og_tags as $property => $content ) {
		if ( empty( $content ) ) {
			continue;
		}
		printf( '<meta property="%s" content="%s"/>' . PHP_EOL, og_escape( $property ), og_escape( $content ) );
	}
}

function the_styles() : void {
	$styles = [
		'' => [ 'styles.css' ],
	];
	$assets = asset_loader( $styles, 'css' );
	foreach ( $assets as $id => $href ) {
		printf( "<link rel='stylesheet' id='%s' href='%s'>", $id, $href );
	}
}

function the_scripts() : void {
	$scripts = [
		'logged_in' => [ '/growing-input.js', '/confirm-delete.js', '/link-edit-buttons.js' ],
	];
	$assets = asset_loader( $scripts, 'js' );
	foreach ( $assets as $id => $href ) {
		printf( "<script id='%s' src='%s'></script>", $id, $href );
	}
}

function asset_loader( $assets, $asset_dir ) : Generator {
	foreach ( $assets as $dir => $files ) {
		foreach ( $files as $file ) {
			$is_admin_script = $dir === SESSION_LOGIN;
			if ( empty( $dir ) || ( $is_admin_script && isset( $_SESSION[ SESSION_LOGIN ] ) ) ) {
				$href = ROOT_URL . "/$asset_dir/$dir$file";
				yield md5( $href ) => $href;
			}
		}
	}
}

/**
 * Thanks to Rose Perrone
 * @link https://stackoverflow.com/a/11813996
 */
function human_time( $timestamp ) : string {
	// Get time difference and setup arrays
	$difference = time() - $timestamp;
	$periods = [ "second", "minute", "hour", "day", "week", "month", "years" ];
	$lengths = [ "60", "60", "24", "7", "4.35", "12" ];

	// Past or present
	if ( $difference >= 0 ) {
		$ending = "ago";
	} else {
		$difference = - $difference;
		$ending = "to go";
	}

	// Figure out difference by looping while less than array length
	// and difference is larger than lengths.
	$arr_len = count( $lengths );
	for ( $j = 0; $j < $arr_len && $difference >= $lengths[ $j ]; $j ++ ) {
		$difference /= $lengths[ $j ];
	}

	// Round up
	$difference = (int) round( $difference );

	// Make plural if needed
	if ( $difference !== 1 ) {
		$periods[ $j ] .= "s";
	}

	// Default format
	$text = "$difference $periods[$j] $ending";

	// over 24 hours
	if ( $j > 2 ) {
		// future date over a day format with year
		if ( $ending === "to go" ) {
			if ( $j === 3 && $difference === 1 ) {
				$text = "Tomorrow at " . date( "g:i a", $timestamp );
			} else {
				$text = date( "F j, Y \a\\t g:i a", $timestamp );
			}

			return $text;
		}

		if ( $j === 3 && $difference === 1 ) // Yesterday
		{
			$text = "Yesterday at " . date( "g:i a", $timestamp );
		} else if ( $j === 3 ) // Less than a week display -- Monday at 5:28pm
		{
			$text = date( "l \a\\t g:i a", $timestamp );
		} else if ( $j < 6 && ! ( $j === 5 && $difference === 12 ) ) // Less than a year display -- June 25 at 5:23am
		{
			$text = date( "F j \a\\t g:i a", $timestamp );
		} else // if over a year or the same month one year ago -- June 30, 2010 at 5:34pm
		{
			$text = date( "F j, Y \a\\t g:i a", $timestamp );
		}
	}

	return $text;
}

function redirect_to() : string {
	return (string) filter_input( INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL );
}

function escape( string $html ) : string {
	return htmlspecialchars( $html, ENT_HTML5 | ENT_QUOTES | ENT_SUBSTITUTE );
}

function og_escape( string $html ) : string {
	return htmlspecialchars( htmlspecialchars_decode( $html ), ENT_COMPAT | ENT_HTML5 );
}

function li_menu_items() {
	global $config;
	$items = [];
	$format = '<li><a href="%s">%s</a></li>';
	if ( empty( $config['menu_items'] ) ) {
		return '';
	}
	foreach ( $config['menu_items'] as $slug => $label ) {
		$items[] = sprintf( $format, escape($slug), escape($label) );
	}

	return implode( PHP_EOL, $items );
}

?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <title><?= escape( $config['site_title'] ) ?></title>
    <link rel="alternate" type="application/atom+xml" href="<?= ROOT_URL . '/feed' ?>"
          title="<?= escape( $config['site_title'] ) ?>">
	<?php the_styles(); ?>
	<?php the_opengraph(); ?>
</head>
<body>
<nav>
    <ul>
        <li>
            <a href="/" class="not-underlined">🐑</a>
			<?php if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ): ?>
                <a href="/login">Login</a>
			<?php else: ?>
                <a href="/logout">Logout</a>
			<?php endif; ?>
        </li>
		<?php echo li_menu_items(); ?>
        <li>
            <form action="/search" method="get" class="form-search">
                <label for="s"><span class="screen-reader-text">Search</span></label>
                <input type="text" name="s" id="s" required>
                <input type="submit" value="🔎">
            </form>
        </li>
    </ul>
</nav>
<main>
	<?php
	if ( isset( $_SESSION['flash'] ) ):
		while ( count( $_SESSION['flash'] ) > 0 ):
			$flash = array_pop( $_SESSION['flash'] );
			?>
            <div class="flash">⚠️ <?= escape( $flash ) ?></div>
		<?php
		endwhile;
	endif;
	require( ROOT_DIR . "/views/actions/$template.php" ); ?>
</main>
<footer>
    <small>Powered by <a href="https://github.com/svandragt/lamb">Lamb</a>.</small>
</footer>
<?php the_scripts(); ?>
</body>
</html>
