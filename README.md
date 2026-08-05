# AdamSmartSearchUI

<p align="center">
  <img src="Public/img/smartsearchui-v112.png" width="180" alt="AdamSmartSearchUI module icon">
</p>

<p align="center">
  Enhanced unified conversation search for FreeScout.
</p>

<p align="center">
  <strong>Version 2.7.2</strong> ·
  <a href="https://adamcoffeeoverflow.com">AdamCoffeeOverflow</a> ·
  AGPL-3.0
</p>

## Overview

AdamSmartSearchUI adds an always-visible navbar search and a dedicated Smart Search workspace without modifying FreeScout core files.

Version 2.7.2 keeps the v2.7.1 split-search performance improvements and corrects navbar suggestion styling when Windows or the browser uses dark mode while FreeScout remains in its light theme.

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

## What changed in 2.7.2

- Prevented the operating-system/browser dark preference from darkening only the Smart Search dropdown while FreeScout remains light
- Added explicit light-theme text, heading, hover, focus, and keyboard-active contrast
- Improved the existing FreeScout dark-theme dropdown states, including active keyboard selection
- No database migration and no search-behavior changes

## Compatibility

- FreeScout 1.8.211 or newer recommended
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
