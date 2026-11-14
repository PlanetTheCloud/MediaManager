# MediaManager design requirements

MediaManager is a single-user, offline gallery for videos and GIFs already organized in folders. It combines Foldergram-style browsing with Hydrus-style tags and search. The app reads media in place and runs locally in the browser.

## Library storage and locking

- Store `mediamanager.sqlite` **directly in the configured media library directory**, alongside its media files and subfolders. Each library has its own database. The library directory must be on an encrypted volume that the user can lock or dismount independently.
- Store the file catalog, tags, favorites, star ratings, and any saved searches or collections in that database. Keep SQLite journals and sidecar files beside it. Keep SQLite temporary data in memory or on the same encrypted volume. If the library directory becomes unavailable, stop library reads and writes. Require an explicit first-time setup to create a catalog.
- Bind the server to `127.0.0.1` and bundle the assets it needs. Serve media through a route confined to the configured library directory. Keep the database, its sidecars, and other app data inaccessible over HTTP.
- Use a dedicated, unsynced browser profile on the encrypted volume. Send `Cache-Control: no-store` on library pages, search results, and media. Keep telemetry, analytics, playback history, and persistent search or request logs out of the app.
- Generate previews on demand. Persistent thumbnails are off by default. If added later, make them opt-in, place them under the library directory, and show their location, size, and a clear-cache control. Document every persistent write and its location.
- Provide **Close & lock**. Save pending edits, close the viewer, stop the server, and lock or dismount the volume. Report success only after the volume is confirmed locked. The app must stop showing library data until the volume is unlocked and the app is started again.

The app controls its own storage and browser profile. Windows can also write to the system drive through paging, hibernation, or crash dumps. OS-level traces require separate controls.

## TODO

- [ ] **Open & lock.** Launch a dedicated browser profile on the encrypted volume and implement the lock sequence above.
- [ ] **Advanced Booru-style search.** Add OR groups and duration and dimension comparisons.
- [ ] **Saved views and collections.** Add saved searches, manual collections, an Untagged queue, and an Unrated favorites view. Consider a continuous clips feed for rediscovery.
- [ ] **Hash-based move review.** Suggest same-size files with matching content hashes within the selected folder and its descendants.
- [ ] **Duplicate review.** Compare exact duplicates side by side and evaluate similar-media review once that workflow is useful.
- [ ] **Optional thumbnails.** Offer an opt-in thumbnail cache under the media library directory if the grid needs it. Show its size and location and provide a clear-cache control.

## Library and privacy checks

- Tag a batch, favorite a clip, restart, and find both by search. Rename a tagged clip and confirm its metadata survives a rescan.
- Verify that the database and any SQLite sidecars appear directly in the media library directory and cannot be retrieved through the app. Confirm that browsing and metadata edits work with outbound internet access blocked.
- Exercise a normal close and an interrupted session with disposable media. Check where files were written. After locking the volume, verify that a clip, its filename, tags, and favorites are inaccessible through the viewer until the library is unlocked and reopened.
