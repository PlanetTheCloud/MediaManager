<?php

class DuplicateReviewStale extends DomainException
{
    public function __construct()
    {
        parent::__construct('A file, folder, or deletion mark changed. Scan again before applying marks to this group.');
    }
}

function duplicateScopeSnapshot(array $stat): array
{
    return ['device' => $stat['dev'], 'inode' => $stat['ino']];
}

function verifyDuplicateScope(string $directory, string $scope, array $expectedScope): string
{
    clearstatcache(true);
    if (!is_dir($directory) || !is_readable($directory)) {
        throw new MediaLibraryUnavailable('The media library is unavailable.');
    }
    try {
        $root = externalMoveScanRoot($directory, $scope);
    } catch (DomainException $error) {
        throw new DuplicateReviewStale();
    }
    $stat = @stat($root);
    if ($stat === false || $stat['ino'] === 0 || duplicateScopeSnapshot($stat) !== $expectedScope) {
        throw new DuplicateReviewStale();
    }
    return $root;
}

function duplicateFileSnapshot(string $directory, string $scope, string $root, string $path, array $expected): string
{
    try {
        $entry = checkScopedMediaPath($directory, $scope, $root, $path, false);
    } catch (DomainException $error) {
        throw new DuplicateReviewStale();
    }
    if (($entry['identity'] ?? null) !== $expected) {
        throw new DuplicateReviewStale();
    }
    return $entry['file'];
}

function hashScopedMediaFile(string $directory, string $scope, string $root, string $path, array $expected): string
{
    $file = duplicateFileSnapshot($directory, $scope, $root, $path, $expected);
    $stream = @fopen($file, 'rb');
    if ($stream === false) {
        throw new DomainException('The file could not be opened.');
    }
    try {
        $before = @fstat($stream);
        if (mediaIdentityFromStat($before) !== $expected) {
            throw new DuplicateReviewStale();
        }
        duplicateFileSnapshot($directory, $scope, $root, $path, $expected);
        $hash = hash_init('sha256');
        // Bound the read by the inventoried size, even if a file is still being written.
        $bytes = @hash_update_stream($hash, $stream, $expected['size']);
        $after = @fstat($stream);
        if (mediaIdentityFromStat($after) !== $expected || $after['ctime'] !== $before['ctime']) {
            throw new DuplicateReviewStale();
        }
        if ($bytes !== $expected['size']) {
            throw new DomainException('The file could not be read in full.');
        }
        duplicateFileSnapshot($directory, $scope, $root, $path, $expected);
        return hash_final($hash);
    } finally {
        fclose($stream);
    }
}

function scanDuplicateMedia(string $directory, string $scope): array
{
    $inventory = externalMoveInventory($directory, $scope);
    $root = $inventory['root'];
    $scopeSnapshot = duplicateScopeSnapshot($inventory['rootIdentity']);
    $issues = array_map(static fn ($path) => ['path' => $path, 'reason' => 'Could not inspect this path.'], $inventory['unreadable']);
    $sizes = [];
    foreach (array_filter($inventory['entries']) as $path => $identity) {
        if ($identity['inode'] === 0 || strpbrk($path, '\\:') !== false || preg_match('//u', $path) !== 1) {
            $issues[] = ['path' => $path, 'reason' => 'The path or file identity could not be verified.'];
            continue;
        }
        try {
            $file = duplicateFileSnapshot($directory, $scope, $root, $path, $identity);
            if (!is_readable($file)) {
                throw new DomainException('The file could not be read.');
            }
            $sizes[$identity['size']][$path] = $identity;
        } catch (DomainException $error) {
            $issues[] = ['path' => $path, 'reason' => $error instanceof DuplicateReviewStale ? 'The file changed during scanning.' : $error->getMessage()];
        }
    }

    $groups = $matchedPaths = [];
    foreach ($sizes as $size => $candidates) {
        if (count($candidates) < 2) {
            continue;
        }
        $matches = [];
        foreach ($candidates as $path => $identity) {
            try {
                $digest = hashScopedMediaFile($directory, $scope, $root, $path, $identity);
                $matches[$digest][] = ['path' => $path, 'identity' => $identity];
            } catch (DomainException $error) {
                $issues[] = ['path' => $path, 'reason' => $error instanceof DuplicateReviewStale ? 'The file changed during scanning.' : $error->getMessage()];
            }
        }
        foreach ($matches as $digest => $files) {
            $storage = [];
            foreach ($files as &$file) {
                ['device' => $device, 'inode' => $inode] = $file['identity'];
                $key = $device . ':' . $inode;
                $storage[$key] ??= count($storage) + 1;
                $file['copy'] = $storage[$key];
            }
            unset($file);
            $copies = count($storage);
            if ($copies > 1) {
                $groups[] = compact('digest', 'size', 'copies', 'files');
                foreach ($files as $file) {
                    $matchedPaths[] = $file['path'];
                }
            }
        }
    }
    verifyDuplicateScope($directory, $scope, $scopeSnapshot);
    $metadata = getMediaMetadata($directory, $matchedPaths);
    foreach ($groups as &$group) {
        foreach ($group['files'] as &$file) {
            $item = $metadata[$file['path']] ?? EMPTY_MEDIA_METADATA;
            unset($item['tag_keys'], $item['tag_aliases']);
            $file['metadata'] = $item;
            $file['type'] = mediaType($file['path']);
        }
        unset($file);
    }
    unset($group);
    return compact('scopeSnapshot', 'groups', 'issues') + ['skipped' => $inventory['skipped']];
}

function duplicateReviewFile(mixed $file): array
{
    if (!is_array($file) || !is_string($file['path'] ?? null) || mediaType($file['path']) === null
        || !is_string($file['snapshot'] ?? null) || !is_bool($file['marked'] ?? null)) {
        throw new InvalidArgumentException('Choose a keeper and the copies to mark from the scan.');
    }
    validateMediaMetadataPath($file['path']);
    $snapshot = json_decode($file['snapshot'], true, flags: JSON_THROW_ON_ERROR);
    $identity = [];
    foreach (['device', 'inode', 'size', 'modified', 'links'] as $field) {
        $value = $snapshot[$field] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('Scan again to verify the selected files.');
        }
        $identity[$field] = $value;
    }
    return ['path' => $file['path'], 'identity' => $identity, 'marked' => $file['marked']];
}

function applyDuplicateMarks(string $directory, string $scope, array $input): array
{
    $digest = $input['digest'] ?? null;
    $snapshot = is_string($input['scopeSnapshot'] ?? null)
        ? json_decode($input['scopeSnapshot'], true, flags: JSON_THROW_ON_ERROR) : null;
    $selected = $input['selected'] ?? null;
    $nonce = $input['nonce'] ?? null;
    if (!is_string($digest) || !preg_match('/\A[0-9a-f]{64}\z/', $digest)
        || !is_array($snapshot) || !is_int($snapshot['device'] ?? null) || !is_int($snapshot['inode'] ?? null)
        || !is_string($nonce) || !preg_match('/\A[0-9a-f]{32}\z/', $nonce)
        || !is_array($selected) || !array_is_list($selected) || !$selected) {
        throw new InvalidArgumentException('Select a keeper and at least one copy to mark.');
    }
    $scopeSnapshot = ['device' => $snapshot['device'], 'inode' => $snapshot['inode']];
    $keeper = duplicateReviewFile($input['keeper'] ?? null);
    $files = [$keeper['path'] => $keeper];
    foreach (array_map('duplicateReviewFile', $selected) as $file) {
        if (isset($files[$file['path']])) {
            throw new InvalidArgumentException('Choose only other copies to mark.');
        }
        $files[$file['path']] = $file;
    }
    if ($keeper['marked']) {
        throw new InvalidArgumentException('Choose an unmarked keeper.');
    }
    foreach ($files as $path => $file) {
        if ($path === $scope || !inMediaScanScope($scope, $path)) {
            throw new InvalidArgumentException('Choose files inside the selected folder.');
        }
    }

    $root = verifyDuplicateScope($directory, $scope, $scopeSnapshot);
    $token = $nonce . '.' . tagChangeToken([$directory, $scope, $scopeSnapshot, $digest, $files, $nonce]);
    $database = openMediaMetadataDatabase($directory);
    $receipt = $database === null ? null : bulkReceipt($database, $token);
    unset($database);
    if ($receipt !== null) {
        return $receipt + ['token' => $token];
    }
    foreach ($files as $path => $file) {
        try {
            $current = hashScopedMediaFile($directory, $scope, $root, $path, $file['identity']);
        } catch (DomainException $error) {
            throw new DuplicateReviewStale();
        }
        if (!hash_equals($digest, $current)) {
            throw new DuplicateReviewStale();
        }
    }

    $receipt = null;
    return writeMediaMetadata($directory,
        static function (PDO $database) use ($directory, $files, $keeper, $token, &$receipt): array {
            if ($receipt !== null) {
                return $receipt + ['token' => $token];
            }
            $rows = [];
            $counts = ['changed' => 0, 'unchanged' => 0, 'blocked' => 0];
            foreach ($files as $file) {
                if ($file['path'] === $keeper['path']) {
                    continue;
                }
                $status = $file['marked'] ? 'unchanged' : 'changed';
                $rows[] = ['status' => $status, 'tags' => []] + $file;
                $counts[$status]++;
            }
            return commitBulkEdit($database, $directory, 'mark', compact('rows', 'counts'), $token) + ['token' => $token];
        },
        static function (PDO $database) use ($directory, $scope, $scopeSnapshot, $files, $token, &$receipt): void {
            $receipt = bulkReceipt($database, $token);
            if ($receipt !== null) {
                return;
            }
            requireNoPendingMediaRelocation($database);
            $root = verifyDuplicateScope($directory, $scope, $scopeSnapshot);
            $version = getMediaMetadataSchemaVersion($database);
            $readMark = $version >= 2 ? $database->prepare('SELECT marked_for_deletion FROM media_metadata WHERE path = ?') : null;
            foreach ($files as $path => $file) {
                duplicateFileSnapshot($directory, $scope, $root, $path, $file['identity']);
                if ($version >= 3) {
                    requireMatchingMediaRecord($database, $path, $file['identity']);
                }
                $marked = false;
                if ($readMark !== null) {
                    $readMark->execute([$path]);
                    $marked = (bool) $readMark->fetchColumn();
                    $readMark->closeCursor();
                }
                if ($marked !== $file['marked']) {
                    throw new DuplicateReviewStale();
                }
            }
        }
    );
}
