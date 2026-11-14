# Duplicate review

**Find duplicates** lets users compare byte-identical clips side by side, choose a copy to keep, and use reversible deletion marks on the copies selected for cleanup. See the [gallery guide](../README.md#duplicate-review) for usage.

## Scan scope and matching

- Scan on demand within the selected folder and its descendants. **All folders** means the configured library root. The folder sets the scan scope even when a search narrows the visible grid. To compare across sibling folders, select their common parent.
- Reuse the external-move scope checks. Reject unavailable scopes and skip symbolic links, junctions, and paths that resolve outside the selected folder. Report unreadable or changing files and distinguish an incomplete scan from a completed scan with zero matches.
- Group eligible videos and GIFs by byte size. Stream SHA-256 only for size groups with more than one candidate, then group matching digests. Compare filesystem identities so multiple hard links to the same file are identified separately from independent copies. A group of hard links to one file alone does not count as duplicate storage.
- Verify the opened file against the scan inventory and compare its identity, size, and modification time before and after hashing. Recheck the path before accepting the result. Exclude files that fail those checks. Keep hash results in memory for the review session and allow the user to rescan.

## Review and cleanup

- Show each group's relative paths, sizes, tags, ratings, favorites, and deletion marks. Use the existing media route to preview two selected items side by side. A group can contain more than two copies. Let the user switch the comparison pair while keeping video playback on demand.
- Let the user designate a keeper and explicitly select copies to mark. Require at least one unmarked, verified copy to remain after applying. Highlight selected copies with tags, ratings, or favorites so users can spot metadata they want to keep. Metadata stays attached to each file. Annotation merging is a separate feature decision.
- Before applying, revalidate the keeper and every selected copy against the original scope and confirm that their contents still match. Hash outside the SQLite write lock, then recheck file snapshots and deletion marks under the lock. A stale group returns to review without changing its marks.
- Apply marks in one transaction through the existing bulk-edit and undo flow. Show the changed count and **Undo**. Keep all media files in place.

## Implementation

- `duplicate-review.php` reuses the scoped inventory and path checks in `external-moves.php`. The explicit scan runs synchronously and keeps the results in review-page memory.
- The review page loads previews from `/media` and preserves the gallery URL for **Back to gallery**. It clears the results when the app confirms the library is unavailable.
- The review rehashes files before SQLite takes the write lock. Marking shares the transaction, receipt, and undo history in `bulk-editing.php`.
- Both the source app and standalone build serve the review with `Cache-Control: no-store`.

## Acceptance checks

- Verify identical files with different names group together, while same-size files with different bytes remain separate. Confirm unique-size files are never hashed and hard-linked paths do not inflate the independent-copy count.
- Scan one child folder and verify a duplicate in its sibling is never opened or shown. Scan the common parent to find both. Include a junction and an unreadable candidate to verify reporting and scope handling.
- Review a three-copy group. Confirm that only explicitly selected copies are marked, an unmarked keeper remains, annotations are preserved, and Undo restores the changed marks without touching later unrelated edits.
- Change or move a file between scan and Apply. Confirm the stale group is reported and its marks are unchanged. Check the same behavior when the library becomes unavailable.

Re-encoded, trimmed, or visually similar clips are follow-up work. Hash-based move review can reuse the streaming hash helper once it has a way to record a fingerprint before the original file disappears.
