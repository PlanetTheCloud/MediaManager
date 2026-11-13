<?php

const MEDIA_METADATA_FILENAME = 'mediamanager.sqlite';
const MEDIA_METADATA_SCHEMA_VERSION = 3;
const MEDIA_METADATA_MAX_TAGS = 100;
const MEDIA_METADATA_MAX_TAG_LENGTH = 100;
const EMPTY_MEDIA_METADATA = ['favorite' => false, 'rating' => null, 'marked_for_deletion' => false, 'tags' => []];

class MediaLibraryUnavailable extends RuntimeException
{
}

function openMediaMetadataDatabase(string $libraryDirectory, bool $write = false): ?PDO
{
    clearstatcache(true);
    $libraryDirectory = realpath($libraryDirectory);
    if ($libraryDirectory === false || !is_dir($libraryDirectory)) {
        throw new MediaLibraryUnavailable('The media library is unavailable.');
    }

    $databasePath = rtrim($libraryDirectory, '/\\') . DIRECTORY_SEPARATOR . MEDIA_METADATA_FILENAME;
    foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
        $path = $databasePath . $suffix;
        // A concurrent commit can remove the journal between filesystem checks.
        $fileInfo = @lstat($path);
        if (is_link($path) || ($fileInfo !== false && ($fileInfo['mode'] & 0170000) !== 0100000)) {
            throw new RuntimeException('The metadata database must use regular files in the library directory.');
        }
    }

    if (!$write && !is_file($databasePath)) {
        return null;
    }
    if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('The PHP PDO SQLite driver is required.');
    }

    $openFlags = $write ? PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE : PDO::SQLITE_OPEN_READONLY;
    $database = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS => $openFlags,
    ]);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('PRAGMA temp_store = MEMORY');

    return $database;
}

function getMediaMetadataSchemaVersion(PDO $database): int
{
    $version = (int) $database->query('PRAGMA user_version')->fetchColumn();
    if ($version < 0 || $version > MEDIA_METADATA_SCHEMA_VERSION) {
        throw new RuntimeException('The metadata database uses an unsupported schema version.');
    }

    return $version;
}

function initializeMediaMetadataSchema(PDO $database): void
{
    $version = getMediaMetadataSchemaVersion($database);
    if ($version === MEDIA_METADATA_SCHEMA_VERSION) {
        return;
    }
    if ($version === 0) {
        if ($database->query('SELECT 1 FROM sqlite_master LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('The media library contains an unrecognized metadata database.');
        }

        $database->exec(<<<'SQL'
            CREATE TABLE media_metadata (
                path TEXT PRIMARY KEY NOT NULL,
                favorite INTEGER NOT NULL DEFAULT 0 CHECK (favorite IN (0, 1)),
                rating INTEGER CHECK (rating BETWEEN 1 AND 5)
            );

            CREATE TABLE tags (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL COLLATE NOCASE UNIQUE
            );

            CREATE TABLE media_tags (
                media_path TEXT NOT NULL REFERENCES media_metadata(path) ON DELETE CASCADE,
                tag_id INTEGER NOT NULL REFERENCES tags(id),
                PRIMARY KEY (media_path, tag_id)
            );

            CREATE INDEX media_tags_tag_id ON media_tags (tag_id);
            SQL
        );
    }

    if ($version < 2) {
        $database->exec(<<<'SQL'
            ALTER TABLE media_metadata ADD COLUMN marked_for_deletion INTEGER NOT NULL DEFAULT 0
                CHECK (marked_for_deletion IN (0, 1));

            CREATE TABLE media_relocation (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                source TEXT NOT NULL,
                destination TEXT NOT NULL,
                device INTEGER NOT NULL,
                inode INTEGER NOT NULL
            );
            SQL
        );
    }

    $database->exec(<<<'SQL'
        ALTER TABLE media_tags RENAME TO old_media_tags;
        ALTER TABLE media_metadata RENAME TO old_media_metadata;

        CREATE TABLE media_metadata (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path TEXT NOT NULL UNIQUE COLLATE BINARY,
            favorite INTEGER NOT NULL DEFAULT 0 CHECK (favorite IN (0, 1)),
            rating INTEGER CHECK (rating BETWEEN 1 AND 5),
            marked_for_deletion INTEGER NOT NULL DEFAULT 0 CHECK (marked_for_deletion IN (0, 1)),
            device INTEGER,
            inode INTEGER,
            size INTEGER,
            modified INTEGER
        );
        INSERT INTO media_metadata (path, favorite, rating, marked_for_deletion)
            SELECT path, favorite, rating, marked_for_deletion FROM old_media_metadata;

        CREATE TABLE media_tags (
            media_id INTEGER NOT NULL REFERENCES media_metadata(id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (media_id, tag_id)
        );
        INSERT INTO media_tags (media_id, tag_id)
            SELECT media_metadata.id, old_media_tags.tag_id FROM old_media_tags
            JOIN media_metadata ON media_metadata.path = old_media_tags.media_path;
        DROP TABLE old_media_tags;
        DROP TABLE old_media_metadata;
        CREATE INDEX media_tags_tag_id ON media_tags (tag_id);
        SQL
    );
    $database->exec('PRAGMA user_version = ' . MEDIA_METADATA_SCHEMA_VERSION);
}

function validateMediaMetadataPath(string $mediaPath): void
{
    if ($mediaPath === '' || preg_match('~^/|/$|//|^[A-Za-z]:|(?:^|/)\.{1,2}(?:/|$)|\x00~', $mediaPath)
        || strpos($mediaPath, '\\') !== false) {
        throw new InvalidArgumentException('A library-relative media path is required.');
    }
}

function normalizeMediaMetadataTags(array $tags): array
{
    if (count($tags) > MEDIA_METADATA_MAX_TAGS) {
        throw new InvalidArgumentException('A file can have up to ' . MEDIA_METADATA_MAX_TAGS . ' tags.');
    }

    $normalized = [];
    foreach ($tags as $tag) {
        if (!is_string($tag) || preg_match('/[\x00-\x1F\x7F]/u', $tag) !== 0) {
            throw new InvalidArgumentException('Tags must contain valid text without control characters.');
        }

        $parts = array_map('trim', explode(':', $tag, 2));
        if (in_array('', $parts, true)) {
            throw new InvalidArgumentException('A tag and its optional namespace must have a name.');
        }

        $tag = implode(':', $parts);
        if (!preg_match('/\A.{1,' . MEDIA_METADATA_MAX_TAG_LENGTH . '}\z/us', $tag)) {
            throw new InvalidArgumentException('A tag can be up to ' . MEDIA_METADATA_MAX_TAG_LENGTH . ' characters.');
        }

        $key = strtolower($tag);
        $normalized[$key] = $normalized[$key] ?? $tag;
    }

    return array_values($normalized);
}

/**
 * Return metadata keyed by library-relative path. Untracked paths are absent.
 */
function getMediaMetadata(string $libraryDirectory, array $mediaPaths): array
{
    if (!$mediaPaths) {
        return [];
    }
    foreach ($mediaPaths as $mediaPath) {
        validateMediaMetadataPath($mediaPath);
    }

    $database = openMediaMetadataDatabase($libraryDirectory);
    if ($database === null) {
        return [];
    }

    $database->beginTransaction();
    $metadata = readMediaMetadata($database, $mediaPaths, $libraryDirectory);
    $database->commit();
    return $metadata;
}

function readMediaMetadata(PDO $database, array $mediaPaths, ?string $libraryDirectory = null): array
{
    $metadata = [];
    $version = getMediaMetadataSchemaVersion($database);
    if ($version > 0) {
        $deletionMark = $version === 1 ? '0' : 'marked_for_deletion';
        $tagJoin = $version < 3 ? 'media_tags.media_path = media_metadata.path' : 'media_tags.media_id = media_metadata.id';
        $identity = $version < 3 ? 'NULL AS device, NULL AS inode' : 'device, inode';
        $excluded = [];
        // Stay under the SQL parameter limit on older SQLite builds.
        foreach (array_chunk(array_unique($mediaPaths), 400) as $batch) {
            $parameters = implode(', ', array_fill(0, count($batch), '?'));
            $statement = $database->prepare("
                SELECT media_metadata.path, favorite, rating, $deletionMark AS marked_for_deletion, $identity, tags.name AS tag
                FROM media_metadata
                LEFT JOIN media_tags ON $tagJoin
                LEFT JOIN tags ON tags.id = media_tags.tag_id
                WHERE media_metadata.path IN ($parameters)
                ORDER BY media_metadata.path, tags.name COLLATE NOCASE
            ");
            $statement->execute($batch);
            foreach ($statement as $row) {
                $path = $row['path'];
                if (!isset($metadata[$path]) && !isset($excluded[$path]) && $libraryDirectory !== null && $row['inode'] !== null
                    && !hasMediaFileIdentity(resolveLibraryPath($libraryDirectory, $path), $row)) {
                    $excluded[$path] = true;
                }
                if (isset($excluded[$path])) {
                    continue;
                }
                $metadata[$path] = $metadata[$path] ?? [
                    'favorite' => (bool) $row['favorite'],
                    'rating' => $row['rating'] === null ? null : (int) $row['rating'],
                    'marked_for_deletion' => (bool) $row['marked_for_deletion'],
                    'tags' => [],
                ];
                if ($row['tag'] !== null) {
                    $metadata[$path]['tags'][] = $row['tag'];
                }
            }
        }
    }
    return $metadata;
}

function writeMediaMetadata(string $libraryDirectory, callable $write): mixed
{
    $database = openMediaMetadataDatabase($libraryDirectory, true);
    $database->exec('BEGIN IMMEDIATE');
    try {
        initializeMediaMetadataSchema($database);
        $result = $write($database);
        $database->exec('COMMIT');
        return $result;
    } catch (Throwable $exception) {
        try {
            $database->exec('ROLLBACK');
        } catch (PDOException) {
            // SQLite may already have rolled back the transaction.
        }
        throw $exception;
    }
}

function pendingMediaRelocation(PDO $database): ?array
{
    if (getMediaMetadataSchemaVersion($database) < 2) {
        return null;
    }
    $relocation = $database->query('SELECT source, destination, device, inode FROM media_relocation WHERE id = 1')->fetch();
    return $relocation === false ? null : $relocation;
}

function requireNoPendingMediaRelocation(PDO $database): void
{
    if (pendingMediaRelocation($database) !== null) {
        throw new DomainException('A file move is pending. Reload the gallery before making another change.');
    }
}

function removeEmptyMediaMetadata(PDO $database, string $mediaPath): void
{
    $statement = $database->prepare('
        DELETE FROM media_metadata
        WHERE path = ? AND favorite = 0 AND rating IS NULL AND marked_for_deletion = 0
            AND NOT EXISTS (SELECT 1 FROM media_tags WHERE media_id = media_metadata.id)
    ');
    $statement->execute([$mediaPath]);
}

function mediaRecordAtPath(PDO $database, string $path): ?array
{
    $statement = $database->prepare('SELECT id, path, device, inode, size, modified FROM media_metadata WHERE path = ?');
    $statement->execute([$path]);
    return $statement->fetch() ?: null;
}

function requireMatchingMediaRecord(PDO $database, string $path, ?array $identity): void
{
    $record = mediaRecordAtPath($database, $path);
    if ($record !== null && $record['inode'] !== null && ($identity === null || !sameMediaFile($identity, $record))) {
        throw new DomainException('This path belongs to another saved file. Use Scan for moves to reconnect its details.');
    }
}

function recordMediaIdentity(PDO $database, int $id, string $path, array $identity): void
{
    $statement = $database->prepare('UPDATE media_metadata SET path = ?, device = ?, inode = ?, size = ?, modified = ? WHERE id = ?');
    $statement->execute([$path, $identity['device'], $identity['inode'] ?: null, $identity['size'], $identity['modified'], $id]);
}

function mediaAnnotationIdentity(PDO $database, string $directory, string $path, ?callable $checkFile): ?array
{
    $resolved = $checkFile === null ? resolveLibraryPath($directory, $path) : $checkFile();
    $identity = mediaFileIdentity(is_string($resolved) ? $resolved : resolveLibraryPath($directory, $path));
    requireMatchingMediaRecord($database, $path, $identity);
    return $identity;
}

function setMediaDeletionMark(string $libraryDirectory, string $mediaPath, bool $marked, callable $checkFile): void
{
    validateMediaMetadataPath($mediaPath);
    writeMediaMetadata($libraryDirectory, static function (PDO $database) use ($libraryDirectory, $mediaPath, $marked, $checkFile): void {
        requireNoPendingMediaRelocation($database);
        $identity = mediaAnnotationIdentity($database, $libraryDirectory, $mediaPath, $checkFile);
        $statement = $database->prepare('
            INSERT INTO media_metadata (path, marked_for_deletion) VALUES (?, ?)
            ON CONFLICT(path) DO UPDATE SET marked_for_deletion = excluded.marked_for_deletion
        ');
        $statement->execute([$mediaPath, (int) $marked]);
        if ($identity !== null) {
            recordMediaIdentity($database, mediaRecordAtPath($database, $mediaPath)['id'], $mediaPath, $identity);
        }
        removeEmptyMediaMetadata($database, $mediaPath);
    });
}

/**
 * Replace the details form fields atomically, preserving any deletion mark.
 */
function saveMediaMetadata(
    string $libraryDirectory,
    string $mediaPath,
    bool $favorite,
    ?int $rating,
    array $tags,
    ?callable $checkFile = null
): array
{
    $saved = patchMediaMetadata($libraryDirectory, $mediaPath, compact('favorite', 'rating', 'tags'), $checkFile);
    return ['favorite' => $saved['favorite'], 'rating' => $saved['rating'], 'tags' => $saved['tags']];
}

function patchMediaMetadata(string $libraryDirectory, string $mediaPath, array $changes, ?callable $checkFile = null): array
{
    validateMediaMetadataPath($mediaPath);
    $rating = $changes['rating'] ?? null;
    if ($rating !== null && ($rating < 1 || $rating > 5)) {
        throw new InvalidArgumentException('A rating must be between 1 and 5 stars.');
    }
    $tags = isset($changes['tags']) ? normalizeMediaMetadataTags($changes['tags']) : null;

    return writeMediaMetadata($libraryDirectory, static function (PDO $database) use ($libraryDirectory, $mediaPath, $changes, $rating, $tags, $checkFile): array {
        requireNoPendingMediaRelocation($database);
        $identity = mediaAnnotationIdentity($database, $libraryDirectory, $mediaPath, $checkFile);
        $statement = $database->prepare('
            INSERT INTO media_metadata (path, favorite, rating) VALUES (?, ?, ?)
            ON CONFLICT(path) DO UPDATE SET
                favorite = CASE WHEN ? THEN excluded.favorite ELSE media_metadata.favorite END,
                rating = CASE WHEN ? THEN excluded.rating ELSE media_metadata.rating END
        ');
        $statement->execute([
            $mediaPath, (int) ($changes['favorite'] ?? false), $rating,
            (int) array_key_exists('favorite', $changes), (int) array_key_exists('rating', $changes),
        ]);
        $id = mediaRecordAtPath($database, $mediaPath)['id'];
        if ($identity !== null) {
            recordMediaIdentity($database, $id, $mediaPath, $identity);
        }

        if ($tags !== null) {
            $statement = $database->prepare('DELETE FROM media_tags WHERE media_id = ?');
            $statement->execute([$id]);
            $createTag = $database->prepare('INSERT INTO tags (name) VALUES (?) ON CONFLICT(name) DO NOTHING');
            $attachTag = $database->prepare('INSERT INTO media_tags (media_id, tag_id) SELECT ?, id FROM tags WHERE name = ?');
            foreach ($tags as $tag) {
                $createTag->execute([$tag]);
                $attachTag->execute([$id, $tag]);
            }
            $database->exec('DELETE FROM tags WHERE NOT EXISTS (SELECT 1 FROM media_tags WHERE media_tags.tag_id = tags.id)');
        }

        removeEmptyMediaMetadata($database, $mediaPath);
        return readMediaMetadata($database, [$mediaPath])[$mediaPath] ?? EMPTY_MEDIA_METADATA;
    });
}
