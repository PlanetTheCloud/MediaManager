# Infinite grid and fullscreen viewer

## Goal

Open a folder or filtered view, scroll through its clips, and open any clip to watch and edit. Closing the viewer returns to the same card and restores keyboard focus. The milestone covers single-item browsing and edits.

## Infinite grid

- Server-render the first batch using the existing page size. Keep the numbered page links available without JavaScript. When JavaScript is active, load another batch near the end of the grid and show a **Load more** button for manual loading or retry.
- Use the same folder, filename search, tag, rating, favorite, deletion-mark, and sort filters for every batch. Keep a single request in flight for the current grid. Discard responses from an older view after the user changes filters.
- Preserve the existing filename, date, and seeded shuffle orders. Use a continuation cursor tied to the active filters and ordered result set. If a move, rename, or edit changes which items appear or their order, offer **Refresh results** and keep the already loaded cards in place until the user refreshes.
- Render videos as lightweight tiles with a play affordance. Keep GIFs lazy-loaded and use stable tile sizes so the grid stays in place as they load. Show clear loading, retry, empty, and end-of-results states.

## Fullscreen viewer

- Open a viewport-filling viewer from a tile by click, tap, or Enter. Provide visible Previous, Next, and Close controls, along with a button for browser fullscreen. Previous and Next follow the grid's current order. At the last loaded card, Next loads the next batch when one exists.
- Use a single video player with play, pause, mute, seek, and fullscreen controls. Load the selected video on open and start it paused. Pause and release the media source when switching clips or closing the viewer. Display the original GIF when selected.
- Show the filename, favorite, stars, and tags in the viewer. Apply favorite and rating changes individually so they preserve tags and deletion marks. Reflect successful edits on the grid card. If an edit removes the clip from the active filter, offer **Refresh results** and keep navigation stable until refresh. Surface save failures and keep the last confirmed values.
- Keep focus inside the open viewer. Return focus and scroll position to the clip that opened it. If that card is unavailable, focus the nearest visible card or the grid heading. Keep the controls usable on narrow screens and with reduced motion enabled.

| Shortcut | Action |
| --- | --- |
| Enter on a grid tile | Open the viewer |
| Left / Right | Previous / next clip |
| Space | Play or pause video |
| F | Toggle favorite |
| 1–5, 0 | Set stars or clear rating |
| T | Open the tag editor |
| Esc | Close the active editor, exit browser fullscreen, or close the viewer at the current level |

Viewer shortcuts apply while it has focus. Except for Esc, leave shortcuts inactive while the user types or uses focused playback controls. Leave browser shortcuts with modifiers alone. Show the shortcut list in the viewer.

## Implementation sequence

1. **Share the gallery query and card renderer.** In `loader.php`, reuse the current filesystem scan, filtering, and ordering for the HTML page and its batch response. In `index.php`, render cards through one shared function. Include the view identity, the last item's relative path, and a fingerprint of the ordered matching paths in the cursor. Validate it against the current query before returning another batch. Keep folder lists and tag suggestions in the initial page and measure scan time before introducing an index.
2. **Add progressive loading.** Request batches from the existing gallery route. Return the rendered cards and next cursor. Use local JavaScript to observe the grid boundary, append each batch once, and retry without losing scroll position. Keep the existing numbered page flow as the fallback.
3. **Add the viewer and shortcuts.** Use the loaded cards as the navigation order and the existing `/media` route for playback. Reuse the current tag validation and SQLite write transactions. Add field-specific favorite and rating writes through the same-origin gallery POST route.
4. **Keep source and standalone builds in sync.** Serve the local gallery script through the router and include it in `build.php` so the generated `MediaManager.php` works offline. Apply `Cache-Control: no-store` to batch responses and media. Keep scroll, cursors, and playback state in memory. Persist only explicit metadata edits in the library's SQLite database. If the server confirms that the library is unavailable, close the viewer, release media, and clear the rendered library data.

## Acceptance checks

- Browse several batches in a disposable library with tied timestamps. Repeat with filename search, combined filters, and a seeded shuffle. Each matching file appears once in the expected order.
- Change filters during a batch request, then test a failed request and a move between batches. Confirm that the grid discards stale results, retries cleanly, and offers refresh when the result set changes.
- Play and seek a video and open a GIF in the viewer. Navigate across a batch boundary with the keyboard, then close and confirm focus and scroll return to the opener. Confirm that switching clips stops the previous player and that typing in the tag editor leaves shortcuts inactive.
- Favorite, rate, and tag a clip from the viewer. Reopen the gallery to verify persistence. Confirm that changing a rating preserves tags and a deletion mark.
- Repeat a smoke test in the single-file build with outbound internet access blocked. Confirm that numbered pages work with JavaScript disabled, browsing produces no new persistent cache files, and a library-unavailable response clears the viewer and grid.
