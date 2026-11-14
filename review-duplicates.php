<?php

function handleDuplicateReview(string $directory, string $method): void
{
    if ($method === 'HEAD') {
        return;
    }
    $filters = galleryFilters($_GET);
    $scope = $filters['folder'] ?? '';
    if ($method === 'POST') {
        try {
            $action = $_POST['action'] ?? null;
            if ($action === 'scan') {
                $report = scanDuplicateMedia($directory, $scope);
                // Filesystem identifiers can exceed the exact numeric range in browsers.
                $report['scopeSnapshot'] = json_encode($report['scopeSnapshot'], JSON_THROW_ON_ERROR);
                foreach ($report['groups'] as &$group) {
                    foreach ($group['files'] as &$file) {
                        $file['snapshot'] = json_encode($file['identity'], JSON_THROW_ON_ERROR);
                        unset($file['identity']);
                    }
                    unset($file);
                }
                unset($group);
                sendGalleryJson($report);
                return;
            }
            $input = is_string($_POST['payload'] ?? null)
                ? json_decode($_POST['payload'], true, flags: JSON_THROW_ON_ERROR) : null;
            if (!is_array($input)) {
                throw new InvalidArgumentException('Choose an action from the review.');
            }
            if ($action === 'apply') {
                $result = applyDuplicateMarks($directory, $scope, $input);
                $result['undo'] = latestBulkUndo(openMediaMetadataDatabase($directory)) === $result['token'] ? $result['token'] : null;
                unset($result['token']);
                sendGalleryJson($result);
                return;
            }
            if ($action === 'undo' && is_string($input['token'] ?? null)) {
                sendGalleryJson(undoBulkEdit($directory, $input['token']));
                return;
            }
            throw new InvalidArgumentException('Choose an action from the review.');
        } catch (DuplicateReviewStale $error) {
            sendGalleryJson(['message' => $error->getMessage(), 'error' => 'scan-stale'], 409);
        } catch (DomainException $error) {
            sendGalleryJson(['message' => $error->getMessage()], 409);
        } catch (InvalidArgumentException | JsonException $error) {
            sendGalleryJson(['message' => 'The scan scope or selection is invalid.'], 400);
        }
        return;
    }
    renderDuplicateReview($filters);
}

function renderDuplicateReview(array $filters): void
{
    $back = escapeHtml(galleryUrl($filters));
    ?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Find duplicates · MediaManager</title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/gallery.css" rel="stylesheet">
    <script src="/assets/duplicate-review.js" defer></script>
</head>
<body>
    <main class="container py-4" id="duplicate-review" data-gallery="<?= $back ?>">
        <header class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
            <h1 class="h3 mb-0">Find duplicates</h1>
            <a class="btn btn-outline-light btn-sm" href="<?= $back ?>">Back to gallery</a>
        </header>
        <p class="text-body-secondary">Scan <strong class="text-break"><?= escapeHtml($filters['folder'] ?? 'All folders') ?></strong> and its subfolders for byte-identical clips.</p>
        <button type="button" class="btn btn-primary" id="duplicate-scan">Scan for duplicates</button>
        <p class="mt-3 mb-2" id="duplicate-scan-status" role="status"></p>
        <div id="duplicate-report"></div>
        <section id="duplicate-results" hidden>
            <div class="mb-3">
                <label class="form-label" for="duplicate-group">Duplicate group</label>
                <select class="form-select" id="duplicate-group"></select>
            </div>
            <div class="row g-3 mb-3" aria-label="Comparison">
                <?php foreach (['left' => 'Left preview', 'right' => 'Right preview'] as $side => $label) { ?>
                    <div class="col-12 col-sm-6" data-duplicate-preview="<?= $side ?>">
                        <label class="form-label" for="duplicate-<?= $side ?>"><?= $label ?></label>
                        <select class="form-select mb-2" id="duplicate-<?= $side ?>"></select>
                        <div class="duplicate-stage rounded border" data-media></div>
                        <p class="small text-body-secondary mb-0" data-media-status role="status"></p>
                    </div>
                <?php } ?>
            </div>
            <form id="duplicate-form">
                <fieldset id="duplicate-choices">
                    <legend class="h5">Choose a keeper and the copies to mark</legend>
                    <p class="small text-body-secondary">The keeper must be unmarked. Tags, ratings, and favorites stay with each file.</p>
                    <ul class="list-group mb-3" id="duplicate-files"></ul>
                </fieldset>
                <button type="submit" class="btn btn-danger" id="duplicate-apply" disabled>Mark selected copies</button>
            </form>
        </section>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
            <p class="mb-0" id="duplicate-edit-status" role="status"></p>
            <button type="button" class="btn btn-outline-light btn-sm" id="duplicate-undo" hidden>Undo</button>
        </div>
        <noscript><p class="mt-3">Enable JavaScript to scan and review duplicates.</p></noscript>
    </main>
</body>
</html>
    <?php
}
