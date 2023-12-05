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
    console.log(cursorPosition);

    return cursorPosition;
}

function handleFiles(files, cursorPosition, textareaId) {
    const textarea = document.getElementById(textareaId);
    const formData = new FormData();
    formData.append('cursorPosition', cursorPosition);

    for (const file of files) {
        formData.append('imageFile', file);
    }

    console.log(formData);

    // Make an AJAX request or submit the form with FormData
    // Example using fetch API
    fetch('/upload', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            // Get the current textarea value
            const currentText = textarea.value;

            // Insert the data at the specified cursor position
            const newText = currentText.slice(0, cursorPosition) + data + currentText.slice(cursorPosition);

            // Update textarea content with the modified value
            textarea.value = newText;
            console.log(data);
        })
        .catch(error => console.error(error));
}
