<?php 
function action_delete($bleat) {
	if (! isset($bleat['id'])) {
		return '';
	}
	return sprintf('<form action="/delete/%s" method="post" onsubmit="return confirm(\'Really delete bleat %s?\');"><input type="submit" value="Delete"/></form>',
		$bleat['id'],
		$bleat['id'],
	);
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
	<meta charset="utf-8">
	<title><?= $data['title'] ?></title>

	<style type="text/css">
		body {
			font: 16px/1.4em "Inter", sans-serif;
			color:  #222;
		}
		main {
			margin: auto;
			padding: 0 1%;
			max-width:  70ch;
		}

		nav {
			background: #444;
		}

		nav ul, nav li {
			display: inline;
			margin: 0;
			padding:0;
		}

		nav a {
			display:inline-block;
			border: 0 1px solid white;
			color: white;
			line-height: 2em;
			padding: 0 0.5em;
		}

		form {
			margin: 2em 0;
		}

		h1,h2,h3 {
			font-weight: normal;
		}

		h1 {
			border-top:  2px solid black;
			padding-top: 1em;
		}

		article	 {
			background: #eee;
			padding: 0.1px 1em;
			border-bottom: 1px solid #aaa;
			margin: 1rem 0;
		}
		section:last-child {
			border:none;
		}

		small {
			border-top: 1px dotted #aaa;
			background: #ddd;
			display:block;
			margin: 0 -1rem;
			padding: 0 1rem;
		}
		small a {
			margin-right: 1rem;
		}

		small form {
			margin: auto;
			display:inline;
		}

		textarea {
			font: 12px/1.4em "DejaVu Sans Mono", monospace;
			width: 100%;
			min-height: 10em;
			display:block;
			margin: 1em 0;
			box-sizing: border-box;
		}

		.nunderlined {
			text-decoration: none;
		}
	</style>
</head>
<body>
	<nav>
		<ul>
			<li>
				<a href="/" class="nunderlined">🐑</a>
				<?php 	if ( !$_SESSION['loggedin']): ?>
					<a href="/login">Login</a>
				<?php else: ?>
					<a href="/logout">Logout</a>
				<?php endif; ?>

			</li>
		</ul>
	</nav>
	<main>
		<?php require("views/$action" . '.php'); ?>
	</main>
</body>
</html>