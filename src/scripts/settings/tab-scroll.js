/**
 * Keeps the checked settings tab visible. The tab bar scrolls sideways on a phone, and the
 * arrow keys check the next hidden radio without scrolling its label into view.
 */
onLoaded(() => {
    $$('.settings-tabs > input[type="radio"]').forEach(radio => {
        radio.on('change', () => {
            $(`.settings-tabs__nav label[for="${radio.id}"]`)?.scrollIntoView({block: 'nearest', inline: 'nearest'})
        })
    })
})
