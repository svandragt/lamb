document.addEventListener('DOMContentLoaded', function () {
    const textarea = document.getElementById('contents');

    textarea.addEventListener('dragover', function (event) {
        event.stopPropagation();
        event.preventDefault();
    });

    textarea.addEventListener('drop', function (event) {
        event.stopPropagation();
        event.preventDefault();

        const cursorPosition = getCursorPosition(textarea, event);

        const files = event.dataTransfer.files;

        if (files.length > 0) {
            handleFiles(files, cursorPosition, textarea.id);
            console.log(files);
        }
    });
});

function getCursorPosition(textarea, event) {
    const {clientX, clientY} = event;
    const {top, left, height, width} = textarea.getBoundingClientRect();
    const x = clientX - left;
    const y = clientY - top;
    const rowHeight = height / textarea.rows;
    const colWidth = width / textarea.cols;
    const row = Math.floor(y / rowHeight);
    const col = Math.floor(x / colWidth);
    const cursorPosition = textarea.selectionStart + (row * textarea.cols) + col;

    return cursorPosition;
}

function handleFiles(files, cursorPosition, textareaId) {
    const formData = new FormData();
    formData.append(textareaId, document.getElementById(textareaId).value);
    formData.append('cursorPosition', cursorPosition);

    for (const file of files) {
        formData.append('imageFile', file);
    }

    // Make an AJAX request or submit the form with FormData
    // Example using fetch API
    fetch('upload.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            // Update textarea content with the inserted image link
            document.getElementById(textareaId).value = data[textareaId];
            console.log(data);
        })
        .catch(error => console.error(error));
}
