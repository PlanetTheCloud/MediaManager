<?php

require_once __DIR__ . '/metadata.php';

const ITEMS_PER_PAGE = 15;
const MEDIA_TYPES = [
    'gif' => 'image/gif',
    'webm' => 'video/webm',
    'mp4' => 'video/mp4',
    'mkv' => 'video/x-matroska',
    'm4v' => 'video/mp4',
];

function isInPublicDocumentRoot(string $path): bool
{
    $configured = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $documentRoot = $configured === '' ? false : realpath($configured);
    return $documentRoot !== false && ($path === $documentRoot
        || str_starts_with($path, rtrim($documentRoot, '/\\') . DIRECTORY_SEPARATOR));
}

function getMediaLibraryDirectory(): string
{
    $configured = getenv('MEDIA_LIBRARY_DIR');
    $directory = realpath($configured === false ? __DIR__ : $configured);
    if ($configured === '' || $directory === false || !is_dir($directory)) {
        throw new RuntimeException('The media library is unavailable.');
    }
    if (isInPublicDocumentRoot($directory)) {
        throw new RuntimeException('The media library must be outside the public document root.');
    }
    return $directory;
}

function resolveLibraryPath(string $directory, string $relative): ?string
{
    if ($relative === '') {
        return $directory;
    }
    if (strpbrk($relative, "\0\\:") !== false || array_intersect(explode('/', $relative), ['', '.', '..'])) {
        return null;
    }

    $prefix = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
    $candidate = $prefix . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $resolved = realpath($candidate);
    if ($resolved === false || $resolved !== $candidate || is_link($candidate)) {
        return null;
    }
    return $resolved;
}

function relativeMediaPath(string $directory, string $path): string
{
    return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(rtrim($directory, '/\\')) + 1));
}

function mediaType(string $path): ?string
{
    return MEDIA_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
}

function gatherMediaFiles($path = '.', $recursive = true): array
{
    $path = realpath($path);
    if ($path === false || !is_dir($path)) {
        throw new RuntimeException('The media folder is unavailable.');
    }
    $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
    $entries = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry): bool {
        $pathname = $entry->getPathname();
        return !$entry->isLink() && realpath($pathname) === $pathname
            && ($entry->isDir() || ($entry->isFile() && mediaType($pathname) !== null));
    });
    if ($recursive) {
        $entries = new RecursiveIteratorIterator($entries, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    }
    $files = [];
    foreach ($entries as $entry) {
        if ($entry->isFile()) {
            $files[] = $entry->getPathname();
        }
    }
    return $files;
}

function gatherMediaFolders(string $directory, bool $recursive = false): array
{
    $folders = [];
    foreach (new DirectoryIterator($directory) as $entry) {
        if (!$entry->isDot() && $entry->isDir() && !str_starts_with($entry->getFilename(), '.')
            && !isInPublicDocumentRoot($entry->getPathname())
            && resolveLibraryPath($directory, $entry->getFilename()) !== null) {
            $name = $entry->getFilename();
            $folders[] = $name;
            if ($recursive && is_readable($entry->getPathname())) {
                foreach (gatherMediaFolders($entry->getPathname(), true) as $child) {
                    $folders[] = $name . '/' . $child;
                }
            }
        }
    }
    natcasesort($folders);
    return $folders;
}

function seeded_shuffle(array $items, int $seed): array
{
    $items = array_values($items);
    mt_srand($seed);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

function sort_by_time($items, $direction = SORT_ASC): array
{
    $timestamps = array_map('filemtime', $items);
    array_multisort($timestamps, $direction, $items, SORT_ASC, SORT_STRING);
    return $items;
}

function recursiveSearch(string $query, array $files): array
{
    foreach (preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) as $term) {
        $exclude = str_starts_with($term, '-') && strlen($term) > 1;
        $needle = $exclude ? substr($term, 1) : $term;
        $files = array_filter($files, static fn ($file) => str_contains($file, $needle) !== $exclude);
    }
    return $files;
}

function requireLibraryMediaFile(string $directory, string $file): string
{
    clearstatcache(true);
    $path = resolveLibraryPath($directory, $file);
    if ($path === null || !is_file($path) || mediaType($file) === null) {
        throw new DomainException('This media file is no longer available. Reload the gallery.');
    }
    return $path;
}

function mediaMoveDestination(string $directory, string $relative): string
{
    $parent = dirname($relative);
    $folder = resolveLibraryPath($directory, $parent === '.' ? '' : $parent);
    if ($folder === null || !is_dir($folder) || isInPublicDocumentRoot($folder)) {
        throw new DomainException('Choose an existing destination folder inside the media library.');
    }
    return rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . basename($relative);
}

function hasMediaFileIdentity(?string $path, array $identity): bool
{
    $stat = $path === null ? false : @stat($path);
    return $stat !== false && $stat['dev'] === (int) $identity['device'] && $stat['ino'] === (int) $identity['inode'];
}

function isCaseOnlyMediaRename(string $source, string $target): bool
{
    return $source !== $target && strcasecmp($source, $target) === 0
        && !is_link($target) && realpath($target) === $source;
}

function recoverMediaRelocation(string $directory): ?string
{
    $reader = openMediaMetadataDatabase($directory);
    if ($reader === null || pendingMediaRelocation($reader) === null) {
        return null;
    }
    unset($reader);

    return writeMediaMetadata($directory, static function (PDO $database) use ($directory): ?string {
        $move = pendingMediaRelocation($database);
        if ($move === null) {
            return null;
        }

        clearstatcache(true);
        $source = resolveLibraryPath($directory, $move['source']);
        try {
            $target = mediaMoveDestination($directory, $move['destination']);
        } catch (DomainException $error) {
            // The destination may be temporarily unavailable. Preserve the intent.
            throw new DomainException('Restore the destination folder and reload to finish the pending move.');
        }
        $targetResolved = resolveLibraryPath($directory, $move['destination']);
        $sourceMatches = hasMediaFileIdentity($source, $move);
        $targetMatches = hasMediaFileIdentity($targetResolved, $move);

        $error = null;
        if ($sourceMatches && isCaseOnlyMediaRename($source, $target)) {
            // On case-insensitive volumes this is a case-only rename of the same file.
            if (!@rename($source, $target)) {
                $error = 'The rename failed. Close the file and try again.';
            }
        } elseif ($sourceMatches) {
            if (!$targetMatches && (@lstat($target) !== false || is_link($target))) {
                $error = 'A file or folder already uses that name.';
            } elseif (!$targetMatches && !@link($source, $target)) {
                $error = 'The move failed. Check write permissions and use a destination on the same volume that supports hard links.';
            } else {
                clearstatcache(true);
                if (!hasMediaFileIdentity(resolveLibraryPath($directory, $move['destination']), $move)) {
                    $error = 'The destination changed before the move could finish.';
                } elseif (hasMediaFileIdentity(resolveLibraryPath($directory, $move['source']), $move) && !@unlink($source)) {
                    if (!@unlink($target)) {
                        throw new DomainException('Close the file and reload to finish the pending move.');
                    }
                    $error = 'The original file could not be moved. Close it and try again.';
                }
            }
        } elseif (!$targetMatches) {
            $error = 'The original file could not be located to complete the move.';
        }

        if ($error === null) {
            $database->exec('PRAGMA defer_foreign_keys = ON');
            $statement = $database->prepare('UPDATE media_metadata SET path = ? WHERE path = ?');
            $statement->execute([$move['destination'], $move['source']]);
            $statement = $database->prepare('UPDATE media_tags SET media_path = ? WHERE media_path = ?');
            $statement->execute([$move['destination'], $move['source']]);
        }
        $database->exec('DELETE FROM media_relocation WHERE id = 1');
        return $error;
    });
}

function moveLibraryMediaFile(string $directory, string $file, string $folder, string $name): string
{
    $invalidName = $name === '' || strpbrk($name, "\0/\\:") !== false || preg_match('/[\x00-\x1F\x7F]/', $name);
    if (PHP_OS_FAMILY === 'Windows') {
        $invalidName = $invalidName || strpbrk($name, '*?"<>|') !== false || rtrim($name, ' .') !== $name
            || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³])(?:\.|$)/iu', $name);
    }
    if ($invalidName || mediaType($file) === null
        || strcasecmp(pathinfo($name, PATHINFO_EXTENSION), pathinfo($file, PATHINFO_EXTENSION)) !== 0) {
        throw new DomainException('Enter a valid filename with the original extension.');
    }
    // Validate the user-supplied folder before dirname() can normalize it.
    if (resolveLibraryPath($directory, $folder) === null) {
        throw new DomainException('Choose an existing destination folder inside the media library.');
    }
    $destination = $folder === '' ? $name : $folder . '/' . $name;
    if ($destination === $file) {
        requireLibraryMediaFile($directory, $file);
        return $file;
    }

    $identity = writeMediaMetadata($directory, static function (PDO $database) use ($directory, $file, $destination): array {
        requireNoPendingMediaRelocation($database);
        $source = requireLibraryMediaFile($directory, $file);
        $target = mediaMoveDestination($directory, $destination);
        if ((@lstat($target) !== false || is_link($target)) && !isCaseOnlyMediaRename($source, $target)) {
            throw new DomainException('A file or folder already uses that name.');
        }
        $statement = $database->prepare('SELECT 1 FROM media_metadata WHERE path = ?');
        $statement->execute([$destination]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('That path already has saved details. Choose a different name or folder.');
        }
        $stat = @stat($source);
        if ($stat === false || $stat['ino'] === 0) {
            throw new DomainException('The filesystem could not identify the original file for a recoverable move.');
        }

        // Commit the intent before touching either file so a restart can finish the move.
        $statement = $database->prepare('INSERT INTO media_relocation VALUES (1, ?, ?, ?, ?)');
        $statement->execute([$file, $destination, $stat['dev'], $stat['ino']]);
        return ['device' => $stat['dev'], 'inode' => $stat['ino']];
    });
    $error = recoverMediaRelocation($directory);
    clearstatcache(true);
    if ($error !== null || !hasMediaFileIdentity(resolveLibraryPath($directory, $destination), $identity)) {
        throw new DomainException($error ?? 'The destination could not be confirmed. Reload the gallery.');
    }
    return $destination;
}

function galleryFilters(array $input): array
{
    $filters = [];
    foreach (['folder', 'query', 'tag', 'favorites', 'rating', 'marked', 'random', 'seed', 'newest', 'oldest', 'page'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        if (!is_string($input[$key])) {
            throw new InvalidArgumentException('Invalid gallery filter.');
        }
        if ($input[$key] !== '' || in_array($key, ['random', 'newest', 'oldest'], true)) {
            $filters[$key] = $input[$key];
        }
    }
    if (isset($filters['rating']) && !preg_match('/^[1-5]$/D', $filters['rating'])) {
        throw new InvalidArgumentException('Invalid rating filter.');
    }
    if (isset($filters['seed']) && !preg_match('/^[0-9]{1,9}$/D', $filters['seed'])) {
        throw new InvalidArgumentException('Invalid shuffle seed.');
    }
    return $filters;
}

function loadMediaGallery(string $directory, array $filters): array
{
    $folder = resolveLibraryPath($directory, $filters['folder'] ?? '');
    if ($folder === null || !is_dir($folder)) {
        throw new InvalidArgumentException('The requested folder is unavailable.');
    }

    $paths = gatherMediaFiles($folder);
    sort($paths, SORT_STRING);
    if (isset($filters['random'])) {
        $paths = seeded_shuffle($paths, (int) $filters['seed']);
    } elseif (isset($filters['newest']) || isset($filters['oldest'])) {
        $paths = sort_by_time($paths, isset($filters['newest']) ? SORT_DESC : SORT_ASC);
    }

    $files = array_map(static fn ($path) => relativeMediaPath($directory, $path), $paths);
    $files = recursiveSearch($filters['query'] ?? '', $files);
    $annotations = null;
    if (isset($filters['tag']) || isset($filters['favorites']) || isset($filters['rating']) || isset($filters['marked'])) {
        $annotations = getMediaMetadata($directory, array_values($files));
        $files = array_filter($files, static function ($file) use ($annotations, $filters): bool {
            $item = $annotations[$file] ?? null;
            return $item !== null
                && (!isset($filters['favorites']) || $item['favorite'])
                && (!isset($filters['marked']) || $item['marked_for_deletion'])
                && (!isset($filters['rating']) || $item['rating'] === (int) $filters['rating'])
                && (!isset($filters['tag']) || in_array(strtolower($filters['tag']), array_map('strtolower', $item['tags']), true));
        });
    }

    $pageCount = (int) ceil(count($files) / ITEMS_PER_PAGE);
    $page = max(1, min((int) ($filters['page'] ?? 1), $pageCount));
    $pageFiles = array_slice($files, ($page - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
    $annotations ??= getMediaMetadata($directory, $pageFiles);
    return [$pageFiles, gatherMediaFolders($directory), $page, $pageCount, $annotations, listMediaTags($directory)];
}
