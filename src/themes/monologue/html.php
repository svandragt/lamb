<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\li_menu_items;
use function Lamb\Theme\part;
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
<html lang="en">
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

<div class="page">
    <div class="frame">

        <aside class="sidebar card">
            <div class="brand">
                <div class="avatar" aria-hidden="true"></div>
                <div>
                    <h1><a href="/"><?= escape($config['site_title']) ?></a></h1>
                    <?php if (!empty($config['author_name'])) : ?>
                    <span class="handle"><?= escape($config['author_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <nav class="sidebar-nav" aria-label="Main navigation">
                <ul>
                    <?= li_menu_items() ?>
                </ul>
                <div class="sidebar-search">
                    <form action="/search" method="get">
                        <label for="s" class="screen-reader-text">Search</label>
                        <input type="text" name="s" id="s" placeholder="Search…" required>
                        <input type="submit" value="🔎" aria-label="Search">
                    </form>
                </div>
                <div class="sidebar-auth">
                    <?php if (!isset($_SESSION[SESSION_LOGIN])) : ?>
                        <a href="/login">Login</a>
                    <?php else : ?>
                        <a href="/settings">Settings</a>
                        <a href="/logout">Logout</a>
                    <?php endif; ?>
                </div>
            </nav>
        </aside>

        <main class="content">
            <?php if (isset($_SESSION['flash'])) :
                while (count($_SESSION['flash']) > 0) :
                    $flash = array_pop($_SESSION['flash']); ?>
                    <div class="flash">⚠️ <?= escape($flash) ?></div>
                <?php endwhile;
            endif; ?>

            <?php part($template); ?>
            <?php part('_related'); ?>
            <?php part('_pagination'); ?>
        </main>

    </div>
</div>

<nav class="mobile-nav" aria-label="Mobile navigation">
    <a href="/" class="<?= $template === 'home' ? 'active' : '' ?>">
        <b>◫</b><span>Home</span>
    </a>
    <a href="/search">
        <b>⌕</b><span>Search</span>
    </a>
    <?php if (isset($_SESSION[SESSION_LOGIN])) : ?>
    <a href="/settings">
        <b>⚙</b><span>Settings</span>
    </a>
    <?php endif; ?>
</nav>

<?php the_scripts(); ?>
</body>
</html>
