<?php

global $data;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\csrf_token;

$post = $data['post'];

if (isset($_SESSION[SESSION_LOGIN]) && $post->id > 0) :
    $submitLabel = SUBMIT_EDIT;
    $heading     = 'Edit Status';
    ?>
    <h2><?= $heading ?></h2>

    <form method="post" action="/edit" id="editform">
        <label for="contents">Contents</label><textarea placeholder="What's happening?" name="contents" required
                                                        id="contents"
        ><?= strip_tags($post->body) ?></textarea>
        <input type="hidden" name="id" value="<?= strip_tags($post->id) ?>"/>
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
