# Booru-style search

## Goal

Use one search box to combine filename terms, tags, and clip properties. You can type `subject:train -status:draft is:favorite rating:>=4 type:video`, click tags on cards, or remove individual filters as chips. Search stays within the selected folder and keeps the current sort order, including seeded shuffle.

## First release query language

Space-separated terms are combined with AND. A leading `-` excludes matches for that term. Double quotes keep spaces inside a term or value. Inside quotes, `\"` represents a literal quote and `\\` represents a literal backslash.

| Query | Matches |
| --- | --- |
| `demo -draft` | Paths containing `demo` and excluding `draft`, as in the current gallery |
| `file:"train yard"` | The phrase `train yard` anywhere in the relative file path |
| `subject:train -status:draft` | Clips with the exact `subject:train` tag and without `status:draft` |
| `tag:"night train"` | The exact unnamespaced tag `night train` |
| `subject:"night train"` | The exact namespaced tag `subject:night train` |
| `tag:type:video` | The literal tag `type:video` when a tag uses a reserved field name |
| `type:video` or `type:gif` | Videos or GIFs, using the gallery's existing media types |
| `is:favorite`, `is:marked` | Favorites or clips marked for deletion |
| `is:untagged`, `is:unrated` | Clips with zero tags or no star rating |
| `rating:4`, `rating:>=4` | Exactly four stars, or four stars and above |

Recognize `file`, `tag`, `type`, `is`, and `rating` as reserved fields. Treat other `namespace:value` terms as exact tag names. Match tags with the existing SQLite ASCII case-folding behavior and keep their stored spelling for display. Unrated clips fail positive rating comparisons. Files with no SQLite row count as untagged and unrated.

Show an inline error for an unclosed quote, an empty field value, an invalid rating, or an unsupported value for a reserved field. Keep the query available to correct. Preserve existing filename matching and accept the current `query`, `tag`, `favorites`, `rating`, and `marked` URL parameters so existing links still work. Combine those parameters with typed terms using AND.

## Search interface

- Show one search box, applied filter chips, and a short syntax guide. Use the existing folder and sort controls. Keep the Favorites and Marked for deletion shortcuts and show their active filters as chips.
- Clicking a card tag adds it as an exact filter. Add it once and switch an existing exclusion for the same tag to an inclusion. A chip can be removed individually and the remaining query stays active.
- Offer tag suggestions from the library with counts of matching clips in the submitted search and folder. Fetch the counts when suggestions are first opened for that view, then match the draft prefix in memory and display up to 20 suggestions. Label counts as being in the current results. Invalidate them after a view change or a successful metadata edit. Preserve a typed `-` when accepting an exclusion suggestion.
- Use Up and Down to move through suggestions, Tab or Enter to accept a highlighted suggestion, and Esc to dismiss. Enter submits the search when no suggestion is highlighted. Standard form submission and card tag links work with JavaScript disabled.

## Implementation sequence

1. **Parse and evaluate one query.** Convert the text and legacy filters into typed terms in PHP. Match file and media-type terms before fetching metadata where possible. Reuse SQLite annotation reads for tag, rating, and status predicates. Use the same matcher for the first page, subsequent batches, and membership checks after a viewer edit.
2. **Keep pagination and links consistent.** Include the effective search and sort in the cursor fingerprint. Reset to the first page when terms change, and reuse the grid's refresh behavior when an edit changes membership. Use one canonical formatter for query chips, card tag links, and suggestions so quoted or reserved tag names round-trip.
3. **Add suggestions and chips.** Count each matching live file once per tag. Exclude stale database paths from suggestions. Keep suggestion results in the current tab's memory and measure how long they take to load before introducing a persistent index.
4. **Verify the offline bundle.** Include any new search code and assets in `build.php`. Serve search and suggestion responses from localhost with `Cache-Control: no-store`. Keep searches, counts, and autocomplete drafts out of SQLite and app logs. Bookmarkable queries appear in URLs, so browser history belongs in the dedicated unsynced profile on the encrypted volume.

## Follow-up

- Add parenthesized OR groups such as `subject:train (location:station OR location:yard) -status:draft`. The group matches at least one alternative and combines with surrounding terms using AND. Design the grammar before supporting nested groups.
- Add duration and dimension comparisons once local media metadata is available, such as `duration:>=30s width:>=1920`. Unknown values fail positive comparisons. Read available metadata during search and perform media probing separately.
- Keep saved searches, tag aliases, and tag implications in their own milestones.

## Acceptance checks

- Test legacy links and `demo -draft`, quoted tags, literal reserved names, negation, AND, rating comparisons, and files without saved metadata. Verify malformed queries preserve the input and show an error.
- In a disposable library, combine tags, favorite status, media type, and rating across several batches. Verify stable order, no duplicates, and a refresh prompt when a viewer edit removes a clip from the result set.
- Verify that suggestions count only live files in the current view, can be selected by keyboard, and refresh after edits. Check that clicking a tag and typing its equivalent produce the same results.
- Repeat in the single-file build with outbound internet blocked. Verify the no-JavaScript form fallback and confirm searching creates no new database, cache, or history files owned by the app.
