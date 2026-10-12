=== Cookie Dialog ===
Contributors: engenty
Tags: cookie, consent, gdpr, dsgvo, cookie banner
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, beautiful cookie dialog for the EU: scripts and embeds only after consent, a consent log, Google Consent Mode v2 – and an assistant that sets it up.

== Description ==

Cookie Dialog reads your website as a visitor without consent, proposes the whole setup – services, texts, look from your theme – and shows it on your home page before anything changes. One click switches it on.

* Two equal buttons, nothing pre-selected, categories and services on the second layer.
* Scripts, pixels and embeds wait for consent; YouTube, Maps, Instagram and others as placeholders with a preview image fetched by your server.
* Google Fonts, Font Awesome and other third-party files are copied to your server, so no IP address leaves the site.
* A consent log with the version each person saw, IP addresses only hashed, CSV export.
* Google Consent Mode v2, Tag Manager, Meta and Microsoft consent signals.
* A library of 60 services with German and English texts.
* The assistant's jobs (texts in the site's tone, translating, naming unknown hosts, the privacy paragraph) use the AI provider set up under Settings › Connectors (WordPress 7.0+); simple ones run in your browser's built-in model. Everything else works without AI.
* With engenty.goose, the copilot for WordPress, you say on Start what should be different: it changes dialog, services and settings through Cookie Dialog's abilities, and every change can be taken back.

== Changelog ==

= Unreleased =
* The conversation moved to engenty.goose, the copilot for WordPress: Cookie Dialog brings a skill and three abilities (read the configuration, change it, take a change back); on its own pages Goose has the skill from the start. Without Goose, Start shows how to activate or get it. The jobs (translating, shortening, naming hosts, the privacy paragraph, texts in the site's tone) stay in Cookie Dialog.

= 0.2.2 =
* The assistant works with OpenAI: its answer schemas go to the provider in the strict form structured outputs require, without a temperature, and the model lists start with today's models (GPT-6 Luna, Claude Haiku 5.5, Gemini 3.5 Flash-Lite; for „stark“ Claude Sonnet 5.5, GPT-6.1 Sol, Gemini 3.8 Flash).
* The assistant sees the last scan and says what the site loads and where; a service it did not see is not used.
* Checks of the Prüfung can be ignored until they find something else.
* The assistant's suggestions come from the site: an open check, an unknown host, a service in use, the log, a missing language.
* On Start one input at a time: while a conversation is open in the chat, the card steps back.
* „Immer laden“ on a placeholder allows only that service; the banner stays open for the rest.
* Second layer: Alle akzeptieren, Nur notwendige, Auswahl speichern; „Gleich“ makes all three the same.
* The credit reads engenty Cookie Dialog and links to the plugin's page on engenty.ai.

= 0.2.1 =
* WordPress names the comment cookies only where the site shows a comment form (the scan looks for one), and wp-postpass_* only with a password-protected post.
* The arrow beside the number of services sits in the middle of the line.
* The Instagram feed of Smash Balloon's Instagram Feed waits as a placeholder with „Beiträge laden“ and „Immer laden“, like a video; loaded, the feed starts by itself.
* A placeholder whose embed brings a script ran that script twice when loaded.
* The preview bar on the site is one short line at the top.

= 0.2.0 =
* Export and import under Settings: the settings as a JSON file from one site to another, e.g. from staging to live. The site keeps whether the dialog is on and its log; privacy policy and imprint are found by their address.
* A preview on the site: `?cookie-dialog=preview` shows the dialog to an admin as a new visitor sees it, also before it is switched on; nothing is stored or logged.
* Look: button style (both filled, accept emphasised, both outlined), switches for the title and for the links to privacy policy and imprint.
* A category of its own for external media: videos, maps and posts from other platforms (YouTube, Vimeo, Google Maps, Instagram and others) no longer sit in Marketing.
* The Instagram feed of Smash Balloon's Instagram Feed waits for consent to Instagram.
* Visitors can pick the language of the dialog when there is more than one.
* The assistant translates in the browser with Chrome's built-in Translator.
* Admin and dialog in eleven more languages: French, Spanish, Italian, Dutch, Portuguese, Polish, Swedish, Danish, Norwegian, Finnish and Czech.
* The link to the settings on the first layer has no arrow.
* Fixed: the log's CSV download answered 403.

= 0.1.0 =
* First release.
