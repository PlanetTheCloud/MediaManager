# Recognize moves made outside MediaManager

Add a **Scan for moves** action that reconnects saved tags, favorites, ratings, and deletion marks when a clip is renamed or moved in Explorer. Reconnecting changes the catalog path in SQLite and leaves the media where it is.

## Scan boundary

- Start at the selected gallery folder and scan only that folder and its descendants. **All folders** means the configured library root. The scope follows the folder selection even when a search or other filter narrows the visible results.
- Resolve and validate the selected folder on the server before scanning. Skip symbolic links, junctions, and any entry whose resolved path escapes the selected folder. An unavailable or invalid scope stops the scan. Never fall back to a parent folder or the library root.
- Limit identity backfill, file checks, hashing, and match candidates to the same subtree. Only reconcile catalog entries whose previous path is also in that subtree. To detect a move between sibling folders, explicitly select a common parent and scan there.
- Preserve catalog entries when files are missing or any part of the scan is incomplete. Report skipped or unreadable folders. Require a complete scan of the selected subtree before reconnecting records automatically.

## Matching

- Give annotated media a stable SQLite ID and keep the last known relative path, volume and file IDs, size, and modification time. Store everything in `mediamanager.sqlite` in the configured library directory.
- Backfill identities for annotated files that still exist within the selected scope. For same-volume detection, reconnect automatically when the old path is missing and exactly one new path matches the recorded volume and file IDs, size, and modification time. Recheck the candidate before updating its path in a transaction.
- Leave ambiguous matches, occupied old paths, and records without a captured identity for review. Add hash-based candidate review as a follow-up, using the same scan boundary.

## Implementation sequence

1. **Migrate the SQLite catalog.** Add an integer media ID, a unique library-relative path, and nullable identity fields for volume, file ID, size, and modification time. Migrate tag links from path to media ID in the same transaction. Preserve the annotations and pending move record from existing databases. Keep path-based gallery and form inputs working by resolving them to the current media ID. Update in-app move recovery to change the path on that record and leave its ID and tag links in place. Capture identity when a file is annotated. Existing records get their first identity during an explicit scan while the file is still at its recorded path.
2. **Build a scoped inventory in memory.** Run the scan from an explicit same-origin POST in `index.php`. Derive the scan root from the active folder selection and use `loader.php` to walk that subtree. Check directory entries before descending and verify containment at each level. A directory read or file identity check that fails makes the scan incomplete. The scan reads catalog rows only for paths equal to the scope or beginning with its exact `scope/` prefix. Keep this request separate from the normal gallery load and in-app move recovery. If an in-app move is pending, ask the user to finish that move before running a scan.
3. **Reconcile the inventory with SQLite.** Classify annotated records as unchanged, ready to backfill, ready to reconnect, or needing review. Reconnect only when the original path is absent, one candidate matches all recorded identity fields, and the destination is unclaimed. Treat multiple matching paths and hard-link ambiguity as review cases. After a complete scan, acquire the existing SQLite write lock and recheck the scope, affected rows, original path, and candidate identity before committing. If the inventory changes during the scan or a recheck fails, leave the affected record unchanged and ask for a rescan. Keep the filesystem read-only throughout this workflow.
4. **Show results and let the user relink unresolved records.** Put **Scan for moves** beside the folder controls and show the selected scope before it runs. Report how many records were reconnected, backfilled, or left for review. For unresolved records, show the last known path and any in-scope candidates. A user may select an unclaimed file inside the same scope and explicitly choose **Reconnect**. Revalidate the selection on the server before changing the database. Keep the old record reserved when a different file occupies its path, so the replacement cannot inherit its annotations through a gallery read or edit. Refresh the gallery after a successful reconnect and invalidate any active pagination cursor whose result set changed.
5. **Verify both distributions.** Keep scan results in memory and store durable identities only in the library database. If implementation adds PHP or JavaScript files, include them in `build.php`. Verify the source checkout and the single-file build on a disposable encrypted-library layout. Measure scan time before deciding whether large libraries need a resumable scan.

The first release uses same-volume file identity and an explicit scan. It also supports manual relinking for records whose files moved before identity was captured. SHA-256 candidate review can follow once the scoped scan is working.

## Acceptance checks

- Scan `Trips/2025` and recover a rename there and a move into `Trips/2025/Day 2`. Verify that tags, ratings, favorites, and deletion marks follow the clip.
- Move a clip from `Trips/2025` to `Trips/2026`. Confirm a scan of either child alone leaves the record unchanged and performs no checks in the sibling. Scan `Trips` to find the move.
- Place a junction or symbolic link inside the scope pointing to a sibling or outside the library. Confirm the scan performs no file checks or hashing through the link.
- Remove access to the selected folder or interrupt the scan. Confirm the app reports the incomplete scan, preserves annotations, and performs no broader scan.
- Migrate a copy of a version 2 database containing tags, ratings, favorites, deletion marks, and a pending in-app move. Verify the same annotations after migration and restart. Confirm the initial identity backfill touches only the selected subtree.
- Test a case-only rename on Windows, duplicate hard links, a replacement at the original path, and a destination with its own annotations. Verify each ambiguous case keeps its existing records and that a manual reconnect rejects an out-of-scope or claimed destination.
- Change a candidate between scan and commit, then rerun the scan twice. Verify that a changed candidate is left alone and that rerunning a successful scan produces no duplicate metadata or tag links.
