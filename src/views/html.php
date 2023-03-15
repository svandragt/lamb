<?php 
function action_delete($bleat) {
	if (! isset($bleat['id']) || ! $_SESSION[SESSION_LOGIN]) {
		return '';
	}
	return sprintf('<form action="/delete/%s" method="post" onsubmit="return confirm(\'Really delete bleat %s?\');"><input type="submit" value="Delete…"/><input type="hidden" name="csrf" value="%s" />
</form>',
		$bleat['id'],
		$bleat['id'],
		csrf_token(),
	);
}

function action_edit($bleat) {
	if (! isset($bleat['id']) || ! $_SESSION[SESSION_LOGIN]) {
		return '';
	}
	return sprintf('<button type="button" onclick="location.href=\'/edit/%s\'">Edit</button>',
		$bleat['id'],
	);
}
function csrf_token() {
	$_SESSION[CSRF_TOKEN_NAME] = $_SESSION[CSRF_TOKEN_NAME] ?? hash('sha256',uniqid(mt_rand(), true));
	return $_SESSION[CSRF_TOKEN_NAME];
}


function date_created($bleat) {
	if (! isset($bleat['created'])) {
		return '';
	}
	return sprintf('<a href="/bleat/%s">%s</a>',
		$bleat['id'],
		$bleat['created']
	);
}

function parse_tags($text) {
	return preg_replace('/(?:^|\s)#(\w+)/', ' <a href="/tag/$1">#$1</a>', $text);
}

function site_title() {
	global $config;
	return sprintf('<h1>%s</h1>', $config['site_title']);
}

function page_title() {
	global $data;
	if (! isset($data['title'])) {
		return '';
	}
	return sprintf('<h1>%s</h1>', $data['title']);
}

function page_intro() {
	global $data;
	if (! isset($data['intro'])) {
		return '';
	}
	return sprintf('<p>%s</p>', $data['intro']);
}



?>
<!DOCTYPE html>
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta charset="utf-8">
	<title><?= $config['site_title'] ?></title>
	<link rel="alternate" type="application/atom+xml" href="<?= HOSTNAME; ?>/feed/" title="<?= $config['site_title'] ?>">


	<style type="text/css">
		html, body {
			padding: 0;
			margin:0;
		}

		body {
			font: 16px/1.4em "Inter", sans-serif;
			color:  RGB(40, 39, 35);
			background: #f0efe7;
		}

		input[type='submit'], button {
			cursor: pointer;
		}
		main {
			margin: auto;
			padding: 0 1%;
			max-width:  70ch;
		}

		nav {
			background: RGB(90, 79, 71);
			color: white;
		}

		nav ul, nav li {
			display: inline;
			margin: 0;
			padding:0;
		}

		nav a {
			display:inline-block;
			color: white;
			line-height: 2em;
			padding: 0 0.5em;
		}

		nav form {
			display:inline-block;
			margin:0 0.5em;
			line-height: 1.9em;
		}

		footer {
			text-align: center;
			opacity:0.5;
		}


		form {
			margin: 2em 0;
		}


		h1,h2,h3 {
			font-weight: normal;
		}

		h1 {
			border-top:  2px solid RGB(90, 79, 71);
			padding-top: 1em;
		}

		article	 {
			background: #fff;
			padding: 0.1px 1em;
			border: 1px solid RGB(220, 219, 211);
			margin: 1rem 0;
			border-radius: 4px;
		}
		section:last-child {
			border:none;
		}

		main small {
			overflow:auto;
			border-top: 1px dotted #aaa;
			background: RGBA(240, 239, 231, 0.5);
			display:block;
			margin: 0 -1rem;
			padding: 1px 1rem;
		}
		main small a {
			margin-right: 1rem;
		}

		small form {
			margin: auto;
			display:inline;
		}

		textarea {
			font: 12px/1.4em "DejaVu Sans Mono", monospace;
			width: 100%;
			max-width: 100%;
			min-height: 10em;
			display:block;
			margin: 1em 0;
			box-sizing: border-box;
		}

		.flash {
			margin: 1em 0; 
			padding: 0.25em 0.5em;
			background: orange;
		} 

		.nunderlined {
			text-decoration: none;
		}
	</style>
</head>
<body>
	<nav>
		<ul style="display:flow-root">
			<li style="float:left;">
				<a href="/" class="nunderlined">🐑</a>
				<?php 	if ( !isset($_SESSION[SESSION_LOGIN])): ?>
					<a href="/login">Login</a>
				<?php else: ?>
					<a href="/logout">Logout</a>
				<?php endif; ?>
			</li>
			<li style="float:right;">
				<form action="/search" method="get"><input type="text" name="s" required /><input type="submit" value="🔎" /></form>
			</li>
		</ul>
	</nav>
	<main>
		<?php 
		if (isset($_SESSION['flash'])):
		while (count($_SESSION['flash']) > 0):
			$flash = array_pop($_SESSION['flash']);
			?>
			<div class="flash"><?= $flash; ?></div>
		<?php 
		endwhile;
		endif; ?>
		
		<?php require("views/actions/$action" . '.php'); ?>
	</main>
	<footer>
		<small>Powered by <a href="https://github.com/svandragt/lamb">Lamb</a>.</small>
	</footer>
</body>
</html>