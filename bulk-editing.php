<?php

class BulkPreviewChanged extends DomainException
{
    public function __construct(public array $preview)
    {
        parent::__construct('The selection or its details changed. Preview the batch again.');
    }
}

function initializeBulkEditing(PDO $database): void
{
    $database->exec(<<<'SQL'
        CREATE TABLE bulk_receipts (
            token TEXT PRIMARY KEY,
            result TEXT NOT NULL
        );
        CREATE TABLE bulk_undo (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            token TEXT NOT NULL
        );
        CREATE TABLE bulk_undo_fields (
            id INTEGER PRIMARY KEY,
            media_id INTEGER NOT NULL REFERENCES media_metadata(id),
            tag_id INTEGER REFERENCES tags(id) ON DELETE SET NULL,
            before_value INTEGER NOT NULL,
            touched INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX bulk_undo_media ON bulk_undo_fields (media_id, tag_id);
        CREATE INDEX bulk_undo_tag ON bulk_undo_fields (tag_id);
        CREATE TRIGGER bulk_watch_tag_add AFTER INSERT ON media_tags BEGIN
            UPDATE bulk_undo_fields SET touched = 1 WHERE media_id = NEW.media_id AND tag_id = NEW.tag_id;
        END;
        CREATE TRIGGER bulk_watch_tag_remove AFTER DELETE ON media_tags BEGIN
            UPDATE bulk_undo_fields SET touched = 1 WHERE media_id = OLD.media_id AND tag_id = OLD.tag_id;
        END;
        CREATE TRIGGER bulk_watch_mark AFTER UPDATE OF marked_for_deletion ON media_metadata
            WHEN NEW.marked_for_deletion <> OLD.marked_for_deletion BEGIN
            UPDATE bulk_undo_fields SET touched = 1 WHERE media_id = NEW.id AND tag_id IS NULL;
        END;

        CREATE TABLE bulk_move_items (
            id INTEGER PRIMARY KEY,
            batch TEXT NOT NULL REFERENCES bulk_receipts(token),
            source TEXT NOT NULL,
            destination TEXT NOT NULL,
            identity TEXT NOT NULL,
            folders TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT 'remaining' CHECK (state IN ('remaining', 'completed', 'failed')),
            error TEXT
        );
        CREATE INDEX bulk_move_batch ON bulk_move_items (batch, id);
        ALTER TABLE media_relocation ADD COLUMN bulk_item INTEGER REFERENCES bulk_move_items(id);
        SQL
    );
}

function bulkOperation(array $input): array
{
    $operation = $input['operation'] ?? null;
    $paths = $input['paths'] ?? null;
    if (!in_array($operation, ['add-tags', 'remove-tags', 'mark', 'unmark', 'move'], true)
        || !is_array($paths) || !array_is_list($paths) || !$paths) {
        throw new InvalidArgumentException('Select files and choose a bulk action.');
    }
    foreach ($paths as $path) {
        if (!is_string($path)) {
            throw new InvalidArgumentException('Invalid selected path.');
        }
        validateMediaMetadataPath($path);
    }
    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);
    $tags = [];
    if ($operation === 'add-tags' || $operation === 'remove-tags') {
        $tags = normalizeMediaMetadataTags(is_array($input['tags'] ?? null) ? $input['tags'] : []);
        if (!$tags) {
            throw new InvalidArgumentException('Enter at least one tag.');
        }
    }
    $destination = $operation === 'move' ? ($input['destination'] ?? null) : '';
    if (!is_string($destination)) {
        throw new InvalidArgumentException('Choose a destination folder.');
    }
    return compact('operation', 'paths', 'tags', 'destination');
}

function bulkFolderIdentity(string $directory, string $folder): array
{
    clearstatcache(true);
    $path = resolveLibraryPath($directory, $folder);
    $stat = $path === null || !externalMoveScanDirectory($path) || isInPublicDocumentRoot($path) ? false : @stat($path);
    if ($stat === false) {
        throw new DomainException('The folder is unavailable.');
    }
    return ['device' => $stat['dev'], 'inode' => $stat['ino']];
}

function bulkSourceIdentity(string $directory, string $scope, string $path): array
{
    if ($path === $scope || !inMediaScanScope($scope, $path)) {
        throw new DomainException('The file is outside the active folder.');
    }
    $source = requireLibraryMediaFile($directory, $path);
    $identity = mediaFileIdentity($source);
    if ($identity === null || $identity['inode'] === 0 || !is_readable($source)) {
        throw new DomainException('The file identity could not be verified.');
    }
    return $identity;
}

function previewBulkEdit(?PDO $database, string $directory, string $scope, array $request, string $nonce): array
{
    ['operation' => $operation, 'paths' => $paths, 'tags' => $tags, 'destination' => $destination] = $request;
    $version = $database === null ? 0 : getMediaMetadataSchemaVersion($database);
    $catalog = $version && $tags ? readTagRelationships($database, $version) : ['lookup' => [], 'names' => [], 'parents' => []];
    $requested = [];
    foreach ($tags as $name) {
        $id = $catalog['lookup'][strtolower($name)] ?? null;
        $requested[$id ?? 'new:' . strtolower($name)] = $id === null ? $name : $catalog['names'][$id];
    }
    $folderError = null;
    $folders = ['scopePath' => $scope];
    try {
        $folders['scope'] = bulkFolderIdentity($directory, $scope);
        if ($operation === 'move') {
            $folders['destination'] = bulkFolderIdentity($directory, $destination);
        }
    } catch (DomainException $error) {
        $folderError = $error->getMessage();
    }
    if ($database !== null && pendingMediaRelocation($database) !== null) {
        $folderError = 'A file move is pending. Resume it or reload the gallery.';
    }

    $counts = ['changed' => 0, 'unchanged' => 0, 'blocked' => 0];
    $rows = $ancestors = [];
    $duplicates = $operation === 'move' ? array_count_values(array_map('bulkMoveFilenameKey', $paths)) : [];
    $assignments = $version && $tags ? $database->prepare('SELECT tag_id FROM media_tags WHERE '
        . ($version < 3 ? 'media_path' : 'media_id') . ' = ? ORDER BY tag_id') : null;
    $mark = $version >= 2 && in_array($operation, ['mark', 'unmark'], true)
        ? $database->prepare('SELECT marked_for_deletion FROM media_metadata WHERE path = ?') : null;
    foreach ($paths as $path) {
        $row = ['path' => $path, 'status' => 'unchanged', 'tags' => [], 'inherited' => []];
        $identity = $record = $state = null;
        if ($operation === 'move') {
            $row['destination'] = $destination === '' ? basename($path) : $destination . '/' . basename($path);
        }
        try {
            if ($folderError !== null) {
                throw new DomainException($folderError);
            }
            $identity = bulkSourceIdentity($directory, $scope, $path);
            $record = $version >= 3 ? mediaRecordAtPath($database, $path) : null;
            if ($record !== null && $record['inode'] !== null && !sameMediaFile($identity, $record)) {
                throw new DomainException('This path belongs to another saved file. Scan for moves to reconnect its details.');
            }
            if ($operation === 'move') {
                if ($duplicates[bulkMoveFilenameKey($path)] > 1) {
                    throw new DomainException('Multiple selected files have this filename.');
                }
                if ($row['destination'] !== $path) {
                    checkBulkMoveDestination($database, $version, $directory, $path, $row['destination'], $identity, $folders['destination']);
                    $row['status'] = 'changed';
                }
            } elseif ($tags) {
                $direct = [];
                if ($assignments !== null && ($record !== null || $version < 3)) {
                    $assignments->execute([$record['id'] ?? $path]);
                    $direct = array_fill_keys($assignments->fetchAll(PDO::FETCH_COLUMN), true);
                }
                $adding = $operation === 'add-tags';
                $changed = $adding ? array_diff_key($requested, $direct) : array_intersect_key($requested, $direct);
                $after = $adding ? $direct + $changed : array_diff_key($direct, $changed);
                $known = array_intersect_key($after, $catalog['names']);
                $effective = effectiveMediaTagIds($known, $catalog['parents'], $ancestors);
                $state = [$requested, array_keys($direct), array_keys($effective)];
                if ($adding && count($after) > MEDIA_METADATA_MAX_TAGS && $changed) {
                    throw new DomainException('Adding these tags would exceed the per-file tag limit.');
                }
                $row['tags'] = array_values($changed);
                if (!$adding) {
                    $row['inherited'] = array_values(array_intersect_key($requested, $effective));
                }
                $row['status'] = $changed ? 'changed' : 'unchanged';
            } else {
                $state = false;
                if ($mark !== null) {
                    $mark->execute([$path]);
                    $state = (bool) $mark->fetchColumn();
                    $mark->closeCursor();
                }
                $row['status'] = $state === ($operation === 'mark') ? 'unchanged' : 'changed';
            }
        } catch (DomainException $error) {
            $row['status'] = 'blocked';
            $row['reason'] = $error->getMessage();
        }
        $row['fileVersion'] = tagChangeToken([$identity, $folders]);
        $row['detailsVersion'] = tagChangeToken([$record['id'] ?? null, $state]);
        $row['fingerprint'] = tagChangeToken($row);
        $rows[] = $row + ['identity' => $identity];
        $counts[$row['status']]++;
    }
    $token = $nonce . '.' . tagChangeToken([$nonce, $directory, $scope, $request, array_column($rows, 'fingerprint')]);
    return compact('token', 'rows', 'counts', 'folders') + ['tags' => array_values($requested)];
}

function readBulkPreview(string $directory, string $scope, array $request): array
{
    $database = openMediaMetadataDatabase($directory);
    $database?->beginTransaction();
    $preview = previewBulkEdit($database, $directory, $scope, $request, bin2hex(random_bytes(16)));
    $database?->commit();
    return $preview;
}

function publicBulkPreview(array $preview): array
{
    unset($preview['folders']);
    foreach ($preview['rows'] as &$row) {
        unset($row['identity']);
    }
    return $preview;
}

function bulkReceipt(PDO $database, string $token): ?array
{
    if (getMediaMetadataSchemaVersion($database) < 6) {
        return null;
    }
    $statement = $database->prepare('SELECT result FROM bulk_receipts WHERE token = ?');
    $statement->execute([$token]);
    $result = $statement->fetchColumn();
    return $result === false ? null : json_decode($result, true, flags: JSON_THROW_ON_ERROR);
}

function latestBulkUndo(?PDO $database): ?string
{
    return $database !== null && getMediaMetadataSchemaVersion($database) >= 6
        ? ($database->query('SELECT token FROM bulk_undo WHERE id = 1')->fetchColumn() ?: null) : null;
}

function applyBulkEdit(string $directory, string $scope, array $request, string $token): array
{
    if (!preg_match('/\A([0-9a-f]{32})\.[0-9a-f]{64}\z/', $token, $parts)) {
        throw new InvalidArgumentException('Preview the bulk action before applying it.');
    }
    $preview = $receipt = null;
    return writeMediaMetadata($directory,
        static function (PDO $database) use ($directory, $request, $token, &$preview, &$receipt): array {
            if ($receipt !== null) {
                return $receipt + ['repeated' => true];
            }
            return commitBulkEdit($database, $directory, $request['operation'], $preview, $token);
        },
        static function (PDO $database) use ($directory, $scope, $request, $token, $parts, &$preview, &$receipt): void {
            $receipt = bulkReceipt($database, $token);
            if ($receipt !== null) {
                return;
            }
            // Check the snapshot under the write lock, before even migrating a legacy database.
            $preview = previewBulkEdit($database, $directory, $scope, $request, $parts[1]);
            if (!hash_equals($preview['token'], $token)) {
                throw new BulkPreviewChanged(publicBulkPreview($preview));
            }
            if ($preview['counts']['blocked'] && ($request['operation'] !== 'move' || !$preview['counts']['changed'])) {
                throw new DomainException('Review the blocked files and preview the selection again.');
            }
        }
    );
}

function commitBulkEdit(PDO $database, string $directory, string $operation, array $preview, string $token): array
{
    $rows = array_values(array_filter($preview['rows'], static fn ($row) => $row['status'] === 'changed'));
    $result = ['operation' => $operation, 'counts' => $preview['counts'], 'paths' => array_column($rows, 'path')];
    if ($operation === 'move' && $rows) {
        $result['batch'] = $token;
    } elseif ($rows) {
        applyBulkMetadata($database, $directory, $operation, $rows, $token);
    }
    $database->prepare('INSERT INTO bulk_receipts (token, result) VALUES (?, ?)')
        ->execute([$token, json_encode($result, JSON_THROW_ON_ERROR)]);
    if (isset($result['batch'])) {
        saveBulkMoveItems($database, $token, $rows, $preview['folders']);
    }
    return $result;
}

function clearBulkUndo(PDO $database): array
{
    $fields = $database->query('
        SELECT bulk_undo_fields.*, media_metadata.path, media_metadata.device, media_metadata.inode
        FROM bulk_undo_fields JOIN media_metadata ON media_metadata.id = bulk_undo_fields.media_id
    ')->fetchAll();
    $database->exec('DELETE FROM bulk_undo_fields; DELETE FROM bulk_undo;');
    return $fields;
}

function applyBulkMetadata(PDO $database, string $directory, string $operation, array $rows, string $token): void
{
    $old = clearBulkUndo($database);
    $database->prepare('INSERT INTO bulk_undo VALUES (1, ?)')->execute([$token]);
    $create = $database->prepare('INSERT INTO media_metadata (path) VALUES (?)');
    $mark = $database->prepare('UPDATE media_metadata SET marked_for_deletion = ? WHERE id = ?');
    $track = $database->prepare('INSERT INTO bulk_undo_fields (media_id, tag_id, before_value) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        $path = $row['path'];
        $identity = mediaAnnotationIdentity($database, $directory, $path, static fn () => requireLibraryMediaFile($directory, $path));
        if ($identity !== $row['identity']) {
            throw new DomainException('A selected file changed during the edit. Preview the batch again.');
        }
        $record = mediaRecordAtPath($database, $path);
        if ($record === null) {
            $create->execute([$path]);
        }
        $id = $record['id'] ?? (int) $database->lastInsertId();
        recordMediaIdentity($database, $id, $path, $identity);
        if ($row['tags']) {
            $tags = resolveTagIds($database, $row['tags']);
            $add = $operation === 'add-tags';
            changeDirectMediaTags($database, $id, $add ? $tags : [], $add ? [] : $tags);
            foreach ($tags as $tag) {
                $track->execute([$id, $tag, (int) !$add]);
            }
        } else {
            $marked = $operation === 'mark';
            $mark->execute([(int) $marked, $id]);
            $track->execute([$id, null, (int) !$marked]);
        }
    }
    foreach (array_unique(array_column($old, 'path')) as $path) {
        removeEmptyMediaMetadata($database, $path);
    }
    removeUnusedTags($database);
}

function undoBulkEdit(string $directory, string $token): array
{
    return writeMediaMetadata($directory, static function (PDO $database) use ($directory, $token): array {
        requireNoPendingMediaRelocation($database);
        if ($token !== latestBulkUndo($database)) {
            throw new DomainException('The latest bulk edit changed. Refresh before using Undo.');
        }
        $fields = clearBulkUndo($database);
        $mark = $database->prepare('UPDATE media_metadata SET marked_for_deletion = ? WHERE id = ?');
        $paths = $verified = [];
        $undone = $skipped = 0;
        foreach ($fields as $field) {
            ['path' => $path, 'media_id' => $id, 'tag_id' => $tag, 'before_value' => $before] = $field;
            clearstatcache(true);
            $verified[$id] ??= hasMediaFileIdentity(resolveLibraryPath($directory, $path), $field);
            if ($field['touched'] || !$verified[$id]) {
                $skipped++;
                continue;
            }
            if ($tag === null) {
                $mark->execute([$before, $id]);
            } else {
                changeDirectMediaTags($database, $id, $before ? [$tag] : [], $before ? [] : [$tag]);
            }
            $paths[] = $path;
            $undone++;
        }
        foreach (array_unique(array_column($fields, 'path')) as $path) {
            removeEmptyMediaMetadata($database, $path);
        }
        removeUnusedTags($database);
        return ['paths' => array_values(array_unique($paths)), 'undone' => $undone, 'skipped' => $skipped];
    });
}
