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
    const bulkTools = byId('bulk-tools');
    const bulkToggle = byId('bulk-toggle');
    const bulkForm = byId('bulk-form');
    const bulkAction = byId('bulk-action');
    const bulkReview = byId('bulk-review');
    const bulkApply = byId('bulk-apply');
    const bulkUndo = byId('bulk-undo');
    const bulkRecovery = byId('bulk-recovery');
    const bulkReport = byId('bulk-move-report');
    const bulkStatus = byId('bulk-status');
    const bulkSelection = new Set();
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
    let selecting = false;
    let bulkBusy = false;
    let bulkPreview = null;

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
        bulkSelection.clear();
        bulkPreview = null;
        if (bulkReview.open) {
            bulkReview.close();
        }
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
            updateBulkSelection();
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
        card.querySelector('[data-mark-badge]').hidden = !item.marked_for_deletion;
        const mark = card.querySelector('[data-card-mark]');
        mark.value = item.marked_for_deletion ? 'unmark' : 'mark';
        mark.textContent = item.marked_for_deletion ? 'Clear deletion mark' : 'Mark for deletion';
    }

    function updateBulkSelection() {
        for (const card of grid.children) {
            const checkbox = card.querySelector('[data-bulk-select]');
            checkbox.closest('[data-bulk-control]').hidden = !selecting;
            checkbox.checked = bulkSelection.has(card.dataset.file);
            checkbox.disabled = bulkBusy;
        }
        bulkToggle.setAttribute('aria-pressed', String(selecting));
        bulkToggle.textContent = selecting ? 'Done selecting' : 'Select';
        bulkToggle.disabled = bulkBusy;
        bulkForm.hidden = !selecting;
        byId('bulk-count').textContent = `${bulkSelection.size} selected`;
        byId('bulk-loaded').disabled = bulkBusy;
        byId('bulk-preview').disabled = bulkBusy || !bulkSelection.size;
        bulkUndo.disabled = bulkBusy;
        for (const button of bulkRecovery.querySelectorAll('button')) {
            button.disabled = bulkBusy;
        }
        bulkApply.disabled = bulkBusy || !bulkPreview?.canApply;
        byId('bulk-close').disabled = bulkBusy;
    }

    async function bulkRequest(action, payload) {
        bulkBusy = true;
        updateBulkSelection();
        try {
            const response = await fetch(viewUrl, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action, payload: JSON.stringify(payload) }),
                signal: requests.signal,
            });
            if (showUnavailable(response) || inactive) {
                return null;
            }
            const result = await response.json();
            if (!response.ok && !result.preview) {
                throw new Error(result.message || 'The bulk action could not be completed.');
            }
            return result;
        } finally {
            bulkBusy = false;
            if (!inactive) {
                updateBulkSelection();
            }
        }
    }

    function showBulkPreview(preview, request, actionLabel, previous = null) {
        const { changed, unchanged, blocked } = preview.counts;
        bulkPreview = {
            request, actionLabel, preview,
            canApply: changed > 0 && !previous && (blocked === 0 || request.operation === 'move'),
        };
        const heading = byId('bulk-review-title');
        heading.textContent = preview.tags.length ? `${actionLabel} · ${preview.tags.join(', ')}` : actionLabel;
        const summary = `${changed} changed · ${unchanged} unchanged · ${blocked} blocked`;
        byId('bulk-review-counts').textContent = previous
            ? `The selection or its details changed. Close this review and preview again. ${summary}`
            : summary;
        const old = new Map(previous?.rows.map(row => [row.path, row]));
        const items = document.createDocumentFragment();
        for (const row of preview.rows) {
            const item = document.createElement('li');
            item.className = 'list-group-item text-break';
            const parts = [row.destination === undefined ? row.path : `${row.path} → ${row.destination}`, row.status];
            const before = old.get(row.path);
            if (before && before.fingerprint !== row.fingerprint) {
                if (before.fileVersion !== row.fileVersion) {
                    parts.push('File or folder identity changed since preview');
                } else if (before.detailsVersion !== row.detailsVersion) {
                    parts.push('Saved details changed since preview');
                } else {
                    parts.push('Availability changed since preview');
                }
            }
            if (row.reason) {
                parts.push(row.reason);
            }
            if (row.tags.length) {
                parts.push(row.tags.join(', '));
            }
            if (row.inherited.length) {
                parts.push(`Still visible through a parent relationship: ${row.inherited.join(', ')}`);
            }
            item.textContent = parts.join(' · ');
            items.append(item);
        }
        byId('bulk-review-files').replaceChildren(items);
        updateBulkSelection();
        if (!bulkReview.open) {
            bulkReview.showModal();
        }
        heading.focus();
    }

    function updateBulkCards(result) {
        const cards = new Map(Array.from(grid.children, card => [card.dataset.file, card]));
        for (const update of result.updates) {
            const card = cards.get(update.path);
            if (card) {
                updateCardDetails(card, update.item, update.tagLinks);
            }
        }
        bulkUndo.dataset.token = result.undo ?? '';
        bulkUndo.hidden = !result.undo;
        if (result.updates.some(update => update.matchesView !== cards.has(update.path))) {
            markViewStale();
        } else if (result.updates.length) {
            document.dispatchEvent(new Event('gallery:results-changed'));
        }
    }

    function showBulkMove(move, blocked = []) {
        const { completed, failed, remaining } = move.counts;
        let resume = Array.from(bulkRecovery.children).find(button => button.dataset.bulkResume === move.batch);
        if ((failed || remaining) && !resume) {
            resume = document.createElement('button');
            resume.type = 'button';
            resume.className = 'btn btn-outline-warning btn-sm';
            resume.textContent = 'Resume move batch';
            resume.dataset.bulkResume = move.batch;
            bulkRecovery.append(resume);
        } else if (!failed && !remaining) {
            resume?.remove();
        }
        const list = document.createElement('ul');
        list.className = 'list-group overflow-auto';
        const moved = new Set();
        for (const row of move.rows) {
            const item = document.createElement('li');
            item.className = 'list-group-item text-break';
            item.textContent = `${row.source} → ${row.destination} · ${row.state}${row.error ? ` · ${row.error}` : ''}`;
            list.append(item);
            if (row.state === 'completed') {
                moved.add(row.source);
                bulkSelection.delete(row.source);
            }
        }
        for (const row of blocked) {
            const item = document.createElement('li');
            item.className = 'list-group-item text-break';
            item.textContent = `${row.path} → ${row.destination} · blocked · ${row.reason}`;
            list.append(item);
        }
        for (const card of grid.children) {
            if (moved.has(card.dataset.file)) {
                card.remove();
            }
        }
        bulkReport.replaceChildren(list);
        bulkReport.hidden = false;
        const counts = `${completed} completed · ${failed} failed · ${remaining} remaining`;
        setMessage(bulkStatus, [counts, blocked.length ? `${blocked.length} blocked` : '', move.message].filter(Boolean).join(' · '));
        updateBulkSelection();
        if (completed) {
            markViewStale();
        }
    }

    function reportBulkError(error) {
        if (!inactive) {
            const message = error instanceof TypeError ? 'Could not reach the library. Try again.' : error.message;
            setMessage(bulkReview.open ? byId('bulk-review-counts') : bulkStatus, message);
        }
    }

    bulkTools.hidden = !grid.children.length && bulkUndo.hidden && !bulkRecovery.children.length;
    bulkToggle.addEventListener('click', () => {
        selecting = !selecting;
        if (!selecting) {
            bulkSelection.clear();
        }
        updateBulkSelection();
    });
    byId('bulk-loaded').addEventListener('click', () => {
        for (const card of grid.children) {
            bulkSelection.add(card.dataset.file);
        }
        updateBulkSelection();
    });
    grid.addEventListener('change', event => {
        if (!event.target.matches('[data-bulk-select]')) {
            return;
        }
        const path = event.target.closest('[data-file]').dataset.file;
        if (event.target.checked) {
            bulkSelection.add(path);
        } else {
            bulkSelection.delete(path);
        }
        updateBulkSelection();
    });
    bulkAction.addEventListener('change', () => {
        byId('bulk-tag-field').hidden = !bulkAction.value.endsWith('-tags');
        byId('bulk-folder-field').hidden = bulkAction.value !== 'move';
    });
    bulkForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (bulkBusy || !bulkSelection.size) {
            return;
        }
        const request = {
            operation: bulkAction.value,
            paths: [...bulkSelection],
            tags: byId('bulk-tags').value.split(/\r?\n/).map(tag => tag.trim()).filter(Boolean),
            destination: byId('bulk-folder').value,
        };
        const actionLabel = bulkAction.selectedOptions[0].textContent;
        setMessage(bulkStatus, 'Preparing preview…');
        try {
            const preview = await bulkRequest('bulk-preview', request);
            if (preview) {
                setMessage(bulkStatus, '');
                showBulkPreview(preview, request, actionLabel);
            }
        } catch (error) {
            reportBulkError(error);
        }
    });
    byId('bulk-close').addEventListener('click', () => bulkReview.close());
    bulkReview.addEventListener('cancel', event => {
        if (bulkBusy) {
            event.preventDefault();
        }
    });
    bulkReview.addEventListener('close', () => {
        bulkPreview = null;
        byId('bulk-preview').focus();
    });
    bulkApply.addEventListener('click', async () => {
        if (bulkBusy || !bulkPreview?.canApply) {
            return;
        }
        const { request, actionLabel, preview } = bulkPreview;
        try {
            const result = await bulkRequest('bulk-apply', { ...request, token: preview.token });
            if (!result) {
                return;
            }
            if (result.preview) {
                showBulkPreview(result.preview, request, actionLabel, preview);
                return;
            }
            bulkReview.close();
            if (result.move) {
                showBulkMove(result.move, preview.rows.filter(row => row.status === 'blocked'));
            } else {
                updateBulkCards(result);
                const { changed, unchanged } = result.counts;
                setMessage(bulkStatus, `${changed} changed · ${unchanged} unchanged`);
            }
        } catch (error) {
            reportBulkError(error);
        }
    });
    bulkUndo.addEventListener('click', async () => {
        setMessage(bulkStatus, 'Undoing bulk edit…');
        try {
            const result = await bulkRequest('bulk-undo', { token: bulkUndo.dataset.token });
            if (result) {
                updateBulkCards(result);
                setMessage(bulkStatus, `${result.undone} changes undone · ${result.skipped} skipped`);
            }
        } catch (error) {
            reportBulkError(error);
        }
    });
    bulkRecovery.addEventListener('click', async event => {
        const button = event.target.closest('[data-bulk-resume]');
        if (!button || bulkBusy) {
            return;
        }
        setMessage(bulkStatus, 'Resuming move batch…');
        try {
            const result = await bulkRequest('bulk-resume', { token: button.dataset.bulkResume });
            if (result) {
                showBulkMove(result.move);
            }
        } catch (error) {
            reportBulkError(error);
        }
    });

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
