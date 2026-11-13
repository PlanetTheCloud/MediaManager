<?php

function initializeTagRules(PDO $database): void
{
    $database->exec(<<<'SQL'
        CREATE TABLE tag_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder TEXT NOT NULL,
            needle TEXT NOT NULL,
            revision TEXT NOT NULL
        );
        CREATE TABLE tag_rule_tags (
            rule_id INTEGER NOT NULL REFERENCES tag_rules(id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (rule_id, tag_id)
        );
        CREATE INDEX tag_rule_tags_tag_id ON tag_rule_tags (tag_id);
        CREATE TABLE tag_rule_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            description TEXT NOT NULL,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE tag_rule_additions (
            run_id INTEGER NOT NULL REFERENCES tag_rule_runs(id) ON DELETE CASCADE,
            media_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY (media_id, tag_id),
            FOREIGN KEY (media_id, tag_id) REFERENCES media_tags(media_id, tag_id) ON DELETE CASCADE
        );
        CREATE INDEX tag_rule_additions_run_id ON tag_rule_additions (run_id);
        SQL
    );
}

function readTagRules(?PDO $database): array
{
    if ($database === null || getMediaMetadataSchemaVersion($database) < 5) {
        return [];
    }
    $rules = [];
    foreach ($database->query('
        SELECT tag_rules.*, tags.id AS tag_id, tags.name AS tag FROM tag_rules
        JOIN tag_rule_tags ON rule_id = tag_rules.id JOIN tags ON tags.id = tag_rule_tags.tag_id
        ORDER BY tag_rules.id, tags.name COLLATE NOCASE
    ') as $row) {
        $rules[$row['id']] ??= [
            'id' => $row['id'], 'folder' => $row['folder'], 'needle' => $row['needle'], 'revision' => $row['revision'], 'tags' => [],
        ];
        $rules[$row['id']]['tags'][$row['tag_id']] = $row['tag'];
    }
    return $rules;
}

function tagRuleUndoRuns(?PDO $database): array
{
    if ($database === null || getMediaMetadataSchemaVersion($database) < 5) {
        return [];
    }
    return $database->query('
        SELECT id, description, applied_at, COUNT(*) AS additions FROM tag_rule_runs
        JOIN tag_rule_additions ON run_id = id GROUP BY id ORDER BY id DESC
    ')->fetchAll();
}

function tagRuleDraft(string $folder, string $needle, array $tags): array
{
    if ($needle === '' || strlen($needle) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $needle) !== 0) {
        throw new InvalidArgumentException('Enter a literal filename fragment of up to 255 bytes.');
    }
    $tags = normalizeMediaMetadataTags($tags);
    if (!$tags) {
        throw new InvalidArgumentException('Enter at least one tag for the rule.');
    }
    return compact('folder', 'needle', 'tags');
}

function saveTagRule(string $directory, ?int $id, array $draft, string $expected): int
{
    ['folder' => $folder, 'needle' => $needle, 'tags' => $tags] = $draft;
    externalMoveScanRoot($directory, $folder);
    return writeMediaMetadata($directory, static function (PDO $database) use ($id, $folder, $needle, $tags, $expected): int {
        $revision = bin2hex(random_bytes(16));
        if ($id === null) {
            $database->prepare('INSERT INTO tag_rules (folder, needle, revision) VALUES (?, ?, ?)')->execute([$folder, $needle, $revision]);
            $id = (int) $database->lastInsertId();
        } else {
            requireTagRuleVersion($database, $id, $expected);
            $database->prepare('UPDATE tag_rules SET folder = ?, needle = ?, revision = ? WHERE id = ?')
                ->execute([$folder, $needle, $revision, $id]);
            $database->prepare('DELETE FROM tag_rule_tags WHERE rule_id = ?')->execute([$id]);
        }
        $insert = $database->prepare('INSERT INTO tag_rule_tags (rule_id, tag_id) VALUES (?, ?)');
        foreach (resolveTagIds($database, $tags) as $tag) {
            $insert->execute([$id, $tag]);
        }
        removeUnusedTags($database);
        return $id;
    });
}

function requireTagRuleVersion(PDO $database, int $id, string $expected): void
{
    $rule = readTagRules($database)[$id] ?? null;
    if ($rule === null || tagChangeToken($rule) !== $expected) {
        throw new DomainException('The rule changed. Open it again to review the current settings.');
    }
}

function previewDraftTagRule(?PDO $database, string $directory, array $draft): array
{
    $catalog = $database === null ? ['names' => [], 'lookup' => []]
        : readTagRelationships($database, getMediaMetadataSchemaVersion($database));
    $tags = [];
    foreach ($draft['tags'] as $name) {
        $id = $catalog['lookup'][strtolower($name)] ?? null;
        $tags[$id ?? 'draft:' . strtolower($name)] = $id === null ? $name : $catalog['names'][$id];
    }
    return previewTagRule($database, $directory, array_replace($draft, ['tags' => $tags]));
}

function previewTagRule(?PDO $database, string $directory, array $rule): array
{
    $folder = $rule['folder'];
    $inventory = externalMoveInventory($directory, $folder);
    $records = [];
    $version = $database === null ? 0 : getMediaMetadataSchemaVersion($database);
    $assignments = null;
    if ($version > 0) {
        if ($version >= 3) {
            foreach (scopedMediaRecords($database, $folder) as $record) {
                $records[$record['path']] = $record;
            }
        }
        $key = $version < 3 ? 'media_path' : 'media_id';
        $assignments = $database->prepare("SELECT tag_id FROM media_tags WHERE $key = ?");
    }
    $matches = $media = $blocked = [];
    $present = $additions = 0;
    foreach ($inventory['entries'] as $path => $identity) {
        if ($identity === null) {
            continue;
        }
        $media[$path] = $identity;
        if (!str_contains(basename($path), $rule['needle'])) {
            continue;
        }
        $record = $records[$path] ?? null;
        $current = [];
        if ($record !== null && $record['inode'] !== null && !sameMediaFile($identity, $record)) {
            $blocked[] = $path;
            continue;
        }
        if ($assignments !== null && ($record !== null || $version < 3)) {
            $assignments->execute([$record['id'] ?? $path]);
            $current = $assignments->fetchAll(PDO::FETCH_COLUMN);
        }
        $existing = array_intersect_key($rule['tags'], array_fill_keys($current, true));
        $add = array_diff_key($rule['tags'], $existing);
        $matches[$path] = ['record' => $record['id'] ?? null, 'add' => $add, 'existing' => $existing];
        $additions += count($add);
        $present += count($existing);
    }
    ksort($matches, SORT_STRING);
    ksort($media, SORT_STRING);
    sort($blocked, SORT_STRING);
    $unreadable = $inventory['unreadable'];
    $skipped = $inventory['skipped'];
    $root = array_intersect_key($inventory['rootIdentity'], ['dev' => true, 'ino' => true]);
    $token = tagChangeToken([$directory, $root, $rule, $media, $matches, $blocked, $unreadable, $skipped]);
    return compact('matches', 'present', 'additions', 'blocked', 'unreadable', 'skipped', 'token') + ['identities' => $media];
}

function applyTagRule(string $directory, int $id, string $token): array
{
    return writeMediaMetadata($directory, static function (PDO $database) use ($directory, $id, $token): array {
        $rule = readTagRules($database)[$id] ?? throw new DomainException('The rule is no longer available.');
        requireNoPendingMediaRelocation($database);
        $preview = previewTagRule($database, $directory, $rule);
        if ($preview['token'] !== $token || $preview['unreadable'] || $preview['blocked']) {
            return ['preview' => $preview, 'rule' => $rule];
        }
        if ($preview['additions'] === 0) {
            return ['added' => 0];
        }

        // Resolve every matching path and its current catalog record before making any assignments.
        $root = externalMoveScanRoot($directory, $rule['folder']);
        $identities = [];
        foreach ($preview['matches'] as $path => $match) {
            $file = checkScopedMediaPath($directory, $rule['folder'], $root, $path);
            $identity = $file['identity'] ?? null;
            if ($file['state'] !== 'present' || $identity !== $preview['identities'][$path]) {
                throw new DomainException('A matching file changed during the scan. Preview the rule again.');
            }
            requireMatchingMediaRecord($database, $path, $identity);
            $identities[$path] = $identity;
        }

        $description = ($rule['folder'] === '' ? 'All folders' : $rule['folder']) . ' · ' . $rule['needle'];
        $database->prepare('INSERT INTO tag_rule_runs (description) VALUES (?)')->execute([$description]);
        $run = (int) $database->lastInsertId();
        $create = $database->prepare('INSERT INTO media_metadata (path) VALUES (?)');
        $add = $database->prepare('INSERT INTO media_tags (media_id, tag_id) VALUES (?, ?)');
        $track = $database->prepare('INSERT INTO tag_rule_additions (run_id, media_id, tag_id) VALUES (?, ?, ?)');
        foreach ($preview['matches'] as $path => $match) {
            if (!$match['add']) {
                continue;
            }
            $record = $match['record'];
            if ($record === null) {
                $create->execute([$path]);
                $record = (int) $database->lastInsertId();
            }
            recordMediaIdentity($database, $record, $path, $identities[$path]);
            foreach ($match['add'] as $tag => $unused) {
                $add->execute([$record, $tag]);
                $track->execute([$run, $record, $tag]);
            }
        }
        advanceTagCatalogRevision($database);
        return ['added' => $preview['additions']];
    });
}

function undoTagRuleRun(PDO $database, int $id): int
{
    requireNoPendingMediaRelocation($database);
    $statement = $database->prepare('
        SELECT media_id, tag_id, path FROM tag_rule_additions
        JOIN media_metadata ON media_id = media_metadata.id WHERE run_id = ?
    ');
    $statement->execute([$id]);
    $assignments = $statement->fetchAll();
    $remove = $database->prepare('DELETE FROM media_tags WHERE media_id = ? AND tag_id = ?');
    $paths = [];
    foreach ($assignments as $assignment) {
        $remove->execute([$assignment['media_id'], $assignment['tag_id']]);
        $paths[$assignment['path']] = true;
    }
    foreach (array_keys($paths) as $path) {
        removeEmptyMediaMetadata($database, $path);
    }
    $database->prepare('DELETE FROM tag_rule_runs WHERE id = ?')->execute([$id]);
    removeUnusedTags($database);
    if ($assignments) {
        advanceTagCatalogRevision($database);
    }
    return count($assignments);
}
