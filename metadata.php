<?php

const MEDIA_METADATA_FILENAME = 'mediamanager.sqlite';
const MEDIA_METADATA_SCHEMA_VERSION = 2;
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
    $metadata = readMediaMetadata($database, $mediaPaths);
    $database->commit();
    return $metadata;
}

function readMediaMetadata(PDO $database, array $mediaPaths): array
{
    $metadata = [];
    $version = getMediaMetadataSchemaVersion($database);
    if ($version > 0) {
        $deletionMark = $version === 1 ? '0' : 'marked_for_deletion';
        // Stay under the SQL parameter limit on older SQLite builds.
        foreach (array_chunk(array_unique($mediaPaths), 400) as $batch) {
            $parameters = implode(', ', array_fill(0, count($batch), '?'));
            $statement = $database->prepare("
                SELECT media_metadata.path, favorite, rating, $deletionMark AS marked_for_deletion, tags.name AS tag
                FROM media_metadata
                LEFT JOIN media_tags ON media_tags.media_path = media_metadata.path
                LEFT JOIN tags ON tags.id = media_tags.tag_id
                WHERE media_metadata.path IN ($parameters)
                ORDER BY media_metadata.path, tags.name COLLATE NOCASE
            ");
            $statement->execute($batch);
            foreach ($statement as $row) {
                $path = $row['path'];
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

function listMediaTags(string $libraryDirectory): array
{
    $database = openMediaMetadataDatabase($libraryDirectory);
    if ($database === null || getMediaMetadataSchemaVersion($database) === 0) {
        return [];
    }

    return $database->query('SELECT name FROM tags ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN);
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
            AND NOT EXISTS (SELECT 1 FROM media_tags WHERE media_path = media_metadata.path)
    ');
    $statement->execute([$mediaPath]);
}

function setMediaDeletionMark(string $libraryDirectory, string $mediaPath, bool $marked, callable $checkFile): void
{
    validateMediaMetadataPath($mediaPath);
    writeMediaMetadata($libraryDirectory, static function (PDO $database) use ($mediaPath, $marked, $checkFile): void {
        requireNoPendingMediaRelocation($database);
        $checkFile();
        $statement = $database->prepare('
            INSERT INTO media_metadata (path, marked_for_deletion) VALUES (?, ?)
            ON CONFLICT(path) DO UPDATE SET marked_for_deletion = excluded.marked_for_deletion
        ');
        $statement->execute([$mediaPath, (int) $marked]);
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

    return writeMediaMetadata($libraryDirectory, static function (PDO $database) use ($mediaPath, $changes, $rating, $tags, $checkFile): array {
        requireNoPendingMediaRelocation($database);
        if ($checkFile !== null) {
            $checkFile();
        }
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

        if ($tags !== null) {
            $statement = $database->prepare('DELETE FROM media_tags WHERE media_path = ?');
            $statement->execute([$mediaPath]);
            $createTag = $database->prepare('INSERT INTO tags (name) VALUES (?) ON CONFLICT(name) DO NOTHING');
            $attachTag = $database->prepare('INSERT INTO media_tags (media_path, tag_id) SELECT ?, id FROM tags WHERE name = ?');
            foreach ($tags as $tag) {
                $createTag->execute([$tag]);
                $attachTag->execute([$mediaPath, $tag]);
            }
            $database->exec('DELETE FROM tags WHERE NOT EXISTS (SELECT 1 FROM media_tags WHERE media_tags.tag_id = tags.id)');
        }

        removeEmptyMediaMetadata($database, $mediaPath);
        return readMediaMetadata($database, [$mediaPath])[$mediaPath] ?? EMPTY_MEDIA_METADATA;
    });
}
