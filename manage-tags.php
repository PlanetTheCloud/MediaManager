<?php

function tagManagerUrl(array $parameters = []): string
{
    $query = http_build_query(array_filter($parameters, static fn ($value) => $value !== '' && $value !== null), '', '&', PHP_QUERY_RFC3986);
    return '/tags' . ($query === '' ? '' : '?' . $query);
}

function tagManagerText(array $input, string $name, string $default = ''): string
{
    $value = $input[$name] ?? $default;
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid tag manager form.');
    }
    return $value;
}

function tagManagerId(array $input, string $name): ?int
{
    $value = tagManagerText($input, $name);
    if ($value === '') {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Choose a saved tag or rule.');
    }
    return $id;
}

function tagManagerRuleDraft(array $form): array
{
    $names = preg_split('/\r\n|\r|\n/', tagManagerText($form, 'tags'));
    $names = array_values(array_filter($names, static fn ($name) => trim($name) !== ''));
    return tagRuleDraft(tagManagerText($form, 'folder'), tagManagerText($form, 'value'), $names);
}

function readTagManagerPage(string $directory): array
{
    $database = openMediaMetadataDatabase($directory);
    $database?->beginTransaction();
    $catalog = tagManagerCatalog($database);
    $rules = readTagRules($database);
    $runs = tagRuleUndoRuns($database);
    $database?->commit();
    return [$catalog, $rules, $runs];
}

function applyTagManagerChange(string $directory, string $action, int $tag, array $form): int
{
    ['target' => $target, 'value' => $value, 'token' => $token, 'keep' => $keep, 'source' => $source] = $form;
    $merge = null;
    return writeMediaMetadata($directory, static function (PDO $database) use ($action, $tag, $source, $target, $value, $keep, &$merge): int {
        if ($action === 'merge') {
            applyTagMerge($database, $merge);
            $tag = $target;
        } else {
            $catalog = readTagRelationships($database, MEDIA_METADATA_SCHEMA_VERSION);
            if ($action === 'rename') {
                renameCatalogTag($database, $catalog, $tag, $source, $value, $keep);
            } else {
                if (requireCatalogTag($catalog, $tag) !== $source) {
                    throw new DomainException('The tag changed. Open it again to review its current name.');
                }
                editCatalogTagLinks($database, $catalog, $tag, $action, $value);
            }
        }
        advanceTagCatalogRevision($database);
        return $tag;
    }, static function (PDO $database) use ($action, $tag, $target, $keep, $token, &$merge): void {
        if ($action !== 'merge') {
            return;
        }
        $catalog = readTagRelationships($database, getMediaMetadataSchemaVersion($database));
        $merge = previewTagMerge($database, $catalog, $tag, $target ?? 0, $keep);
        if ($merge['token'] !== $token) {
            throw new DomainException('The tags or saved assignments changed. Preview the merge again.');
        }
    });
}

function handleTagManager(string $directory): void
{
    $folder = tagManagerText($_GET, 'folder');
    $query = trim(tagManagerText($_GET, 'q'));
    $tag = tagManagerId($_GET, 'tag');
    $ruleId = tagManagerId($_GET, 'rule');
    $context = ['folder' => $folder, 'q' => $query, 'tag' => $tag, 'rule' => $ruleId];
    $url = tagManagerUrl($context);
    $notice = match ($_GET['notice'] ?? '') {
        'tag' => 'Tag changes saved.',
        'rule' => 'Rule saved.',
        'deleted' => 'Rule deleted.',
        default => null,
    };
    $error = $rename = $merge = $rulePreview = $previewedRule = $draft = null;
    $messageSection = match ($_GET['notice'] ?? '') {
        'rule', 'deleted' => 'rules',
        'tag' => 'tag-editor',
        default => null,
    };
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = tagManagerText($_POST, 'action');
            $messageSection = str_contains($action, '-rule') ? 'rules' : 'tag-editor';
            $target = tagManagerId($_POST, 'target');
            $value = tagManagerText($_POST, 'value');
            $token = tagManagerText($_POST, 'token');
            $keep = isset($_POST['keep']);
            $source = tagManagerText($_POST, 'source');
            if (in_array($action, ['preview-rename', 'preview-merge', 'preview-rule'], true)) {
                $database = openMediaMetadataDatabase($directory) ?? throw new DomainException('There are no saved tags or rules yet.');
                $database->beginTransaction();
                if ($action === 'preview-rule') {
                    $previewedRule = readTagRules($database)[$ruleId] ?? throw new DomainException('The rule is no longer available.');
                    $rulePreview = previewTagRule($database, $directory, $previewedRule);
                } else {
                    $catalog = readTagRelationships($database, getMediaMetadataSchemaVersion($database));
                    if ($action === 'preview-rename') {
                        $rename = previewTagRename($catalog, $tag ?? 0, $value);
                    } else {
                        $merge = previewTagMerge($database, $catalog, $tag ?? 0, $target ?? 0, $keep);
                    }
                }
                $database->commit();
            } elseif (in_array($action, ['rename', 'merge', 'add-alias', 'remove-alias', 'add-parent', 'remove-parent'], true)) {
                $selected = applyTagManagerChange($directory, $action, $tag ?? 0, compact('target', 'value', 'token', 'keep', 'source'));
                header('Location: ' . tagManagerUrl(array_replace($context, ['tag' => $selected, 'notice' => 'tag'])) . '#tag-editor', true, 303);
                return;
            } elseif ($action === 'preview-rule-draft') {
                $draft = tagManagerRuleDraft($_POST);
                $database = openMediaMetadataDatabase($directory);
                $database?->beginTransaction();
                $rulePreview = previewDraftTagRule($database, $directory, $draft);
                $database?->commit();
            } elseif ($action === 'save-rule') {
                $draft = tagManagerRuleDraft($_POST);
                $saved = saveTagRule($directory, $ruleId, $draft, $token);
                header('Location: ' . tagManagerUrl(array_replace($context, ['rule' => $saved, 'notice' => 'rule'])) . '#rules', true, 303);
                return;
            } elseif ($action === 'delete-rule') {
                writeMediaMetadata($directory, static function (PDO $database) use ($ruleId, $token): void {
                    requireTagRuleVersion($database, $ruleId ?? 0, $token);
                    $database->prepare('DELETE FROM tag_rules WHERE id = ?')->execute([$ruleId]);
                    removeUnusedTags($database);
                });
                header('Location: ' . tagManagerUrl(array_replace($context, ['rule' => null, 'notice' => 'deleted'])) . '#rules', true, 303);
                return;
            } elseif ($action === 'apply-rule') {
                $result = applyTagRule($directory, $ruleId ?? 0, $token);
                $rulePreview = $result['preview'] ?? null;
                $previewedRule = $result['rule'] ?? null;
                if ($rulePreview !== null) {
                    $error = 'The rule or its scan changed. Review this updated preview.';
                    http_response_code(409);
                } else {
                    $notice = $result['added'] . ' assignments added.';
                }
            } elseif ($action === 'undo-rule') {
                $run = tagManagerId($_POST, 'run');
                $removed = writeMediaMetadata($directory, static fn (PDO $database) => undoTagRuleRun($database, $run ?? 0));
                $notice = $removed . ' rule assignments removed.';
            } else {
                throw new InvalidArgumentException('Choose a tag manager action.');
            }
        }
    } catch (InvalidArgumentException | DomainException $exception) {
        $error = $exception->getMessage();
        http_response_code($exception instanceof DomainException ? 409 : 400);
    }

    [$catalog, $rules, $runs] = readTagManagerPage($directory);
    $selectedRule = $previewedRule ?? $rules[$ruleId] ?? null;
    if ($messageSection === 'tag-editor' && !isset($catalog['names'][$tag])) {
        $messageSection = null;
    }
    ?>
    <!doctype html>
    <html lang="en" data-bs-theme="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Manage tags · MediaManager</title>
        <link rel="stylesheet" href="/assets/bootstrap.min.css">
    </head>
    <body>
        <main class="container py-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h1 class="h3 mb-0">Manage tags</h1>
                <nav class="d-flex gap-2" aria-label="Tag management">
                    <a class="btn btn-outline-secondary" href="<?= escapeHtml($folder === '' ? '/' : '/?' . http_build_query(['folder' => $folder])) ?>">Back to gallery</a>
                    <a class="btn btn-outline-light" href="#rules">Tagging rules</a>
                </nav>
            </div>
            <?php if ($messageSection === null) { renderTagManagerMessage($error, $notice); } ?>
            <div class="row g-4">
                <section class="col-lg-7" aria-labelledby="tag-catalog">
                    <h2 class="h5" id="tag-catalog">Library tags</h2>
                    <form method="get" action="/tags" role="search" class="mb-3">
                        <input type="hidden" name="folder" value="<?= escapeHtml($folder) ?>">
                        <label for="tag-query" class="form-label">Tag name or namespace</label>
                        <div class="input-group">
                            <input id="tag-query" name="q" type="search" class="form-control" value="<?= escapeHtml($query) ?>">
                            <button class="btn btn-outline-light">Search tags</button>
                            <?php if ($query !== '') { ?>
                                <a class="btn btn-outline-secondary" href="<?= escapeHtml(tagManagerUrl(['folder' => $folder])) ?>">Clear</a>
                            <?php } ?>
                        </div>
                    </form>
                    <?php renderTagTable($catalog, $context); ?>
                </section>
                <?php if (isset($catalog['names'][$tag])) { ?>
                    <section class="col-lg-5" id="tag-editor" aria-labelledby="tag-editor-heading">
                        <?php if ($messageSection === 'tag-editor') { renderTagManagerMessage($error, $notice); } ?>
                        <?php renderTagEditor($catalog, $tag, $url, $rename, $merge); ?>
                    </section>
                <?php } ?>
            </div>
            <hr class="my-4">
            <section id="rules" aria-labelledby="rules-heading">
                <?php if ($messageSection === 'rules') { renderTagManagerMessage($error, $notice); } ?>
                <?php renderTagRules($context, $rules, $runs, $selectedRule, $rulePreview, $draft); ?>
            </section>
        </main>
    </body>
    </html>
    <?php
}

function renderTagManagerMessage(?string $error, ?string $notice): void
{
    if ($error !== null) {
        ?><p class="alert alert-warning" role="alert"><?= escapeHtml($error) ?></p><?php
    } elseif ($notice !== null) {
        ?><p class="alert alert-success" role="status"><?= escapeHtml($notice) ?></p><?php
    }
}

function renderTagTable(array $catalog, array $context): void
{
    $shown = 0;
    ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th scope="col">Tag</th><th scope="col">Namespace</th><th scope="col">Saved assignments</th><th scope="col">Effective clips</th><th scope="col">Edit</th></tr></thead>
            <tbody>
                <?php foreach ($catalog['names'] as $id => $name) { ?>
                    <?php
                    $aliases = $catalog['aliases'][$id] ?? [];
                    $query = $context['q'];
                    if ($query !== '' && !array_filter([$name, ...$aliases], static fn ($spelling) => stripos($spelling, $query) !== false)) {
                        continue;
                    }
                    $shown++;
                    $namespace = str_contains($name, ':') ? explode(':', $name, 2)[0] : '—';
                    ?>
                    <tr>
                        <th scope="row">
                            <a class="link-light text-break" href="/?tag=<?= rawurlencode($name) ?>"><?= escapeHtml($name) ?></a>
                            <?php if ($aliases) { ?>
                                <div class="small fw-normal text-body-secondary">Aliases · <?= escapeHtml(implode(', ', $aliases)) ?></div>
                            <?php } ?>
                        </th>
                        <td><?= escapeHtml($namespace) ?></td>
                        <td><?= $catalog['saved'][$id] ?? 0 ?></td>
                        <td><?= $catalog['effective'][$id] ?? 0 ?></td>
                        <td><a class="btn btn-sm btn-outline-light" aria-label="Edit <?= escapeHtml($name) ?>" href="<?= escapeHtml(tagManagerUrl(array_replace($context, ['tag' => $id]))) ?>#tag-editor">Edit</a></td>
                    </tr>
                <?php } ?>
                <?php if ($shown === 0) { ?>
                    <tr><td colspan="5"><?= $catalog['names'] ? 'No matching tags.' : 'No saved tags yet.' ?></td></tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <p class="small text-body-secondary">Counts include catalog records for files that are temporarily missing or awaiting reconnection.</p>
    <?php
}

function renderTagChoices(array $names, int $exclude): void
{
    echo '<option value="">Choose a tag</option>';
    foreach ($names as $id => $name) {
        if ($id !== $exclude) {
            ?><option value="<?= $id ?>"><?= escapeHtml($name) ?></option><?php
        }
    }
}

function renderKeepAlias(string $id, bool $checked = true): void
{
    ?>
    <div class="form-check my-2">
        <input class="form-check-input" id="<?= $id ?>" type="checkbox" name="keep" value="1" <?= $checked ? 'checked' : '' ?>>
        <label class="form-check-label" for="<?= $id ?>">Keep the old spelling as an alias</label>
    </div>
    <?php
}

function renderTagEditor(array $catalog, int $id, string $url, ?array $rename, ?array $merge): void
{
    $name = $catalog['names'][$id];
    $parents = $catalog['parents'][$id] ?? [];
    $aliases = $catalog['aliases'][$id] ?? [];
    ?>
    <h2 id="tag-editor-heading" class="h5 text-break"><?= escapeHtml($name) ?></h2>
    <div class="border rounded p-3 mb-3">
        <h3 class="h6">Rename or merge</h3>
        <form method="post" action="<?= escapeHtml($url) ?>#tag-editor" class="mb-3">
            <label for="rename-tag" class="form-label">New name</label>
            <div class="input-group">
                <input id="rename-tag" name="value" class="form-control" value="<?= escapeHtml($rename['name'] ?? $name) ?>" required>
                <button class="btn btn-outline-light" name="action" value="preview-rename">Preview rename</button>
            </div>
        </form>
        <?php if ($rename !== null) { ?>
            <form method="post" action="<?= escapeHtml($url) ?>#tag-editor" class="alert alert-secondary">
                <input type="hidden" name="source" value="<?= escapeHtml($rename['source']) ?>">
                <input type="hidden" name="value" value="<?= escapeHtml($rename['name']) ?>">
                <?php if ($rename['conflict'] !== null) { ?>
                    <input type="hidden" name="target" value="<?= $rename['conflict'] ?>">
                    <p><strong><?= escapeHtml($rename['name']) ?></strong> already belongs to <?= escapeHtml($catalog['names'][$rename['conflict']] ?? 'another tag') ?>.</p>
                    <input type="hidden" name="keep" value="1">
                    <button class="btn btn-outline-light" name="action" value="preview-merge">Preview merge</button>
                <?php } else { ?>
                    <p>Rename <strong><?= escapeHtml($rename['source']) ?></strong> to <strong><?= escapeHtml($rename['name']) ?></strong>. This updates saved assignments across all folders, including records for temporarily missing files.</p>
                    <?php if (strcasecmp($rename['source'], $rename['name']) !== 0) { renderKeepAlias('rename-alias'); } ?>
                    <button class="btn btn-primary" name="action" value="rename">Apply rename</button>
                <?php } ?>
            </form>
        <?php } ?>
        <?php if (count($catalog['names']) > 1) { ?>
            <form method="post" action="<?= escapeHtml($url) ?>#tag-editor">
                <label for="merge-target" class="form-label">Merge into</label>
                <select id="merge-target" class="form-select" name="target" required>
                    <?php renderTagChoices($catalog['names'], $id); ?>
                </select>
                <?php renderKeepAlias('merge-alias', $merge['keep'] ?? true); ?>
                <button class="btn btn-outline-light" name="action" value="preview-merge">Preview merge</button>
            </form>
        <?php } ?>
        <?php if ($merge !== null) { renderTagMergePreview($url, $merge); } ?>
    </div>
    <div class="border rounded p-3 mb-3">
        <h3 class="h6">Aliases</h3>
        <?php foreach ($aliases as $alias) { renderTagLinkRemoval($url, $name, 'remove-alias', $alias, $alias); } ?>
        <form method="post" action="<?= escapeHtml($url) ?>#tag-editor">
            <input type="hidden" name="source" value="<?= escapeHtml($name) ?>">
            <label for="alias-name" class="form-label">Alias name</label>
            <div class="input-group">
                <input id="alias-name" name="value" class="form-control" required>
                <button class="btn btn-outline-light" name="action" value="add-alias">Add alias</button>
            </div>
        </form>
    </div>
    <div class="border rounded p-3">
        <h3 class="h6">Parent tags</h3>
        <p class="small text-body-secondary">Clips with this tag also match its parents.</p>
        <?php foreach ($parents as $parent) {
            renderTagLinkRemoval($url, $name, 'remove-parent', (string) $parent, $catalog['names'][$parent]);
        } ?>
        <?php if (count($catalog['names']) > 1) { ?>
            <form method="post" action="<?= escapeHtml($url) ?>#tag-editor">
                <input type="hidden" name="source" value="<?= escapeHtml($name) ?>">
                <label for="parent-tag" class="form-label">Parent tag</label>
                <div class="input-group">
                    <select id="parent-tag" class="form-select" name="value" required>
                        <?php renderTagChoices($catalog['names'], $id); ?>
                    </select>
                    <button class="btn btn-outline-light" name="action" value="add-parent">Add parent</button>
                </div>
            </form>
        <?php } ?>
    </div>
    <?php
}

function renderTagLinkRemoval(string $url, string $name, string $action, string $value, string $label): void
{
    ?>
    <form method="post" action="<?= escapeHtml($url) ?>#tag-editor" class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <input type="hidden" name="source" value="<?= escapeHtml($name) ?>">
        <input type="hidden" name="value" value="<?= escapeHtml($value) ?>">
        <span class="text-break"><?= escapeHtml($label) ?></span>
        <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= $action ?>" aria-label="Remove <?= escapeHtml($label) ?>">Remove</button>
    </form>
    <?php
}

function renderTagMergePreview(string $url, array $merge): void
{
    ?>
    <form method="post" action="<?= escapeHtml($url) ?>#tag-editor" class="alert alert-secondary mt-3 mb-0">
        <input type="hidden" name="target" value="<?= $merge['target'] ?>">
        <input type="hidden" name="token" value="<?= $merge['token'] ?>">
        <?php if ($merge['keep']) { ?><input type="hidden" name="keep" value="1"><?php } ?>
        <h4 class="h6">Merge <?= escapeHtml($merge['sourceName']) ?> into <?= escapeHtml($merge['targetName']) ?></h4>
        <p>This updates saved assignments across all folders, including records for temporarily missing files.</p>
        <dl class="row mb-2">
            <dt class="col-8">Assignments to move</dt><dd class="col-4"><?= $merge['moves'] ?></dd>
            <dt class="col-8">Clips with both tags</dt><dd class="col-4"><?= $merge['overlap'] ?></dd>
            <dt class="col-8">Resulting saved assignments</dt><dd class="col-4"><?= $merge['result'] ?></dd>
        </dl>
        <dl class="mb-2">
            <dt>Resulting aliases</dt><dd><?= escapeHtml(implode(', ', $merge['aliases']) ?: 'None') ?></dd>
            <dt>Resulting parents</dt><dd><?= escapeHtml(implode(', ', $merge['outgoing']) ?: 'None') ?></dd>
            <dt>Resulting children</dt><dd><?= escapeHtml(implode(', ', $merge['incoming']) ?: 'None') ?></dd>
        </dl>
        <?php if ($merge['cycle']) { ?>
            <p class="mb-0">This merge would create a self-link or parent cycle. Edit the parent links first.</p>
        <?php } else { ?>
            <button class="btn btn-primary" name="action" value="merge">Apply merge</button>
        <?php } ?>
    </form>
    <?php
}

function renderTagRules(array $context, array $rules, array $runs, ?array $selected, ?array $preview, ?array $draft): void
{
    $url = tagManagerUrl(array_replace($context, ['rule' => $selected['id'] ?? null])) . '#rules';
    $fields = $draft ?? $selected;
    ?>
    <div>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <h2 id="rules-heading" class="h5 mb-0">Tagging rules</h2>
            <?php if ($selected !== null) { ?>
                <a class="btn btn-sm btn-outline-light" href="<?= escapeHtml(tagManagerUrl(array_replace($context, ['rule' => null]))) ?>#rules">New rule</a>
            <?php } ?>
        </div>
        <div class="row g-4">
            <div class="col-lg-7">
                <?php if ($preview !== null && $fields !== null) { renderTagRulePreview($url, $fields, $preview, $draft !== null); } ?>
                <?php if ($rules) { ?>
                    <ul class="list-group mb-3">
                        <?php foreach ($rules as $rule) { ?>
                            <li class="list-group-item">
                                <a class="link-light text-break" href="<?= escapeHtml(tagManagerUrl(array_replace($context, ['rule' => $rule['id']]))) ?>#rules"><?= escapeHtml(($rule['folder'] === '' ? 'All folders' : $rule['folder']) . ' · ' . $rule['needle']) ?></a>
                                <div class="small text-body-secondary text-break"><?= escapeHtml(implode(', ', $rule['tags'])) ?></div>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
                <?php if ($runs) { ?>
                    <h3 class="h6 mt-3">Undo a rule application</h3>
                    <ul class="list-group">
                        <?php foreach ($runs as $run) { ?>
                            <li class="list-group-item">
                                <form method="post" action="<?= escapeHtml($url) ?>" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                    <input type="hidden" name="run" value="<?= $run['id'] ?>">
                                    <div class="text-break">
                                        <?= escapeHtml($run['description']) ?>
                                        <div class="small text-body-secondary"><?= $run['additions'] ?> removable assignments · <?= escapeHtml($run['applied_at']) ?> UTC</div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-warning" name="action" value="undo-rule">Undo application</button>
                                </form>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
            <div class="col-lg-5">
                <div class="border rounded p-3">
                    <h3 class="h6"><?= $selected === null ? 'New rule' : 'Edit rule' ?></h3>
                    <form method="post" action="<?= escapeHtml($url) ?>">
                        <?php if ($selected !== null) { ?><input type="hidden" name="token" value="<?= tagChangeToken($selected) ?>"><?php } ?>
                        <label for="rule-folder" class="form-label">Folder</label>
                        <input id="rule-folder" class="form-control mb-1" name="folder" aria-describedby="rule-scope" value="<?= escapeHtml($fields['folder'] ?? $context['folder']) ?>">
                        <p class="form-text" id="rule-scope">Use a library-relative path such as <code>trains/steam</code>. Leave blank for all folders. Subfolders are included.</p>
                        <label for="rule-needle" class="form-label">Filename contains</label>
                        <input id="rule-needle" class="form-control mb-1" name="value" aria-describedby="rule-matching" value="<?= escapeHtml($fields['needle'] ?? '') ?>" required>
                        <p class="form-text" id="rule-matching">Matches literal text in the filename, including the extension. Matching is case-sensitive.</p>
                        <label for="rule-tags" class="form-label">Tags to add (one per line)</label>
                        <textarea id="rule-tags" class="form-control mb-3" name="tags" rows="3" required><?= escapeHtml(implode("\n", $fields['tags'] ?? [])) ?></textarea>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-outline-light" name="action" value="save-rule">Save rule</button>
                            <button class="btn btn-outline-light" name="action" value="preview-rule-draft">Preview draft</button>
                        </div>
                    </form>
                    <?php if ($selected !== null) { ?>
                        <form method="post" action="<?= escapeHtml($url) ?>" class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                            <input type="hidden" name="token" value="<?= tagChangeToken($selected) ?>">
                            <button class="btn btn-primary" name="action" value="preview-rule">Preview saved rule</button>
                            <button class="btn btn-outline-danger" name="action" value="delete-rule">Delete rule</button>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderTagRulePreview(string $url, array $rule, array $preview, bool $draft): void
{
    ?>
    <div class="border rounded p-3 mb-3">
        <h3 class="h6">Rule preview</h3>
        <p class="text-break"><?= escapeHtml($rule['folder'] === '' ? 'All folders' : $rule['folder']) ?> and subfolders · filenames containing <strong><?= escapeHtml($rule['needle']) ?></strong></p>
        <p><?= count($preview['matches']) ?> matching files · <?= $preview['additions'] ?> assignments to add · <?= $preview['present'] ?> already present</p>
        <?php if ($preview['unreadable']) { ?>
            <p class="text-warning">The scan was incomplete. Restore access and preview again.</p>
            <ul><?php foreach ($preview['unreadable'] as $path) { ?><li class="text-break"><?= escapeHtml($path) ?></li><?php } ?></ul>
        <?php } ?>
        <?php if ($preview['blocked']) { ?>
            <p class="text-warning">These paths belong to other saved files. Reconnect them with Scan for moves before applying the rule.</p>
            <ul><?php foreach ($preview['blocked'] as $path) { ?><li class="text-break"><?= escapeHtml($path) ?></li><?php } ?></ul>
        <?php } ?>
        <?php if ($preview['skipped']) { ?>
            <p class="small text-body-secondary"><?= count($preview['skipped']) ?> linked or excluded paths skipped.</p>
        <?php } ?>
        <?php if ($preview['matches']) { ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th scope="col">File</th><th scope="col">Tags to add</th><th scope="col">Already assigned</th></tr></thead>
                    <tbody>
                        <?php foreach ($preview['matches'] as $path => $match) { ?>
                            <tr>
                                <th class="text-break fw-normal" scope="row"><a class="link-light" href="/media?file=<?= rawurlencode($path) ?>"><?= escapeHtml($path) ?></a></th>
                                <td class="text-break"><?= escapeHtml($match['add'] ? implode(', ', $match['add']) : '—') ?></td>
                                <td class="text-break"><?= escapeHtml($match['existing'] ? implode(', ', $match['existing']) : '—') ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
        <?php if ($draft) { ?>
            <p class="small mb-0">Save the draft, then preview the saved rule to apply it.</p>
        <?php } else { ?>
            <form method="post" action="<?= escapeHtml($url) ?>">
                <input type="hidden" name="token" value="<?= $preview['token'] ?>">
                <button class="btn btn-primary" name="action" value="apply-rule" <?= $preview['unreadable'] || $preview['blocked'] || !$preview['additions'] ? 'disabled' : '' ?>>Apply rule</button>
            </form>
        <?php } ?>
    </div>
    <?php
}
