# Tag management

Add a **Manage tags** page for a single library. Start with the operations that clean up tags users already have, then add relationships and rules. Keep tag definitions, relationships, and rules in the library's existing `mediamanager.sqlite` database. Browsing and previewing should be read-only and work offline.

## Phase 1. Browse, rename, and merge

- List tags with their namespace and assignment count. Search by full name or namespace and open the matching clips in the gallery. Label counts as **Saved assignments** because some annotated clips may be temporarily missing or awaiting reconnection. Opening the manager requires no filesystem scan.
- **Rename** changes the name of an existing tag and preserves its ID and every assignment. Use the current tag normalization and ASCII case-insensitive uniqueness. Support case-only changes. If another tag already has the requested name, show the conflict and offer a separate merge preview.
- **Merge** moves assignments from a source tag to a target. Show how many assignments move, how many clips already have both tags, and the resulting count. Recheck the source, target, and previewed assignment set when applying. Use one SQLite transaction to add missing target assignments, remove source assignments, and remove the source tag. Leave ratings, favorites, and deletion marks intact.
- Treat renames and merges as library-wide changes to catalog records. A folder filter in the gallery does not limit them. The preview must say when a change affects assignments across folders, including records for temporarily missing files.

## Phase 2. Aliases and parent tags

- Add aliases that resolve directly to a canonical tag ID. Require each alias name to be unique across aliases and canonical names. Search and exclusions accept either spelling, while autocomplete and new assignments use the canonical spelling. Keep the old spelling as an alias by default when renaming or merging once aliases are available. Earlier renames and merges may require users to update bookmarked search URLs.
- Store parent relationships between tag IDs. A child can have several parents. Reject self-links and cycles, including cycles that a merge would introduce. Show the resulting aliases and parent links in the merge preview and apply their changes in the same transaction as the assignments.
- Derive inherited tags at read time. If `subject:steam_train` implies `subject:train`, searching or excluding `subject:train` includes clips tagged only with the child. Show inherited tags separately in the viewer and edit only direct assignments. Count a clip once per effective tag, even if it has several paths to that parent.
- Resolve aliases and inherited tags consistently in the gallery, pagination, suggestions, and viewer membership checks. When relationships change a result set, reuse the existing refresh behavior. Preserve tags referenced by aliases or parent relationships when their last direct assignment is removed.

## Phase 3. Preview and apply tagging rules

- Start with folder rules and literal filename matching. Apply rules only on demand to the selected folder and its descendants. Use the same scope validation and link-skipping rules as **Scan for moves**. Block Apply when the preview scan is incomplete.
- Show the matching files, tags to add, and assignments already present. Apply additions only, so manual tags stay intact. If the files or rule changed since preview, show an updated preview before applying. Use one SQLite transaction and make repeated application safe.
- Offer undo for assignments created by that application. Keep the undo record in the same database and preserve subsequent manual edits. Previewing a rule does not create a database, persist file lists, or write a search history.

## Implementation sequence

1. Reuse the existing `tags` and `media_tags` tables and `writeMediaMetadata()` transaction helper for Phase 1. Add a searchable tag page and route rename and merge through same-origin POST requests. Invalidate a merge preview if its assignments change before apply.
2. Add a SQLite migration for aliases and parent edges in Phase 2. Keep the existing media IDs and direct tag links. Use the same effective-tag resolver for search, exclusions, counts, and viewer display. Rebuild the standalone bundle with any new code or assets.
3. Reuse scoped filesystem traversal for Phase 3. Resolve every proposed assignment before writing. Record enough information to undo only the assignments made by that run. Add a separate migration if saved rules and undo records need new tables.

## Acceptance checks

- Rename a tag, including a case-only change, and verify its assignments follow files across folders. Merge tags with overlapping assignments and confirm each affected clip has one target assignment and retains its other details. Verify that a stale preview leaves the catalog unchanged.
- Verify that alias searches and exclusions match the canonical tag. Test inherited tags through two parents, reject cycles, and confirm removing a parent relationship leaves all direct tags intact.
- Preview and apply a rule in a child folder. Verify that sibling folders are untouched, existing tags are preserved, a second apply adds nothing, and undo removes only the additions from that run.
- Repeat representative workflows in the source app and standalone bundle with disposable media. Confirm tag data stays in the library's SQLite database and that opening the manager does not scan the library or write metadata.
