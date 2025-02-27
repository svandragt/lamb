<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\li_menu_items;
use function Lamb\Theme\part;
use function Lamb\Theme\the_opengraph;
use function Lamb\Theme\the_scripts;
use function Lamb\Theme\the_styles;

global $config;
global $template;
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <title><?= escape($config['site_title']) ?></title>
    <link rel="alternate" type="application/atom+xml" href="<?= ROOT_URL . '/feed' ?>"
          title="<?= escape($config['site_title']) ?>">
	<?php
	the_styles(); ?>
	<?php
	the_opengraph(); ?>
</head>
<body>
<nav>
    <ul>
		<?php
		echo li_menu_items(); ?>
        <li class="right">
            <form action="/search" method="get" class="form-search">
                <label for="s"><span class="screen-reader-text">Search</span></label>
                <input type="text" name="s" id="s" required>
                <input type="submit" value="🔎">
            </form>
			<?php
			if (!isset($_SESSION[SESSION_LOGIN])) : ?>
                <a href="/login">Login</a>
			<?php
			else : ?>
                <a href="/logout">Logout</a>
			<?php
			endif; ?>
        </li>
    </ul>
</nav>
<div class="container">
    <main>
		<?php
		if (isset($_SESSION['flash'])) :
			while (count($_SESSION['flash']) > 0) :
				$flash = array_pop($_SESSION['flash']);
				?>
                <div class="flash">⚠️ <?= escape($flash) ?></div>
			<?php
			endwhile;
		endif;
		part($template); ?>
    </main>
</div>
<?php
part("_related"); ?>
<footer>
    <small>Powered by <a href="https://github.com/svandragt/lamb">Lamb</a>.</small>
</footer>
<?php
the_scripts(); ?>
</body>
</html>
