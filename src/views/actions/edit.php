<?php
global $data;
$post = $data['post'];

if ( isset( $_SESSION[ SESSION_LOGIN ] ) && $post->id > 0 ): ?>
    <h2> Edit Status</h2>

    <!-- TODO reuse form from home -->
    <form method="post" action="/edit" id="editform">
        <textarea placeholder="What's happening?" name="contents" required
                  ondrop="handleDrop(event)"
                  ondragover="handleDragOver(event)"
                  id="contents"
                  _="on every input set my.style.height to my.scrollHeight + 'px'"
        ><?= strip_tags( $post->body ); ?></textarea>
        <input type="hidden" name="id" value="<?= strip_tags( $post->id ); ?>"/>
        <input type="submit" form="editform" name="submit" value="<?= SUBMIT_EDIT; ?>">
        <input type="hidden" name="<?= HIDDEN_CSRF_NAME; ?>" value="<?= csrf_token(); ?>"/>
    </form>

    <small><?= action_delete( $post ); ?></small>
<?php else:
	$_SESSION['flash'][] = "Error: Status does not exist!";
	Svandragt\Lamb\Response\respond_404();
endif;
