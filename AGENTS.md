# Guidance for AI contributors to Adelia

This file applies to the whole Adelia project. Read the code and current configuration before changing behavior. README.md is the user guide; README.html is its offline HTML edition. STORAGE.md explains the persistence protocol, and THEMES.md explains the presentation layer.

## Project direction

Adelia is deliberately a lightweight imageboard for modest traffic. Preserve its current appearance on phones and computers, straightforward posting and replies, attachments, catalog, and management area. Prefer small, understandable components and native APIs over new frameworks or services.

- **Use the latest stable PHP version and current patch release.** Verify it at https://www.php.net/downloads.php before PHP modernization work; do not assume the version written here remains current. PHP 8.5.10 on a 64-bit runtime was verified on 7 September 2026. The current bootstrap minimum is PHP 8.5, not a permanent development target. Do not target preview releases unless the user requests one.
- **Backward compatibility with older PHP versions is not required.** Use modern PHP syntax, strict types, native types and suitable current language features. Do not add older-version branches, compatibility shims, polyfills or legacy database APIs. Update runtime checks, development configuration and documentation together when the required version changes. New syntax should improve the code, not merely make it look different.
- **SQLite 3 and flat-file are the only storage backends.** Their configuration names are `sqlite` and `flatfile`. Do not reintroduce MySQL, MariaDB, PostgreSQL or another database backend. Flat-file must work without a database extension; SQLite uses PDO/pdo_sqlite.
- Keep runtime operation free of Composer, Twig and jQuery. Use native JavaScript modules and local assets. Development checks may use separate tools; they must not become installation requirements for running the board.
- Keep bans and poster-IP collection out of the app. Do not add IP hashes, proxy-header tracking or a replacement fingerprinting system. Hosting/server logs are outside this application's storage model.

## Flat-file quality is a central goal

**The flat-file system is attempting to be the best it can be** for this small-board use case: reliable, consistent, recoverable, secure, understandable and efficient. Treat that as a continuing engineering goal, not a claim of perfection or proof that it is better than every other store.

Flat-file is a supported backend for the full application. Posting, replies, reports, accounts, moderator edits and deletions, pinning, locking, attachment replacement, conversion and maintenance must remain consistent with SQLite. Do not turn it into a reduced-feature demo or bypass the shared typed storage interface.

Before persistence changes, read STORAGE.md and the actual implementation. Preserve these invariants:

1. Use stable locks and reload committed state under the writer lock. Related records, ID counters and durable maintenance jobs belong in one transaction. Committed deleted IDs must not be reused; no-op changes should avoid unnecessary revisions.
2. The `.head` commit record is authoritative. Accept only a snapshot with the matching store identity, revision and checksum. Never select data merely because it has the highest revision, silently roll back to an older snapshot, or recreate missing established storage as an empty board. Initialization and legacy-format upgrades are explicit operations.
3. Check writes, including partial writes; flush files before publication. Preserve same-directory atomic replacement and directory synchronization where the platform supports it. Keep the committed snapshot recoverable before replacing its journal. Do not weaken uncertain-commit reporting.
4. Reserve upload names before creating files, validate and flush referenced media before committing records, and remove only media that committed records no longer reference. Rebuild public pages before deleting old attachments. Durable jobs must make interrupted rebuilding and cleanup retryable.
5. Validate complete record structures and enforce size bounds. Keep the 16 MiB record-payload limit and single-host/local-filesystem scope documented unless a tested redesign changes them. Media are outside that payload limit. Avoid live databases on network shares or cloud-synchronized folders.
6. Preserve fail-closed corruption handling and exact recovery checks. Journals and current recovery copies are not historical backups. Protect the entire bundle, settings, upload ledger, password hashes and backups from public access.

Measure changes and use failure-injection/concurrency tests where relevant. Continue to document Windows directory-sync and real power-loss limits honestly. Do not advertise complete hardware durability on the strength of process-kill tests. SQLite remains the appropriate option when whole-snapshot rewrites or board activity exceed flat-file's practical scope.

## Preserve user data and identity

No requirement for old-PHP compatibility does **not** authorize data loss. Preserve existing records, media, account hashes, counters and settings. An intentional storage-format change needs an explicit, verified migration and a private backup, with clear failure behavior. Never silently discard fields or overwrite a destination store.

The initial `admin` / `password` account is bootstrapped only when the accounts table is empty and must require a password change before management. Credentials belong in storage as PHP password hashes, not as permanent configuration overrides. Do not reset an existing account as part of an ordinary code or documentation update. Preserve current-password verification, role checks and session invalidation after a successful credential commit.

Keep `tripseed` private and stable. Preserve saved tripcodes and established identity behavior. `Name##secret` uses the longer, domain-separated HMAC-SHA-256 secure mode; do not downgrade it or persist its raw secret. The single-hash/exclamation forms have existing keyed identities. An intentional identity-format change must be documented rather than silently altering users' future identities.

## Security and presentation

Keep request validation, output escaping, CSRF protection, authorization checks and stale-editor conflict checks at their existing boundaries. Maintain prepared SQLite statements, bounded uploads/downloads and controlled subprocess arguments. Staff content must not bypass HTML escaping. Keep executable content out of uploads and private application/storage paths out of public routing.

Retain the current theme-compatible markup and the user-selected themes (Yotsuba, Yotsuba B, Dark, Futaba Light, Jungle, Miku, Rugby and Sharp) for posting, replies, the catalog and management. Do not restore removed themes or their unused assets without a user request. Keep the shared style.css and assets referenced by retained themes. Shared layout CSS belongs under stylesheets/shared so it does not appear in the top-level theme selector. Use neutral filenames and implementation comments while preserving required license and footer notices. Use stylesheets/shared/adelia.css for layout integration. Test affected screens at phone and desktop widths. Preserve the original copyright/license files and existing attribution when changing branding or assets.

## Verification and documentation

The sole application configuration file is settings.php. The public repository tracks a documented installation copy with blank private keys; preserve that distinction from a configured deployment. Never commit populated tripseed/managekey values, generated pages or board data. A Git ignore rule does not protect a file that is already tracked. Maintain its per-option comments when behavior changes; edit it directly and preserve saved values/secrets. Do not add a second application settings template. Test suites use their independent tests/fixtures/config.php and must never load private live settings as a fixture.

Work on isolated fixtures for tests. Never use a live board as disposable test data or publish its settings, database, backups, credentials or tripcode key. Repository changes should contain code and documentation, not generated board content or private state.

Run the checks appropriate to the change. Shared storage or posting changes should exercise both drivers; persistence changes need the storage and durability suites; authentication or tripcode changes need the accounts suite. The commands below assume PHP is on PATH; on the current Desktop, use C:\php\php.exe otherwise.

```text
php tests/run.php sqlite
php tests/run.php flatfile
php tests/storage.php
php tests/durability.php
php tests/accounts.php
php tests/security.php
php tests/remote-media.php
```

Run the security suite for request, authorization or output-boundary changes, and the deterministic remote-media suite for download/thumbnail changes. Keep hosting examples in the README synchronized with public routes, asset needs and response headers; never assume the development router protects Apache or nginx.

Use PHP syntax checks and the existing PHPStan/PHP-CS-Fixer configuration for relevant code edits. Verify browser behavior when changing forms, sessions, previews, styles or moderation. Report actual checks and limitations; do not equate a passing suite with a complete security proof.

Keep README.md and README.html in sync. The HTML edition also embeds STORAGE.md, THEMES.md, this guidance and the license texts for offline reading; refresh those sections when their sources change. Preserve the HTML's source-hash manifest so a mismatch can be detected. Check internal links, desktop/mobile layout and offline operation. Documentation-only changes need those checks, not another full application test run.

AGENTS.md is the canonical AI guidance. Keep agent.md as a short pointer to it so the two files cannot acquire conflicting instructions. Preserve this project's direction while following the user's authorized task and any higher-priority instructions.
