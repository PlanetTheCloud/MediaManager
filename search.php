<?php

const GALLERY_SEARCH_WHITESPACE = " \t\r\n\v\f";
const GALLERY_LEGACY_SEARCH_FIELDS = ['tag', 'favorites', 'rating', 'marked'];

function parseGallerySearch(string $query): array
{
    $terms = [];
    $length = strlen($query);
    $offset = 0;
    while ($offset < $length) {
        $offset += strspn($query, GALLERY_SEARCH_WHITESPACE, $offset);
        if ($offset === $length) {
            break;
        }

        $negated = $query[$offset] === '-' && $offset + 1 < $length
            && !str_contains(GALLERY_SEARCH_WHITESPACE, $query[$offset + 1]);
        $offset += (int) $negated;
        $word = '';
        $separator = null;
        $quoted = false;
        for (; $offset < $length; $offset++) {
            $character = $query[$offset];
            if ($character === '"') {
                $quoted = !$quoted;
                continue;
            }
            if ($quoted && $character === '\\' && $offset + 1 < $length && str_contains('"\\', $query[$offset + 1])) {
                $word .= $query[++$offset];
                continue;
            }
            if (!$quoted) {
                if (str_contains(GALLERY_SEARCH_WHITESPACE, $character)) {
                    break;
                }
                if ($character === ':' && $separator === null) {
                    $separator = strlen($word);
                }
            }
            $word .= $character;
        }
        if ($quoted) {
            throw new InvalidArgumentException('Close the quoted search term with a double quote.');
        }

        $field = $separator === null ? 'file' : strtolower(substr($word, 0, $separator));
        $value = $separator === null ? $word : substr($word, $separator + 1);
        if ($field === '' || $value === '') {
            throw new InvalidArgumentException('Each search term needs a value and any tag namespace needs a name.');
        }

        switch ($field) {
            case 'file':
            case 'tag':
                break;
            case 'type':
                $value = strtolower($value);
                if (!in_array($value, ['video', 'gif'], true)) {
                    throw new InvalidArgumentException('Use type:video or type:gif.');
                }
                break;
            case 'is':
                $value = strtolower($value);
                if (!in_array($value, ['favorite', 'marked', 'untagged', 'unrated'], true)) {
                    throw new InvalidArgumentException('Use is:favorite, is:marked, is:untagged, or is:unrated.');
                }
                break;
            case 'rating':
                if (!preg_match('/\A(?:>=)?[1-5]\z/', $value)) {
                    throw new InvalidArgumentException('Use a rating from 1 to 5, such as rating:4 or rating:>=4.');
                }
                break;
            default:
                $field = 'tag';
                $value = $word;
        }
        $terms[] = compact('field', 'value', 'negated');
    }
    return $terms;
}

function gallerySearchTermKey(array $term): string
{
    $value = $term['field'] === 'tag' ? strtolower($term['value']) : $term['value'];
    return serialize([$term['field'], $value, $term['negated']]);
}

function gallerySearchTerms(array $filters): array
{
    $terms = parseGallerySearch($filters['query'] ?? '');
    if (isset($filters['tag'])) {
        $terms[] = ['field' => 'tag', 'value' => $filters['tag'], 'negated' => false];
    }
    if (isset($filters['rating'])) {
        $terms[] = ['field' => 'rating', 'value' => $filters['rating'], 'negated' => false];
    }
    foreach (['favorites' => 'favorite', 'marked' => 'marked'] as $parameter => $status) {
        if (isset($filters[$parameter])) {
            $terms[] = ['field' => 'is', 'value' => $status, 'negated' => false];
        }
    }

    $unique = [];
    foreach ($terms as $term) {
        $unique[gallerySearchTermKey($term)] ??= $term;
    }
    return array_values($unique);
}

function formatGallerySearchTerm(array $term): string
{
    $value = $term['value'];
    if (strpbrk($value, GALLERY_SEARCH_WHITESPACE . '"\\') !== false) {
        $value = '"' . addcslashes($value, '"\\') . '"';
    }
    return ($term['negated'] ? '-' : '') . $term['field'] . ':' . $value;
}

function formatGallerySearch(array $terms): string
{
    return implode(' ', array_map('formatGallerySearchTerm', $terms));
}

function gallerySearchMatches(array $terms, string $file, array $item): bool
{
    foreach ($terms as $term) {
        $value = $term['value'];
        $matches = match ($term['field']) {
            'file' => str_contains($file, $value),
            'tag' => isset($item['tag_keys'][strtolower($value)]),
            'type' => (mediaType($file) === 'image/gif' ? 'gif' : 'video') === $value,
            'rating' => $item['rating'] !== null && (str_starts_with($value, '>=')
                ? $item['rating'] >= (int) substr($value, 2) : $item['rating'] === (int) $value),
            'is' => match ($value) {
                'favorite' => $item['favorite'],
                'marked' => $item['marked_for_deletion'],
                'untagged' => $item['tags'] === [],
                'unrated' => $item['rating'] === null,
            },
        };
        if ($matches === $term['negated']) {
            return false;
        }
    }
    return true;
}
