<?php
global $config;
global $action;
function action_delete( $bleat ) : string {
	if ( ! isset( $bleat['id'] ) || ! $_SESSION[ SESSION_LOGIN ] ) {
		return '';
	}

	return sprintf( '<form action="/delete/%s" method="post" onsubmit="return confirm(\'Really delete bleat %s?\');"><input type="submit" value="Delete…"/><input type="hidden" name="csrf" value="%s" />
</form>', $bleat['id'], $bleat['id'], csrf_token() );
}

function action_edit( $bleat ) : string {
	if ( ! isset( $bleat['id'] ) || ! $_SESSION[ SESSION_LOGIN ] ) {
		return '';
	}

	return sprintf( '<button type="button" onclick="location.href=\'/edit/%s\'">Edit</button>', $bleat['id'] );
}

function csrf_token() : string {
	$_SESSION[ HIDDEN_CSRF_NAME ] = $_SESSION[ HIDDEN_CSRF_NAME ] ?? hash( 'sha256', uniqid( mt_rand(), true ) );

	return $_SESSION[ HIDDEN_CSRF_NAME ];
}

function date_created( $bleat ) : string {
	if ( ! isset( $bleat['created'] ) ) {
		return '';
	}

	$created = human_time( strtotime( $bleat['created'] ) );

	return sprintf( '<a href="/status/%s" title="%s">%s</a>', $bleat['id'], $bleat['created'], $created );
}

function parse_tags( $html ) {
	return preg_replace( '/(^|[\s>])#(\w+)/', '$1<a href="/tag/$2">#$2</a>', $html );
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

function the_styles() : void {
	foreach ( glob( ROOT_DIR . "/css/*.css" ) as $filename ) {
		$id = basename( $filename );
		$href = str_replace( ROOT_DIR, ROOT_URL, $filename );
		$html = "<link rel='stylesheet' id='%s' href='%s' />";
		printf( $html, $id, $href );
	}
}

/**
 * Thanks to Rose Perrone
 * @link https://stackoverflow.com/a/11813996
 */
function human_time( $timestamp ) {
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
	$difference = round( $difference );

	// Make plural if needed
	if ( $difference != 1 ) {
		$periods[ $j ] .= "s";
	}

	// Default format
	$text = "$difference $periods[$j] $ending";

	// over 24 hours
	if ( $j > 2 ) {
		// future date over a day formate with year
		if ( $ending === "to go" ) {
			if ( $j == 3 && $difference == 1 ) {
				$text = "Tomorrow at " . date( "g:i a", $timestamp );
			} else {
				$text = date( "F j, Y \a\\t g:i a", $timestamp );
			}

			return $text;
		}

		if ( $j == 3 && $difference == 1 ) // Yesterday
		{
			$text = "Yesterday at " . date( "g:i a", $timestamp );
		} else if ( $j == 3 ) // Less than a week display -- Monday at 5:28pm
		{
			$text = date( "l \a\\t g:i a", $timestamp );
		} else if ( $j < 6 && ! ( $j == 5 && $difference == 12 ) ) // Less than a year display -- June 25 at 5:23am
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

function current_request() {
	return filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
}

function escape( string $html ) : string {
	return htmlspecialchars( $html, ENT_HTML5 | ENT_QUOTES | ENT_SUBSTITUTE );
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
</head>
<body>
<nav>
    <ul style="display:flow-root">
        <li style="float:left;">
            <a href="/" class="nunderlined">🐑</a>
			<?php if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ): ?>
                <a href="/login?redirect_to=<?= escape( current_request() ); ?>">Login</a>
			<?php else: ?>
                <a href="/logout">Logout</a>
			<?php endif; ?>
        </li>
        <li style="float:right;">
            <form action="/search" method="get"><label><span class="screen-reader-text">Search</span>
                    <input type="text"
                           name="s"
                           required/><input
                            type="submit" value="🔎"/></form>
        </li>
    </ul>
</nav>
<main>
	<?php
	if ( isset( $_SESSION['flash'] ) ):
		while ( count( $_SESSION['flash'] ) > 0 ):
			$flash = array_pop( $_SESSION['flash'] );
			?>
            <div class="flash">⚠️ <?= escape( $flash ); ?></div>
		<?php
		endwhile;
	endif; ?>

	<?php require( ROOT_DIR . "/views/actions/$action.php" ); ?>
</main>
<footer>
    <small>Powered by <a href="https://github.com/svandragt/lamb">Lamb</a>.</small>
</footer>
</body>
</html>
