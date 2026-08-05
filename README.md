# AdamSmartSearchUI

<p align="center">
  <img src="Public/img/smartsearchui-v112.png" width="180" alt="AdamSmartSearchUI module icon">
</p>

<p align="center">
  Fast, permission-safe unified conversation search for FreeScout.
</p>

<p align="center">
  <strong>Version 2.7.1</strong> ·
  <a href="https://adamcoffeeoverflow.com">AdamCoffeeOverflow</a> ·
  AGPL-3.0
</p>

## Overview

AdamSmartSearchUI adds an always-visible navbar search and a dedicated Smart Search workspace without modifying FreeScout core files.

The default search path is optimized for everyday lookups across conversation and customer data. Full message, reply, and internal-note searching remains available as a separate deep-search option so large FreeScout installations are not forced to scan thread bodies during every search.

## Screenshots

### Always-visible navbar search

<img width="477" height="68" alt="AdamSmartSearchUI navbar search" src="https://github.com/user-attachments/assets/ac5b5581-0166-45cd-8414-817c7226ea3c">

### Smart Search workspace

<img width="1536" height="1024" alt="AdamSmartSearchUI search workspace" src="https://github.com/user-attachments/assets/2e2e291e-b1b0-4872-b723-0a74ed7a431a">

### Permission-safe suggestions and recent searches

<img width="468" height="255" alt="AdamSmartSearchUI suggestions dropdown" src="https://github.com/user-attachments/assets/edbad90f-f7fb-4765-9287-bfb79d48d263">

> The screenshots show the established Smart Search interface. Version 2.7.1 also adds the **Search inside messages and internal notes** option to the search filters.

## Features

### Fast default search

Searches the following without scanning every stored thread body:

- Conversation subject and latest preview
- Customer email, first name, last name, full name, and phone data
- Optional Custom Fields values
- Conversation ID, display number, and thread ID resolution
- Numeric references that appear in normal searchable fields

### Optional deep-content search

Enable **Search inside messages and internal notes** when a term may appear only inside:

- Published customer messages
- Published agent replies
- Published internal notes

Deep search is isolated from the normal query path, requires a configurable minimum query length, and uses bounded next-page detection instead of an expensive exact result count.

### Filters and result controls

- Mailbox
- Custom field
- Status
- Folder
- Assignee, including unassigned
- Updated-date sorting
- Recent conversations when the query is empty
- Deleted-state detection using `conversation.state`

### Exact numeric behavior

- `#1234` prefers FreeScout's displayed conversation number
- `1234` prefers the internal conversation ID
- Thread ID lookup remains available as a fallback
- A failed exact lookup continues into normal text search
- Every direct conversation result is checked against FreeScout authorization before redirecting

### Page-only bulk actions

Select visible Smart Search results and:

- Assign or unassign conversations
- Update conversation status
- Add the same internal note to selected conversations

Bulk operations use CSRF protection, validate bounded input, re-check each conversation through FreeScout authorization, verify assignable users per mailbox, and skip inaccessible or deleted conversations.

### Interface

- Bootstrap 3 and jQuery
- Always-visible desktop navbar search
- Mobile-constrained suggestions dropdown
- Responsive results table and bulk toolbar
- `/` keyboard shortcut to focus navbar search
- English and French translations
- Dark-theme-friendly module styling

## What changed in 2.7.1

Version 2.7.1 is a performance-focused split-search release:

- Restored the fast default query path for normal searches
- Moved message, reply, and internal-note matching into a separate deep query
- Added **Search inside messages and internal notes**
- Kept navbar suggestions on the fast path only
- Removed exact total counting from deep searches
- Added bounded `page size + 1` pagination for deep results
- Added an optional zero-result deep-search fallback, disabled by default
- Preserved v2.7.0 permissions, filters, deleted-state badges, and bulk actions

See [CHANGELOG.md](CHANGELOG.md) for the complete release history.

## Compatibility

- Source-reviewed against FreeScout 1.8.229
- FreeScout 1.8.211 or newer recommended
- PHP 7.1-compatible syntax
- Laravel 5.5-compatible APIs
- Bootstrap 3 and jQuery frontend
- MySQL, MariaDB, PostgreSQL, and SQLite-aware search handling
- Custom Fields module optional
- Teams and other assignment extensions supported through FreeScout's assignable-user surface

No FreeScout core files are modified.

## Installation

1. Download and extract the release archive.
2. Upload the `AdamSmartSearchUI` folder to:

   ```text
   Modules/AdamSmartSearchUI
   ```

3. Activate the module under **Manage → Modules**.
4. Rebuild and clear caches:

   ```bash
   php artisan freescout:build
   php artisan freescout:module-build
   php artisan view:clear
   php artisan cache:clear
   php artisan config:clear
   ```

The module loads its optional cross-database performance-index migration during activation.

## Updating

Replace the existing `Modules/AdamSmartSearchUI` folder with the new release package, reactivate the module if required, and run the build/cache commands above.

No new database migration was added in version 2.7.1.

## Configuration

Defaults are stored in `Config/config.php`:

| Setting | Purpose |
|---|---|
| `show_topbar` | Show the always-visible navbar input |
| `use_core_search_icon` | Route the core search icon through Smart Search |
| `hide_core_search_icon` | Hide FreeScout's original search icon |
| `min_query_len` | Minimum normal-search query length |
| `per_page` | Results displayed per page |
| `search_thread_body` | Allow explicit deep-content searching |
| `search_thread_body_fallback` | Automatically try deep search after zero fast matches |
| `search_thread_body_min_query_len` | Minimum deep-search query length |
| `bulk_max_selected` | Maximum conversations in one bulk request |
| `bulk_note_max_length` | Maximum bulk-note length |
| `schema_cache_minutes` | Cache duration for schema capability checks |

For large installations, keep `search_thread_body_fallback` disabled unless its performance has been verified against the production database.

## Security and engineering notes

- Mailbox access is applied before search results are returned
- Assigned-only restrictions are enforced in search queries
- Exact numeric redirects are authorization-checked
- Bulk actions perform object-level authorization per conversation
- State-changing forms use POST and CSRF protection
- The module does not use `$request->all()`
- Heavy message-content matching is excluded from navbar autosuggestions

See [SECURITY.md](SECURITY.md), [ARCHITECTURE.md](ARCHITECTURE.md), and [TEST_EVIDENCE.md](TEST_EVIDENCE.md) for implementation and verification details.

## Support and project links

- Website: [adamcoffeeoverflow.com](https://adamcoffeeoverflow.com)
- Releases: [GitHub releases](https://github.com/AdamCoffeeOverflow/freescout-Adam-Smart-SearchUI/releases)
- Issues: [GitHub issues](https://github.com/AdamCoffeeOverflow/freescout-Adam-Smart-SearchUI/issues)

## License

AdamSmartSearchUI is released under the [GNU Affero General Public License v3.0](LICENSE).
