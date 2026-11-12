<?php

if (!isset($libraryDirectory)) {
    http_response_code(503);
    exit('Start MediaManager with the command in the README.');
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function galleryUrl(array $filters, array $changes = []): string
{
    $parameters = array_filter(array_replace($filters, $changes), static fn ($value) => $value !== null);
    return $parameters ? '/?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '/';
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

    $tags = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $tags)), static fn ($tag) => $tag !== ''));
    saveMediaMetadata($directory, $file, $favorite === '1', $rating === '' ? null : (int) $rating, $tags, $checkFile);
    return $file;
}

$filters = galleryFilters($_GET);
try {
    $error = recoverMediaRelocation($libraryDirectory);
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
    $error = $exception->getMessage();
    http_response_code(409);
}
if ($error === null && isset($filters['random']) && !isset($filters['seed'])) {
    header('Location: ' . galleryUrl($filters, ['seed' => (string) random_int(1, 999999999)]), true, 302);
    return;
}

[$files, $folders, $page, $pageCount, $annotations, $tags] = loadMediaGallery($libraryDirectory, $filters);
$currentUrl = galleryUrl($filters);
$resetOrder = ['random' => null, 'seed' => null, 'newest' => null, 'oldest' => null, 'page' => null];
$notice = match ($_GET['notice'] ?? '') {
    'moved' => 'File moved or renamed.',
    'mark' => 'File marked for deletion.',
    'unmark' => 'Deletion mark cleared.',
    default => null,
};
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediaManager</title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/video-js.css" rel="stylesheet">
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
                <?php foreach (array_intersect_key($filters, array_flip(['folder', 'random', 'seed', 'newest', 'oldest', 'favorites', 'marked'])) as $key => $value) { ?>
                    <input type="hidden" name="<?= escapeHtml($key) ?>" value="<?= escapeHtml($value) ?>">
                <?php } ?>
                <input class="form-control flex-fill w-auto" type="search" name="query" placeholder="Search filenames" aria-label="Search filenames" value="<?= escapeHtml($filters['query'] ?? '') ?>">
                <input class="form-control w-auto" type="search" name="tag" list="library-tags" placeholder="Filter by tag" aria-label="Filter by tag" value="<?= escapeHtml($filters['tag'] ?? '') ?>">
                <datalist id="library-tags">
                    <?php foreach ($tags as $tag) { ?>
                        <option value="<?= escapeHtml($tag) ?>"></option>
                    <?php } ?>
                </datalist>
                <select class="form-select w-auto" name="rating" aria-label="Filter by rating">
                    <option value="">Any rating</option>
                    <?php for ($stars = 1; $stars <= 5; $stars++) { ?>
                        <option value="<?= $stars ?>" <?= ($filters['rating'] ?? '') === (string) $stars ? 'selected' : '' ?>><?= $stars ?> <?= $stars === 1 ? 'star' : 'stars' ?></option>
                    <?php } ?>
                </select>
                <button class="btn btn-outline-light" type="submit">Search</button>
            </form>
            <?php $favoritesOnly = isset($filters['favorites']); ?>
            <a class="btn btn-outline-warning <?= $favoritesOnly ? 'active' : '' ?>" <?= $favoritesOnly ? 'aria-current="true"' : '' ?> href="<?= escapeHtml(galleryUrl($filters, ['favorites' => $favoritesOnly ? null : '1', 'page' => null])) ?>">
                <?= $favoritesOnly ? 'Show all media' : 'Favorites' ?>
            </a>
            <?php $markedOnly = isset($filters['marked']); ?>
            <a class="btn btn-outline-danger <?= $markedOnly ? 'active' : '' ?>" <?= $markedOnly ? 'aria-current="true"' : '' ?> href="<?= escapeHtml(galleryUrl($filters, ['marked' => $markedOnly ? null : '1', 'page' => null])) ?>">
                <?= $markedOnly ? 'Show all statuses' : 'Marked for deletion' ?>
            </a>
        </div>
    </header>
    <main class="container pb-4">
        <?php if ($error !== null) { ?>
            <p class="alert alert-danger" role="alert"><?= escapeHtml($error) ?></p>
        <?php } elseif ($notice !== null) { ?>
            <p class="alert alert-success" role="status"><?= escapeHtml($notice) ?></p>
        <?php } ?>
        <?php if (!$files) { ?>
            <p class="text-body-secondary">No media matches this view.</p>
        <?php } else { ?>
            <datalist id="move-folders">
                <?php foreach (gatherMediaFolders($libraryDirectory, true) as $folder) { ?>
                    <option value="<?= escapeHtml($folder) ?>"></option>
                <?php } ?>
            </datalist>
        <?php } ?>
        <div class="row g-3">
            <?php foreach ($files as $file) { ?>
                <?php
                $item = $annotations[$file] ?? ['favorite' => false, 'rating' => null, 'marked_for_deletion' => false, 'tags' => []];
                $mediaUrl = '/media?file=' . rawurlencode($file);
                $id = 'media-' . hash('sha256', $file);
                $folder = dirname($file);
                ?>
                <div class="col-md-6 col-lg-4" id="<?= $id ?>">
                    <article class="card">
                        <div class="card-body">
                            <p class="text-break mb-2"><?= escapeHtml($file) ?></p>
                            <?php if (mediaType($file) === 'image/gif') { ?>
                                <img style="max-height: 240px;" class="img-fluid" loading="lazy" src="<?= escapeHtml($mediaUrl) ?>" alt="<?= escapeHtml(basename($file)) ?>">
                            <?php } else { ?>
                                <video class="video-js" controls preload="none" data-setup='{"fluid": true}'>
                                    <source src="<?= escapeHtml($mediaUrl) ?>" type="<?= escapeHtml(mediaType($file)) ?>">
                                    <p class="vjs-no-js">Use a browser with HTML5 video playback.</p>
                                </video>
                            <?php } ?>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <?php if ($item['marked_for_deletion']) { ?>
                                    <span class="badge text-bg-danger">Marked for deletion</span>
                                <?php } ?>
                                <?php if ($item['favorite']) { ?>
                                    <span class="badge text-bg-warning">Favorite</span>
                                <?php } ?>
                                <?php if ($item['rating'] !== null) { ?>
                                    <span class="badge text-bg-secondary" role="img" aria-label="<?= $item['rating'] ?> out of 5 stars"><?= str_repeat('★', $item['rating']) ?></span>
                                <?php } ?>
                                <?php foreach ($item['tags'] as $tag) { ?>
                                    <a class="badge text-bg-secondary text-decoration-none" href="<?= escapeHtml(galleryUrl($filters, ['tag' => $tag, 'page' => null])) ?>"><?= escapeHtml($tag) ?></a>
                                <?php } ?>
                            </div>
                            <details class="mt-2">
                                <summary>Edit details</summary>
                                <form class="mt-2" action="<?= escapeHtml($currentUrl) ?>" method="post">
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
                                    <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="<?= $item['marked_for_deletion'] ? 'unmark' : 'mark' ?>">
                                        <?= $item['marked_for_deletion'] ? 'Clear deletion mark' : 'Mark for deletion' ?>
                                    </button>
                                </form>
                            </details>
                        </div>
                    </article>
                </div>
            <?php } ?>
        </div>
        <?php if ($pageCount > 1) { ?>
            <nav class="mt-3" aria-label="Media pages">
                <ul class="pagination flex-wrap justify-content-center">
                    <?php for ($i = 1; $i <= $pageCount; $i++) { ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                            <a class="page-link" <?= $page === $i ? 'aria-current="page"' : '' ?> href="<?= escapeHtml(galleryUrl($filters, ['page' => (string) $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php } ?>
                </ul>
            </nav>
        <?php } ?>
    </main>
    <script src="/assets/bootstrap.bundle.min.js"></script>
    <script src="/assets/video.min.js"></script>
</body>
</html>
