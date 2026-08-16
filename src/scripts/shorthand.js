/**
 * Returns the first element that matches the specified CSS selector within the document.
 *
 * @function $
 * @param {string} selector - The CSS selector used to select the element.
 * @returns {Element|null} The first element that matches the selector, or null if no elements are found.
 */
let $ = document.querySelector.bind(document)

/**
 * Returns a collection of elements that matches the specified CSS selector(s) within the document.
 *
 * @param {string} selectors - CSS selector(s) to match elements against.
 * @returns {NodeList} - A collection of elements that match the specified CSS selector(s).
 */
const $$ = document.querySelectorAll.bind(document)


/**
 * Method used to handle event listener registration.
 *
 * @public
 * @param {string} event - The name of the event.
 * @param {function} func - The event handler function.
 * @returns {Node}
 */
Node.prototype.on = Document.prototype.on = function (event, func) {
    this.addEventListener(event, func)
    return this
};

/**
 * Attach a function to the 'DOMContentLoaded' event.
 *
 * @param {function} func - The function to be attached.
 *
 * @return {void}
 */
const onLoaded = func => {
    document.on('DOMContentLoaded', func)
};

/**
 * Builds a URL for one of the app's own endpoints.
 *
 * An install served from a subdirectory answers on /blog/upload, not /upload, so
 * a bare path would post outside the app entirely. the_scripts() publishes the
 * install's base as window.LAMB_BASE; it is absent (and empty) at a domain root.
 *
 * @param {string} path - A root-relative app path, with its leading slash.
 * @returns {string} The path with the install's base prefixed.
 */
function appPath(path)
{
    return (window.LAMB_BASE || '') + path
}

/**
 * Cancels the default behavior and propagation of an event.
 *
 * @param {Event} ev - The event object to be cancelled.
 *
 * @return {void}
 */
function cancel(ev)
{
    ev.preventDefault()
    ev.stopPropagation()
}