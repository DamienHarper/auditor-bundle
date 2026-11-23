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

    // Technical mode of a history: opens (or closes) the details of every action of the list.
    function expandAll(container, open) {
        container.querySelectorAll('details[data-dh-auditor-details]').forEach(function (details) {
            details.open = open;
        });
    }

    function isExpanded(container) {
        var toggle = container.querySelector('[data-dh-auditor-expand-all]');

        return toggle instanceof HTMLInputElement && toggle.checked;
    }

    // "Show older actions": the link works without JavaScript; with it, the next rows are loaded in place.
    function loadMore(link) {
        var container = link.closest('[data-dh-auditor-more-container]');
        var actions = link.closest('[data-dh-auditor-actions]');
        if (!container || typeof window.fetch !== 'function') {
            return false;
        }

        link.setAttribute('aria-busy', 'true');
        window.fetch(link.href, {headers: {'X-DH-Auditor-Fragment': '1'}, credentials: 'same-origin'})
            .then(function (response) {
                // a redirect (an expired session sent to the login page…) or an error: open the page itself
                if (!response.ok || response.redirected) {
                    throw new Error(response.redirected ? 'redirected' : response.statusText);
                }

                return response.text();
            })
            .then(function (html) {
                var template = document.createElement('template');
                template.innerHTML = html;
                container.replaceWith(template.content);
                if (actions && isExpanded(actions)) {
                    expandAll(actions, true);
                }
            }, function (error) {
                console.error('auditor: older actions could not be loaded in place', error);
                window.location.href = link.href;
            });

        return true;
    }

    function copy(button) {
        var source = document.getElementById(button.getAttribute('data-dh-auditor-copy') || '');
        if (!source || !navigator.clipboard) {
            return;
        }

        var show = function (attribute) {
            var label = button.getAttribute(attribute);
            if (label) {
                button.textContent = label;
            }
        };

        navigator.clipboard.writeText(source.textContent || '').then(function () {
            show('data-dh-auditor-copied');
        }, function () {
            show('data-dh-auditor-copy-failed');
        });
    }

    // the copy buttons need the clipboard API (a secure context): hide them without it
    document.addEventListener('DOMContentLoaded', function () {
        if (!navigator.clipboard) {
            document.querySelectorAll('[data-dh-auditor-copy]').forEach(function (button) {
                button.hidden = true;
            });
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        if (target.closest('[data-dh-auditor-theme-toggle]')) {
            var dark = root.classList.toggle('dark');
            writeTheme(dark ? 'dark' : 'light');

            return;
        }

        var copyButton = target.closest('[data-dh-auditor-copy]');
        if (copyButton) {
            // the button sits in a <summary>: copying must not toggle the details
            event.preventDefault();
            copy(copyButton);

            return;
        }

        var more = target.closest('a[data-dh-auditor-more]');
        if (more && !event.metaKey && !event.ctrlKey && !event.shiftKey && 0 === event.button && loadMore(more)) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', function (event) {
        var field = event.target;
        if (!(field instanceof HTMLSelectElement) && !(field instanceof HTMLInputElement)) {
            return;
        }

        if (field.hasAttribute('data-dh-auditor-expand-all')) {
            var actions = field.closest('[data-dh-auditor-actions]');
            if (actions) {
                expandAll(actions, field.checked);
            }

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
