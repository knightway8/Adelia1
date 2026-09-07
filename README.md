# Adelia

A lightweight imageboard with selectable themes, native JavaScript, a PHP backend, and static board, thread and catalog pages. Storage is **SQLite 3 or transactional JSON flat-file only**. No Composer, Twig or jQuery is required.

Documentation checked against the application on **7 September 2026**. The verified runtime is [PHP 8.5.10](https://www.php.net/releases/8_5_10.php), 64 bit. Development targets the **latest stable PHP release**, with no requirement to support older PHP versions. The current startup check requires PHP 8.5 or newer; this is a minimum check, not a promise of compatibility with every future release. Check [PHP downloads](https://www.php.net/downloads.php) before updating the runtime.

Open **README.html** directly in a browser for the offline guide, including the storage and theme references. It is documentation, separate from the generated board page `index.html`.

## Repository contents and first launch

This repository contains the complete application source, eight selected themes and their required images, tests, launchers, licenses and both README editions. Hidden development files and the `.gitignore` files in `src`, `thumb` and `res` are included so a clone retains those directories.

The tracked `settings.php` contains the documented installation options with a **blank `tripseed`**. Copy or deploy the source, generate a private key with `php -r "echo bin2hex(random_bytes(32));"`, and set it in the deployed `settings.php` before the first launch. Startup refuses a blank key. Keep an established board's key when upgrading unless you are intentionally rotating an exposed key; a rotation changes future tripcodes but does not rewrite saved posts.

Do not commit a populated private key or access key to this public repository. Git tracks `settings.php`, so `.gitignore` cannot hide changes to it. Keep your configured deployment copy separate from the source checkout. The desktop board's private database, account hashes, uploaded attachments, generated HTML/JSON, locks and backups are intentionally excluded. The application builds public pages on first launch from its own storage; a fresh clone starts a new board, not a copy of an existing board's records.

## Start the Desktop board

After configuring the private key as described above, double-click **Start Adelia.bat**. Keep it beside `imgboard.php`, or beside a subfolder named `Adelia`. The launcher locates the board relative to itself, so the folder can be moved. It uses `C:\php\php.exe`, rebuilds the static pages, starts the local server, and opens `http://127.0.0.1:8080/`. Keep the console open while using the board; close it or press Ctrl+C to stop.

The distributed configuration uses SQLite, allows text-only threads, and has all four CAPTCHA settings disabled. Edit the commented `settings.php` directly to change these choices. The initial management login is **admin / password**; the first login requires a new password. Saved accounts retain their credentials on later starts and upgrades.

The local server binds to loopback. `local-router.php` permits public board pages and assets while blocking application code, configuration, storage and executable uploads. Open this documentation from the filesystem; the local router does not expose `README.html`.

## Requirements and fresh setup

- A 64-bit, current stable PHP runtime. The present code requires PHP 8.5+ with GD/FreeType, DOM, mbstring, fileinfo and curl.
- SQLite needs PDO and `pdo_sqlite`. Flat-file storage needs no database extension or separate server.
- PHP needs write access to the board directory, its storage location, and `src`, `thumb` and `res`.
- A browser with native JavaScript module support; JavaScript supplies form tokens on static pages and interactive controls.

Run commands from the board directory. On this Desktop, PHP is installed at `C:\php\php.exe`; use that full path if `php` is not on PATH.

1. Open `settings.php` and follow the comments beside each option. This is the application configuration file. Preserve an existing board's settings and secrets when upgrading.
2. For a new board only, set `tripseed` to a random private value. Keep an established board's key unchanged. Generate a new key with `php -r "echo bin2hex(random_bytes(32));"`.
3. Choose `sqlite` with `.adelia.db`, or `flatfile` with a hidden JSON filename such as `.adelia.json`.
4. For a new empty flat-file store, run `php bin/storage.php init`. SQLite creates its schema automatically.
5. Run `php imgboard.php`, or start the Desktop launcher, to create the initial account and static pages.
6. Open **Manage**, sign in with **admin / password**, and change the password before using management. Use HTTPS for a public deployment so passwords and tripcode secrets are protected in transit.

## Settings

`settings.php` returns an array loaded and validated by `Adelia\Config`. Its comments explain every option, accepted choices, units, disabled values and external-tool requirements. Use native PHP booleans (`true`/`false`), integers, strings and arrays. Keep every required key; unknown or retired keys are rejected. After changing visible options, use **Manage → Rebuild All** or `php bin/maintenance.php` to refresh static pages.

| Setting | Purpose and current configuration |
| --- | --- |
| `board`, `boarddesc`, `boardtitle` | Alphanumeric board identifier, heading and browser title |
| `timezone` | PHP timezone identifier; `UTC` |
| `captcha`, `replycaptcha`, `managecaptcha`, `reportcaptcha` | `''` disables each challenge; `simple` enables it. Currently all disabled |
| `report` | Reporting; `false` |
| `dbdriver`, `dbpath` | `sqlite` and `.adelia.db`; alternatively `flatfile` and `.adelia.json` |
| `defaultstyle` | Theme filename without `.css`; `futaba-light` |
| `maxkb`, `maxkbdesc` | Upload size in KiB and its displayed description; `2048` and `2 MB` |
| `uploads`, `embeds` | Detected MIME type to saved extension/preset thumbnail, and provider name to oEmbed endpoint; currently JPEG, PNG and GIF uploads |
| `thumbnail` | Currently `gd`; alternatives are `imagemagick` and `ffmpeg`, with their external programs installed |
| `maxwop`, `maxhop`, `maxw`, `maxh` | Opening-post and reply thumbnail dimensions |
| `threadsperpage`, `maxthreads`, `maxreplies` | Threads per page, automatic thread retention, and reply-count limit for bumping (not a reply lock) |
| `uploadviaurl` | Remote file downloads; `false` |
| `nofileok` | Allow opening posts without an attachment; currently `true` |

`updatebumped` and the `stylesheets` array are still required configuration keys but currently have no effect. Themes are discovered from CSS files automatically. The inline comments identify these retained fields explicitly.

Disabling `catalog` or `json` removes the corresponding generated files on the next rebuild. Run **Rebuild All** or `php bin/maintenance.php` after changing these settings; switching JSON exports off does not disable dynamic reply refresh.

Only the simple CAPTCHA is supported, through `inc/captcha.php`. There is no reCAPTCHA or translation-package dependency. Keep `tripseed` private and unchanged after setup: it determines posting identities and editor conflict tokens.

## Accounts and passwords

When the accounts table is empty, Adelia creates one super-administrator named `admin` with the initial password `password`. The password is stored using PHP password hashing and verified against the saved account. The initial pair is not an override for a changed password, and ordinary upgrades do not reset accounts. There are no `adminpass` or `modpass` settings.

**Manage → Change Password** requires the current password, a new password and confirmation. New account passwords must have 12–64 characters and at most 72 UTF-8 bytes, avoiding truncation by the current bcrypt implementation. The first password change is required before other management actions become available.

After a successful database commit, the current session is rotated and other authenticated sessions are invalidated. A failed save preserves the previous credential and session. The super-administrator can rename logins and manage accounts under **Accounts**. Usernames must be nonblank, contain no control characters and have at most 64 characters. The last super-administrator cannot be deleted, disabled or demoted. Moderators can change their own passwords without account-administration privileges.

## Posting identities and secure tripcodes

Enter `Your name##your-private-passphrase` in the name field for a secure tripcode. The displayed identity is your name followed by `!!` and a 22-character identifier. It uses 128 bits of HMAC-SHA-256 with the board's private `tripseed` and a separate secure-tripcode context. The raw passphrase is not saved in post fields or published in HTML/JSON. Use a long private passphrase; a tripcode is a posting identity, not a moderator login.

`Name#secret` and `Name!secret` retain the earlier Adelia 12-character keyed HMAC identities. The `##` form is explicitly the longer mode; it differs from the old behavior of treating the second hash as part of a single-hash secret. Saved posts remain unchanged. Different board keys produce different tripcodes. Original TinyIB crypt tripcodes and MD5/crypt deletion-password formats are not supported.

## Posting, themes and moderation

The eight retained themes—**Yotsuba, Yotsuba B, Dark, Futaba Light, Jungle, Miku, Rugby and Sharp**—are available through **Style** on the board, threads, catalog and management pages. The browser remembers the selection. Futaba Light is the default for new visitors; a saved choice that was removed falls back to this default. Unused themes, images, fonts and legacy stylesheet folders have been removed. Themes are discovered from `stylesheets/*.css`; layout adjustments belong in `stylesheets/shared/adelia.css`. The shared file is outside the top-level theme list, so the Style menu still has eight choices. See [THEMES.md](THEMES.md) for customization.

Native JavaScript handles quote previews, image/GIF expansion, reply refresh, backlinks, CAPTCHA refresh and theme preferences. MP4/WebM playback is supported by the frontend, but accepting and processing video also requires suitable upload configuration and external tools. The current upload allowlist is JPEG, PNG and GIF. Optional video processing and remote providers have not been tested end to end.

Public deletion accepts multiple selected posts and validates all deletion passwords before committing any deletion. New deletion passwords accept at most 72 UTF-8 bytes and no NUL characters.

Open **Manage → Moderate Post**, enter a post number, and choose **Edit post / change image**. Administrators and moderators can edit the subject and message of a thread or reply, replace its attachment, or remove an attachment when the post remains valid. The author, tripcode, timestamp and deletion password are preserved. Text uses the same escaped quote, link and spoiler formatting as public posts; staff posts do not accept raw HTML.

**Pin thread / Unpin thread** changes thread ordering. **Lock thread / Unlock thread** controls public replies. These actions can be reached from an opening post or reply and affect the containing thread. Saving rebuilds the thread, board index and catalog; refresh an already-open page to see edits. A stale editor is rejected if another change has been saved in the meantime. Use **Manage → Rebuild All** after changing templates or themes.

## SQLite 3 and flat-file storage

Only `sqlite` and `flatfile` are supported. SQLite 3 is the default. MySQL, MariaDB and PostgreSQL are not part of this application; no database server credentials are needed.

The flat-file system is **attempting to be the best it can be for a lightweight board**: reliable, consistent, recoverable and fully usable through moderation. This is an engineering goal, not a guarantee of perfect durability. Its transactional JSON bundle uses a checksummed commit record and recovery journal. Posting and moderation commit related record changes together, then durable jobs finish static-page rebuilding and unreferenced-media cleanup.

For a new empty flat-file board, select `flatfile` and `.adelia.json`, run `php bin/storage.php init`, then run `php imgboard.php`. Missing or inconsistent flat-file storage fails closed instead of silently starting an empty board. SQLite's schema initialization is automatic.

To convert an existing board, stop serving it and back up the whole folder, including hidden files. Run `php bin/convert-storage.php flatfile .adelia.json`. The converter verifies the new destination and preserves the source, settings, media and ID counters; it refuses to overwrite an existing destination. Select the new driver and path in settings, run `php bin/maintenance.php`, then restart. Conversion back to SQLite is also available. Never select an outdated source after accepting new posts.

Keep `.adelia.json`, `.adelia.json.head` and `.adelia.json.journal` together, along with the board's other private state. Do not hand-edit them. Useful commands are:

```powershell
C:\php\php.exe bin/storage.php check
C:\php\php.exe bin/storage.php repair
C:\php\php.exe bin/maintenance.php
```

`check` validates committed storage. `repair` restores the main flat-file snapshot from an exactly matching committed journal; it cannot invent a missing commit record. `maintenance.php` rebuilds public pages and retries cleanup.

Use a local filesystem with working locks and one server. The flat-file record payload limit is 16 MiB, excluding media, and mutations replace complete snapshots. SQLite is preferable as records or activity grow. Recovery copies are not historical backups. Windows filesystem and device power-loss behavior remain limits; process-crash tests cannot prove survival of every hardware failure. Read [STORAGE.md](STORAGE.md) before conversion, backup or recovery.

## Upgrading an older Adelia installation

Stop serving the board and make a private backup before migrations. Preserve settings, secrets, media and records. Older-PHP compatibility is not required, but an upgrade must not discard user data.

Remove retired keys from an older `settings.php`: `adminpass`, `modpass`, `banmessage`, `cloudflare`, `dbbans`, `dbhost`, `dbport`, `dbusername`, `dbpassword`, `dbname` and `dbdsn`. Keep the supported settings and the existing `tripseed`.

For an earlier Adelia SQLite schema, run `php bin/upgrade-moderation.php` from the CLI before serving the updated code. It adds editable source fields, removes old IP columns and the retired ban table, and cleans identifying ban records from the moderation log. If the old ban table had a custom name, pass its name as the first argument. The command is repeatable; fresh databases already have the current schema.

For the previous Adelia version 1 JSON store, select its existing path and run `php bin/storage.php upgrade`. The upgrade preserves a private `.legacy` copy. It does not import original TinyIB serialized/TSV flat files. Run storage checks and maintenance after migration, then verify the board before reopening it.

## Website hosting

Adelia is a PHP website application; it does not require Windows or a particular web server. Apache and nginx can serve it with the required PHP runtime and extensions. The Windows BAT file and `local-router.php` are for local development. Hosted sites need their own routing and access controls. No production `nginx.conf` or `.htaccess` is bundled.

### Before making a board public

1. Set up HTTPS, create the board privately, and replace **admin / password** before allowing public access. Use a unique administrator passphrase. Keep PHP, the web server and optional media tools patched. Protect management at the hosting/edge layer if practical; Adelia does not implement a global login-attempt limiter.
2. Use a dedicated site account and local filesystem. Keep database bundles, PHP sessions, logs and backups outside the document root where hosting permits. `dbpath` accepts an absolute path; move an existing store only while the board is stopped, preserving its complete bundle and checking the result before restarting. Do not point an established board at an empty or outdated store.
3. Give PHP only the access it needs. Code and settings should belong to the deployment account; generated pages, media and storage need write access. **The current layout also requires the board's root directory to be writable for generated pages and private lock/upload state.** Making individual PHP files read-only does not prevent a compromised process from replacing files in a writable parent directory. Isolate the site from other sites and system files; do not use world-writable permissions. A stronger separation of generated pages and code would need a layout change.
4. Allow public access only to generated board pages, approved asset paths and the two PHP entry points. Block settings, application code, maintenance commands, tests, database sidecars, dotfiles, lock files, temporary files, editor backups and documentation. Disable directory listings and symlink access. Store backups outside the website even when a deny rule exists. See [PHP filesystem security](https://www.php.net/manual/en/security.filesystem.php).
5. Keep unused integrations disabled. `uploadviaurl = false` disables general remote file uploads; `embeds = []` disables new provider embeds. Existing saved embeds remain recognizable after providers are disabled. Remote requests accept only public Internet addresses on standard HTTP/HTTPS ports, pin validated DNS results, verify TLS, reject redirects and limit response size/time. Network egress restrictions provide another layer of protection. Provider availability and arbitrary external integrations still need separate testing.
6. Align upload limits in PHP, the web server and the edge/CDN. The example below supports the default 2 MiB attachment plus form overhead. Limit request rates/connections at the server or edge according to expected traffic. Expensive image processing, password verification and remote requests consume resources; dynamic requests also share a board lock. Browser-session cooldowns are not protection against a determined flood.

Adelia does not record poster IP addresses. Server, hosting and Cloudflare logging/rate limits are separate policies. Avoid logging request bodies or cookies: they can contain passwords and tripcode secrets. Static uploads are public files, not private or access-controlled storage; knowing an attachment URL can make it accessible even while its post is awaiting approval.

### PHP and HTTPS configuration

The following is an example **site PHP configuration**, not an application settings array. Replace the private paths and use a session directory writable only by the site's PHP account. Keep the required extensions enabled. Match larger upload allowances to `maxkb`, image dimensions and available memory.

```ini
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /srv/adelia-private/php-error.log
upload_tmp_dir = /srv/adelia-private/uploads
session.save_path = /srv/adelia-private/sessions
cgi.fix_pathinfo = 0
upload_max_filesize = 2M
post_max_size = 3M
max_file_uploads = 1
memory_limit = 256M
max_execution_time = 30
```

Create those directories privately before starting PHP. On Linux, use a dedicated PHP-FPM pool, a small `pm.max_children` appropriate to memory/traffic, a bounded `request_terminate_timeout`, and `security.limit_extensions = .php`. Bind FastCGI to a private Unix socket or loopback; never expose port 9000 to the Internet. Adapt the examples if the host supplies a Unix socket. See the [PHP-FPM configuration reference](https://www.php.net/manual/en/install.fpm.configuration.php).

Use HTTPS for posting as well as management: the name field can contain a tripcode passphrase. Adelia sets HttpOnly and SameSite=Lax session cookies, uses cookie-only strict sessions, and marks cookies Secure when PHP receives `HTTPS=on`. When TLS terminates at a proxy/CDN, configure that value from the **trusted proxy connection**, and prevent direct untrusted access to the backend. Do not blindly copy a visitor-supplied forwarding header into it.

Do not cache `imgboard.php` responses, including token, management and preview requests. They send `Cache-Control: private, no-store`. Make the CDN respect this and disable any "cache everything" rule for PHP. Generated HTML/JSON should be revalidated after edits and deletions; purge any existing CDN cache when changing these rules. Media and theme assets may have separate caching policies.

### nginx example

Put these directives inside the site's existing HTTPS `server` block, with its domain and certificate already configured. This example serves a board at the domain root with the default `index.html` name. Change `/srv/adelia` and the FastCGI endpoint for the host. Subdirectory installations, renamed index files, added media types and custom external assets require corresponding rule changes.

```nginx
root /srv/adelia;
index index.html;
autoindex off;
disable_symlinks on;
client_max_body_size 3m;

# nginx supplies these consistently for both PHP and static responses.
fastcgi_hide_header X-Content-Type-Options;
fastcgi_hide_header Referrer-Policy;

add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "same-origin" always;
add_header X-Frame-Options "DENY" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; media-src 'self'; connect-src 'self'; frame-src https://www.youtube.com https://youtube.com https://www.youtube-nocookie.com https://youtube-nocookie.com https://player.vimeo.com https://w.soundcloud.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" always;

location = /imgboard.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/imgboard.php;
    fastcgi_param HTTPS $https if_not_empty;
    fastcgi_param HTTP_PROXY "";
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_cache off;
}
location = /inc/captcha.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/inc/captcha.php;
    fastcgi_param HTTPS $https if_not_empty;
    fastcgi_param HTTP_PROXY "";
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_cache off;
}

# Exact PHP locations above take precedence; all other script paths are denied.
# Keep these denial expressions before the public-asset expressions.
location ~ "(^|/)\." { return 404; }
location ~* "\.(?:php[0-9]*|phtml|phar)(?:[./]|$)" { return 404; }

location = / {
    try_files /index.html =404;
    expires -1;
    etag off;
    if_modified_since off;
}
location ~ "^/(?:index|catalog|[0-9]+)\.html$|^/(?:catalog|threads)\.json$|^/res/[0-9]+\.(?:html|json)$" {
    try_files $uri =404;
    expires -1;
    etag off;
    if_modified_since off;
}
location ~* "^/(?:js/[a-z0-9_+.-]+\.js|stylesheets/(?:[a-z0-9_+.-]+/)*[a-z0-9_+.-]+\.(?:css|png|gif|jpe?g|svg|ico|woff2?|ttf|eot))$" {
    try_files $uri =404;
}
location ~* "^/(?:src|thumb)/[a-z0-9_-]+\.(?:jpe?g|png|gif|webp|ico|aac|flac|ogg|opus|mp3|mp4|wav|webm)$" {
    try_files $uri =404;
}
location ~ "^/(?:favicon\.ico|(?:lock|sticky|swf_thumbnail|video_overlay)\.png)$" {
    try_files $uri =404;
}
location / { return 404; }
```

Retain the standard `mime.types` include in nginx's `http` block. Remove conflicting generic PHP handlers, broad static-file locations, aliases and cache rules from this site's block. The exact script locations deliberately avoid executing arbitrary `.php` files. `disable_symlinks` requires a platform with nginx's supported filesystem calls; it is a Linux/Unix hosting control, not a promise about the Windows development server. Check the [nginx location rules](https://nginx.org/en/docs/http/ngx_http_core_module.html#location) and [FastCGI parameter documentation](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_param) when adapting this example.

### Apache example

Use the site's existing HTTPS `VirtualHost` configuration, **not `.htaccess`**. This example needs `mod_rewrite`, `mod_headers`, `mod_mime`, `mod_dir`, `mod_proxy` and `mod_proxy_fcgi`, plus the site's TLS module/configuration. It assumes a dedicated virtual host without another global PHP handler or conflicting alias. Change the document root and private FastCGI endpoint for the host.

```apache
DocumentRoot "/srv/adelia"
DirectoryIndex index.html
ProxyRequests Off
TraceEnable Off
LimitRequestBody 3145728

<Directory "/srv/adelia">
    AllowOverride None
    Options -Indexes -Includes -ExecCGI -MultiViews -FollowSymLinks -SymLinksIfOwnerMatch
    AcceptPathInfo Off
    Require all granted
    <FilesMatch "(?i)\.(?:php[0-9]*|phtml|phar)(?:\.|$)">
        Require all denied
    </FilesMatch>
</Directory>

# Keep these rules at VirtualHost level, before other rewrites.
RewriteEngine On
RewriteCond %{REQUEST_URI} "(^|/)\."
RewriteRule ^ - [R=404,END]
RewriteCond %{REQUEST_URI} "!^/(?:imgboard\.php|inc/captcha\.php)$"
RewriteCond %{REQUEST_URI} "\.(?:php[0-9]*|phtml|phar)(?:[./]|$)" [NC]
RewriteRule ^ - [R=404,END]

# Every other path must be one of the explicitly public files below.
RewriteCond %{REQUEST_URI} "!^/(?:$|imgboard\.php|inc/captcha\.php)$"
RewriteCond %{REQUEST_URI} "!^/(?:index|catalog|[0-9]+)\.html$"
RewriteCond %{REQUEST_URI} "!^/(?:catalog|threads)\.json$"
RewriteCond %{REQUEST_URI} "!^/res/[0-9]+\.(?:html|json)$"
RewriteCond %{REQUEST_URI} "!^/js/[a-zA-Z0-9_+.-]+\.js$"
RewriteCond %{REQUEST_URI} "!^/stylesheets/(?:[a-zA-Z0-9_+.-]+/)*[a-zA-Z0-9_+.-]+\.(?:css|png|gif|jpe?g|svg|ico|woff2?|ttf|eot)$" [NC]
RewriteCond %{REQUEST_URI} "!^/(?:src|thumb)/[a-zA-Z0-9_-]+\.(?:jpe?g|png|gif|webp|ico|aac|flac|ogg|opus|mp3|mp4|wav|webm)$" [NC]
RewriteCond %{REQUEST_URI} "!^/(?:favicon\.ico|(?:lock|sticky|swf_thumbnail|video_overlay)\.png)$"
RewriteRule ^ - [R=404,END]

# Location sections merge after the file denial above: only these two can run.
<LocationMatch "^/(?:imgboard\.php|inc/captcha\.php)$">
    Require all granted
    SetHandler "proxy:fcgi://127.0.0.1:9000"
</LocationMatch>

Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "same-origin"
Header always set X-Frame-Options "DENY"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; media-src 'self'; connect-src 'self'; frame-src https://www.youtube.com https://youtube.com https://www.youtube-nocookie.com https://youtube-nocookie.com https://player.vimeo.com https://w.soundcloud.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
<FilesMatch "\.(?:html|json)$">
    Header set Cache-Control "no-cache"
    FileETag None
    Header unset Last-Modified
    RequestHeader unset If-Modified-Since
    RequestHeader unset If-None-Match
</FilesMatch>
```

The public-path allowlist also blocks non-PHP private files; denying `.php` alone would expose SQLite/JSON stores and backups. Upload routes have no executable handler, and script-like double extensions are denied. Section merging matters: review Apache's [configuration-section order](https://httpd.apache.org/docs/2.4/sections.html), [rewrite rules](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html#rewriterule), [authorization rules](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#require) and [PHP/FastCGI handler examples](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html) before combining this with existing host configuration. Shared hosting that does not permit equivalent controls needs the hosting administrator's help before deployment.

### Headers and deployment checks

The examples' Content Security Policy permits the supplied local JavaScript/styles and supported provider frames. Inline styles remain allowed for the current layout; inline scripts, plugins and framing the board are blocked. If you add an external logo, custom asset host or provider, adapt the specific source list and inspect the browser console. Start with `Content-Security-Policy-Report-Only` on a staging site if the hosting setup differs. No reporting endpoint is enabled by these examples.

After HTTPS works everywhere, HSTS can be enabled with `Strict-Transport-Security: max-age=31536000`. Do not add `includeSubDomains` or request preload until every affected hostname is permanently ready for HTTPS. Keep the HTTP listener redirecting to HTTPS.

Run `nginx -t` or `apachectl configtest` (`httpd -t` on Windows) before reloading. Then check the **actual public origin and CDN**, not just the local BAT server:

| Probe | Expected result |
| --- | --- |
| `/`, `/index.html`, `/catalog.html`, an existing `/res/1.html`, supplied CSS/JS and a valid uploaded image | Public files load; unavailable/disabled exports return 404 |
| `/imgboard.php?csrf` and management requests | Dynamic responses have `Cache-Control: private, no-store`; session cookie is Secure, HttpOnly and SameSite=Lax over HTTPS |
| `/settings.php`, `/bootstrap.php`, `/app/Config.php`, `/bin/storage.php`, `/tests/run.php`, `/local-router.php`, `/README.html` | 403 or 404, with no source/file content |
| `/.adelia.db`, its `-wal`/`-shm` sidecars, `/.adelia.json`, `.head`/`.journal`, `/adelia.lock`, dotfiles and backup/temp filenames | 403 or 404, including percent-encoded path variants |
| `/imgboard.php/extra`, `/inc/captcha.php/extra`, an unknown `.php`, or a script/double-extension file under `src`/`thumb` | 403 or 404; no execution and no PHP source download |
| Posting, replying, editing, replacing/deleting an image and deleting multiple posts | Actions require the proper token/permissions; rebuilt pages show current content on the next request |
| PHP-FPM temporarily unavailable on a private staging copy | PHP routes fail with a gateway/service error; source is never served as text |

Keep a private backup and test recovery separately. On 7 September 2026, the nginx rules above were syntax-checked and exercised with both storage backends, PHP FastCGI and a browser on Windows. Test copies used local paths/ports and HTTP; the Linux-only symlink directive was omitted. Private-file probes, all 35 theme files then bundled, uploads, moderation, cache/security headers and failure with PHP stopped passed. HTTPS certificates, Apache and Linux runtime behavior remain unverified here; validate the installed server and merged site configuration before publishing. Optional FFmpeg/ImageMagick/ExifTool processing and live third-party providers require separate integration tests.


## Privacy and security boundaries

Adelia has no ban system and does not read or store poster IP addresses or IP hashes. Posting cooldowns and duplicate-report checks use the browser session; clearing cookies resets those checks. Web-server, Cloudflare and hosting-provider logs are separate from the application.

The September 2026 review fixed access to replies beneath hidden threads, unsafe moderation-log HTML, malformed deletion/upload input, incomplete multiple-post deletion, last-administrator lockout, stale disabled exports, remote destination validation and incorrect remote-thumbnail dimensions. Tests include rejected private/mixed DNS destinations and pinned transport settings. Provider responses are mocked for deterministic media tests; this does not certify live third-party services.

The code uses prepared SQLite statements, validated JSON records, request normalization, session CSRF tokens, expiring one-use CAPTCHA when enabled, password hashing, escaped output, bounded remote downloads and controlled subprocess arguments. Static pages are replaced atomically per file. PHP warnings and deprecations are logged as exceptions.

Keep settings, database files, password hashes, upload reservations, backups and temporary files private. The local router protects development requests; configure equivalent restrictions on the hosting web server. These controls and the regression tests reduce known risks; they are not a claim that every deployment or optional integration has been audited exhaustively.

## Development and verification

Every PHP file declares strict types. Application code uses the `Adelia` namespace, native parameter and return types, readonly configuration, property hooks and role enums. Current PHP 8.5 features include the pipe operator, native URI parsing, `array_first`, `#[NoDiscard]` and clone-with. Password arguments use `#[SensitiveParameter]`. New work should use the latest stable PHP appropriately, without older-version branches or polyfills.

Entry points are `bootstrap.php`, `imgboard.php`, `inc/captcha.php` and `local-router.php`. Logic lives in `app`; maintenance commands live in `bin`. There is no Composer, Twig, jQuery, translation package, reCAPTCHA, ban subsystem or server-database backend.

Run the fixture-based regression suites from the board directory. They use `tests/fixtures/config.php`, a test-only configuration independent of private `settings.php`:

```powershell
C:\php\php.exe tests/run.php sqlite
C:\php\php.exe tests/run.php flatfile
C:\php\php.exe tests/storage.php
C:\php\php.exe tests/durability.php
C:\php\php.exe tests/accounts.php
C:\php\php.exe tests/security.php
C:\php\php.exe tests/remote-media.php
```

The suites cover both backends, storage conversion and query parity, concurrent writers, corrupted files, injected write/flush/publication failures, process termination at commit boundaries, moderation failures, credentials, tripcodes and input validation. Earlier application verification also exercised browser forms and isolated nginx workflows. GD uploads and thumbnails were exercised; optional remote providers, video processing, ImageMagick, FFmpeg and ExifTool need separate end-to-end validation.

`phpstan.neon` configures PHPStan level 5; `.php-cs-fixer.dist.php` configures PHP-FIG PER style and PHP 8.5 migration rules. These are development tools, not runtime dependencies. Re-run relevant checks after code changes; a past pass is not proof that later edits work.

Future AI contributors should read [AGENTS.md](AGENTS.md). Keep this README and its HTML edition synchronized when behavior changes. A documentation-only edit needs content, link and layout checks, not a destructive test against a live board.

## Credits and licenses

Adelia is a fork of TinyIB with third-party themes and assets. Preserve the original copyright and license notices in [LICENSE](LICENSE), [LICENSE.Themes.md](LICENSE.Themes.md) and [LICENSE.Tinyboard.md](LICENSE.Tinyboard.md), together with the existing attribution. The application name and PHP namespace are Adelia; its entry point remains `imgboard.php`.
