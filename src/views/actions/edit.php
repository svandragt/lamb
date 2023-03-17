<?php
global $bleat;
if ( $_SESSION[ SESSION_LOGIN ] ): ?>
    <h2> Edit Bleat</h2>

    <form method="post" action="/edit" id="editform">
        <textarea placeholder="Bleat here..." name="contents" required><?= strip_tags( $bleat->body ); ?></textarea>
        <input type="hidden" name="id" value="<?= strip_tags( $bleat->id ); ?>"/>
        <input type="submit" form="editform" name="submit" value="<?= BUTTON_SAVE; ?>">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>"/>
    </form>

    <small><?= action_delete( $bleat ); ?></small>
<?php endif; ?>
