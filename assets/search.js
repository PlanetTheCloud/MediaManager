(() => {
    'use strict';

    const input = document.getElementById('gallery-query');
    if (!input || input.hasAttribute('aria-invalid')) {
        return;
    }
    const panel = document.getElementById('search-suggestions');
    const status = document.getElementById('search-suggestion-status');
    const list = document.getElementById('search-tag-options');
    const url = new URL(document.getElementById('gallery-grid').dataset.viewUrl, location.href);
    url.searchParams.delete('page');
    url.searchParams.set('suggest', 'tags');

    const asciiLower = value => value.replace(/[A-Z]/g, letter => letter.toLowerCase());
    const whitespace = /[ \t\r\n\v\f]/;
    let suggestions = null;
    let request = null;
    let open = false;
    let options = [];
    let highlighted = -1;

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');

    function draftTerm() {
        const text = input.value;
        const caret = input.selectionStart ?? text.length;
        let start = 0;
        let end = text.length;
        let quoted = false;
        for (let i = 0; i < text.length; i++) {
            const character = text[i];
            if (quoted && character === '\\' && /["\\]/.test(text[i + 1] ?? '')) {
                i++;
            } else if (character === '"') {
                quoted = !quoted;
            } else if (!quoted && whitespace.test(character)) {
                if (i >= caret) {
                    end = i;
                    break;
                }
                start = i + 1;
            }
        }

        const raw = text.slice(start, caret);
        const negated = raw.startsWith('-');
        const value = negated ? raw.slice(1) : raw;
        if (/^(file|type|is|rating):/i.test(value)) {
            return null;
        }
        const prefix = value.replace(/^tag:/i, '').replace(/\\(["\\])|"/g, (_, escaped) => escaped ?? '');
        return { start, end, negated, prefix: asciiLower(prefix) };
    }

    function highlight(index) {
        if (highlighted >= 0) {
            list.children[highlighted]?.setAttribute('aria-selected', 'false');
            list.children[highlighted]?.classList.remove('active');
        }
        highlighted = index;
        const selected = list.children[index];
        if (selected) {
            selected.setAttribute('aria-selected', 'true');
            selected.classList.add('active');
            input.setAttribute('aria-activedescendant', selected.id);
            selected.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function dismiss() {
        open = false;
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        highlight(-1);
    }

    function renderSuggestions(draft) {
        highlight(-1);
        options = [];
        for (const entry of suggestions) {
            const name = asciiLower(entry.tag);
            const separator = name.indexOf(':');
            if (name.startsWith(draft.prefix) || (!draft.prefix.includes(':') && separator !== -1 && name.slice(separator + 1).startsWith(draft.prefix))) {
                options.push(entry);
                if (options.length === 20) {
                    break;
                }
            }
        }
        status.textContent = options.length ? 'Tags in current results' : 'No matching tags in current results.';
        list.replaceChildren(...options.map((entry, index) => {
            const option = document.createElement('li');
            option.id = `search-option-${index}`;
            option.dataset.index = index;
            option.className = 'list-group-item list-group-item-action py-1';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            const name = document.createElement('span');
            name.textContent = entry.tag;
            const count = document.createElement('span');
            count.className = 'small';
            count.textContent = entry.count;
            count.setAttribute('aria-label', `${entry.count} in current results`);
            option.append(name, count);
            return option;
        }));
    }

    async function loadSuggestions() {
        const controller = new AbortController();
        request = controller;
        status.textContent = 'Loading tags…';
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (response.headers.get('X-MediaManager-Error') === 'library-unavailable') {
                document.dispatchEvent(new Event('gallery:library-unavailable'));
                return;
            }
            if (!response.ok) {
                throw new Error('Tag suggestions failed.');
            }
            const payload = await response.json();
            if (request !== controller || !Array.isArray(payload.suggestions)) {
                return;
            }
            suggestions = payload.suggestions;
            const draft = draftTerm();
            if (open && draft) {
                renderSuggestions(draft);
            }
        } catch {
            if (request === controller && open) {
                status.textContent = 'Could not load tags. Focus the search box again to retry.';
            }
        } finally {
            if (request === controller) {
                request = null;
            }
        }
    }

    function showSuggestions() {
        const draft = draftTerm();
        if (!draft) {
            dismiss();
            return;
        }
        open = true;
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        if (suggestions !== null) {
            renderSuggestions(draft);
        } else if (!request) {
            loadSuggestions();
        }
    }

    function acceptSuggestion(index) {
        const entry = options[index];
        const draft = draftTerm();
        if (!entry || !draft) {
            return;
        }
        const separator = draft.end === input.value.length ? ' ' : '';
        const term = (draft.negated ? '-' : '') + entry.term + separator;
        input.setRangeText(term, draft.start, draft.end, 'end');
        dismiss();
    }

    function clearSuggestions() {
        request?.abort();
        request = null;
        suggestions = null;
        options = [];
        list.replaceChildren();
        dismiss();
    }

    input.addEventListener('focus', showSuggestions);
    input.addEventListener('input', showSuggestions);
    input.addEventListener('click', showSuggestions);
    input.addEventListener('blur', dismiss);
    input.addEventListener('keyup', event => {
        if (open && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            showSuggestions();
        }
    });
    input.addEventListener('keydown', event => {
        if (event.isComposing || event.ctrlKey || event.altKey || event.metaKey) {
            return;
        }
        if (event.key === 'Escape' && open) {
            event.preventDefault();
            dismiss();
        } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && draftTerm()) {
            event.preventDefault();
            if (!open) {
                showSuggestions();
            }
            if (options.length) {
                const next = event.key === 'ArrowDown' ? highlighted + 1 : (highlighted < 0 ? -1 : highlighted - 1);
                highlight((next + options.length) % options.length);
            }
        } else if (open && highlighted >= 0 && (event.key === 'Enter' || (event.key === 'Tab' && !event.shiftKey))) {
            event.preventDefault();
            acceptSuggestion(highlighted);
        }
    });

    list.addEventListener('mousedown', event => event.preventDefault());
    list.addEventListener('click', event => {
        const option = event.target.closest('[data-index]');
        if (option) {
            acceptSuggestion(Number(option.dataset.index));
        }
    });
    document.addEventListener('gallery:results-changed', clearSuggestions);
    document.addEventListener('gallery:library-unavailable', clearSuggestions);
    window.addEventListener('pagehide', clearSuggestions);
})();
