# Adelia storage and recovery

Adelia supports SQLite 3 and a transactional JSON flat-file store. Both use the same posting, moderation and maintenance workflow. PHP 8.5 and a local filesystem with working locks are required. Use one board installation on one server; do not put the live store on a network share or in a folder synchronized by a cloud-drive client.

## Create or convert

For a new empty flat-file board, set these values in `settings.php`:

```php
'dbdriver' => 'flatfile',
'dbpath' => '.adelia.json',
```

Then run:

```powershell
C:\php\php.exe bin/storage.php init
C:\php\php.exe imgboard.php
```

Flat-file initialization is explicit. A missing flat-file database never means permission to silently create an empty board. SQLite creates its schema automatically. `init` refuses an existing store or a partially initialized bundle. It requires the parent directory to exist.

To convert an existing SQLite board, stop the server, back up the board folder, and run:

```powershell
C:\php\php.exe bin/convert-storage.php flatfile .adelia.json
```

The converter copies all current records and ID counters into a new destination, verifies equality, and leaves the source, settings and uploaded media unchanged. It refuses an existing destination. After successful conversion, change `dbdriver` and `dbpath` to the values above, run `php bin/maintenance.php`, and restart. Keep your tripseed and credentials. If the chosen destination exists, select another hidden filename; do not overwrite it.

To convert back, use `php bin/convert-storage.php sqlite .adelia-converted.db`, then select that file in settings while stopped. Never switch back to an old database after accepting new posts without first converting the current data.

For the previous Adelia version 1 JSON store, back up the board while stopped, select its existing path, then run `php bin/storage.php upgrade`. This explicit upgrade preserves the old JSON in a private `.legacy` file and creates the new commit files. It does not read original TinyIB serialized/TSV storage.

## Files to preserve

With `dbpath` set to `.adelia.json`, the database is a bundle:

| File | Purpose |
| --- | --- |
| `.adelia.json` | Complete checked snapshot of all records |
| `.adelia.json.journal` | Complete prepared or committed snapshot used for recovery |
| `.adelia.json.head` | Authoritative store identity, revision and SHA-256 commit checksum |
| `.adelia.json.lock` | Stable lock used by readers and writers |
| `adelia.lock` | Board lock coordinating posting, moderation, conversion and maintenance |
| `.adelia-uploads.json` | Checked list of reserved uploads awaiting commit or cleanup |

The three data/commit files must remain together. A journal is recovery data, **not a historical backup**. Normal commits replace both snapshots with current data; Adelia does not intentionally retain a history of deleted posts. A failed checkpoint can leave an older main snapshot until the next successful repair/write. Private backups can also contain deleted material.

Images and videos remain in `src`, thumbnails in `thumb`. Public HTML and JSON under the board root and `res` are generated from committed records. Settings contain secrets, and database records contain password hashes and moderator logs. Never publish storage, settings, backups or temporary files. The local development router blocks hidden files and private application directories. A hosted website needs equivalent access rules in its own Apache or nginx configuration; the development router is not applied automatically.

## What a commit does

1. Acquire the board lock and complete earlier pending maintenance. Begin an exclusive record transaction and read the current committed revision.
2. Validate the request and collect its record changes in memory. Posts, reports, moderator logs, thread state and cleanup jobs commit together. New upload names are recorded before the files are created. New referenced media are checked and flushed before records can commit.
3. Validate the complete JSON snapshot. Write and flush a new journal to a temporary file in the same directory, then atomically replace the journal.
4. Write, flush and atomically replace the commit record. This is the commit point.
5. Replace the main snapshot. If this step fails, the matching journal still contains the committed result. The next write repairs the main copy before replacing the journal again.
6. Rebuild public pages using atomic file replacements, then remove only media no committed post references. Finally remove completed maintenance jobs in another transaction.

Recovery accepts only a snapshot matching the authoritative commit record, including its checksum. It never guesses by choosing the largest revision or silently falling back to older content. A failure before the commit point leaves the previous transaction intact. A failure after it leaves the complete new transaction recoverable. If confirmation fails after the commit record was published, an uncertain-acknowledgement error requires checking the saved result before resubmitting.

Generated pages and media are separate filesystem objects. Their changes cannot be one atomic filesystem operation. After an interruption, some static pages can be stale until the next dynamic request or maintenance command finishes the durable jobs. Images are retained until page rebuilding succeeds. If a change was committed but maintenance fails, the response says it was saved and needs maintenance; do not submit it again blindly. Reserved uploads without committed references are removed during recovery.

Readers cache a validated snapshot and build indexes for common post/thread/account lookups. A small commit-record check detects another writer's changes. Writes reload current committed state under a lock; unchanged updates do not create a new revision. IDs increase monotonically and committed deleted IDs are not reused. Locks time out with an error rather than proceeding without a lock.

## Check, repair and back up

Run these from the board directory:

```powershell
C:\php\php.exe bin/storage.php check
C:\php\php.exe bin/storage.php repair
C:\php\php.exe bin/maintenance.php
```

`check` validates committed record structure and integrity; it is not an exhaustive audit of existing image contents. `repair` restores a damaged/missing main snapshot from the exact matching committed journal. Neither command invents a missing commit record or recovers unrelated damaged copies. `maintenance.php` rebuilds all public pages and retries pending media cleanup. Pending jobs also run at the start and end of dynamic board requests.

For a complete backup, stop PHP/nginx from serving this board, run maintenance successfully, then copy the **whole board folder**, including hidden files, settings, media and the entire database bundle, to a private location outside the web root. If storage is outside the board folder, copy its bundle too. Restart only after copying completes. Keep separate dated backups; test a restore in a separate folder. The conversion command can create a checked record-only copy at a new private destination, but that copy does not include uploaded media or settings.

If a commit record or both matching snapshots are damaged, stop writes, preserve all existing files, and restore a known complete backup into a separate directory. Verify it with `check`, run maintenance, and only then select the restored board. Do not mix `.head`/`.journal`/`.json` files from different backups. Do not hand-edit JSON, delete locks, or remove individual bundle files while running. Hidden `.adelia-write-*.tmp` files left by a killed process are never selected as data; they can be removed after all board processes are stopped and the committed bundle has been verified.

## Scope and limits

The record payload is limited to 16 MiB, excluding uploaded media. JSON envelopes add escaping overhead; allow disk space for both snapshots, temporary replacements, public-page generation and backups. A mutation rewrites the complete snapshot. The app groups related writes into transactions and avoids redundant work, but SQLite remains preferable when records or concurrent activity grow.

Successful file writes are explicitly flushed. On Unix, file publication and media deletion also synchronize their directories. PHP on Windows does not expose the directory-handle flushing needed for the same directory-sync step. Filesystem, operating-system and storage-device behavior therefore remain part of durability, especially for actual power loss. Checksums detect accidental corruption; they do not authenticate data against an attacker who can rewrite the whole bundle. Restrict filesystem access and keep backups.

The automated durability suite injects short/zero writes, failed flushes and replacements, corrupt/missing files, and cleanup failures. It also terminates actual PHP worker processes at each publication boundary and reopens storage. These are process-failure tests on the installed Windows/PHP runtime, not physical power-cut tests or a proof against every hardware failure.

```powershell
C:\php\php.exe tests/run.php sqlite
C:\php\php.exe tests/run.php flatfile
C:\php\php.exe tests/storage.php
C:\php\php.exe tests/durability.php
```
