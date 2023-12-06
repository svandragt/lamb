document.addEventListener('DOMContentLoaded', function () {
    const textarea = document.getElementById('contents');

    textarea.addEventListener('dragover', function (event) {
        event.stopPropagation();
        event.preventDefault();
    });

    textarea.addEventListener('drop', function (event) {
        event.stopPropagation();
        event.preventDefault();

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            handleFiles(files, textarea);
        }
    });
});

/**
 * Handle files dropped into the textarea.
 *
 * @param {FileList} files
 * @param {HTMLElement} textarea
 */
function handleFiles(files, textarea) {
    const formData = new FormData();
    for (const file of files) {
        formData.append('imageFiles[]', file);
    }
    const currentText = textarea.value;
    const cursorPosition = textarea.selectionStart;
    fetch('/upload', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            textarea.value = currentText.slice(0, cursorPosition) + data + currentText.slice(cursorPosition);
            textarea.dispatchEvent(new Event('input'));
        })
        .catch(error => console.error(error));
}
