/*
 * auditor-bundle viewer behaviours.
 *
 * Loaded as an external script so that the viewer works under a strict Content-Security-Policy
 * (no 'unsafe-inline' in script-src): the templates carry no inline script nor event handler
 * attribute, only data-dh-auditor-* attributes handled here by delegation.
 *
 * The script is loaded synchronously in <head>: the theme is applied before the first paint, and
 * the listeners are attached to the document, so they do not wait for the body to be parsed.
 */
(function () {
    'use strict';

    var THEME_KEY = 'dh_auditor_theme';
    var root = document.documentElement;

    function readTheme() {
        try {
            return window.localStorage.getItem(THEME_KEY);
        } catch (e) {
            return null;
        }
    }

    function writeTheme(theme) {
        try {
            window.localStorage.setItem(THEME_KEY, theme);
        } catch (e) {
            // storage unavailable (private mode, blocked site data): the toggle still works for this page
        }
    }

    var theme = readTheme();
    if ('dark' === theme || (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        root.classList.add('dark');
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target instanceof Element ? event.target.closest('[data-dh-auditor-theme-toggle]') : null;
        if (!toggle) {
            return;
        }

        var dark = root.classList.toggle('dark');
        writeTheme(dark ? 'dark' : 'light');
    });

    document.addEventListener('change', function (event) {
        var field = event.target;
        if (!(field instanceof HTMLSelectElement) && !(field instanceof HTMLInputElement)) {
            return;
        }

        // Filters of a stream: submit their form as soon as a value changes.
        if (field.hasAttribute('data-dh-auditor-autosubmit') && field.form) {
            if (typeof field.form.requestSubmit === 'function') {
                field.form.requestSubmit();
            } else {
                field.form.submit();
            }

            return;
        }

        // Sort of the entity list: keep the other query parameters of the page.
        if (field.hasAttribute('data-dh-auditor-sort')) {
            var url = new URL(window.location.href);
            if (field.value) {
                url.searchParams.set('sort', field.value);
            } else {
                url.searchParams.delete('sort');
            }

            window.location.href = url.toString();
        }
    });
})();
