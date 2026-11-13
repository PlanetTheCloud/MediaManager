# MediaManager

View and manage videos and gifs in the browser. Designed to be standalone and works without internet connection.  
If you have a lot of video files and would like to view it from the browser in grid view, this is the repo you're looking for.  
This is mostly a PoC and can be implemented in other languages of your liking.  

## Setup

Use PHP 8.1 or newer with the `pdo_sqlite` extension enabled. From the repository root, set `MEDIA_LIBRARY_DIR` to the folder holding your media and launch the local server. In PowerShell:

```powershell
$env:MEDIA_LIBRARY_DIR = 'E:\My Media'
php -S 127.0.0.1:8000 -t assets router.php
```

Open `http://127.0.0.1:8000/`. The router serves the gallery, bundled assets, and media. The `-t assets` argument keeps the media directory outside the server's static document root. If `MEDIA_LIBRARY_DIR` is unset, the gallery uses the repository directory.

For a private library, keep the media folder and a dedicated, unsynced browser profile on the same encrypted volume. Use the server command above to keep SQLite and its sidecars out of HTTP responses. To lock the library, close the browser profile, stop the server, and lock or dismount the volume.

## Single-file build

From the repository root, run `php build.php` to generate `MediaManager.php`. Copy the generated file beside your media. The destination needs PHP 8.1 or newer with `pdo_sqlite` enabled. The styles and scripts are embedded in the file.

From the directory containing your copy of `MediaManager.php`, launch the gallery with an empty document root outside the media library. In PowerShell, create the directory once and reuse it as long as it stays empty:

```powershell
$webRoot = Join-Path $env:TEMP 'MediaManager-webroot'
New-Item -ItemType Directory -Path $webRoot
php -S 127.0.0.1:8000 -t "$webRoot" MediaManager.php
```

Open `http://127.0.0.1:8000/`. By default, the bundle browses the directory containing `MediaManager.php`. Set `MEDIA_LIBRARY_DIR` before starting the server to use a different directory. Metadata stays in `mediamanager.sqlite` in the selected library. The bundle treats a library folder named `assets` like any other media folder.

## Configuration
The `loader.php` is the main logic file where all the processing happens. At the top you can modify the following settings:
- `ITEMS_PER_PAGE`: Controls how many files to show per page.  

## Structure
```
.
├── assets/
│   ├── bootstrap.bundle.min.js
│   ├── bootstrap.min.css
│   ├── gallery.css
│   ├── gallery.js
│   ├── search.js
│   ├── video-js.css
│   └── video.min.js
├── build.php
├── bulk-editing.php
├── bulk-moves.php
├── external-moves.php
├── index.php
├── loader.php
├── manage-tags.php
├── metadata.php
├── router.php
├── search.php
├── tag-rules.php
└── tags.php
```

## Dependencies
- Bootstrap - v5.3.0-alpha3
- VideoJS - v4.0.1

## Supported Formats
The following extension are supported:
- .webm
- .mp4
- .gif
- .mkv
- .m4v

The `MEDIA_TYPES` map in `loader.php` controls file discovery. Playback depends on the browser's support for the container and codecs.

## Search Feature

Use one search box to combine filename terms, tags, and media properties. Separate terms with spaces to require all of them. Prefix a term with `-` to exclude it, and use double quotes around a phrase.

- `demo -draft` searches relative paths.
- `file:"train yard"` searches for a phrase in the relative path.
- `subject:train -status:draft is:favorite rating:>=4 type:video` combines tags, favorite status, rating, and media type.
- `tag:"night train"`, `is:untagged`, and `is:unrated` find exact tags or clips that still need editing.
- `tag:type:video` searches for the literal tag `type:video`. Use `tag:` when a tag starts with a reserved field name.
- `rating:4` finds clips with exactly four stars. `is:marked` finds clips marked for deletion, and `type:gif` finds GIFs.

The reserved fields are `file`, `tag`, `type`, `is`, and `rating`. Other `namespace:value` terms match exact tags. Inside quotes, `\"` represents a double quote and `\\` represents a backslash.

Click a tag on a card to add it to your search, or click a filter chip to remove it. Tag suggestions show counts in the current results. Use the arrow keys to highlight a suggestion, **Tab** or **Enter** to insert it, and **Esc** to dismiss the list.

## Browsing and playback

The grid loads more results as you scroll. Use **Load more** to request a batch manually or retry a failed load. Numbered pages are available with JavaScript disabled.

Open a tile to view it and use **Fullscreen** to expand the viewer. Use the arrow keys to move between clips, **Space** to play or pause, **F** to favorite, **1–5** to rate, **0** to clear the rating, and **T** to edit tags. **Esc** closes the active editor or viewer. Closing the viewer returns you to the opening card.

## Bulk editing

Choose **Select**, then check individual clips or use **Select loaded results**. You can keep selecting as you scroll. Choose an action and use **Preview** to review the files before applying it.

**Undo last bulk edit** reverses the latest batch of tag or deletion-mark changes, skipping assignments or marks that were edited afterward. For moves, the preview lists blocked files and leaves them selected. If a move stops partway through, use **Resume move batch** to continue from the last completed file.

## Favorites, ratings, and tags

Open **Edit details** on a card. Check **Favorite**, choose a rating from one to five stars or **Unrated**, and enter one tag per line. Tags can use a namespace such as `subject:train`. Click **Save** to apply the edits. Clicking a tag on a card filters the gallery by that tag.

The first edit creates `mediamanager.sqlite` directly in the media library directory. SQLite may create temporary journal sidecars next to it. The app stores edits against the file's path relative to that directory.

### Manage tags

Open **Manage tags** to browse library-wide saved assignments, including clips awaiting reconnection. Click a tag to find its clips in the gallery. Use **Edit** to preview renames and merges or to manage aliases and parents. Renames and merges keep the old spelling as an alias by default. Aliases work in searches and exclusions. A clip also matches its tag's ancestors. The viewer lists inherited tags separately, and **Edit tags** changes its direct assignments.

Under **Tagging rules**, enter a folder, a case-sensitive filename fragment, and the tags to add. **Preview draft** checks the matches before you save the rule. Open **Preview saved rule** to review matches in the folder and its descendants before applying. **Undo application** removes the run's additions that still exist. A tag you remove and later re-add manually is preserved.

Tag relationships, rules, and undo records are stored in the library's SQLite database. Opening the manager reads the catalog. A rule preview scans its selected folder without saving the scan results.

## Move, rename, and mark for deletion

Open **Manage file** on a card. Choose an existing destination folder or leave it blank for the library root. Edit the filename while keeping its extension, then click **Move or rename**. The app carries tags, stars, favorites, and the deletion mark to the new path. Moves and renames use hard links for collision-safe file placement, so use a filesystem that supports them, such as NTFS, and keep both paths on the same volume. Use the gallery to preserve details when changing a path.

**Mark for deletion** records a reversible flag in SQLite and leaves the media file in place. Use the **Marked for deletion** filter to review flagged files, and **Clear deletion mark** to keep one. Existing databases are upgraded automatically when you make an edit. The database also stores the pending move record used to recover if the app is interrupted.

### Moves made in Explorer

Select a folder and click **Scan for moves**. The scan checks that folder and its descendants. To find a move between sibling folders, select their common parent. **All folders** checks the configured library root.

Run a scan before moving older annotated clips so MediaManager can record their file identities. An unambiguous same-volume match reconnects automatically. For unresolved records, enter the new path relative to the library and choose **Reconnect**. These actions update the library's SQLite catalog and leave the media files in place.

## Randomize Feature
Choose **Sort → Randomize** to shuffle the gallery. The generated seed in the URL preserves the order for the same set of files.
