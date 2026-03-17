<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\li_menu_items;
use function Lamb\Theme\site_or_page_title;
use function Lamb\Theme\the_opengraph;
use function Lamb\Theme\the_preconnect;
use function Lamb\Theme\the_scripts;
use function Lamb\Theme\the_styles;

global $config;
global $data;
global $template;
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="author" content="<?= escape($config['author_name'] ?? '') ?>">
    <meta name="generator" content="Lamb">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape(site_or_page_title('text')) ?></title>
    <link rel="alternate" type="application/atom+xml" href="<?= ROOT_URL . '/feed' ?>"
          title="<?= escape($config['site_title']) ?>">
    <?php if (!empty($data['feed_url']) && $data['feed_url'] !== ROOT_URL . '/feed') : ?>
    <link rel="alternate" type="application/atom+xml" href="<?= escape($data['feed_url']) ?>"
          title="<?= escape($data['title'] ?? $config['site_title']) ?>">
    <?php endif; ?>
    <?php the_preconnect(); ?>
    <?php the_styles(); ?>
    <?php the_opengraph(); ?>
</head>
<body class="<?= escape($template) ?>">

<header class="site-header">
    <div class="header-top">
        <div class="header-inner">
            <div class="site-branding">
                <a href="/" class="site-name"><?= escape($config['site_title']) ?></a>
            </div>
            <div class="header-utils">
                <form action="/search" method="get" class="form-search" role="search">
                    <label for="s"><span class="screen-reader-text">Search</span></label>
                    <input type="text" name="s" id="s" placeholder="Search…" required>
                    <button type="submit" aria-label="Search">&#128269;</button>
                </form>
                <?php if (!isset($_SESSION[SESSION_LOGIN])) : ?>
                    <a href="/login" class="util-link">Login</a>
                <?php else : ?>
                    <a href="/settings" class="util-link">Settings</a>
                    <a href="/logout" class="util-link">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <nav class="site-nav" aria-label="Primary navigation">
        <div class="nav-inner">
            <ul>
                <?php echo li_menu_items(); ?>
                <li><a href="/feed" class="feed-link">RSS</a></li>
            </ul>
        </div>
    </nav>
</header>

<div class="site-container">
    <?php if (isset($_SESSION['flash'])) :
        while (count($_SESSION['flash']) > 0) :
            $flash = array_pop($_SESSION['flash']);
            ?>
            <div class="flash">&#9888; <?= escape($flash) ?></div>
            <?php
        endwhile;
    endif; ?>

    <main id="main-content">
        <?php
        use function Lamb\Theme\part;

        part($template); ?>
    </main>

    <?php
    part("_related");
    part("_pagination");
    ?>
</div>

<footer class="site-footer">
    <div class="footer-inner">
        <p>Powered by <a href="https://github.com/svandragt/lamb">Lamb</a>.</p>
    </div>
</footer>

<?php the_scripts(); ?>
</body>
</html>
