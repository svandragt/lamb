<?php

global $data;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\csrf_token;
use function Lamb\Theme\escape;

$post = $data['post'];

if (isset($_SESSION[SESSION_LOGIN]) && $post->id > 0) :
    $submitLabel = SUBMIT_EDIT;
    $heading     = 'Edit Status';
    // escape(), not strip_tags(): the body is Markdown source, not markup to be
    // filtered. strip_tags() deleted every `<…>` run in it, and that mangled
    // text was what the form posted back — so opening the editor and saving
    // destroyed content.
    $body        = escape((string) $post->body);
    ?>
    <h2><?= $heading ?></h2>

    <form method="post" action="/edit" id="editform">
        <label for="contents">Contents</label><textarea placeholder="What's happening?" name="contents" required
                                                        id="contents"
        ><?= $body ?></textarea>
        <input type="hidden" name="id" value="<?= (int) $post->id ?>"/>
        <input type="submit" form="editform" name="submit" value="<?= $submitLabel ?>">
        <input type="hidden" name="<?= HIDDEN_CSRF_NAME ?>" value="<?= csrf_token() ?>"/>
    </form>

    <?php if (!$post->deleted) : ?>
    <small><?= action_delete($post) ?></small>
    <?php endif; ?>
    <?php
else :
    $_SESSION['flash'][] = "Error: Status does not exist!";
    Lamb\Response\respond_404();
endif;
