<?php

function inMediaScanScope(string $scope, string $path): bool
{
    return $scope === '' || $path === $scope || str_starts_with($path, $scope . '/');
}

function externalMoveScanDirectory(string $path): bool
{
    return !is_link($path) && realpath($path) === $path && is_dir($path) && is_readable($path);
}

function externalMoveScanRoot(string $directory, string $scope): string
{
    clearstatcache(true);
    $root = rtrim($directory, '/\\');
    if ($scope !== '') {
        validateMediaMetadataPath($scope);
        foreach (explode('/', $scope) as $part) {
            if (str_contains($part, ':')) {
                throw new DomainException('Select a folder inside the media library.');
            }
            $root .= DIRECTORY_SEPARATOR . $part;
            if (!externalMoveScanDirectory($root)) {
                throw new DomainException('The selected folder is unavailable for scanning.');
            }
        }
    }
    $root = $scope === '' ? $directory : $root;
    if (!externalMoveScanDirectory($root) || isInPublicDocumentRoot($root)) {
        throw new DomainException('The selected folder is unavailable for scanning.');
    }
    return $root;
}

function externalMoveInventory(string $directory, string $scope): array
{
    $root = externalMoveScanRoot($directory, $scope);
    $rootIdentity = @stat($root);
    if ($rootIdentity === false) {
        throw new DomainException('The selected folder is unavailable for scanning.');
    }
    $folders = [$root];
    $entries = [];
    $identities = [];
    $skipped = [];
    $unreadable = [];
    while ($folders) {
        $folder = array_pop($folders);
        clearstatcache(true);
        if (!externalMoveScanDirectory($folder) || ($names = @scandir($folder)) === false) {
            $unreadable[] = $folder === $root ? ($scope ?: 'All folders') : relativeMediaPath($directory, $folder);
            continue;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $name;
            $relative = relativeMediaPath($directory, $path);
            $entries[$relative] = null;
            if (is_link($path) || isInPublicDocumentRoot($path)) {
                $skipped[] = $relative;
                continue;
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                $unreadable[] = $relative;
                continue;
            }
            if ($resolved !== $path) {
                $skipped[] = $relative;
                continue;
            }
            if (is_dir($path)) {
                $folders[] = $path;
            } elseif (mediaType($relative) !== null) {
                $file = mediaFileIdentity($path);
                if ($file === null) {
                    $unreadable[] = $relative;
                    continue;
                }
                $entries[$relative] = $file;
                if ($file['inode'] !== 0) {
                    $identities[externalMoveIdentityKey($file)][] = $relative;
                }
            }
        }
    }
    return compact('root', 'rootIdentity', 'entries', 'identities', 'skipped', 'unreadable');
}

function externalMoveIdentityKey(array $file): string
{
    return implode(':', [$file['device'], $file['inode'], $file['size'], $file['modified']]);
}

function scopedMediaRecords(PDO $database, string $scope): array
{
    $select = 'SELECT id, path, device, inode, size, modified FROM media_metadata';
    if ($scope === '') {
        return $database->query($select . ' ORDER BY path')->fetchAll();
    }
    // Binary range for an exact directory prefix. The next ASCII byte after '/' is '0'.
    $statement = $database->prepare($select . ' WHERE path = ? OR (path >= ? AND path < ?) ORDER BY path');
    $statement->execute([$scope, $scope . '/', $scope . '0']);
    return $statement->fetchAll();
}

/** Check a path from the scan root outward, without following redirected directories. */
function checkScopedMediaPath(string $directory, string $scope, string $root, string $path): array
{
    if (!inMediaScanScope($scope, $path) || $path === $scope) {
        throw new DomainException('Choose a file inside the selected folder.');
    }
    validateMediaMetadataPath($path);
    if (str_contains($path, ':')) {
        return ['state' => 'unverified'];
    }
    clearstatcache(true);
    if (!externalMoveScanDirectory($root)) {
        throw new DomainException('The selected folder changed. Scan again.');
    }
    $current = $root;
    $suffix = $scope === '' ? $path : substr($path, strlen($scope) + 1);
    $parts = explode('/', $suffix);
    $last = array_pop($parts);
    foreach ($parts as $part) {
        $parent = $current;
        $current = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $part;
        if (is_link($current) || isInPublicDocumentRoot($current)) {
            return ['state' => 'unverified'];
        }
        $resolved = realpath($current);
        if ($resolved === false) {
            return missingScopedMediaPath($parent, $part);
        }
        if ($resolved !== $current && PHP_OS_FAMILY === 'Windows' && strcasecmp($resolved, $current) === 0
            && is_dir($resolved) && is_readable($resolved)) {
            $current = $resolved;
        } elseif ($resolved !== $current || !is_dir($current) || !is_readable($current)) {
            return ['state' => 'unverified'];
        }
    }
    $parent = $current;
    $current = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $last;
    if (is_link($current) || isInPublicDocumentRoot($current)) {
        return ['state' => 'unverified'];
    }
    $resolved = realpath($current);
    if ($resolved === false) {
        return missingScopedMediaPath($parent, $last);
    }
    if ($resolved !== $current) {
        // Windows can resolve the old spelling of a case-only rename to the new path.
        if (PHP_OS_FAMILY === 'Windows' && strcasecmp($resolved, $current) === 0) {
            return ['state' => 'renamed-case', 'path' => relativeMediaPath($directory, $resolved)];
        }
        return ['state' => 'unverified'];
    }
    if (relativeMediaPath($directory, $current) !== $path) {
        return ['state' => 'renamed-case', 'path' => relativeMediaPath($directory, $current)];
    }
    return ['state' => 'present', 'identity' => mediaFileIdentity($current)];
}

function missingScopedMediaPath(string $parent, string $name): array
{
    $names = externalMoveScanDirectory($parent) ? @scandir($parent, SCANDIR_SORT_NONE) : false;
    return ['state' => $names !== false && !in_array($name, $names, true) ? 'missing' : 'unverified'];
}

function originalMediaPathIsGone(array $source, string $destination): bool
{
    return $source['state'] === 'missing' || ($source['state'] === 'renamed-case' && $source['path'] === $destination);
}

function scanExternalMediaMoves(string $directory, string $scope): array
{
    $report = ['reconnected' => 0, 'backfilled' => 0, 'review' => [], 'skipped' => [], 'unreadable' => []];
    $reader = openMediaMetadataDatabase($directory);
    if ($reader === null || getMediaMetadataSchemaVersion($reader) === 0) {
        externalMoveScanRoot($directory, $scope);
        return $report;
    }
    requireNoPendingMediaRelocation($reader);
    unset($reader);

    $inventory = externalMoveInventory($directory, $scope);
    $report['skipped'] = $inventory['skipped'];
    $report['unreadable'] = $inventory['unreadable'];
    if ($report['unreadable']) {
        return $report;
    }

    return writeMediaMetadata($directory, static function (PDO $database) use ($directory, $scope, $inventory, $report): array {
        requireNoPendingMediaRelocation($database);
        $root = externalMoveScanRoot($directory, $scope);
        $currentRoot = @stat($root);
        if ($root !== $inventory['root'] || $currentRoot === false
            || $currentRoot['dev'] !== $inventory['rootIdentity']['dev'] || $currentRoot['ino'] !== $inventory['rootIdentity']['ino']) {
            throw new DomainException('The selected folder changed. Scan again.');
        }

        $records = scopedMediaRecords($database, $scope);
        $claims = [];
        foreach ($records as $record) {
            if ($record['inode'] !== null) {
                $key = externalMoveIdentityKey($record);
                $claims[$key] = ($claims[$key] ?? 0) + 1;
            }
        }
        foreach ($records as $record) {
            $path = $record['path'];
            $observed = $inventory['entries'][$path] ?? null;
            $key = $record['inode'] === null ? null : externalMoveIdentityKey($record);
            $candidates = $inventory['identities'][$key] ?? [];
            $reason = 'Original path is missing.';
            if ($observed !== null) {
                if ($record['inode'] !== null && sameMediaFile($observed, $record)) {
                    continue;
                }
                if ($record['inode'] === null) {
                    $live = checkScopedMediaPath($directory, $scope, $root, $path);
                    if ($observed['inode'] !== 0 && ($live['identity'] ?? null) === $observed) {
                        recordMediaIdentity($database, $record['id'], $path, $observed);
                        $report['backfilled']++;
                        continue;
                    }
                }
                $reason = 'The recorded path is occupied or changed during the scan.';
            } elseif (array_key_exists($path, $inventory['entries'])) {
                $reason = 'The recorded path could not be verified.';
            } elseif (count($candidates) === 1 && $claims[$key] === 1) {
                $destination = $candidates[0];
                $source = checkScopedMediaPath($directory, $scope, $root, $path);
                $target = checkScopedMediaPath($directory, $scope, $root, $destination);
                $identity = $target['identity'] ?? null;
                if (originalMediaPathIsGone($source, $destination) && $identity !== null && $identity['links'] === 1
                    && $identity === $inventory['entries'][$destination] && mediaRecordAtPath($database, $destination) === null) {
                    recordMediaIdentity($database, $record['id'], $destination, $identity);
                    $report['reconnected']++;
                    continue;
                }
                $reason = 'The candidate is claimed, hard-linked, or changed during the scan.';
            } elseif ($candidates) {
                $reason = 'More than one path or saved record matches this identity.';
            } elseif ($record['inode'] === null) {
                $reason = 'No file identity was saved for the original path.';
            }
            $report['review'][] = ['id' => $record['id'], 'path' => $path, 'reason' => $reason, 'candidates' => $candidates];
        }
        return $report;
    });
}

function reconnectExternalMedia(string $directory, string $scope, int $id, string $expectedPath, string $destination): void
{
    if ($destination === '' || !inMediaScanScope($scope, $destination) || mediaType($destination) === null) {
        throw new DomainException('Choose an existing media file inside the selected folder.');
    }
    $root = externalMoveScanRoot($directory, $scope);
    if (openMediaMetadataDatabase($directory) === null) {
        throw new DomainException('The saved media record is unavailable.');
    }
    writeMediaMetadata($directory, static function (PDO $database) use ($directory, $scope, $root, $id, $expectedPath, $destination): void {
        requireNoPendingMediaRelocation($database);
        if (externalMoveScanRoot($directory, $scope) !== $root) {
            throw new DomainException('The selected folder changed. Scan again.');
        }
        $statement = $database->prepare('SELECT id, path, device, inode, size, modified FROM media_metadata WHERE id = ?');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if (!$record || $record['path'] !== $expectedPath || !inMediaScanScope($scope, $record['path'])) {
            throw new DomainException('The saved media record is outside this scan or is unavailable.');
        }
        $source = checkScopedMediaPath($directory, $scope, $root, $record['path']);
        $sourceIdentity = $source['identity'] ?? null;
        if (!in_array($source['state'], ['missing', 'renamed-case'], true)
            && ($sourceIdentity === null || $record['inode'] === null || sameMediaFile($sourceIdentity, $record))) {
            throw new DomainException('The original path must be verified as missing or occupied by a different file.');
        }
        $target = checkScopedMediaPath($directory, $scope, $root, $destination);
        if (!isset($target['identity']) || mediaRecordAtPath($database, $destination) !== null) {
            throw new DomainException('That file is unavailable or already has saved details.');
        }
        recordMediaIdentity($database, $record['id'], $destination, $target['identity']);
    });
}
