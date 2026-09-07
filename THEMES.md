# Themes for Adelia

Use the **Style** menu at the top of the board, a thread, the catalog or the management area. The eight choices are **Yotsuba, Yotsuba B (original), Dark, Futaba Light, Jungle, Miku, Rugby and Sharp**. The browser remembers your selection. **Yotsuba B** is also the shared base stylesheet used by the other themes.

Start the app with the existing **Start Adelia.bat**. If you had the board open during the update, refresh it once. The launcher still works from the folder containing Adelia.

## Default and customization

`defaultstyle` in `settings.php` is `futaba-light`, the default for new visitors. Available filenames without `.css` are `yotsuba`, `style` (Yotsuba B), `dark`, `futaba-light`, `jungle`, `miku`, `rugby` and `sharp`. An unavailable configured default falls back to `style`; an unavailable saved browser choice leaves the configured default selected.

Themes are discovered from `stylesheets/*.css`. The older `stylesheets` configuration array is retained for configuration compatibility but no longer controls the menu. Do not include `style.css` again inside a new theme: the base file is always loaded first. Keep each theme's supporting files under `stylesheets/`.

Layout adjustments belong in `stylesheets/shared/adelia.css`, loaded after the theme. All application CSS now lives under `stylesheets/`; there is no separate `css/` directory. The eight theme CSS files stay at the top level, their 11 images are under `img/`, and the shared layout file is `shared/adelia.css`. The shared layout is not a selectable theme. Unused fonts, jQuery UI, old moderation/player styles and other theme assets were removed. Sharp's unused reference to a missing legacy logo background was removed too. After changing a PHP template or the theme menu, use **Manage → Rebuild All** to regenerate the static pages. Browser preferences override the default until changed in the Style menu.

## Compatibility

Posts use `div.post.op` and `div.post.reply`, with `intro`, `subject`, `name`, `trip`, `quote`, `fileinfo`, `post-image` and `body` classes. Catalog cards use `theme-catalog`, `threads` and `thread`. Adelia's form fields and JavaScript hooks are retained. Stored posts receive class aliases during rendering without altering their database contents.

The PHP development router serves theme images and web fonts while blocking private application files. Configure the hosting web server to serve these public assets and protect configuration, databases and application code. The retained themes use local assets; every remaining CSS image reference resolves to a bundled file.

The original theme licenses are in `LICENSE.Themes.md` and `LICENSE.Tinyboard.md`. Their attribution is included in the page footer. TinyIB's own license is unchanged.

This update changes presentation. Existing posting options, moderation rules, accounts and data storage remain governed by Adelia's configuration and backend.

## Native JavaScript

The interactive frontend uses native JavaScript modules and browser APIs. jQuery and its scrolling plugin have been removed. The selected themes and visible post/form/catalog markup are retained.

Image/GIF expansion, MP4/WebM playback, quote previews, live reply refresh, backlinks, CAPTCHA refresh, style preferences and deletion passwords work without a JavaScript library. Quote previews also support keyboard focus and Escape. Inactive pages show an unread count in the tab title instead of blinking it.

Fetched post markup is parsed in a detached, inert template and reconstructed with allowed elements, attributes and URLs before insertion. Previews omit duplicate IDs and form controls. Expanded media uses newly created DOM elements; standard YouTube, Vimeo and SoundCloud iframes use restricted attributes and a sandbox. Arbitrary scripts from an embed provider are not executed. Server-side validation and escaping remain necessary.

Use a browser with JavaScript module support. The local launcher serves the modules directly, and a hosting web server can serve them as static JavaScript; no Node.js, npm, build step, CDN or frontend package installation is required. After upgrading, use Manage → Rebuild All to refresh all generated pages, including existing threads.
