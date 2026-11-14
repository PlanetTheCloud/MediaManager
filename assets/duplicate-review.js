(() => {
    const byId = id => document.getElementById(id);
    const review = byId('duplicate-review');
    const scan = byId('duplicate-scan');
    const scanStatus = byId('duplicate-scan-status');
    const report = byId('duplicate-report');
    const results = byId('duplicate-results');
    const groups = byId('duplicate-group');
    const previews = Array.from(review.querySelectorAll('[data-duplicate-preview]'));
    const form = byId('duplicate-form');
    const choices = byId('duplicate-choices');
    const files = byId('duplicate-files');
    const apply = byId('duplicate-apply');
    const undo = byId('duplicate-undo');
    const editStatus = byId('duplicate-edit-status');
    let scanResult = null;
    let undoToken = null;
    let pendingApply = null;
    let busy = false;
    let unavailable = false;
    let probe = null;

    function element(tag, className, text = '') {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    }

    function currentGroup() {
        return scanResult?.groups[groups.value];
    }

    function stopPreview(panel) {
        const stage = panel.querySelector('[data-media]');
        const video = stage.querySelector('video');
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
        }
        stage.replaceChildren();
        panel.querySelector('[data-media-status]').textContent = '';
    }

    function checkUnavailable(response) {
        if (response.headers.get('X-MediaManager-Error') !== 'library-unavailable') {
            return false;
        }
        if (!unavailable) {
            unavailable = true;
            scanResult = undoToken = pendingApply = null;
            previews.forEach(stopPreview);
            const heading = element('h1', 'h4', 'The media library is unavailable.');
            heading.tabIndex = -1;
            const back = element('a', 'btn btn-outline-light mt-2', 'Back to gallery');
            back.href = review.dataset.gallery;
            review.replaceChildren(heading, back);
            heading.focus();
        }
        return true;
    }

    function probeLibrary() {
        if (!probe) {
            probe = fetch(location.href, { method: 'HEAD', cache: 'no-store' })
                .then(checkUnavailable, () => false)
                .finally(() => { probe = null; });
        }
        return probe;
    }

    function updateControls() {
        const stale = currentGroup()?.stale;
        const keeper = form.querySelector('input[name=keeper]:checked');
        for (const mark of form.querySelectorAll('input[name=mark]')) {
            if (mark.value === keeper?.value) {
                mark.checked = false;
            }
            mark.disabled = mark.value === keeper?.value || mark.dataset.marked === 'true';
            const annotated = mark.closest('li').dataset.annotated === 'true';
            mark.closest('li').classList.toggle('border-warning', mark.checked && annotated);
            mark.closest('li').querySelector('[data-metadata-warning]').hidden = !(mark.checked && annotated);
        }
        scan.disabled = groups.disabled = undo.disabled = busy;
        choices.disabled = busy || Boolean(stale);
        for (const panel of previews) {
            panel.querySelector('select').disabled = busy || Boolean(stale);
        }
        apply.disabled = choices.disabled || !keeper || !form.querySelector('input[name=mark]:checked');
        undo.hidden = !undoToken;
    }

    async function request(action, payload) {
        const body = new URLSearchParams({ action });
        if (payload) {
            body.set('payload', JSON.stringify(payload));
        }
        const response = await fetch(location.href, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body,
        });
        if (checkUnavailable(response) || unavailable) {
            return null;
        }
        const result = await response.json();
        if (!response.ok) {
            throw Object.assign(new Error(result.message || 'The duplicate review action could not be completed.'), { code: result.error });
        }
        return result;
    }

    async function runAction(status, message, action) {
        if (busy || unavailable) {
            return;
        }
        busy = true;
        status.textContent = message;
        updateControls();
        try {
            await action();
        } catch (error) {
            status.textContent = error.message;
            if (error.code === 'scan-stale' && currentGroup()) {
                currentGroup().stale = true;
                pendingApply = null;
                previews.forEach(stopPreview);
            }
        } finally {
            busy = false;
            if (!unavailable) {
                updateControls();
            }
        }
    }

    function addReportList(label, paths) {
        if (!paths.length) {
            return;
        }
        const details = element('details', 'mb-3');
        const list = element('ul', 'mt-2');
        for (const path of paths) {
            list.append(element('li', 'text-break', path));
        }
        details.append(element('summary', '', `${label} (${paths.length})`), list);
        report.append(details);
    }

    function renderPreview(panel) {
        stopPreview(panel);
        const group = currentGroup();
        if (group.stale) {
            return;
        }
        const file = group.files[panel.querySelector('select').value];
        const url = '/media?' + new URLSearchParams({ file: file.path });
        const stage = panel.querySelector('[data-media]');
        const status = panel.querySelector('[data-media-status]');
        const failed = async event => {
            const media = event.currentTarget;
            if (media.getAttribute('src') && !(await probeLibrary()) && stage.contains(media)) {
                status.textContent = 'This preview is unavailable.';
            }
        };
        if (file.type === 'image/gif') {
            const image = document.createElement('img');
            image.alt = file.path;
            image.addEventListener('error', failed);
            image.src = url;
            stage.append(image);
        } else {
            const play = element('button', 'btn btn-outline-light', 'Play video');
            play.type = 'button';
            play.addEventListener('click', () => {
                const video = document.createElement('video');
                video.controls = true;
                video.playsInline = true;
                video.setAttribute('aria-label', file.path);
                video.addEventListener('error', failed);
                video.src = url;
                stage.replaceChildren(video);
                video.focus();
                video.play().catch(() => {});
            });
            stage.append(play);
        }
    }

    function updatePreviewOptions() {
        const [left, right] = previews.map(panel => panel.querySelector('select'));
        for (const [select, other] of [[left, right], [right, left]]) {
            for (const option of select.options) {
                option.disabled = option.value === other.value;
            }
        }
    }

    function fileChoice(name, index, label, disabled) {
        const input = document.createElement('input');
        input.className = 'form-check-input me-1';
        input.type = name === 'keeper' ? 'radio' : 'checkbox';
        input.name = name;
        input.value = index;
        input.disabled = disabled;
        const wrapper = element('label', 'me-3', label);
        wrapper.prepend(input);
        return wrapper;
    }

    function renderFiles() {
        const group = currentGroup();
        const linkCounts = new Map();
        for (const file of group.files) {
            linkCounts.set(file.copy, (linkCounts.get(file.copy) ?? 0) + 1);
        }
        const rows = document.createDocumentFragment();
        group.files.forEach((file, index) => {
            const metadata = file.metadata;
            const tags = [...metadata.tags, ...metadata.inherited_tags];
            const marked = metadata.marked_for_deletion;
            const row = element('li', 'list-group-item');
            row.dataset.annotated = Boolean(tags.length || metadata.rating || metadata.favorite);
            const details = [
                `${group.size.toLocaleString()} bytes`,
                linkCounts.get(file.copy) > 1 ? `Hard-linked paths · file ${file.copy}` : '',
                marked ? 'Marked for deletion' : 'Unmarked',
                metadata.favorite ? 'Favorite' : 'Not a favorite',
                metadata.rating === null ? 'Unrated' : `Rating ${metadata.rating}/5`,
                tags.length ? tags.join(', ') : 'No tags',
            ];
            const keeper = fileChoice('keeper', index, 'Keep', marked);
            keeper.firstChild.setAttribute('aria-label', `Keep ${file.path}`);
            const mark = fileChoice('mark', index, 'Mark', marked);
            mark.firstChild.setAttribute('aria-label', `Mark ${file.path}`);
            mark.firstChild.dataset.marked = marked;
            const warning = element('span', 'text-warning small', 'This copy has saved details.');
            warning.setAttribute('data-metadata-warning', '');
            warning.hidden = true;
            row.append(
                element('strong', 'd-block text-break', file.path),
                element('p', 'small text-body-secondary mb-2 text-break', details.filter(Boolean).join(' · ')),
                keeper, mark, warning,
            );
            rows.append(row);
        });
        files.replaceChildren(rows);
    }

    function renderGroup() {
        const group = currentGroup();
        pendingApply = null;
        editStatus.textContent = group.stale ? 'This group changed. Scan again before applying marks.' : '';
        const options = document.createDocumentFragment();
        group.files.forEach((file, i) => options.append(new Option(file.path, i)));
        for (const [index, panel] of previews.entries()) {
            const select = panel.querySelector('select');
            select.replaceChildren(options.cloneNode(true));
            select.value = index === 0 ? 0 : group.files.findIndex(file => file.copy !== group.files[0].copy);
            renderPreview(panel);
        }
        updatePreviewOptions();
        renderFiles();
        updateControls();
    }

    scan.addEventListener('click', () => runAction(scanStatus, 'Scanning for duplicates…', async () => {
        scanResult = pendingApply = null;
        previews.forEach(stopPreview);
        report.replaceChildren();
        groups.replaceChildren();
        files.replaceChildren();
        editStatus.textContent = '';
        results.hidden = true;
        const result = await request('scan');
        if (!result) {
            return;
        }
        scanResult = result;
        const count = result.groups.length;
        const summary = count === 0 ? 'No duplicate groups found.' : `${count} duplicate ${count === 1 ? 'group' : 'groups'} found.`;
        scanStatus.textContent = result.issues.length ? `Scan incomplete. ${summary}` : summary;
        addReportList('Unreadable or changed paths', result.issues.map(issue => `${issue.path} · ${issue.reason}`));
        addReportList('Skipped paths', result.skipped);
        scan.textContent = 'Scan again';
        if (count) {
            result.groups.forEach((group, i) => groups.add(new Option(
                `${group.files[0].path} · ${group.copies} independent copies`, i,
            )));
            results.hidden = false;
            renderGroup();
        }
    }));

    groups.addEventListener('change', renderGroup);
    for (const panel of previews) {
        panel.querySelector('select').addEventListener('change', () => {
            updatePreviewOptions();
            renderPreview(panel);
        });
    }
    form.addEventListener('change', () => {
        pendingApply = null;
        updateControls();
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (apply.disabled) {
            return;
        }
        const group = currentGroup();
        const selection = new FormData(form);
        const pick = index => {
            const file = group.files[index];
            return { path: file.path, snapshot: file.snapshot, marked: file.metadata.marked_for_deletion };
        };
        pendingApply ??= {
            scopeSnapshot: scanResult.scopeSnapshot,
            digest: group.digest,
            keeper: pick(selection.get('keeper')),
            selected: selection.getAll('mark').map(pick),
            nonce: crypto.randomUUID().replaceAll('-', ''),
        };
        runAction(editStatus, 'Verifying the selected copies…', async () => {
            const result = await request('apply', pendingApply);
            if (result) {
                const marked = new Set(result.paths);
                group.files.forEach(file => {
                    if (marked.has(file.path)) {
                        file.metadata.marked_for_deletion = true;
                    }
                });
                pendingApply = null;
                undoToken = result.undo;
                renderFiles();
                editStatus.textContent = `${result.counts.changed} ${result.counts.changed === 1 ? 'copy' : 'copies'} marked for deletion.`;
            }
        });
    });
    undo.addEventListener('click', () => runAction(editStatus, 'Undoing marks…', async () => {
        const result = await request('undo', { token: undoToken });
        if (result) {
            undoToken = pendingApply = null;
            const undone = new Set(result.paths);
            for (const group of scanResult?.groups ?? []) {
                group.files.forEach(file => {
                    if (undone.has(file.path)) {
                        file.metadata.marked_for_deletion = false;
                    }
                });
            }
            if (currentGroup()) {
                renderFiles();
            }
            editStatus.textContent = `${result.undone} marks undone · ${result.skipped} skipped.`;
        }
    }));
    window.addEventListener('pageshow', event => {
        if (event.persisted && !unavailable) {
            probeLibrary();
        }
    });
})();
