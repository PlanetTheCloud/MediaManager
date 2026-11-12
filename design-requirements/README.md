# MediaManager design requirements

MediaManager is a single-user, offline gallery for videos and GIFs already organized in folders. It combines Foldergram-style browsing with Hydrus-style tags and search. The app reads media in place and runs locally in the browser.

## Library storage and locking

- Store `mediamanager.sqlite` **directly in the configured media library directory**, alongside its media files and subfolders. Each library has its own database. The library directory must be on an encrypted volume that the user can lock or dismount independently.
- Store the file catalog, tags, favorites, star ratings, and any saved searches or collections in that database. Keep SQLite journals and sidecar files beside it. Keep SQLite temporary data in memory or on the same encrypted volume. If the library directory becomes unavailable, stop library reads and writes. Require an explicit first-time setup to create a catalog.
- Bind the server to `127.0.0.1` and bundle the assets it needs. Serve media through a route confined to the configured library directory. Keep the database, its sidecars, and other app data inaccessible over HTTP.
- Use a dedicated, unsynced browser profile on the encrypted volume. Send `Cache-Control: no-store` on library pages, search results, and media. Remove the PHP sessions that currently persist file lists. Keep telemetry, analytics, playback history, and persistent search or request logs out of the app.
- Generate previews on demand. Persistent thumbnails are off by default. If added later, make them opt-in, place them under the library directory, and show their location, size, and a clear-cache control. Document every persistent write and its location.
- Provide **Close & lock**. Save pending edits, close the viewer, stop the server, and lock or dismount the volume. Report success only after the volume is confirmed locked. The app must stop showing library data until the volume is unlocked and the app is started again.

The app controls its own storage and browser profile. Windows can also write to the system drive through paging, hibernation, or crash dumps. OS-level traces require separate controls.

## Feature plan

| Release | Features |
| --- | --- |
| 1. Browse, tag, and favorite | All Media, Folders, and Favorites. Responsive grid with infinite scroll, a Load more fallback, and scroll restoration after closing a clip. Fullscreen viewer with keyboard navigation and one active player. One-click favorites, one to five stars with an unrated state, namespaced tags, tag autocomplete and counts, bulk tagging, and filters for folder, filename, tag, media type, favorite status, and rating. |
| 2. Organize and rediscover | Saved searches, collections, and an Untagged queue. Rename and merge tags, add aliases, support OR groups, and filter by duration and dimensions. Add seeded shuffle and a full-height clips view. |
| 3. Manage larger libraries | Tag implications with inherited tags shown in the viewer. Preview filename and folder tagging rules before applying them. Review exact duplicates, then evaluate similar-media review. Offer optional persistent thumbnails on the encrypted volume. |

Support queries such as `subject:train -status:draft is:favorite type:video` and let users build the same query by clicking tags and filters. Preserve existing filename searches such as `demo -draft`.

## Implementation priorities

1. Establish the library and lock boundary before collecting metadata. Replace the existing PHP session file lists and serve only approved media paths. Refuse paths that resolve outside the library.
2. Use cursor-based pagination with a stable tie-breaker. Apply filters before fetching each batch. When a rescan changes the result set, offer a refresh instead of reordering the grid mid-scroll.
3. Replace preloaded players on grid cards with lightweight tiles. Load previews near the viewport, keep active previews bounded, and make failed batches retryable in place.
4. Assign stable item IDs. Use content hashes to recognize moves and renames when the match is unambiguous. Retain catalog entries through an unavailable volume or interrupted scan. Make bulk tag edits transactional and undoable.

## First release checks

- Scroll a large disposable library without duplicate or skipped items. Verify the viewer returns to the same card and keyboard focus.
- Tag a batch, favorite a clip, restart, and find both by search. Rename a tagged clip and confirm its metadata survives a rescan.
- Verify that the database and any SQLite sidecars appear directly in the media library directory and cannot be retrieved through the app. Confirm that browsing and metadata edits work with outbound internet access blocked.
- Exercise a normal close and an interrupted session with disposable media. Check where files were written. After locking the volume, verify that a clip, its filename, tags, and favorites are inaccessible through the viewer until the library is unlocked and reopened.
