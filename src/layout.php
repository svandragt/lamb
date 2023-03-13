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
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?= $data['title'] ?></title>

	<style type="text/css">
		body {
			font: 16px/1.4em sans-serif;
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
		}

		nav a {
			display:inline-block;
			border: 0 1px solid white;
			color: white;
			line-height: 2em;
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

		section	 {
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
			width: 100%;
			min-height: 10em;
			display:block;
			margin: 1em 0;
			box-sizing: border-box;
		}
	</style>
</head>
<body>
	<nav>
		<ul>
			<li>
				<a href="/">Home</a>
			</li>
		</ul>
	</nav>
	<main>
		<form method="post" action="/">
			<textarea placeholder="Bleat here..." name="contents"></textarea>
			<input type="submit" name="submit" value="Bleat">
		</form>
		<?= page_title(); ?>

		<?php foreach ($data['bleats'] as $b): ?>
			<section>
			<h2><?= $b['title']; ?></h2>
			<?= $b['bleat']; ?>
			
			<small><?= date_created($b); ?> <?= action_delete($b); ?></small>
			</section>
		<?php endforeach; ?>

	</main>
</body>
</html>