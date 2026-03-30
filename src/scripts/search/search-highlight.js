/**
 * Highlights search keywords in post content on the search results page.
 * Runs only when the body has class "search" and the search input has a value.
 */
(function () {
    if (!document.body.classList.contains('search')) {
        return;
    }

    var input = $('#s');
    if (!input || !input.value.trim()) {
        return;
    }

    var words = input.value.trim().split(/\s+/).filter(function (w) { return w.length > 0; });
    if (words.length === 0) {
        return;
    }

    var pattern = new RegExp('(' + words.map(function (w) {
        return w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }).join('|') + ')', 'gi');

    var container = $('main');
    if (!container) {
        return;
    }

    highlightInNode(container, pattern);

    function highlightInNode(node, re) {
        if (node.nodeType === Node.TEXT_NODE) {
            var text = node.nodeValue;
            if (!re.test(text)) {
                return;
            }
            re.lastIndex = 0;
            var frag = document.createDocumentFragment();
            var last = 0;
            var m;
            re.lastIndex = 0;
            while ((m = re.exec(text)) !== null) {
                if (m.index > last) {
                    frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                }
                var mark = document.createElement('mark');
                mark.textContent = m[0];
                frag.appendChild(mark);
                last = re.lastIndex;
            }
            if (last < text.length) {
                frag.appendChild(document.createTextNode(text.slice(last)));
            }
            node.parentNode.replaceChild(frag, node);
            return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        // Skip form elements to avoid breaking UI
        var tag = node.tagName.toUpperCase();
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'INPUT') {
            return;
        }

        // Work on a static copy of childNodes since we mutate the tree
        var children = Array.prototype.slice.call(node.childNodes);
        for (var i = 0; i < children.length; i++) {
            highlightInNode(children[i], re);
        }
    }
}());
