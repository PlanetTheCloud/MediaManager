<?php

function bulkMoveFilenameKey(string $path): string
{
    $name = basename($path);
    return PHP_OS_FAMILY === 'Windows' ? (function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name)) : $name;
}

function checkBulkMoveDestination(?PDO $database, int $version, string $directory, string $path, string $destination,
    array $identity, array $folder): void
{
    validateMediaMoveName($path, basename($destination));
    $target = mediaMoveDestination($directory, $destination);
    if (@lstat($target) !== false || is_link($target)) {
        throw new DomainException('A file or folder already uses the destination name.');
    }
    if ($version > 0) {
        $saved = $database->prepare('SELECT 1 FROM media_metadata WHERE path = ?');
        $saved->execute([$destination]);
        if ($saved->fetchColumn() !== false) {
            throw new DomainException('The destination already has saved details.');
        }
    }
    if ($identity['device'] !== $folder['device']) {
        throw new DomainException('Choose a folder on the same volume that supports hard links.');
    }
    if (!is_writable(dirname($target)) || !is_writable(dirname(requireLibraryMediaFile($directory, $path)))) {
        throw new DomainException('Both folders must allow the move.');
    }
}

function saveBulkMoveItems(PDO $database, string $token, array $rows, array $folders): void
{
    $insert = $database->prepare('INSERT INTO bulk_move_items (batch, source, destination, identity, folders) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows as $row) {
        $insert->execute([$token, $row['path'], $row['destination'], json_encode($row['identity'], JSON_THROW_ON_ERROR),
            json_encode($folders, JSON_THROW_ON_ERROR)]);
    }
}

function bulkMoveItems(PDO $database, string $batch): array
{
    $query = $database->prepare('SELECT * FROM bulk_move_items WHERE batch = ? ORDER BY id');
    $query->execute([$batch]);
    return $query->fetchAll();
}

function bulkMoveStatus(PDO $database, string $batch): array
{
    $items = bulkMoveItems($database, $batch);
    if (!$items) {
        throw new DomainException('This move batch is no longer available.');
    }
    $counts = ['completed' => 0, 'failed' => 0, 'remaining' => 0];
    $rows = [];
    foreach ($items as $item) {
        $counts[$item['state']]++;
        $rows[] = array_intersect_key($item, array_flip(['source', 'destination', 'state', 'error']));
    }
    return ['batch' => $batch, 'rows' => $rows, 'counts' => $counts];
}

function unfinishedBulkMoves(?PDO $database): array
{
    if ($database === null || getMediaMetadataSchemaVersion($database) < 6) {
        return [];
    }
    return $database->query("SELECT batch FROM bulk_move_items WHERE state <> 'completed' GROUP BY batch ORDER BY MIN(id)")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function finishBulkMoveItem(PDO $database, int $id, ?array $identity, ?string $error): void
{
    if ($error !== null) {
        $database->prepare("UPDATE bulk_move_items SET state = 'failed', error = ? WHERE id = ? AND state <> 'completed'")
            ->execute([$error, $id]);
        return;
    }
    $database->prepare("UPDATE bulk_move_items SET state = 'completed', identity = ?, error = NULL WHERE id = ?")
        ->execute([json_encode($identity, JSON_THROW_ON_ERROR), $id]);
}

function requireBulkMoveDestinationFolder(string $directory, string $destination, array $folders): array
{
    $parent = dirname($destination);
    $folder = bulkFolderIdentity($directory, $parent === '.' ? '' : $parent);
    if ($folder !== $folders['destination']) {
        throw new DomainException('The destination folder changed after preview.');
    }
    return $folder;
}

function requireBulkMoveItem(PDO $database, string $directory, array $item, string $source, string $destination): bool
{
    $query = $database->prepare('SELECT state FROM bulk_move_items WHERE id = ? AND source = ? AND destination = ?');
    $query->execute([$item['id'], $source, $destination]);
    if ($query->fetchColumn() === 'completed') {
        return false;
    }
    requireNoPendingMediaRelocation($database);
    if (bulkFolderIdentity($directory, $item['folders']['scopePath']) !== $item['folders']['scope']) {
        throw new DomainException('The source folder changed after preview.');
    }
    $folder = requireBulkMoveDestinationFolder($directory, $destination, $item['folders']);
    checkBulkMoveDestination($database, MEDIA_METADATA_SCHEMA_VERSION, $directory, $source, $destination, $item['identity'], $folder);
    return true;
}

function checkPendingBulkMoveFolder(PDO $database, string $directory, int $id, string $destination): void
{
    $query = $database->prepare('SELECT folders FROM bulk_move_items WHERE id = ?');
    $query->execute([$id]);
    requireBulkMoveDestinationFolder($directory, $destination, json_decode($query->fetchColumn(), true, flags: JSON_THROW_ON_ERROR));
}

function runBulkMove(string $directory, string $batch): array
{
    $database = openMediaMetadataDatabase($directory);
    if ($database === null || getMediaMetadataSchemaVersion($database) < 6) {
        throw new DomainException('This move batch is no longer available.');
    }
    $pending = pendingMediaRelocation($database);
    $items = bulkMoveItems($database, $batch);
    if (!$items) {
        throw new DomainException('This move batch is no longer available.');
    }
    $pendingId = $pending['bulk_item'] ?? null;
    if ($pending !== null) {
        try {
            $error = recoverMediaRelocation($directory);
            if ($error !== null) {
                return bulkMoveStatus($database, $batch) + ['message' => $error];
            }
        } catch (DomainException $error) {
            if (in_array($pendingId, array_column($items, 'id'), true)) {
                writeMediaMetadata($directory, static fn (PDO $writer) => finishBulkMoveItem($writer, $pendingId, null, $error->getMessage()));
            }
            return bulkMoveStatus($database, $batch) + ['message' => $error->getMessage()];
        } catch (PDOException $error) {
            return bulkMoveStatus($database, $batch) + ['message' => 'The database could not finish the pending move. Resume to retry.'];
        }
        $items = bulkMoveItems($database, $batch);
    }
    foreach ($items as $item) {
        if ($item['state'] === 'completed') {
            continue;
        }
        $item['identity'] = json_decode($item['identity'], true, flags: JSON_THROW_ON_ERROR);
        $item['folders'] = json_decode($item['folders'], true, flags: JSON_THROW_ON_ERROR);
        $parent = dirname($item['destination']);
        try {
            moveLibraryMediaFile($directory, $item['source'], $parent === '.' ? '' : $parent, basename($item['destination']), $item);
        } catch (DomainException $error) {
            writeMediaMetadata($directory, static fn (PDO $writer) => finishBulkMoveItem($writer, $item['id'], null, $error->getMessage()));
            break;
        } catch (PDOException $error) {
            return bulkMoveStatus($database, $batch) + ['message' => 'The database could not finish the move. Resume to retry.'];
        }
    }
    return bulkMoveStatus($database, $batch);
}
