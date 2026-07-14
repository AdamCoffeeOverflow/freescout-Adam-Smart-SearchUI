# AdamSmartSearchUI

Enhanced unified conversation search for FreeScout.

**Version:** 2.7.0
**Author:** AdamCoffeeOverflow
**License:** AGPL-3.0

## Features

### Search

- Always-visible navbar search with permission-safe suggestions
- Dedicated Smart Search page
- Subject, preview, customer email, customer name, phone, message, reply, and internal-note search
- Optional Custom Fields module integration
- Exact numeric resolution:
  - `#1234` prefers FreeScout's display conversation number
  - `1234` prefers the internal conversation ID
  - thread ID lookup remains available as a fallback
  - failed numeric lookups continue into normal text search
- Mailbox, custom field, sort, status, folder, and assignee filters
- Deleted conversations use `conversation.state` and display a red **Deleted** badge
- Recent results when the search query is empty

### Bulk actions

Select visible Smart Search results and:

- Assign or unassign conversations
- Update conversation status
- Add the same internal note to selected conversations

Bulk actions:

- Use POST + CSRF protection
- Re-check each conversation through FreeScout's update policy
- Skip deleted or inaccessible conversations
- Validate assignees against each mailbox's assignable-user list
- Support assignment extensions such as Teams through FreeScout's assignable-user hooks
- Limit each request to a configurable maximum

### UI

- Compact Bootstrap 3 interface
- Desktop suggestion hover expansion
- Mobile-constrained suggestion dropdown
- Responsive result table
- Keyboard shortcut: `/` focuses navbar search
- English and French translations
- Dark-theme-friendly styling

## v2.7.0

Release-candidate compatibility and cleanup pass based on v2.6.9.

- Preserved the working v2.6.8 navbar and mobile dropdown behavior
- Preserved the page-only bulk action workflow from v2.6.9
- Restored and hardened message/note body searching
- Aligned assigned-only visibility with current FreeScout policy behavior
- Secured exact numeric redirects with FreeScout authorization checks
- Added current conversation-number support while retaining internal-ID behavior
- Updated assignee loading to use FreeScout's assignable-user extension surface
- Corrected folder loading to use FreeScout folder types instead of a nonexistent name column
- Improved MySQL, PostgreSQL, and SQLite query compatibility
- Added a safe cross-database performance-index migration
- Improved mobile result-table and bulk-toolbar behavior
- Cleaned provider bootstrapping, translations, validation, and logging

## Compatibility

- FreeScout 1.8.211 or newer recommended
- Source-reviewed against the current FreeScout `dist` branch
- PHP 7.1+ syntax
- Laravel 5.5-compatible
- Bootstrap 3 + jQuery frontend
- MySQL, PostgreSQL, and SQLite-aware search queries
- Custom Fields module is optional
- Teams and other assignment modules can extend FreeScout's standard assignment hooks

No FreeScout core files are modified.

## Installation

1. Extract the archive.
2. Upload the `AdamSmartSearchUI` folder to:

   `Modules/AdamSmartSearchUI`

3. Activate the module under **Manage → Modules**.
4. Rebuild and clear caches:

```bash
php artisan freescout:build
php artisan freescout:module-build
php artisan view:clear
php artisan cache:clear
php artisan config:clear
```

The module loads its optional performance-index migration during activation.

## Configuration

Edit `Config/config.php` before deployment when needed:

- `show_topbar`
- `use_core_search_icon`
- `hide_core_search_icon`
- `min_query_len`
- `per_page`
- `search_thread_body`
- `bulk_max_selected`
- `bulk_note_max_length`
- `schema_cache_minutes`

## Update notes

Replace the existing module folder with the new release package, reactivate if required, and run the cache/build commands above.

## Security

See [SECURITY.md](SECURITY.md) for reporting instructions and implementation safeguards.
