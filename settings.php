<?php

declare(strict_types=1);

// Adelia configuration. Edit this file directly; README.html is the offline guide.
// Values below are your current settings. Comments describe choices, not a reset to defaults.
// Use native PHP values: true/false (not quoted), integer numbers, quoted strings, and arrays.
// An empty string is ''. Keep every required key; unknown keys are rejected by Adelia\Config.
// After changing visible options, use Manage > Rebuild All or php bin/maintenance.php.
// Storage conversion and private-key changes have separate rules described below.
// This file contains private secrets: keep it out of Git, public downloads and shared examples.
return [
    // --- Board identity and time ---
    //
    // PHP timezone name, such as UTC, America/New_York or Europe/London.
    'timezone' => 'UTC',

    // PHP date-format string, not strftime syntax; e.g. Y-m-d H:i:s for 2026-09-07 14:30:00.
    'datefmt' => 'y/m/d(D)H:i:s',

    // Board identifier: letters and digits only, e.g. b or chess. Does not rename storage tables.
    'board' => 'b',

    // Visible board heading. This is inserted as trusted HTML; plain text is simplest.
    'boarddesc' => 'Adelia',

    // Browser title text; an empty string uses boarddesc, then Adelia if both are empty.
    'boardtitle' => '',

    // --- Posting behavior ---
    //
    // true: return to the thread after a published post; false: return to the board index.
    // A poster can also enter noko in the email field to return to the thread.
    'alwaysnoko' => false,

    // --- CAPTCHA ---
    //
    // Each CAPTCHA option accepts only '' (disabled) or 'simple' (inc/captcha.php).
    // Challenge for new public threads.
    'captcha' => '',

    // Challenge for public replies: '' or 'simple'.
    'replycaptcha' => '',

    // Challenge when reporting a post: '' or 'simple'; relevant when report is true.
    'reportcaptcha' => '',

    // Challenge for management login: '' or 'simple'.
    'managecaptcha' => '',

    // --- Reporting and approval ---
    //
    // true: let visitors report posts; false: disable public reporting.
    'report' => false,

    // Report count that hides a post pending staff approval; 0 disables automatic hiding.
    // Uses session-based reports, not IP addresses.
    'autohide' => 0,

    // Approval mode: '' publishes immediately; 'files' holds attachment posts; 'all' holds all posts.
    // Applies to visitors; authenticated staff are exempt.
    'reqmod' => '',

    // Retained configuration field, currently unused by the application.
    // Changing true/false currently has no effect; keep the key while Config requires it.
    'updatebumped' => true,

    // --- Text, images and replies ---
    //
    // true: format <s>text</s>, <spoiler>text</spoiler> or <spoilers>text</spoilers> as spoilers.
    // false: display that markup as escaped text. This does not allow arbitrary HTML.
    'spoilertext' => false,

    // true: show a spoiler checkbox for attachments; false: hide the checkbox.
    // Currently GD blurs the thumbnail; the original image stays public.
    // The ImageMagick and FFmpeg thumbnail paths currently do not apply the spoiler blur.
    'spoilerimage' => false,

    // Seconds between automatic reply checks on thread pages; 0 disables them.
    'autorefresh' => 30,

    // Empty string allows new threads. A nonempty message blocks visitor threads and explains why.
    // Authenticated staff can still post.
    'disallowthreads' => '',

    // Empty string allows replies. A nonempty message blocks visitor replies and explains why.
    // Thread locks also prevent public replies; authenticated staff can still post.
    'disallowreplies' => '',

    // --- Pages and layout ---
    //
    // Main static HTML filename. Keep index.html with the supplied local launcher and router.
    // Other names must contain only letters, digits, underscores or hyphens before .html,
    // and require corresponding routing changes.
    'index' => 'index.html',

    // Optional trusted HTML before the board heading; empty string means none.
    // For a logo image use an <img> element with a local, publicly served asset path.
    'logo' => '',

    // Number of threads per index page; integer of at least 1.
    'threadsperpage' => 10,

    // Number of newest replies shown below each thread on index pages; 0 shows only the opener.
    'previewreplies' => 3,

    // Message line-break limit for index previews; 0 disables truncation.
    // Thread pages still show complete messages.
    'truncate' => 15,

    // Insert wrap opportunities in uninterrupted text after this many characters; 0 disables.
    // Applies when formatting new or edited message source.
    'wordbreak' => 80,

    // Maximum expanded media width in viewport percent (vw); use 1-100, e.g. 85.
    'expandwidth' => 85,

    // true: show links to replies that quote a post; false: disable those backlinks.
    'backlinks' => true,

    // true: generate and link catalog.html; false: stop generating/linking the catalog.
    // Turning this off removes catalog.html on the next rebuild; run Rebuild All or maintenance.php.
    'catalog' => true,

    // true: generate public static JSON indexes/thread data; false: stop generating them.
    // This is separate from SQLite/flat-file storage and dynamic reply-refresh responses.
    // Turning this off removes generated JSON on rebuild; run Rebuild All or maintenance.php.
    'json' => true,

    // Theme filename without .css: style (Yotsuba B), yotsuba, dark, futaba-light,
    // jungle, miku, rugby or sharp.
    // Themes come from stylesheets/*.css; an unavailable name falls back to style.
    // A visitor's saved Style selection overrides this initial choice.
    'defaultstyle' => 'futaba-light',

    // --- Posting limits and retention ---
    //
    // Minimum seconds between visitor posts in the same browser session; 0 disables the delay.
    // This does not collect IP addresses; clearing cookies resets the session cooldown.
    'delay' => 30,

    // Maximum retained threads; excess threads and their replies are deleted during trimming.
    // Use 0 for no automatic thread-count limit. Back up before reducing this value.
    'maxthreads' => 100,

    // Reply-count limit for bumping a thread; 0 allows unlimited bumping.
    // This does not reject further replies or lock a thread. The email value sage also stops bumps.
    'maxreplies' => 0,

    // Name-field character limit; 0 removes this configured limit.
    // The browser field includes the name and any #/## tripcode secret.
    'maxname' => 75,

    // Email-field character limit; 0 removes this configured limit. noko and sage are supported.
    'maxemail' => 320,

    // Subject character limit; 0 removes this configured limit.
    'maxsubject' => 75,

    // Message character limit; 0 removes this configured limit.
    // Request-size, memory and storage limits still apply.
    'maxmessage' => 8000,

    // --- Uploads and thumbnails ---
    //
    // Maximum uploaded file size in KiB (1024 bytes); 2048 means 2 MiB.
    // 0 removes this application upload-size cap; PHP/web-server and remote-download limits still apply.
    // Also check upload_max_filesize and post_max_size in php.ini when increasing it.
    'maxkb' => 2048,

    // Human-readable label for maxkb; change it alongside maxkb. The label does not enforce a limit.
    'maxkbdesc' => '2 MB',

    // Image thumbnail engine: 'gd', 'imagemagick' or 'ffmpeg'.
    // GD uses the PHP extension; ImageMagick needs magick on PATH; FFmpeg needs ffmpeg/ffprobe.
    // Video processing uses FFmpeg/ffprobe independently of this image-engine choice.
    'thumbnail' => 'gd',

    // true: allow bounded HTTP/HTTPS downloads from the Embed/URL field when no provider matches.
    // Public Internet destinations only, standard ports 80/443, no redirects; private addresses are blocked.
    // false: disable direct URL uploads; configured oEmbed providers can still be used.
    'uploadviaurl' => false,

    // true: run ExifTool to remove metadata from new uploads; false: leave originals unchanged.
    // Requires exiftool on PATH. Existing files are not rewritten by changing this setting.
    'stripmetadata' => false,

    // true: allow text-only opening posts; false: require an attachment when attachments are enabled.
    // Text-only replies are allowed either way. A post must still contain valid content.
    'nofileok' => true,

    // Maximum opening-post thumbnail width in pixels; integer of at least 1.
    'maxwop' => 250,

    // Maximum opening-post thumbnail height in pixels; integer of at least 1.
    'maxhop' => 250,

    // Maximum reply thumbnail width in pixels; integer of at least 1.
    'maxw' => 250,

    // Maximum reply thumbnail height in pixels; integer of at least 1. Aspect ratio is preserved.
    'maxh' => 250,

    // --- Private keys ---
    //
    // Private random board key for tripcodes and editor conflict tokens. Keep it secret and unchanged.
    // For a NEW board only, generate a key with: php -r "echo bin2hex(random_bytes(32));"
    // Never publish this file or replace an established board key during an upgrade.
    // The repository deliberately ships this blank. Generate a private key before first use.
    // Configure your deployed copy; do not commit the populated value back to GitHub.
    'tripseed' => '',

    // Optional extra management URL key: '' disables it; a nonempty secret requires ?manage=YOUR_KEY.
    // When enabled, the public Manage link is hidden. Account login is still required.
    // This is not an administrator password; account passwords are changed in Manage.
    'managekey' => '',

    // --- Storage: SQLite 3 or flat-file only ---
    //
    // Accounts table/collection name. Keep existing names on an established board.
    // All five names below must be distinct, using letters, digits and underscores,
    // starting with a letter or underscore. Avoid sqlite_ names and reserved adelia_jobs.
    'dbaccounts' => 'accounts',

    // Keyword-rules table/collection name; changing the name does not migrate its records.
    'dbkeywords' => 'keywords',

    // Moderation-log table/collection name; contains staff actions, not poster IP records.
    'dblogs' => 'logs',

    // Posts table/collection name; includes both threads and replies.
    'dbposts' => 'b_posts',

    // Reports table/collection name. Retain it even when public reporting is disabled.
    'dbreports' => 'b_reports',

    // SQLite file path, e.g. .adelia.db; or hidden JSON path, e.g. .adelia.json, for flatfile.
    // Relative paths start at the board directory. Protect absolute paths with private storage rules.
    // For flatfile, keep .json, .json.head and .json.journal together; never edit the bundle by hand.
    // Changing the path does not move data. Read STORAGE.md and use the converter for an existing board.
    'dbpath' => '.adelia.db',

    // Only 'sqlite' or 'flatfile'. SQLite needs PDO/pdo_sqlite; flatfile needs no database extension.
    // A new empty flat-file store needs php bin/storage.php init before starting the board.
    // Do not switch an existing board without conversion. SQLite creates its schema automatically.
    'dbdriver' => 'sqlite',

    // --- Public posting forms and identities ---
    //
    // Fields hidden/ignored for visitor opening posts: [] shows all; e.g. ['email', 'subject'].
    // Supported names: 'name', 'email', 'subject', 'message', 'password', 'file', 'embed'.
    // Staff forms are exempt. Keep enough fields available to submit a valid post.
    // Hiding password prevents visitors from assigning their own post-deletion password.
    'hidefieldsop' => [],

    // Same list of field names as hidefieldsop, applied to visitor replies instead.
    'hidefields' => [],

    // Nonempty list of display names for unnamed posters, e.g. ['Anonymous'].
    // If multiple names are supplied, one is selected at random for each unnamed post.
    'anonymous' => ['Anonymous'],

    // Staff labels: first entry is administrators, second is moderators.
    // Each entry is [label, CSS color], e.g. ['Admin', 'red']. Use trusted text/colors only.
    'capcodes' => [
        ['Admin', 'red'],
        ['Mod', 'purple'],
    ],

    // --- Theme discovery and media allowlists ---
    //
    // Retained configuration array; currently does not control the theme menu.
    // All top-level stylesheets/*.css files are discovered automatically. Use defaultstyle above.
    // Keep the key while Config requires it; edit stylesheets/shared/adelia.css for layout integration.
    'stylesheets' => [],

    // Allowed detected MIME types mapped to a saved extension, without its dot.
    // Example: 'image/png' => ['png']. [] disables file uploads, including URL-downloaded files.
    // The second array item, when used for non-images, is a preset thumbnail path, NOT another extension.
    // Optional video entries include 'video/mp4' => ['mp4'] and 'video/webm' => ['webm'];
    // these need FFmpeg/ffprobe on PATH for processing. Do not allow executable or untrusted HTML types.
    'uploads' => [
        'image/jpeg' => ['jpg'],
        'image/pjpeg' => ['jpg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
    ],

    // oEmbed provider name => endpoint URL. ADELIAEMBED is replaced with the submitted URL.
    // [] disables provider embeds; set uploadviaurl to false as well to remove the public URL field.
    // Existing saved embeds remain recognizable when providers are removed or this list is disabled.
    // Provider services are external and need separate end-to-end validation.
    'embeds' => [
        'SoundCloud' => 'https://soundcloud.com/oembed?format=json&url=ADELIAEMBED',
        'Vimeo' => 'https://vimeo.com/api/oembed.json?url=ADELIAEMBED',
        'YouTube' => 'https://www.youtube.com/oembed?url=ADELIAEMBED&format=json',
    ],
];
