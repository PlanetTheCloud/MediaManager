<?php

if (!isset($libraryDirectory)) {
    http_response_code(503);
    exit('Start MediaManager with the command in the README.');
}

function gallerySearchUrl(array $filters, array $terms): string
{
    $changes = array_fill_keys(GALLERY_LEGACY_SEARCH_FIELDS, null);
    $changes['page'] = null;
    $changes['query'] = $terms ? formatGallerySearch($terms) : null;
    return galleryUrl($filters, $changes);
}

function galleryTermsWithInclusion(array $terms, string $field, string $value): array
{
    $inclusion = ['field' => $field, 'value' => $value, 'negated' => false];
    $exclusion = array_replace($inclusion, ['negated' => true]);
    $keys = [gallerySearchTermKey($inclusion), gallerySearchTermKey($exclusion)];
    $terms = array_filter($terms, static fn ($term) => !in_array(gallerySearchTermKey($term), $keys, true));
    $terms[] = $inclusion;
    return $terms;
}

function galleryTagUrl(array $filters, array $terms, string $tag): string
{
    return gallerySearchUrl($filters, galleryTermsWithInclusion($terms, 'tag', $tag));
}

function galleryStatusShortcut(array $filters, array $terms, string $status): array
{
    $withoutStatus = array_filter($terms, static fn ($term) => $term !== ['field' => 'is', 'value' => $status, 'negated' => false]);
    $active = count($withoutStatus) !== count($terms);
    $next = $active ? $withoutStatus : galleryTermsWithInclusion($terms, 'is', $status);
    return [$active, gallerySearchUrl($filters, $next)];
}

function renderGalleryCards(array $files, array $annotations, array $filters, array $terms): void
{
    $currentUrl = galleryUrl($filters);
    ?>
    <?php foreach ($files as $file) { ?>
        <?php
        $item = $annotations[$file] ?? EMPTY_MEDIA_METADATA;
        $mediaUrl = '/media?file=' . rawurlencode($file);
        $id = 'media-' . hash('sha256', $file);
        $folder = dirname($file);
        ?>
        <div class="col-sm-6 col-lg-4" id="<?= $id ?>" data-file="<?= escapeHtml($file) ?>">
            <article class="card h-100">
                <div class="card-body">
                    <label class="form-check mb-2" data-bulk-control hidden>
                        <input type="checkbox" class="form-check-input" data-bulk-select aria-label="Select <?= escapeHtml($file) ?>"> Select clip
                    </label>
                    <p class="text-break mb-2"><?= escapeHtml($file) ?></p>
                    <?php $isGif = mediaType($file) === 'image/gif'; ?>
                    <a class="gallery-tile" href="<?= escapeHtml($mediaUrl) ?>" data-open-media="<?= $isGif ? 'image' : 'video' ?>" aria-label="Open <?= escapeHtml($file) ?>">
                        <?php if ($isGif) { ?>
                            <img loading="lazy" decoding="async" src="<?= escapeHtml($mediaUrl) ?>" alt="">
                        <?php } else { ?>
                            <span class="gallery-play" aria-hidden="true">▶</span>
                        <?php } ?>
                    </a>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <span class="badge text-bg-danger" data-mark-badge <?= $item['marked_for_deletion'] ? '' : 'hidden' ?>>Marked for deletion</span>
                        <span class="badge text-bg-warning" data-favorite-badge <?= $item['favorite'] ? '' : 'hidden' ?>>Favorite</span>
                        <span class="badge text-bg-secondary" data-rating-badge role="img" aria-label="<?= $item['rating'] ?? 0 ?> out of 5 stars" <?= $item['rating'] === null ? 'hidden' : '' ?>><?= str_repeat('★', $item['rating'] ?? 0) ?></span>
                        <span class="d-inline-flex flex-wrap gap-1" data-tag-badges>
                            <?php foreach ($item['tags'] as $tag) { ?>
                                <a class="badge text-bg-secondary text-decoration-none" href="<?= escapeHtml(galleryTagUrl($filters, $terms, $tag)) ?>"><?= escapeHtml($tag) ?></a>
                            <?php } ?>
                        </span>
                        <span data-inherited-tags hidden>
                            <?php foreach ($item['inherited_tags'] as $tag) { ?>
                                <span><?= escapeHtml($tag) ?></span>
                            <?php } ?>
                        </span>
                    </div>
                    <details class="mt-2">
                        <summary>Edit details</summary>
                        <form class="mt-2" data-card-details action="<?= escapeHtml($currentUrl) ?>" method="post">
                            <input type="hidden" name="file" value="<?= escapeHtml($file) ?>">
                            <label class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" name="favorite" value="1" <?= $item['favorite'] ? 'checked' : '' ?>> Favorite
                            </label>
                            <div class="mb-2">
                                <label class="form-label" for="<?= $id ?>-rating">Rating</label>
                                <select class="form-select form-select-sm" id="<?= $id ?>-rating" name="rating">
                                    <option value="">Unrated</option>
                                    <?php for ($stars = 1; $stars <= 5; $stars++) { ?>
                                        <option value="<?= $stars ?>" <?= $item['rating'] === $stars ? 'selected' : '' ?>><?= str_repeat('★', $stars) ?> <?= $stars ?> of 5</option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="<?= $id ?>-tags">Tags (one per line)</label>
                                <textarea class="form-control form-control-sm" id="<?= $id ?>-tags" name="tags" rows="2" placeholder="subject:train"><?= escapeHtml(implode("\n", $item['tags'])) ?></textarea>
                            </div>
                            <button class="btn btn-sm btn-primary" type="submit">Save</button>
                        </form>
                    </details>
                    <details class="mt-2">
                        <summary>Manage file</summary>
                        <form class="mt-2" action="<?= escapeHtml($currentUrl) ?>" method="post">
                            <input type="hidden" name="file" value="<?= escapeHtml($file) ?>">
                            <div class="mb-2">
                                <label class="form-label" for="<?= $id ?>-folder">Destination folder</label>
                                <input class="form-control form-control-sm" id="<?= $id ?>-folder" name="folder" list="move-folders" value="<?= escapeHtml($folder === '.' ? '' : $folder) ?>" placeholder="Library root">
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="<?= $id ?>-name">Filename</label>
                                <input class="form-control form-control-sm" id="<?= $id ?>-name" name="name" value="<?= escapeHtml(basename($file)) ?>" required>
                            </div>
                            <button class="btn btn-sm btn-outline-light" type="submit" name="action" value="move">Move or rename</button>
                        </form>
                        <form class="mt-2" action="<?= escapeHtml($currentUrl) ?>" method="post">
                            <input type="hidden" name="file" value="<?= escapeHtml($file) ?>">
                            <button class="btn btn-sm btn-outline-danger" data-card-mark type="submit" name="action" value="<?= $item['marked_for_deletion'] ? 'unmark' : 'mark' ?>">
                                <?= $item['marked_for_deletion'] ? 'Clear deletion mark' : 'Mark for deletion' ?>
                            </button>
                        </form>
                    </details>
                </div>
            </article>
        </div>
    <?php } ?>
    <?php
}

function parseGalleryTags(string $tags): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $tags)), static fn ($tag) => $tag !== ''));
}

function handleGalleryBulkEdit(string $directory, array $filters, array $terms): void
{
    try {
        $input = json_decode($_POST['payload'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            throw new InvalidArgumentException('Invalid bulk action.');
        }
        $action = $_POST['action'];
        $scope = $filters['folder'] ?? '';
        $token = $input['token'] ?? '';
        if (!is_string($token)) {
            throw new InvalidArgumentException('Invalid bulk operation token.');
        }
        if ($action === 'bulk-preview') {
            sendGalleryJson(publicBulkPreview(readBulkPreview($directory, $scope, bulkOperation($input))));
            return;
        }
        if ($action === 'bulk-resume') {
            sendGalleryJson(['move' => runBulkMove($directory, $token)]);
            return;
        }
        $result = $action === 'bulk-apply'
            ? applyBulkEdit($directory, $scope, bulkOperation($input), $token)
            : undoBulkEdit($directory, $token);
        if (isset($result['batch'])) {
            $result['move'] = isset($result['repeated'])
                ? bulkMoveStatus(openMediaMetadataDatabase($directory), $result['batch'])
                : runBulkMove($directory, $result['batch']);
            sendGalleryJson($result);
            return;
        }
        $paths = $result['paths'];
        $annotations = getMediaMetadata($directory, $paths);
        $updates = [];
        foreach ($paths as $path) {
            try {
                requireLibraryMediaFile($directory, $path);
            } catch (DomainException $error) {
                continue;
            }
            $item = $annotations[$path] ?? EMPTY_MEDIA_METADATA;
            $matchesView = inMediaScanScope($scope, $path) && gallerySearchMatches($terms, $path, $item);
            unset($item['tag_keys'], $item['tag_aliases']);
            $updates[] = [
                'path' => $path, 'item' => $item, 'matchesView' => $matchesView,
                'tagLinks' => array_map(static fn ($tag) => galleryTagUrl($filters, $terms, $tag), $item['tags']),
            ];
        }
        $result['updates'] = $updates;
        $result['undo'] = latestBulkUndo(openMediaMetadataDatabase($directory));
        sendGalleryJson($result);
    } catch (BulkPreviewChanged $error) {
        sendGalleryJson(['message' => $error->getMessage(), 'preview' => $error->preview], 409);
    } catch (DomainException $error) {
        stopRequest(409, $error->getMessage());
    } catch (InvalidArgumentException | JsonException $error) {
        stopRequest(400, $error->getMessage());
    }
}

function saveViewerMetadata(string $directory, array $fields): array
{
    $file = $fields['file'] ?? null;
    $value = $fields['value'] ?? null;
    if (!is_string($file) || !is_string($value)) {
        throw new InvalidArgumentException('Invalid media details.');
    }
    $changes = match ($fields['action'] ?? '') {
        'favorite' => in_array($value, ['0', '1'], true) ? ['favorite' => $value === '1'] : null,
        'rating' => in_array($value, ['', '1', '2', '3', '4', '5'], true) ? ['rating' => $value === '' ? null : (int) $value] : null,
        'tags' => ['tags' => parseGalleryTags($value)],
        default => null,
    };
    if ($changes === null) {
        throw new InvalidArgumentException('Invalid media details.');
    }
    return patchMediaMetadata($directory, $file, $changes, static fn () => requireLibraryMediaFile($directory, $file));
}

function updateGalleryFile(string $directory, array $fields): string
{
    $file = $fields['file'] ?? null;
    $action = $fields['action'] ?? 'details';
    if (!is_string($file)) {
        throw new InvalidArgumentException('Invalid media file.');
    }
    $checkFile = static fn () => requireLibraryMediaFile($directory, $file);
    if ($action === 'move') {
        if (!is_string($fields['folder'] ?? null) || !is_string($fields['name'] ?? null)) {
            throw new InvalidArgumentException('Invalid move destination.');
        }
        return moveLibraryMediaFile($directory, $file, $fields['folder'], $fields['name']);
    }
    if ($action === 'mark' || $action === 'unmark') {
        setMediaDeletionMark($directory, $file, $action === 'mark', $checkFile);
        return $file;
    }

    $favorite = $fields['favorite'] ?? '0';
    $rating = $fields['rating'] ?? null;
    $tags = $fields['tags'] ?? null;
    if ($action !== 'details' || !is_string($tags) || !in_array($favorite, ['0', '1'], true)
        || !in_array($rating, ['', '1', '2', '3', '4', '5'], true)) {
        throw new InvalidArgumentException('Invalid metadata.');
    }

    saveMediaMetadata($directory, $file, $favorite === '1', $rating === '' ? null : (int) $rating, parseGalleryTags($tags), $checkFile);
    return $file;
}

function renderExternalMoveReport(array $filters, ?array $report, ?string $error): void
{
    $scope = $filters['folder'] ?? '';
    $url = galleryUrl($filters, ['page' => null]);
    ?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scan for moves · MediaManager</title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <main class="container py-4">
        <a class="btn btn-outline-light mb-3" href="<?= escapeHtml($url) ?>">Back to gallery</a>
        <h1 class="h3">Scan for moves</h1>
        <p>Folder: <strong class="text-break"><?= escapeHtml($scope === '' ? 'All folders' : $scope) ?></strong> and its subfolders</p>
        <?php if ($error !== null) { ?>
            <p class="alert alert-danger" role="alert"><?= escapeHtml($error) ?></p>
        <?php } elseif ($report !== null) { ?>
            <?php if ($report['unreadable']) { ?>
                <p class="alert alert-warning" role="alert">The scan was incomplete. No saved details were changed.</p>
                <ul>
                    <?php foreach ($report['unreadable'] as $path) { ?>
                        <li class="text-break"><?= escapeHtml($path) ?></li>
                    <?php } ?>
                </ul>
            <?php } else { ?>
                <p role="status"><?= $report['reconnected'] ?> reconnected, <?= $report['backfilled'] ?> identities recorded, <?= count($report['review']) ?> to review.</p>
            <?php } ?>
            <?php if ($report['skipped']) { ?>
                <details class="mb-3">
                    <summary>Skipped paths (<?= count($report['skipped']) ?>)</summary>
                    <ul>
                        <?php foreach ($report['skipped'] as $path) { ?>
                            <li class="text-break"><?= escapeHtml($path) ?></li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
            <?php foreach ($report['review'] as $record) { ?>
                <?php $candidateList = 'move-candidates-' . $record['id']; ?>
                <section class="card card-body mb-3">
                    <h2 class="h6 text-break"><?= escapeHtml($record['path']) ?></h2>
                    <p><?= escapeHtml($record['reason']) ?></p>
                    <?php if ($record['candidates']) { ?>
                        <ul>
                            <?php foreach ($record['candidates'] as $path) { ?>
                                <li class="text-break"><?= escapeHtml($path) ?></li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                    <form method="post" action="<?= escapeHtml($url) ?>">
                        <input type="hidden" name="record" value="<?= $record['id'] ?>">
                        <input type="hidden" name="source" value="<?= escapeHtml($record['path']) ?>">
                        <label class="form-label" for="move-target-<?= $record['id'] ?>">File path relative to the library</label>
                        <input class="form-control mb-2" id="move-target-<?= $record['id'] ?>" name="destination" list="<?= $candidateList ?>" required>
                        <datalist id="<?= $candidateList ?>">
                            <?php foreach ($record['candidates'] as $path) { ?>
                                <option value="<?= escapeHtml($path) ?>"></option>
                            <?php } ?>
                        </datalist>
                        <button class="btn btn-outline-light" name="action" value="reconnect-external" type="submit">Reconnect</button>
                    </form>
                </section>
            <?php } ?>
        <?php } else { ?>
            <p role="status">Saved details reconnected.</p>
        <?php } ?>
        <form method="post" action="<?= escapeHtml($url) ?>">
            <button class="btn btn-outline-light" name="action" value="scan-external" type="submit">Scan again</button>
        </form>
    </main>
</body>
</html>
    <?php
}

$filters = galleryFilters($_GET);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['scan-external', 'reconnect-external'], true)) {
    $scope = $filters['folder'] ?? '';
    try {
        $report = null;
        if ($_POST['action'] === 'scan-external') {
            $report = scanExternalMediaMoves($libraryDirectory, $scope);
        } else {
            $record = filter_var($_POST['record'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($record === false || !is_string($_POST['source'] ?? null) || !is_string($_POST['destination'] ?? null)) {
                throw new InvalidArgumentException('Choose a saved record and a destination file.');
            }
            reconnectExternalMedia($libraryDirectory, $scope, $record, $_POST['source'], $_POST['destination']);
        }
        renderExternalMoveReport($filters, $report, null);
    } catch (DomainException | InvalidArgumentException $exception) {
        http_response_code($exception instanceof DomainException ? 409 : 400);
        renderExternalMoveReport($filters, null, $exception->getMessage());
    }
    return;
}
$legacyFilters = array_intersect_key($filters, array_flip(GALLERY_LEGACY_SEARCH_FIELDS));
$batchRequest = isset($_GET['batch']);
$suggestRequest = isset($_GET['suggest']);
$viewerEdit = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
$jsonRequest = $batchRequest || $suggestRequest || $viewerEdit;
$searchError = null;
try {
    $terms = gallerySearchTerms($filters);
} catch (InvalidArgumentException $exception) {
    if ($jsonRequest) {
        stopRequest(400, $exception->getMessage());
    }
    $searchError = $exception->getMessage();
    $terms = gallerySearchTerms($legacyFilters);
    http_response_code(400);
}
try {
    if ($viewerEdit && in_array($_POST['action'] ?? '', ['bulk-preview', 'bulk-apply', 'bulk-undo', 'bulk-resume'], true)) {
        if (!is_string($_POST['payload'] ?? null)) {
            throw new InvalidArgumentException('Invalid bulk action.');
        }
        handleGalleryBulkEdit($libraryDirectory, $filters, $terms);
        return;
    }
    if (isset($_GET['tag-revision']) && $_SERVER['REQUEST_METHOD'] === 'GET' && !$suggestRequest && !$batchRequest) {
        sendGalleryJson(['revision' => tagCatalogRevision($libraryDirectory)]);
        return;
    }
    $error = $searchError ?? recoverMediaRelocation($libraryDirectory);
    if ($error !== null && $jsonRequest) {
        stopRequest(409, $error);
    }
    if ($suggestRequest) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $batchRequest || $_GET['suggest'] !== 'tags') {
            throw new InvalidArgumentException('Invalid tag suggestion request.');
        }
        sendGalleryJson(['suggestions' => galleryTagSuggestions($libraryDirectory, $filters, $terms)]);
        return;
    }
    if ($batchRequest) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || !is_string($_GET['batch'])
            || (isset($filters['random']) && !isset($filters['seed']))) {
            throw new InvalidArgumentException('Invalid gallery batch request.');
        }
        try {
            [$batch, $annotations, $page, $cursor] = loadMediaBatch($libraryDirectory, $filters, $_GET['batch'], $terms);
        } catch (DomainException $exception) {
            stopRequest(409, $exception->getMessage(), 'view-changed');
        }
        ob_start();
        renderGalleryCards($batch, $annotations, array_replace($filters, ['page' => (string) $page]), $terms);
        sendGalleryJson(['html' => ob_get_clean(), 'cursor' => $cursor]);
        return;
    }
    if ($viewerEdit) {
        $item = saveViewerMetadata($libraryDirectory, $_POST);
        $matchesView = gallerySearchMatches($terms, $_POST['file'], $item);
        unset($item['tag_keys'], $item['tag_aliases']);
        sendGalleryJson([
            'item' => $item,
            'matchesView' => $matchesView,
            'tagLinks' => array_map(static fn ($tag) => galleryTagUrl($filters, $terms, $tag), $item['tags']),
        ]);
        return;
    }
    if ($error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = updateGalleryFile($libraryDirectory, $_POST);
        $changes = [];
        if (($_POST['action'] ?? '') === 'move' && $file !== $_POST['file']) {
            $folder = dirname($file);
            $changes = ['folder' => $folder === '.' ? null : $folder, 'query' => null, 'page' => null, 'notice' => 'moved'];
        } elseif (in_array($_POST['action'] ?? '', ['mark', 'unmark'], true)) {
            $changes['notice'] = $_POST['action'];
        }
        header('Location: ' . galleryUrl($filters, $changes) . '#media-' . hash('sha256', $file), true, 303);
        return;
    }
} catch (DomainException $exception) {
    if ($jsonRequest) {
        stopRequest(409, $exception->getMessage());
    }
    $error = $exception->getMessage();
    http_response_code(409);
}
if ($error === null && isset($filters['random']) && !isset($filters['seed'])) {
    header('Location: ' . galleryUrl($filters, ['seed' => (string) random_int(1, 999999999)]), true, 302);
    return;
}

[$files, $folders, $page, $pageCount, $annotations, $cursor, $tagRevision] = $searchError === null
    ? loadMediaGallery($libraryDirectory, $filters, $terms)
    : [[], gatherMediaFolders($libraryDirectory), 1, 0, [], null, ''];
$searchText = $legacyFilters && $searchError === null ? formatGallerySearch($terms) : ($filters['query'] ?? '');
$selectedFolder = $filters['folder'] ?? '';
$selectedDirectory = $selectedFolder === '' ? null : resolveLibraryPath($libraryDirectory, $selectedFolder);
$subfolders = $selectedDirectory !== null && is_dir($selectedDirectory) ? gatherMediaFolders($selectedDirectory) : [];
[$favoritesOnly, $favoritesUrl] = galleryStatusShortcut($filters, $terms, 'favorite');
[$markedOnly, $markedUrl] = galleryStatusShortcut($filters, $terms, 'marked');
$resetOrder = ['random' => null, 'seed' => null, 'newest' => null, 'oldest' => null, 'page' => null];
$notice = match ($_GET['notice'] ?? '') {
    'moved' => 'File moved or renamed.',
    'mark' => 'File marked for deletion.',
    'unmark' => 'Deletion mark cleared.',
    default => null,
};
$bulkDatabase = openMediaMetadataDatabase($libraryDirectory);
$bulkUndo = latestBulkUndo($bulkDatabase);
$bulkMoves = unfinishedBulkMoves($bulkDatabase);
unset($bulkDatabase);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediaManager</title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/gallery.css" rel="stylesheet">
</head>
<body>
    <header class="py-3 mb-3 border-bottom">
        <div class="container d-flex gap-3 align-items-center flex-wrap">
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <?= escapeHtml($filters['folder'] ?? 'All folders') ?>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, ['folder' => null, 'page' => null])) ?>">All folders</a></li>
                    <?php foreach ($folders as $folder) { ?>
                        <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, ['folder' => $folder, 'page' => null])) ?>"><?= escapeHtml($folder) ?></a></li>
                    <?php } ?>
                    <?php if ($selectedFolder !== '' && dirname($selectedFolder) !== '.') { ?>
                        <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, ['folder' => dirname($selectedFolder), 'page' => null])) ?>">Parent folder</a></li>
                    <?php } ?>
                    <?php if ($subfolders) { ?>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ($subfolders as $subfolder) { ?>
                            <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, ['folder' => $selectedFolder . '/' . $subfolder, 'page' => null])) ?>"><?= escapeHtml($subfolder) ?></a></li>
                        <?php } ?>
                    <?php } ?>
                </ul>
            </div>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Sort</button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, $resetOrder)) ?>">Filename</a></li>
                    <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, array_replace($resetOrder, ['newest' => '']))) ?>">Newest</a></li>
                    <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, array_replace($resetOrder, ['oldest' => '']))) ?>">Oldest</a></li>
                    <li><a class="dropdown-item" href="<?= escapeHtml(galleryUrl($filters, array_replace($resetOrder, ['random' => '']))) ?>">Randomize</a></li>
                </ul>
            </div>
            <form class="d-flex flex-grow-1 flex-wrap gap-2" role="search" action="/" method="get">
                <?php $searchScope = array_intersect_key($filters, array_flip(['folder', 'random', 'seed', 'newest', 'oldest'])); ?>
                <?php foreach ($searchError === null ? $searchScope : $searchScope + $legacyFilters as $key => $value) { ?>
                    <input type="hidden" name="<?= escapeHtml($key) ?>" value="<?= escapeHtml($value) ?>">
                <?php } ?>
                <div class="gallery-search flex-fill">
                    <input class="form-control" id="gallery-query" type="search" name="query" placeholder="Search filenames, tags, and ratings" aria-label="Search media" autocomplete="off" value="<?= escapeHtml($searchText) ?>" <?= $searchError !== null ? 'aria-invalid="true" aria-describedby="search-error"' : '' ?>>
                    <div class="search-suggestions" id="search-suggestions" hidden>
                        <p class="small text-body-secondary mb-1 px-2" id="search-suggestion-status" role="status"></p>
                        <ul id="search-tag-options" class="list-group list-group-flush mb-0" role="listbox" aria-label="Tags in current results"></ul>
                    </div>
                </div>
                <button class="btn btn-outline-light" type="submit">Search</button>
            </form>
            <a class="btn btn-outline-warning <?= $favoritesOnly ? 'active' : '' ?>" <?= $favoritesOnly ? 'aria-current="true"' : '' ?> href="<?= escapeHtml($favoritesUrl) ?>">
                <?= $favoritesOnly ? 'Clear favorites filter' : 'Favorites' ?>
            </a>
            <a class="btn btn-outline-danger <?= $markedOnly ? 'active' : '' ?>" <?= $markedOnly ? 'aria-current="true"' : '' ?> href="<?= escapeHtml($markedUrl) ?>">
                <?= $markedOnly ? 'Clear deletion filter' : 'Marked for deletion' ?>
            </a>
        </div>
        <div class="container d-flex flex-wrap align-items-start gap-2 mt-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= escapeHtml(tagManagerUrl(['folder' => $selectedFolder])) ?>">Manage tags</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= escapeHtml(galleryUrl($filters, [], '/duplicates')) ?>">Find duplicates</a>
            <form method="post" action="<?= escapeHtml(galleryUrl($filters, ['page' => null])) ?>">
                <button class="btn btn-sm btn-outline-secondary" name="action" value="scan-external" type="submit" aria-describedby="scan-scope">Scan for moves</button>
                <small class="text-body-secondary" id="scan-scope"><?= escapeHtml($filters['folder'] ?? 'All folders') ?> and subfolders</small>
            </form>
            <?php if ($searchError === null) { ?>
                <?php foreach ($terms as $index => $term) { ?>
                    <?php
                    $label = formatGallerySearchTerm($term);
                    $remaining = $terms;
                    unset($remaining[$index]);
                    ?>
                    <a class="btn btn-sm btn-outline-secondary text-break" href="<?= escapeHtml(gallerySearchUrl($filters, $remaining)) ?>" aria-label="Remove <?= escapeHtml($label) ?>">
                        <?= escapeHtml($label) ?> <span aria-hidden="true">×</span>
                    </a>
                <?php } ?>
            <?php } ?>
            <details class="small text-body-secondary">
                <summary>Search syntax</summary>
                <p class="mb-0">Combine terms with spaces. Use <code>-</code> to exclude and double quotes for phrases. Try <code>subject:train -status:draft is:favorite rating:&gt;=4 type:video</code> or <code>is:untagged</code>.</p>
            </details>
        </div>
    </header>
    <main class="container pb-4">
        <h1 id="gallery-heading" class="visually-hidden" tabindex="-1">Media gallery</h1>
        <?php if ($error !== null) { ?>
            <p class="alert alert-danger" role="alert" <?= $searchError !== null ? 'id="search-error"' : '' ?>><?= escapeHtml($error) ?></p>
        <?php } elseif ($notice !== null) { ?>
            <p class="alert alert-success" role="status"><?= escapeHtml($notice) ?></p>
        <?php } ?>
        <?php if (!$files && $searchError === null) { ?>
            <p class="text-body-secondary">No media matches this view.</p>
        <?php } elseif ($files) { ?>
            <datalist id="move-folders">
                <?php foreach (gatherMediaFolders($libraryDirectory, true) as $folder) { ?>
                    <option value="<?= escapeHtml($folder) ?>"></option>
                <?php } ?>
            </datalist>
        <?php } ?>
        <section id="bulk-tools" class="mb-3" aria-label="Bulk editing" hidden>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" id="bulk-toggle" class="btn btn-outline-light btn-sm" aria-pressed="false" <?= !$files ? 'hidden' : '' ?>>Select</button>
                <button type="button" id="bulk-undo" class="btn btn-outline-light btn-sm" data-token="<?= escapeHtml($bulkUndo ?? '') ?>" <?= $bulkUndo === null ? 'hidden' : '' ?>>Undo last bulk edit</button>
                <div id="bulk-recovery" class="d-flex flex-wrap gap-2">
                    <?php foreach ($bulkMoves as $batch) { ?>
                        <button type="button" class="btn btn-outline-warning btn-sm" data-bulk-resume="<?= escapeHtml($batch) ?>">Resume move batch</button>
                    <?php } ?>
                </div>
            </div>
            <form id="bulk-form" class="mt-2" hidden>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span id="bulk-count" role="status">0 selected</span>
                    <button type="button" id="bulk-loaded" class="btn btn-sm btn-outline-light">Select loaded results</button>
                    <label for="bulk-action" class="visually-hidden">Bulk action</label>
                    <select id="bulk-action" class="form-select form-select-sm w-auto">
                        <option value="add-tags">Add tags</option>
                        <option value="remove-tags">Remove tags</option>
                        <option value="mark">Mark for deletion</option>
                        <option value="unmark">Clear deletion marks</option>
                        <option value="move">Move to folder</option>
                    </select>
                    <button id="bulk-preview" class="btn btn-sm btn-primary" type="submit" disabled>Preview</button>
                </div>
                <div id="bulk-tag-field" class="mt-2">
                    <label for="bulk-tags" class="form-label">Tags (one per line)</label>
                    <textarea id="bulk-tags" class="form-control form-control-sm" rows="2"></textarea>
                </div>
                <div id="bulk-folder-field" class="mt-2" hidden>
                    <label for="bulk-folder" class="form-label">Destination folder</label>
                    <input id="bulk-folder" class="form-control form-control-sm" list="move-folders" placeholder="Library root">
                </div>
            </form>
            <p id="bulk-status" class="mt-2 mb-0" role="status" hidden></p>
            <div id="bulk-move-report" class="mt-2" hidden></div>
        </section>
        <div class="row g-3" id="gallery-grid" data-view-url="<?= escapeHtml(galleryUrl($filters)) ?>" data-cursor="<?= escapeHtml($cursor ?? '') ?>" data-tag-revision="<?= escapeHtml($tagRevision) ?>">
            <?php renderGalleryCards($files, $annotations, $filters, $terms); ?>
        </div>
        <?php if ($pageCount > 1) { ?>
            <nav class="mt-3" id="gallery-pages" aria-label="Media pages">
                <ul class="pagination flex-wrap justify-content-center">
                    <?php for ($i = 1; $i <= $pageCount; $i++) { ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                            <a class="page-link" <?= $page === $i ? 'aria-current="page"' : '' ?> href="<?= escapeHtml(galleryUrl($filters, ['page' => (string) $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php } ?>
                </ul>
            </nav>
        <?php } ?>
        <div id="gallery-more" class="text-center py-4" hidden>
            <p class="text-body-secondary mb-2" id="gallery-status" role="status"></p>
            <button type="button" class="btn btn-outline-light" id="gallery-load">Load more</button>
            <a class="btn btn-outline-light" href="<?= escapeHtml(galleryUrl($filters, ['page' => null])) ?>" id="gallery-refresh" hidden>Refresh results</a>
        </div>
    </main>
    <dialog id="bulk-review" aria-labelledby="bulk-review-title" class="rounded border p-3">
        <h2 class="h5" id="bulk-review-title" tabindex="-1"></h2>
        <p id="bulk-review-counts" role="status"></p>
        <ul id="bulk-review-files" class="list-group mb-3 overflow-auto"></ul>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" id="bulk-apply">Apply</button>
            <button type="button" class="btn btn-outline-light" id="bulk-close">Close</button>
        </div>
    </dialog>
    <dialog id="media-viewer" aria-labelledby="viewer-title">
        <div class="viewer-layout">
            <header class="viewer-toolbar">
                <h2 class="h6 text-break mb-0" id="viewer-title" tabindex="-1"></h2>
                <div class="viewer-actions">
                    <button type="button" class="btn btn-sm btn-outline-light" id="viewer-previous">Previous</button>
                    <button type="button" class="btn btn-sm btn-outline-light" id="viewer-next">Next</button>
                    <button type="button" class="btn btn-sm btn-outline-light" id="viewer-fullscreen" hidden>Fullscreen</button>
                    <button type="button" class="btn btn-sm btn-light" id="viewer-close">Close</button>
                </div>
            </header>
            <div class="viewer-stage">
                <video id="viewer-video" controls playsinline preload="metadata" hidden></video>
                <img id="viewer-image" alt="" hidden>
            </div>
            <footer class="viewer-details">
                <div class="viewer-actions">
                    <button type="button" class="btn btn-sm btn-outline-warning" id="viewer-favorite" aria-pressed="false">Favorite</button>
                    <label class="visually-hidden" for="viewer-rating">Rating</label>
                    <select class="form-select form-select-sm w-auto" id="viewer-rating">
                        <option value="">Unrated</option>
                        <?php for ($stars = 1; $stars <= 5; $stars++) { ?>
                            <option value="<?= $stars ?>"><?= str_repeat('★', $stars) ?> <?= $stars ?> of 5</option>
                        <?php } ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-light" id="viewer-edit-tags" aria-controls="viewer-tag-editor" aria-expanded="false">Edit tags</button>
                    <span id="viewer-tags" class="viewer-actions" aria-label="Tags"></span>
                </div>
                <div class="viewer-actions mt-2" id="viewer-inherited" hidden>
                    <span class="small">Inherited tags</span>
                    <span id="viewer-inherited-tags" class="viewer-actions"></span>
                </div>
                <form id="viewer-tag-editor" class="mt-2" hidden>
                    <label for="viewer-tag-input" class="form-label">Tags (one per line)</label>
                    <textarea id="viewer-tag-input" rows="3" class="form-control"></textarea>
                    <div class="viewer-actions mt-2">
                        <button type="submit" class="btn btn-sm btn-primary" id="viewer-save-tags">Save tags</button>
                        <button type="button" class="btn btn-sm btn-outline-light" id="viewer-cancel-tags">Cancel</button>
                    </div>
                </form>
                <p class="text-body-secondary mb-0" role="status" id="viewer-status" hidden></p>
                <p class="text-danger mb-0" role="alert" id="viewer-error" hidden></p>
                <a class="link-light" href="<?= escapeHtml(galleryUrl($filters, ['page' => null])) ?>" id="viewer-refresh" hidden>Refresh results</a>
                <details class="viewer-shortcuts">
                    <summary>Keyboard shortcuts</summary>
                    <p class="small mb-0">← / → Previous or next · Space Play or pause · F Favorite · 1–5 Rate · 0 Clear rating · T Edit tags · Esc Close</p>
                </details>
            </footer>
        </div>
    </dialog>
    <script src="/assets/bootstrap.bundle.min.js"></script>
    <script src="/assets/gallery.js" defer></script>
    <script src="/assets/search.js" defer></script>
</body>
</html>
