# Security Policy

## Supported versions

Security and compatibility fixes are applied to the latest release.

| Version | Support |
|---|---|
| Latest release | Supported |
| Older releases | Best effort only |

## Reporting a vulnerability

Send security reports privately to:

**adamcoffeeoverflow@gmail.com**

Include:

- Affected version
- Reproduction steps
- Impact
- Proof of concept when available
- Suggested mitigation when available

Do not include credentials, customer data, or unrelated personal information.

## Responsible disclosure

- Do not open a public issue before review.
- Do not access data you do not own.
- Do not perform destructive testing.
- Limit testing to what is needed to demonstrate the issue.
- Allow reasonable time for remediation.

## Module safeguards

AdamSmartSearchUI uses the following controls:

- Authenticated FreeScout routes
- CSRF-protected bulk POST requests
- Whitelisted request fields
- Eloquent / parameterized database queries
- Escaped Blade and JavaScript text output
- FreeScout mailbox and conversation policy checks
- Assigned-only visibility enforcement
- Per-mailbox assignee validation
- Deleted-conversation protection for bulk writes
- Configurable bulk request and note-length limits
- No hardcoded credentials
- No FreeScout core modifications
- CSP-safe event binding without inline handlers

Search results and exact numeric redirects are constrained to mailboxes the current user can access. Bulk actions independently re-check each conversation instead of trusting the result-page selection.

## FreeScout core issues

Report FreeScout core vulnerabilities through the FreeScout repository security channel rather than this module's issue tracker.
