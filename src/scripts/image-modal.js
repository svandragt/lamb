/**
 * Lets readers view post images larger than the content column at full size.
 *
 * Only images that the layout actually shrinks (natural width wider than the
 * rendered width) get a clickable affordance; clicking opens a native <dialog>
 * showing the image sized to the viewport. Escape, a backdrop click, or a click
 * on the image dismisses it.
 *
 * Depends on shorthand.js ($$, onLoaded, cancel), which loads first.
 *
 * @return {void}
 */
onLoaded(() => {
    const style = document.createElement('style')
    style.textContent = `
        img.zoomable { cursor: zoom-in; }
        dialog.image-modal { border: 0; padding: 0; background: transparent; max-width: 100vw; max-height: 100vh; }
        dialog.image-modal img { max-width: 95vw; max-height: 95vh; width: auto; height: auto; cursor: zoom-out; }
        dialog.image-modal::backdrop { background: rgba(0, 0, 0, 0.8); }
        @media (prefers-reduced-motion: no-preference) {
            dialog.image-modal[open], dialog.image-modal[open]::backdrop { animation: image-modal-fade 0.15s ease-out; }
            @keyframes image-modal-fade { from { opacity: 0; } to { opacity: 1; } }
        }
    `
    document.head.appendChild(style)

    const dialog = document.createElement('dialog')
    dialog.className = 'image-modal'
    const modalImg = document.createElement('img')
    dialog.appendChild(modalImg)
    document.body.appendChild(dialog)

    // Clicking anywhere in the dialog (image or backdrop) closes it.
    dialog.on('click', () => dialog.close())

    // A native <dialog> does not lock the page behind it, so the content
    // underneath the backdrop stays scrollable while the modal is open. Pin the
    // body's overflow while the modal is shown and restore it on close (covers
    // Escape, backdrop click, and image click alike).
    dialog.on('close', () => {
        document.body.style.overflow = ''
    })

    /**
     * Marks an image as zoomable when the layout renders it smaller than its
     * natural size.
     *
     * @param {HTMLImageElement} img - The post-content image to check.
     * @return {void}
     */
    const markIfWide = img => {
        if (img.naturalWidth > img.clientWidth) {
            img.classList.add('zoomable')
        }
    }

    $$('article img').forEach(img => {
        if (img.complete) {
            markIfWide(img)
        } else {
            img.on('load', () => markIfWide(img))
        }

        img.on('click', ev => {
            if (!img.classList.contains('zoomable')) {
                return
            }
            cancel(ev)
            modalImg.src = img.currentSrc || img.src
            modalImg.alt = img.alt
            document.body.style.overflow = 'hidden'
            dialog.showModal()
        })
    })
})
