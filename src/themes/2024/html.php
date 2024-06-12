<?php

global $config;
global $action;
global $template;

use RedBeanPHP\R;
use function Lamb\get_tags;

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
		<?php echo li_menu_items( "left" ); ?>
        <li class="right">
            <form action="/search" method="get" class="form-search">
                <label for="s"><span class="screen-reader-text">Search</span></label>
                <input type="text" name="s" id="s" required>
                <input type="submit" value="🔎">
            </form>
			<?php if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ): ?>
                <a href="/login">Login</a>
			<?php else: ?>
                <a href="/logout">Logout</a>
			<?php endif; ?>
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
	require( THEME_DIR . "parts/$template.php" ); ?>
</main>
<?php require( THEME_DIR . "_related.php" ); ?>
<footer>
    <small>Powered by <a href="https://github.com/svandragt/lamb">Lamb</a>.</small>
</footer>
<?php the_scripts(); ?>
</body>
</html>
