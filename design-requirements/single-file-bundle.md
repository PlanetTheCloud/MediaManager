# Single-file PHP bundle

Provide a standalone build script that generates `MediaManager.php`. The generated file can be copied beside an existing media library and run offline with PHP 8.1 or newer and `pdo_sqlite` enabled.

## Build requirements

- Package `router.php`, `loader.php`, `metadata.php`, and `index.php` into the generated file. Resolve their local PHP includes during the build.
- Embed the bundled Bootstrap and Video.js CSS and JavaScript. Serve them from the existing `/assets/` routes with the same content types so the gallery can load them offline.
- Apply any packaging-specific changes only to the generated file. Keep the existing source files and development workflow intact.

## Runtime requirements

- Use `MediaManager.php` as the router for PHP's built-in server. Bind to `127.0.0.1` and use an empty document root outside the media library. Serve media through `/media` and keep the library and its SQLite files out of static HTTP serving.
- Use the directory containing `MediaManager.php` as the default media library. Honor `MEDIA_LIBRARY_DIR` when set. Keep media in place and continue to store favorites, ratings, and tags in `mediamanager.sqlite` in the library directory.
- Treat a library folder named `assets` as a regular folder for browsing and moves. The `/assets/` URLs serve the embedded dependencies independently of library folders.

## Acceptance checks

- Run `php -l MediaManager.php`, then copy the generated file beside a disposable media library and launch it without the source checkout or an `assets` directory.
- With internet access disabled, verify that the gallery loads its styles and scripts, a GIF displays, and a video plays and seeks.
- Add a media folder named `assets`. Verify that it appears in the folder menu, can be browsed, and can be selected as a move destination.
- Save a favorite, rating, and tag and verify they persist after a restart. Confirm the database and any SQLite sidecars are inaccessible over HTTP.
