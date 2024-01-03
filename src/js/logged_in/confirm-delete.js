onLoaded(() => {
    const forms = $$('form.form-delete')
    forms?.forEach($form => $form.on('submit', (ev) => {
        cancel(ev)
        // In edit mode there is no article.
        let $a = $form.closest('article')
        if ($a) {
            $a.scrollIntoView({behavior: 'smooth'})
            $a.style.border = '4px solid orange';
        }
        setTimeout(() => {
            let confirmed = confirm(`Really delete status ${ev.target.dataset.id}?`)
            if (!confirmed) {
                if ($a) {
                    $a.style.border = null;
                }
                return
            }
            ev.target.submit()
        }, 10)
    }))
})
