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
	$_SESSION[ CSRF_TOKEN_NAME ] = $_SESSION[ CSRF_TOKEN_NAME ] ?? hash( 'sha256', uniqid( mt_rand(), true ) );

	return $_SESSION[ CSRF_TOKEN_NAME ];
}

function date_created( $bleat ) : string {
	if ( ! isset( $bleat['created'] ) ) {
		return '';
	}

	return sprintf( '<a href="/bleat/%s">%s</a>', $bleat['id'], $bleat['created'] );
}

function parse_tags( $text ) {
	return preg_replace( '/(?:^|\s)#(\w+)/', ' <a href="/tag/$1">#$1</a>', $text );
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

?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <title><?= $config['site_title'] ?></title>
    <link rel="alternate" type="application/atom+xml" href="<?= ROOT_URL . '/feed' ?>"
          title="<?= $config['site_title'] ?>">


    <style>
        :root {
            --shadow: #050100;
            --bg: #9A5F3D;
            --fg: #FEFEFE;
            --bg2: #86745C;
            --bglight: #E2DCD7;
            --info: #FEE684;
            --link: #027EAB;
        }

        html, body {
            padding: 0;
            margin: 0;
        }

        body {
            font: 16px/1.4em "Inter", sans-serif;
            color: var(--shadow);
            background: var(--bglight);
        }

        input[type='submit'], button {
            cursor: pointer;
        }

        main {
            margin: auto;
            padding: 0 1%;
            max-width: 70ch;
        }

        nav {
            background: var(--bg);
            color: var(--fg);
        }

        nav ul, nav li {
            display: inline;
            margin: 0;
            padding: 0;
        }

        nav a {
            display: inline-block;
            color: var(--fg);
            line-height: 2em;
            padding: 0 0.5em;
        }

        nav form {
            display: inline-block;
            margin: 0 0.5em;
            line-height: 1.9em;
        }

        footer {
            text-align: center;
            opacity: 0.5;
        }


        form {
            margin: 2em 0;
        }


        h1, h2, h3 {
            font-weight: 800;
            color: var(--bg);
        }

        h1 {
            border-top: 2px solid var(--shadow);
            padding-top: 1em;
        }

        article {
            background: var(--fg);
            padding: 1px 1em;
            border-bottom: 1px solid var(--bg2);
            border-radius: 4px;
            margin: 0 0 1rem 0;
        }

        section:last-child {
            border: none;
        }

        main a {
            color: var(--link);
        }

        main small {
            overflow: auto;
            border-top: 1px dotted var(--bg2);
            display: block;
            margin: 0 -1rem;
            padding: 1px 1rem;
        }

        main small a {
            margin-right: 1rem;
        }

        small form {
            margin: auto;
            display: inline;
        }

        textarea {
            font: 12px/1.4em "DejaVu Sans Mono", monospace;
            width: 100%;
            max-width: 100%;
            min-height: 10em;
            display: block;
            margin: 1em 0;
            box-sizing: border-box;
        }

        .flash {
            margin: 1em 0;
            padding: 0.25em 0.5em;
            background: var(--info);
            border: 1px solid var(--bg);
            border-radius: 4px;
        }

        .nunderlined {
            text-decoration: none;
        }

        /* Text meant only for screen readers. */
        .screen-reader-text {
            border: 0;
            clip: rect(1px, 1px, 1px, 1px);
            clip-path: inset(50%);
            height: 1px;
            margin: -1px;
            overflow: hidden;
            padding: 0;
            position: absolute;
            width: 1px;
            word-wrap: normal !important;
        }

        .screen-reader-text:focus {
            background-color: #eee;
            clip: auto !important;
            clip-path: none;
            color: #444;
            display: block;
            font-size: 1em;
            height: auto;
            left: 5px;
            line-height: normal;
            padding: 15px 23px 14px;
            text-decoration: none;
            top: 5px;
            width: auto;
            z-index: 100000; /* Above WP toolbar. */
        }
    </style>
</head>
<body>
<nav>
    <ul style="display:flow-root">
        <li style="float:left;">
            <a href="/" class="nunderlined">🐑</a>
			<?php if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ): ?>
                <a href="/login">Login</a>
			<?php else: ?>
                <a href="/logout">Logout</a>
			<?php endif; ?>
        </li>
        <li style="float:right;">
            <form action="/search" method="get"><label><span class="screen-reader-text">Search</span> <input type="text"
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
            <div class="flash">⚠️ <?= $flash; ?></div>
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
