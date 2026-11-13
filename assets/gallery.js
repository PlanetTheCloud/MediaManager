(() => {
    'use strict';

    const byId = id => document.getElementById(id);
    const grid = byId('gallery-grid');
    const more = byId('gallery-more');
    const loadButton = byId('gallery-load');
    const gridStatus = byId('gallery-status');
    const refresh = byId('gallery-refresh');
    const dialog = byId('media-viewer');
    const layout = dialog.querySelector('.viewer-layout');
    const title = byId('viewer-title');
    const video = byId('viewer-video');
    const image = byId('viewer-image');
    const previous = byId('viewer-previous');
    const next = byId('viewer-next');
    const fullscreen = byId('viewer-fullscreen');
    const favorite = byId('viewer-favorite');
    const rating = byId('viewer-rating');
    const tags = byId('viewer-tags');
    const inherited = byId('viewer-inherited');
    const inheritedTags = byId('viewer-inherited-tags');
    const editTags = byId('viewer-edit-tags');
    const tagEditor = byId('viewer-tag-editor');
    const tagInput = byId('viewer-tag-input');
    const saveTags = byId('viewer-save-tags');
    const viewerStatus = byId('viewer-status');
    const viewerError = byId('viewer-error');
    const viewerRefresh = byId('viewer-refresh');
    const viewUrl = new URL(grid.dataset.viewUrl, location.href);
    const requests = new AbortController();

    let cursor = grid.dataset.cursor;
    let pendingBatch = null;
    let pendingProbe = null;
    let stale = false;
    let inactive = false;
    let selected = null;
    let opener = null;
    let openerIndex = 0;
    let scrollPosition = null;
    let saving = false;
    let waitingForNext = false;

    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) {
            loadNextBatch();
        }
    }, { rootMargin: '0px 0px 240px' }) : null;

    function setMessage(element, message) {
        element.textContent = message;
        element.hidden = !message;
    }

    function showMore(message = '', label = 'Load more') {
        more.hidden = false;
        setMessage(gridStatus, message);
        loadButton.textContent = label;
        loadButton.hidden = !cursor || stale;
        refresh.hidden = !stale;
        viewerRefresh.hidden = !stale;
    }

    function markViewStale() {
        stale = true;
        observer?.disconnect();
        showMore('Results have changed.');
        updateNavigation();
        document.dispatchEvent(new Event('gallery:results-changed'));
    }

    function releaseMedia() {
        if (video.hasAttribute('src')) {
            video.pause();
            video.removeAttribute('src');
            video.load();
        }
        image.removeAttribute('src');
        video.hidden = true;
        image.hidden = true;
    }

    function stopBrowsing() {
        inactive = true;
        observer?.disconnect();
        requests.abort();
        releaseMedia();
        if (dialog.open) {
            dialog.close();
        }
        document.documentElement.classList.remove('viewer-open');
        selected = null;
        opener = null;
        scrollPosition = null;
    }

    function showUnavailable(response) {
        if (response.headers.get('X-MediaManager-Error') !== 'library-unavailable') {
            return false;
        }
        document.dispatchEvent(new Event('gallery:library-unavailable'));
        return true;
    }

    function clearUnavailableGallery() {
        if (!inactive) {
            stopBrowsing();
            const panel = document.createElement('main');
            panel.className = 'container py-5';
            const heading = document.createElement('h1');
            heading.className = 'h4';
            heading.textContent = 'The media library is unavailable.';
            heading.tabIndex = -1;
            const retry = document.createElement('a');
            retry.className = 'btn btn-outline-light mt-2';
            retry.href = '/';
            retry.textContent = 'Reopen library';
            panel.append(heading, retry);
            grid.replaceChildren();
            dialog.replaceChildren();
            document.body.replaceChildren(panel);
            heading.focus();
        }
    }

    function loadNextBatch() {
        if (pendingBatch) {
            return pendingBatch;
        }
        if (!cursor || stale || inactive) {
            return Promise.resolve(false);
        }
        const url = new URL(viewUrl);
        url.searchParams.set('batch', cursor);
        observer?.unobserve(more);
        showMore('Loading more media…');
        loadButton.disabled = true;
        pendingBatch = appendBatch(url).finally(() => {
            pendingBatch = null;
            loadButton.disabled = false;
            updateNavigation();
        });
        return pendingBatch;
    }

    async function appendBatch(url) {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: requests.signal });
            if (showUnavailable(response) || inactive || stale) {
                return false;
            }
            if (response.headers.get('X-MediaManager-Error') === 'view-changed') {
                markViewStale();
                return false;
            }
            if (!response.ok) {
                throw new Error('Batch request failed.');
            }
            const batch = await response.json();
            if (inactive || stale) {
                return false;
            }
            if (typeof batch.html !== 'string' || (batch.cursor !== null && (typeof batch.cursor !== 'string' || batch.cursor === cursor))) {
                throw new Error('Invalid batch.');
            }
            const template = document.createElement('template');
            template.innerHTML = batch.html;
            const cardIds = new Set(Array.from(grid.children, card => card.id));
            for (const card of Array.from(template.content.children)) {
                if (cardIds.has(card.id)) {
                    card.remove();
                } else {
                    cardIds.add(card.id);
                }
            }
            grid.append(template.content);
            cursor = batch.cursor;
            showMore(cursor ? '' : 'End of results.');
            if (cursor) {
                observer?.observe(more);
            }
            return true;
        } catch (error) {
            if (!inactive && !stale) {
                showMore('Could not load more media.', 'Retry');
            }
            return false;
        }
    }

    function updateNavigation() {
        if (!selected) {
            return;
        }
        previous.disabled = selected === grid.firstElementChild;
        next.disabled = waitingForNext || (!selected.nextElementSibling && (!cursor || stale));
    }

    function renderTags(container, values, links = null) {
        const badges = values.map((tag, index) => {
            const badge = document.createElement(links ? 'a' : 'span');
            badge.className = 'badge text-bg-secondary text-decoration-none';
            badge.textContent = tag;
            if (links) {
                badge.href = links[index];
            }
            return badge;
        });
        container.replaceChildren(...badges);
    }

    function showViewerDetails() {
        if (!selected) {
            return;
        }
        const details = selected.querySelector('[data-card-details]').elements;
        favorite.setAttribute('aria-pressed', String(details.favorite.checked));
        favorite.textContent = details.favorite.checked ? 'Favorited' : 'Favorite';
        rating.value = details.rating.value;
        renderTags(tags, details.tags.value.split('\n').filter(Boolean));
        const inheritedNames = Array.from(selected.querySelector('[data-inherited-tags]').children, badge => badge.textContent);
        renderTags(inheritedTags, inheritedNames);
        inherited.hidden = inheritedNames.length === 0;
        for (const control of [favorite, rating, saveTags]) {
            control.setAttribute('aria-disabled', String(saving));
        }
    }

    function closeTagEditor(restoreFocus = false) {
        tagEditor.hidden = true;
        tagInput.value = '';
        editTags.setAttribute('aria-expanded', 'false');
        if (restoreFocus) {
            editTags.focus();
        }
    }

    function openTagEditor() {
        if (tagEditor.hidden) {
            tagInput.value = selected.querySelector('[data-card-details]').elements.tags.value;
            tagEditor.hidden = false;
            editTags.setAttribute('aria-expanded', 'true');
        }
        tagInput.focus();
    }

    function selectCard(card) {
        releaseMedia();
        if (tagEditor.contains(document.activeElement)) {
            title.focus();
        }
        closeTagEditor();
        selected = card;
        const tile = card.querySelector('[data-open-media]');
        const medium = tile.dataset.openMedia === 'video' ? video : image;
        title.textContent = card.dataset.file;
        image.alt = card.dataset.file;
        medium.hidden = false;
        medium.src = tile.href;
        setMessage(viewerStatus, '');
        setMessage(viewerError, '');
        updateNavigation();
        showViewerDetails();
    }

    function openViewer(tile) {
        opener = tile;
        const card = tile.closest('[data-file]');
        openerIndex = Array.from(grid.children).indexOf(card);
        scrollPosition = [window.scrollX, window.scrollY];
        document.documentElement.classList.add('viewer-open');
        dialog.showModal();
        selectCard(card);
        title.focus({ preventScroll: true });
    }

    async function navigateViewer(direction) {
        if (!selected) {
            return;
        }
        const neighbor = direction < 0 ? selected.previousElementSibling : selected.nextElementSibling;
        if (neighbor) {
            selectCard(neighbor);
            return;
        }
        if (direction < 0 || !cursor || stale || waitingForNext) {
            return;
        }
        const startingCard = selected;
        waitingForNext = true;
        updateNavigation();
        setMessage(viewerStatus, 'Loading next clip…');
        const loaded = await loadNextBatch();
        waitingForNext = false;
        updateNavigation();
        if (selected !== startingCard || !dialog.open) {
            return;
        }
        setMessage(viewerStatus, '');
        if (loaded && startingCard.nextElementSibling) {
            selectCard(startingCard.nextElementSibling);
        } else if (!stale) {
            setMessage(viewerError, 'Could not load the next clip. Try Next again.');
        }
    }

    function updateCardDetails(card, item, tagLinks) {
        const fields = card.querySelector('[data-card-details]').elements;
        fields.favorite.checked = item.favorite;
        fields.rating.value = item.rating ?? '';
        fields.tags.value = item.tags.join('\n');
        card.querySelector('[data-favorite-badge]').hidden = !item.favorite;
        const stars = card.querySelector('[data-rating-badge]');
        stars.hidden = item.rating === null;
        stars.textContent = '★'.repeat(item.rating ?? 0);
        stars.setAttribute('aria-label', `${item.rating ?? 0} out of 5 stars`);
        renderTags(card.querySelector('[data-tag-badges]'), item.tags, tagLinks);
        renderTags(card.querySelector('[data-inherited-tags]'), item.inherited_tags);
    }

    async function saveViewerChange(action, value) {
        if (!selected || inactive) {
            return;
        }
        if (saving) {
            showViewerDetails();
            return;
        }
        const card = selected;
        saving = true;
        showViewerDetails();
        setMessage(viewerError, '');
        setMessage(viewerStatus, 'Saving…');
        try {
            const response = await fetch(viewUrl, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action, value, file: card.dataset.file }),
                signal: requests.signal,
            });
            if (showUnavailable(response) || inactive) {
                return;
            }
            const result = await response.json();
            if (inactive) {
                return;
            }
            if (!response.ok) {
                throw new Error(result.message || 'Could not save the change.');
            }
            updateCardDetails(card, result.item, result.tagLinks);
            if (!result.matchesView) {
                markViewStale();
            } else {
                document.dispatchEvent(new Event('gallery:results-changed'));
            }
            if (selected === card) {
                if (action === 'tags' && !tagEditor.hidden) {
                    closeTagEditor(true);
                }
                setMessage(viewerStatus, 'Saved.');
            }
        } catch (error) {
            if (!inactive && selected === card) {
                setMessage(viewerStatus, '');
                setMessage(viewerError, error instanceof TypeError ? 'Could not save the change. Try again.' : error.message);
            }
        } finally {
            saving = false;
            showViewerDetails();
        }
    }

    function toggleFavorite() {
        return saveViewerChange('favorite', favorite.getAttribute('aria-pressed') === 'true' ? '0' : '1');
    }

    function togglePlayback() {
        if (video.hidden) {
            return;
        }
        if (video.paused) {
            video.play().catch(() => {
                if (!video.hidden && dialog.open && video.error) {
                    setMessage(viewerError, 'This video could not be played.');
                }
            });
        } else {
            video.pause();
        }
    }

    function closeViewer() {
        if (document.fullscreenElement && dialog.contains(document.fullscreenElement)) {
            document.exitFullscreen().catch(() => {});
        }
        dialog.close();
    }

    async function probeLibrary(url) {
        if (!pendingProbe) {
            pendingProbe = fetch(url, { method: 'HEAD', signal: requests.signal })
                .then(showUnavailable, () => false)
                .finally(() => { pendingProbe = null; });
        }
        return pendingProbe;
    }

    async function viewerMediaFailed(event) {
        const media = event.currentTarget;
        const url = media.getAttribute('src');
        const card = selected;
        if (url && !inactive && (media !== video || video.error) && !(await probeLibrary(url)) && selected === card && dialog.open) {
            setMessage(viewerError, 'This media could not be opened.');
        }
    }

    loadButton.addEventListener('click', loadNextBatch);
    if (cursor) {
        byId('gallery-pages').hidden = true;
        showMore();
        observer?.observe(more);
    }

    grid.addEventListener('error', event => {
        if (event.target instanceof HTMLImageElement && !inactive) {
            probeLibrary(event.target.src);
        }
    }, true);

    if (typeof dialog.showModal === 'function') {
        grid.addEventListener('click', event => {
            const tile = event.target.closest('[data-open-media]');
            if (tile && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
                event.preventDefault();
                openViewer(tile);
            }
        });
    }

    previous.addEventListener('click', () => navigateViewer(-1));
    next.addEventListener('click', () => navigateViewer(1));
    byId('viewer-close').addEventListener('click', closeViewer);
    favorite.addEventListener('click', toggleFavorite);
    rating.addEventListener('change', () => saveViewerChange('rating', rating.value));
    editTags.addEventListener('click', openTagEditor);
    byId('viewer-cancel-tags').addEventListener('click', () => closeTagEditor(true));
    tagEditor.addEventListener('submit', event => {
        event.preventDefault();
        saveViewerChange('tags', tagInput.value);
    });
    video.addEventListener('error', viewerMediaFailed);
    image.addEventListener('error', viewerMediaFailed);

    fullscreen.hidden = !document.fullscreenEnabled || typeof layout.requestFullscreen !== 'function';
    fullscreen.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await layout.requestFullscreen();
            }
        } catch (error) {
            setMessage(viewerError, 'Fullscreen is unavailable.');
        }
    });
    document.addEventListener('fullscreenchange', () => {
        fullscreen.textContent = document.fullscreenElement ? 'Exit fullscreen' : 'Fullscreen';
    });

    dialog.addEventListener('cancel', event => {
        event.preventDefault();
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        } else if (!tagEditor.hidden) {
            closeTagEditor(true);
        } else {
            closeViewer();
        }
    });
    dialog.addEventListener('close', () => {
        document.documentElement.classList.remove('viewer-open');
        releaseMedia();
        closeTagEditor();
        selected = null;
        if (!inactive && scrollPosition) {
            const nearest = grid.children[Math.min(openerIndex, grid.children.length - 1)];
            const target = opener?.isConnected ? opener : nearest?.querySelector('[data-open-media]') ?? byId('gallery-heading');
            target.focus({ preventScroll: true });
            window.scrollTo(...scrollPosition);
        }
        opener = null;
        scrollPosition = null;
    });
    dialog.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.repeat || event.isComposing || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
            || event.target.closest('input, textarea, select, [contenteditable], video') || document.fullscreenElement === video) {
            return;
        }
        const key = event.key.toLowerCase();
        if (key === 'arrowleft' || key === 'arrowright') {
            navigateViewer(key === 'arrowleft' ? -1 : 1);
        } else if (key === ' ' && !event.target.closest('button, a, summary') && !video.hidden) {
            togglePlayback();
        } else if (key === 'f') {
            toggleFavorite();
        } else if (key === 't') {
            openTagEditor();
        } else if (/^[0-5]$/.test(key)) {
            saveViewerChange('rating', key === '0' ? '' : key);
        } else {
            return;
        }
        event.preventDefault();
    });

    document.addEventListener('gallery:library-unavailable', clearUnavailableGallery);
    document.addEventListener('visibilitychange', async () => {
        if (document.hidden || stale || inactive) {
            return;
        }
        try {
            const response = await fetch('/?tag-revision', { signal: requests.signal });
            if (!showUnavailable(response) && response.ok && !inactive && !stale) {
                const { revision } = await response.json();
                if (!inactive && !stale && revision !== grid.dataset.tagRevision) {
                    markViewStale();
                }
            }
        } catch {
            // Retry when the tab becomes visible again.
        }
    });
    window.addEventListener('pagehide', stopBrowsing);
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            location.reload();
        }
    });
})();
