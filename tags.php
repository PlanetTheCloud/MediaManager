<?php

function initializeTagRelationships(PDO $database): void
{
    $database->exec(<<<'SQL'
        CREATE TABLE tag_aliases (
            name TEXT PRIMARY KEY NOT NULL COLLATE NOCASE,
            tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE
        );
        CREATE INDEX tag_aliases_tag_id ON tag_aliases (tag_id);
        CREATE TABLE tag_parents (
            child_id INTEGER NOT NULL REFERENCES tags(id),
            parent_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (child_id, parent_id),
            CHECK (child_id <> parent_id)
        );
        CREATE INDEX tag_parents_parent_id ON tag_parents (parent_id);
        CREATE TABLE tag_catalog_revision (
            revision TEXT NOT NULL
        );
        INSERT INTO tag_catalog_revision VALUES ('');
        SQL
    );
}

function tagCatalogRevision(string $directory): string
{
    $database = openMediaMetadataDatabase($directory);
    return $database !== null && getMediaMetadataSchemaVersion($database) >= 4
        ? $database->query('SELECT revision FROM tag_catalog_revision')->fetchColumn() : '';
}

function advanceTagCatalogRevision(PDO $database): void
{
    $database->prepare('UPDATE tag_catalog_revision SET revision = ?')->execute([bin2hex(random_bytes(16))]);
}

function readTagRelationships(PDO $database, int $version): array
{
    $names = $aliases = $parents = $lookup = [];
    if ($version > 0) {
        foreach ($database->query('SELECT id, name FROM tags ORDER BY name COLLATE NOCASE') as $row) {
            $names[$row['id']] = $row['name'];
            $lookup[strtolower($row['name'])] = $row['id'];
        }
    }
    if ($version >= 4) {
        foreach ($database->query('SELECT name, tag_id FROM tag_aliases ORDER BY name COLLATE NOCASE') as $row) {
            $aliases[$row['tag_id']][] = $row['name'];
            $lookup[strtolower($row['name'])] = $row['tag_id'];
        }
        foreach ($database->query('SELECT child_id, parent_id FROM tag_parents ORDER BY child_id, parent_id') as $row) {
            $parents[$row['child_id']][] = $row['parent_id'];
        }
    }
    return compact('names', 'aliases', 'parents', 'lookup');
}

function tagAncestors(int $tag, array $parents): array
{
    $visited = [];
    $pending = $parents[$tag] ?? [];
    while ($pending) {
        $parent = array_pop($pending);
        if (!isset($visited[$parent])) {
            $visited[$parent] = true;
            foreach ($parents[$parent] ?? [] as $ancestor) {
                $pending[] = $ancestor;
            }
        }
    }
    return $visited;
}

function effectiveMediaTagIds(array $direct, array $parents, array &$ancestors): array
{
    $effective = $direct;
    foreach ($direct as $tag => $unused) {
        $ancestors[$tag] ??= tagAncestors($tag, $parents);
        $effective += $ancestors[$tag];
    }
    return $effective;
}

function resolveMediaTags(array &$item, array $catalog, array &$ancestors): void
{
    $effective = effectiveMediaTagIds($item['tags'], $catalog['parents'], $ancestors);
    foreach ($effective as $id => $unused) {
        $name = $catalog['names'][$id];
        if (!isset($item['tags'][$id])) {
            $item['inherited_tags'][] = $name;
        }
        $aliases = $catalog['aliases'][$id] ?? [];
        if ($aliases) {
            $item['tag_aliases'][$name] = $aliases;
        }
        foreach ([$name, ...$aliases] as $spelling) {
            $item['tag_keys'][strtolower($spelling)] = true;
        }
    }
    natcasesort($item['inherited_tags']);
    $item['inherited_tags'] = array_values($item['inherited_tags']);
    $item['tags'] = array_values($item['tags']);
}

function resolveTagIds(PDO $database, array $names): array
{
    $find = $database->prepare('SELECT id FROM tags WHERE name = ? UNION ALL SELECT tag_id FROM tag_aliases WHERE name = ?');
    $create = $database->prepare('INSERT INTO tags (name) VALUES (?)');
    $ids = [];
    foreach ($names as $name) {
        $find->execute([$name, $name]);
        $id = $find->fetchColumn();
        $find->closeCursor();
        if ($id === false) {
            $create->execute([$name]);
            $id = (int) $database->lastInsertId();
        }
        $ids[$id] = $id;
    }
    return array_values($ids);
}

function removeUnusedTags(PDO $database): void
{
    $database->exec('
        DELETE FROM tags WHERE NOT EXISTS (SELECT 1 FROM media_tags WHERE tag_id = tags.id)
            AND NOT EXISTS (SELECT 1 FROM tag_aliases WHERE tag_id = tags.id)
            AND NOT EXISTS (SELECT 1 FROM tag_parents WHERE child_id = tags.id)
            AND NOT EXISTS (SELECT 1 FROM tag_parents WHERE parent_id = tags.id)
            AND NOT EXISTS (SELECT 1 FROM tag_rule_tags WHERE tag_id = tags.id)
            AND NOT EXISTS (SELECT 1 FROM bulk_undo_fields WHERE tag_id = tags.id)
    ');
}

function changeDirectMediaTags(PDO $database, int $mediaId, array $added, array $removed): void
{
    $remove = $database->prepare('DELETE FROM media_tags WHERE media_id = ? AND tag_id = ?');
    $add = $database->prepare('INSERT INTO media_tags (media_id, tag_id) VALUES (?, ?)');
    foreach ($removed as $id) {
        $remove->execute([$mediaId, $id]);
    }
    foreach ($added as $id) {
        $add->execute([$mediaId, $id]);
    }
}

function replaceDirectMediaTags(PDO $database, int $mediaId, array $names): void
{
    $ids = resolveTagIds($database, $names);
    $statement = $database->prepare('SELECT tag_id FROM media_tags WHERE media_id = ?');
    $statement->execute([$mediaId]);
    $current = $statement->fetchAll(PDO::FETCH_COLUMN);
    changeDirectMediaTags($database, $mediaId, array_diff($ids, $current), array_diff($current, $ids));
    removeUnusedTags($database);
}

function tagManagerCatalog(?PDO $database): array
{
    $version = $database === null ? 0 : getMediaMetadataSchemaVersion($database);
    if ($version === 0) {
        return ['names' => [], 'aliases' => [], 'parents' => [], 'lookup' => [], 'saved' => [], 'effective' => []];
    }
    $catalog = readTagRelationships($database, $version);
    $catalog['saved'] = $catalog['effective'] = $ancestors = $direct = [];
    $media = $version < 3 ? 'media_path' : 'media_id';
    $previous = null;
    foreach ($database->query("SELECT $media AS media, tag_id FROM media_tags ORDER BY $media, tag_id") as $row) {
        if ($previous !== $row['media'] && $direct) {
            countEffectiveAssignments($catalog, $direct, $ancestors);
            $direct = [];
        }
        $previous = $row['media'];
        $id = $row['tag_id'];
        $direct[$id] = true;
        $catalog['saved'][$id] = ($catalog['saved'][$id] ?? 0) + 1;
    }
    countEffectiveAssignments($catalog, $direct, $ancestors);
    return $catalog;
}

function countEffectiveAssignments(array &$catalog, array $direct, array &$ancestors): void
{
    foreach (effectiveMediaTagIds($direct, $catalog['parents'], $ancestors) as $id => $unused) {
        $catalog['effective'][$id] = ($catalog['effective'][$id] ?? 0) + 1;
    }
}

function requireCatalogTag(array $catalog, int $id): string
{
    return $catalog['names'][$id] ?? throw new DomainException('That tag is no longer available.');
}

function tagChangeToken(array $state): string
{
    return hash('sha256', serialize($state));
}

function tagAssignments(PDO $database, int $id, int $version): array
{
    $identity = $version < 3 ? 'media_path' : 'media_id';
    $statement = $database->prepare("SELECT $identity FROM media_tags WHERE tag_id = ? ORDER BY $identity");
    $statement->execute([$id]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function previewTagRename(array $catalog, int $id, string $requested): array
{
    $source = requireCatalogTag($catalog, $id);
    [$name] = normalizeMediaMetadataTags([$requested]);
    $target = $catalog['lookup'][strtolower($name)] ?? $id;
    return ['source' => $source, 'name' => $name, 'conflict' => $target === $id ? null : $target];
}

function renameCatalogTag(PDO $database, array $catalog, int $id, string $expected, string $requested, bool $keep): void
{
    $preview = previewTagRename($catalog, $id, $requested);
    $source = $preview['source'];
    $name = $preview['name'];
    if ($source !== $expected || $preview['conflict'] !== null) {
        throw new DomainException('The tag name changed or is already in use. Review the rename again.');
    }
    $database->prepare('DELETE FROM tag_aliases WHERE name = ? AND tag_id = ?')->execute([$name, $id]);
    $database->prepare('UPDATE tags SET name = ? WHERE id = ?')->execute([$name, $id]);
    if ($keep && strcasecmp($source, $name) !== 0) {
        $database->prepare('INSERT INTO tag_aliases (name, tag_id) VALUES (?, ?)')->execute([$source, $id]);
    }
}

function previewTagMerge(PDO $database, array $catalog, int $source, int $target, bool $keep): array
{
    $sourceName = requireCatalogTag($catalog, $source);
    $targetName = requireCatalogTag($catalog, $target);
    if ($source === $target) {
        throw new DomainException('Choose two different tags.');
    }

    $version = getMediaMetadataSchemaVersion($database);
    $from = tagAssignments($database, $source, $version);
    $to = tagAssignments($database, $target, $version);
    $overlap = count(array_intersect($from, $to));
    $moves = count($from) - $overlap;
    $result = count($to) + $moves;
    $aliases = [...($catalog['aliases'][$target] ?? []), ...($catalog['aliases'][$source] ?? [])];
    if ($keep) {
        $aliases[] = $sourceName;
    }
    sort($aliases, SORT_STRING);

    $parents = $catalog['parents'];
    $links = [];
    $incoming = [];
    $outgoing = [];
    if (isset($parents[$source])) {
        $parents[$target] = [...($parents[$target] ?? []), ...$parents[$source]];
        unset($parents[$source]);
    }
    foreach ($parents as $child => &$ids) {
        $ids = array_values(array_unique(array_map(static fn ($id) => $id === $source ? $target : $id, $ids)));
        foreach ($ids as $parent) {
            if ($child === $target || $parent === $target) {
                $links[$child . ':' . $parent] = [$child, $parent, $catalog['names'][$child], $catalog['names'][$parent]];
            }
            if ($child === $target) {
                $outgoing[] = $catalog['names'][$parent];
            }
            if ($parent === $target) {
                $incoming[] = $catalog['names'][$child];
            }
        }
    }
    unset($ids);
    ksort($links);
    $cycle = isset(tagAncestors($target, $parents)[$target]);
    $token = tagChangeToken([$source, $target, $sourceName, $targetName, $from, $to, $aliases, $links, $keep]);
    return compact('source', 'target', 'sourceName', 'targetName', 'moves', 'overlap', 'result', 'aliases', 'links',
        'incoming', 'outgoing', 'cycle', 'token', 'keep');
}

function applyTagMerge(PDO $database, array $preview): void
{
    ['source' => $source, 'target' => $target, 'links' => $links] = $preview;
    if ($preview['cycle']) {
        throw new DomainException('This merge would create a self-link or a parent cycle. Edit the parent links first.');
    }
    // A merge consumes the source tag and also changes what overlapping target assignments represent.
    $database->prepare('UPDATE bulk_undo_fields SET touched = 1 WHERE tag_id = ? OR (tag_id = ?
        AND media_id IN (SELECT media_id FROM media_tags WHERE tag_id = ?))')->execute([$source, $target, $source]);
    // A shared target assignment now also represents the source assignment. Preserve it on rule undo.
    $database->prepare('
        DELETE FROM tag_rule_additions WHERE tag_id = ?
            AND media_id IN (SELECT media_id FROM media_tags WHERE tag_id = ?)
    ')->execute([$target, $source]);
    $database->prepare('
        DELETE FROM tag_rule_additions WHERE tag_id = ?
            AND media_id IN (SELECT media_id FROM media_tags WHERE tag_id = ?)
    ')->execute([$source, $target]);
    $database->prepare('INSERT OR IGNORE INTO media_tags (media_id, tag_id) SELECT media_id, ? FROM media_tags WHERE tag_id = ?')
        ->execute([$target, $source]);
    $database->prepare('UPDATE tag_rule_additions SET tag_id = ? WHERE tag_id = ?')->execute([$target, $source]);
    $database->prepare('DELETE FROM media_tags WHERE tag_id = ?')->execute([$source]);
    $database->prepare('INSERT OR IGNORE INTO tag_rule_tags (rule_id, tag_id) SELECT rule_id, ? FROM tag_rule_tags WHERE tag_id = ?')
        ->execute([$target, $source]);
    $database->prepare('DELETE FROM tag_rule_tags WHERE tag_id = ?')->execute([$source]);
    $database->prepare('DELETE FROM tag_parents WHERE child_id IN (?, ?) OR parent_id IN (?, ?)')
        ->execute([$source, $target, $source, $target]);
    $addLink = $database->prepare('INSERT INTO tag_parents (child_id, parent_id) VALUES (?, ?)');
    foreach ($links as [$child, $parent]) {
        $addLink->execute([$child, $parent]);
    }
    $database->prepare('UPDATE tag_aliases SET tag_id = ? WHERE tag_id = ?')->execute([$target, $source]);
    $database->prepare('DELETE FROM tags WHERE id = ?')->execute([$source]);
    if ($preview['keep']) {
        $database->prepare('INSERT INTO tag_aliases (name, tag_id) VALUES (?, ?)')->execute([$preview['sourceName'], $target]);
    }
}

function editCatalogTagLinks(PDO $database, array $catalog, int $id, string $action, string $value): void
{
    requireCatalogTag($catalog, $id);
    if ($action === 'remove-alias') {
        $database->prepare('DELETE FROM tag_aliases WHERE name = ? AND tag_id = ?')->execute([$value, $id]);
    } elseif ($action === 'add-alias') {
        [$alias] = normalizeMediaMetadataTags([$value]);
        if (isset($catalog['lookup'][strtolower($alias)])) {
            throw new DomainException('That name is already used by a tag or alias.');
        }
        $database->prepare('INSERT INTO tag_aliases (name, tag_id) VALUES (?, ?)')->execute([$alias, $id]);
    } else {
        $parent = filter_var($value, FILTER_VALIDATE_INT);
        if ($parent === false) {
            throw new InvalidArgumentException('Choose a parent tag.');
        }
        requireCatalogTag($catalog, $parent);
        if ($action === 'remove-parent') {
            $database->prepare('DELETE FROM tag_parents WHERE child_id = ? AND parent_id = ?')->execute([$id, $parent]);
        } else {
            if ($id === $parent || isset(tagAncestors($parent, $catalog['parents'])[$id])) {
                throw new DomainException('That parent link would create a self-link or a cycle.');
            }
            $database->prepare('INSERT OR IGNORE INTO tag_parents (child_id, parent_id) VALUES (?, ?)')->execute([$id, $parent]);
        }
    }
    removeUnusedTags($database);
}
