<?php if ( isset( $_SESSION[ SESSION_LOGIN ] ) ): ?>
    <form method="post" action="/" enctype="multipart/form-data">
        <textarea
            placeholder="What's happening?"
            name="contents"
            required
            id="contents"
            _="
            -- grow input
            on every input set my.style.height to my.scrollHeight + 'px'

            -- upload images
            on dragover or dragenter halt the event
            on dragleave or drop halt the event
            set files to the event's dataTransfer.files
            if length of files > 0
                make a FormData called data
                repeat for file in files
                    data.append('imageFiles[]', file)
                end
                fetch /upload as json with {method:'POST', body:data}
                set currentText to my value
                set curPos to my selectionStart
                put currentText.slice(0, curPos) + result + currentText.slice(curPos) into my value
                trigger input on me
            end
            "
        ></textarea>
        <input type="submit" name="submit" value="<?= SUBMIT_CREATE; ?>">
        <input type="hidden" name="<?= HIDDEN_CSRF_NAME; ?>" value="<?= csrf_token(); ?>"/>
    </form>
<?php endif; ?>

<?= site_title(); ?>
<?= page_intro(); ?>

<?php require ROOT_DIR . "/views/_items.php"; ?>
<!---



-->
