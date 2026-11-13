# Repository Guidelines

## Project Structure

- `index.php` is the browser entry point. It renders the media grid, folder menu, search form, and pagination.
- `loader.php` discovers media files and folders, handles search and sorting, and prepares the data for `index.php`. Set `ITEMS_PER_PAGE` here to change the page size.
- `assets/` contains the bundled Bootstrap and Video.js files used for offline operation. `README.md` documents setup and supported formats.

## Local Development

Run `php -S 127.0.0.1:8000` from the repository root and open `http://127.0.0.1:8000/` in a browser. The app runs directly from the PHP files and bundled assets. Put sample media in the root or a subfolder to exercise file discovery. Use disposable samples and keep personal media out of commits.

## Coding Style

Use four spaces for indentation in PHP and HTML. Match the surrounding brace style, with function braces on the next line and control-flow braces on the same line. Preserve existing helper names such as `gatherMediaFiles()` and `sort_by_time()`. Use uppercase snake case for constants such as `ITEMS_PER_PAGE`. Keep file discovery and filtering in `loader.php` and rendering in `index.php`.

## Testing

Validation is manual. Run `php -l index.php` and `php -l loader.php` to check PHP syntax. In the browser, confirm that a GIF displays, a video plays, and a subfolder can be selected. Check search with a query such as `demo -draft`, then use more than 15 files to verify pagination with the default settings. For sorting changes, check `?newest`, `?oldest`, and repeatable ordering when reopening a seeded Randomize URL.

## Commits and Pull Requests

Recent commits use short subjects such as `Update loader.php` and `Fix #2`. Use a concise subject that describes the change. In the pull request, summarize the user-facing behavior, list the checks you ran, link the related issue when applicable, and include a screenshot for visible UI changes.
