# AdamSmartSearchUI

Enhanced unified search module for FreeScout.

Version: 2.4.1  
Author: AdamCoffeeOverflow  
Author URL: https://github.com/AdamCoffeeOverflow  

## Features

- Exact numeric conversation search (conversation ID or thread ID)
- Common wrappers supported (#123, [#123], (123))
- Email search
- Phone search
- Customer name search
- CustomFields search
- Includes closed conversations (no status filtering)
- Mailbox permission-safe filtering
- Performance-optimized queries

### UI enhancements

- Always-visible topbar search input (next to notifications)
- Native magnifier hidden by default (configurable)
- Keyboard shortcut: press `/` to focus the topbar search
- Lightweight autosuggest dropdown (permission-safe)
- Small focus animation for intentional feel
- Dark-mode friendly styles (prefers-color-scheme + future body classes)

## Installation

1. Upload the module folder to:
   Modules/AdamSmartSearchUI
2. Activate via Manage → Modules
3. Clear cache if needed

## Compatibility

- FreeScout >= 1.8.0
- CustomFields module

## License

AGPL-3.0


## Notes
- Module icon is versioned (smartsearchui-v112.png) to avoid browser/proxy caching after upgrades.
