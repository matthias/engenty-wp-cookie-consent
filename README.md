# Cookie Dialog for WordPress

A simple, beautiful cookie dialog for the EU. Categories and services, scripts and embeds only after consent, third-party files from the site's own server, a consent log that proves what people saw, Google Consent Mode v2 – and an assistant that sets it up through the AI provider WordPress already knows.

By [engenty](https://engenty.ai). GPL-2.0-or-later. WordPress 6.6+, PHP 8.1+; the assistant needs WordPress 7.0 (the AI Client).

## What it does

- **Dialog**: Box, Leiste or Fenster in three sizes; colours, corners, font and logo from the theme; light, dark or as the device. „Alle akzeptieren“ and „Nur notwendige“ as two equal buttons; categories and services with provider, purpose, privacy link and cookies on the second layer. Shadow DOM, so a theme cannot restyle its buttons; focus trap and keyboard; about 10 KB gzipped with its CSS.
- **Scripts only after consent**, four ways: a snippet pasted in the admin; scripts WordPress enqueues or prints, recognised by handle, address or inline code (`script_loader_tag` and the head, body-start and footer output); `type="text/plain" data-cookie-service="…"` in a theme; placeholders for embeds.
- **Embeds** (YouTube, Vimeo, Maps, Instagram, X, TikTok, Spotify and every unknown iframe) wait as placeholders in the content's size. Thumbnail and title come through the site's server into the media library; YouTube loads from youtube-nocookie.com. „Laden“ loads once, „Immer laden“ is consent for the service.
- **Third-party files**: Google Fonts, Adobe Fonts, Font Awesome, CDN stylesheets and images are copied to the site and the references rewritten; script libraries per file; Gravatar through the site; the emoji script and preconnect hints to third parties removed.
- **Scan and Prüfung**: the home page and up to 20 more, read as a visitor without consent; every host by kind, mapped to the library of 60 services; Set-Cookie before consent; checks with one-click fixes; weekly re-scan.
- **Consent log**: consent ID, time, choice, the version the person saw (with a snapshot of the texts), language, source, browser family, the IP as SHA-256 with a daily salt. CSV, retention with a daily prune, lookup by consent ID. A public, rate-limited REST route records decisions, so page caches keep working.
- **Google**: Consent Mode v2 default (all denied, per region) before any tag, the update on choice, Basic or Advanced, Tag Manager, `url_passthrough`, `ads_data_redaction`, the `cookie_dialog_consent` dataLayer event; Meta's `fbq('consent')` and Microsoft UET too.
- **Assistant**: through `wp_ai_client_prompt()` and the provider under Einstellungen › Connectors – no key field of its own. Drafts the texts in the site's tone, names unknown hosts, translates, writes the privacy paragraph, and changes everything on request with cards and undo. Simple jobs run in the browser's built-in model (Chrome, Edge) without any setup. Without either, everything else works.
- Blocks „Cookie-Einstellungen“, „Cookie-Tabelle“, „Erst nach Einwilligung“; shortcodes `[cookie_settings]`, `[cookie_table]`, `[cookie_placeholder service="…"]`, `[cookie_consent_id]`; `wp cookie-dialog export|import|scan|check|live|prune`.

## For theme and plugin code

```html
<script type="text/plain" data-cookie-service="ga4">…</script>
<script type="text/plain" data-cookie-category="statistics marketing">…</script>
<a href="#cookie-settings">Cookie-Einstellungen</a>
```

```js
cookieDialog.has( 'statistics' ); // or a service ID
cookieDialog.open();
document.addEventListener( 'cookie-dialog:consent', ( e ) => console.log( e.detail ) );
```

```php
cookie_dialog_has( 'marketing' ); // reads the cookie: only on pages no cache keeps
add_filter( 'cookie_dialog_library', fn( $presets ) => $presets ); // add or correct presets
add_filter( 'cookie_dialog_active', fn( $on ) => $on );             // no dialog on a page
```

## Develop

```bash
npm install && composer install
npm run build
WP_PATH=/tmp/wp WP_URL=http://127.0.0.1:8920 sh bin/test-site.sh   # WordPress + SQLite + demo site
wp --path=/tmp/wp server --host=127.0.0.1 --port=8920
WP_PATH=/tmp/wp sh bin/smoke.sh
WP_PATH=/tmp/wp vendor/bin/phpunit
WP_URL=http://127.0.0.1:8920 npx playwright test
npm run zip
npm run build && npm run i18n   # POT, PO, MO and the JSON for the built scripts
```

The admin's source strings are German; `languages/` holds English (US, UK). `make-json` gets a map because WP-CLI 2.12 turns `build/admin.js` into `build/a.js` when it strips `.min.js`, which would give the JSON the wrong name.

`includes/` holds the PHP (one class per part, see the plan), `src/dialog` the dialog on the site (TypeScript, no framework), `src/admin` the admin app, `src/blocks` the blocks' editor, `presets/` the library.
