# AdamSmartSearchUI

<p align="center">
  <img src="Public/img/smartsearchui.png" width="180" alt="AdamSmartSearchUI module icon">
</p>

<p align="center">
  Enhanced unified conversation search for FreeScout.
</p>

<p align="center">
  <strong>Version 2.8.2</strong> ·
  <a href="https://adamcoffeeoverflow.com">AdamCoffeeOverflow</a> ·
  AGPL-3.0
</p>

## Overview

AdamSmartSearchUI adds an always-visible navbar search and a dedicated Smart Search workspace without modifying FreeScout core files.

Version 2.8.2 is the FreeScout 1.8.233 compatibility and source-reverification release. The 2.8.0 security, privacy, search-bound, wildcard, throttling, and bulk-truthfulness hardening remains intact; the FreeScout contracts used by Smart Search were rechecked against the exact 1.8.233 source before this release.

## Screenshots

### Always-visible navbar search

<img width="477" height="68" alt="AdamSmartSearchUI navbar search" src="https://github.com/user-attachments/assets/ac5b5581-0166-45cd-8414-817c7226ea3c">

### Smart Search workspace

<img width="1536" height="1024" alt="AdamSmartSearchUI search workspace" src="https://github.com/user-attachments/assets/2e2e291e-b1b0-4872-b723-0a74ed7a431a">

### Suggestions and recent searches

<img width="468" height="255" alt="AdamSmartSearchUI suggestions dropdown" src="https://github.com/user-attachments/assets/edbad90f-f7fb-4765-9287-bfb79d48d263">

## Features

- Conversation ID, number, subject, and preview search
- Customer name, email, and phone search
- Optional Custom Fields search
- Optional message, reply, and internal-note search
- Mailbox, status, folder, and assignee filters
- Deleted-conversation display
- Always-visible navbar search with suggestions
- Page-based bulk assignment, status, and internal-note actions
- Responsive desktop and mobile interface
- English and French translations

## What changed in 2.8.1

- Reverified Smart Search against FreeScout 1.8.233 (`9492779dfc83fde23073f7faa7d0d44700581f15`)
- Confirmed assigned-only core search still enforces `conversations.user_id = current user`
- Confirmed FreeScout's `sqlEscapeLike()` contract, route throttling, CSRF/CSP web middleware, layout asset hooks, assignee lookup, conversation-number helper, and bulk save/event ordering remain compatible
- Preserved all 2.8.0 authorization, privacy, wildcard, pagination, throttling, invalid-ID, and post-commit truthfulness protections
- No database schema change and no new migration

## 2.8.0 hardening retained

- Matched the prior FreeScout 1.8.232 assigned-only search authorization exactly (`conversations.user_id = current user`)
- Prevented `%` and `_` from acting as implicit SQL wildcards in user search text
- Added maximum query-length and deep-page bounds
- Added authenticated throttling to the search workspace, suggestions, custom-field metadata, recent metadata, and bulk mutation endpoints
- Made bulk assignment/status/note outcomes truthful when persistence succeeds but a later hook/event fails
- Added developer diagnostics for post-save bulk side-effect failures without logging exception messages, SQL, bindings, customer text, or message content
- Scoped recent-search localStorage to the authenticated user and removed the old browser-global history keys
- Preserved PostgreSQL-safe numeric normalization and the existing invalid/undefined ID defenses
- Streamlined the public release package while preserving all runtime files and user-facing documentation
- No new database migration; the existing optional `(mailbox_id, updated_at)` index migration is unchanged

## Compatibility

- FreeScout 1.8.233 target baseline (`9492779dfc83fde23073f7faa7d0d44700581f15`)
- PHP 7.1-compatible syntax
- Laravel 5.5-compatible APIs
- Bootstrap 3 and jQuery
- Custom Fields module optional

No FreeScout core files are modified.

## Installation

1. Download and extract the release archive.
2. Upload the `AdamSmartSearchUI` folder to:

   ```text
   Modules/AdamSmartSearchUI
   ```

3. Activate the module under **Manage → Modules**.
4. Clear caches if required:

   ```bash
   php artisan freescout:build
   php artisan freescout:module-build
   php artisan view:clear
   php artisan cache:clear
   php artisan config:clear
   ```

The module can also be updated from **Manage → Modules** when an update is available.

## Support

- Website: [adamcoffeeoverflow.com](https://adamcoffeeoverflow.com)
- Releases: [GitHub releases](https://github.com/AdamCoffeeOverflow/freescout-Adam-Smart-SearchUI/releases)
- Issues: [GitHub issues](https://github.com/AdamCoffeeOverflow/freescout-Adam-Smart-SearchUI/issues)

## License

AdamSmartSearchUI is released under the [GNU Affero General Public License v3.0](LICENSE).
