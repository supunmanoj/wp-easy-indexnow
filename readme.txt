=== Easy IndexNow ===
Contributors: supunmanoj
Tags: indexnow, seo, bing, indexing, search engines
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Instantly notify Bing, Yandex, Seznam, Naver and other IndexNow search engines when your content changes.

== Description ==
* Generates and serves your IndexNow key file automatically (no file uploads).
* Submits URLs when posts/pages are published, updated, unpublished or deleted.
* Batches changes and sends them in the background so saving stays fast.
* Manual URL submission and one-click whole-site submission.
* Log of the last 50 submissions with readable result messages.
* Detects all public content types, including ones from other plugins, and flags new ones.
* "Check key file" button that diagnoses Cloudflare, firewall and cache problems.
* Works alongside free Yoast SEO.

== Installation ==
1. Upload the `easy-indexnow` folder to `/wp-content/plugins/` (or upload the zip via Plugins → Add New → Upload).
2. Activate the plugin.
3. Go to Settings → IndexNow and open the key file link to confirm it shows your key.
4. Click "Submit all published content" once.

== Changelog ==
= 1.3.0 =
* A 202 "key validation pending" response is now followed up automatically: the plugin resubmits after 10 minutes (then 30 and 120 if needed) until the key is confirmed.
* Shows the key status (verified, pending or not confirmed yet) on the settings page.

= 1.2.0 =
* Failed submissions (403 while a key is verified, 429, server errors) are retried automatically after 10, 30 and 120 minutes.
* The log shows the search engine's own error text and the site time zone.
* Clearer messages for new keys and a warning before generating a new key.

= 1.1.0 =
* Writes a real key file to the site root when possible (more reliable behind CDNs and caches).
* New "Check key file" diagnostic with Cloudflare detection.
* Lists every public content type, marks new ones, and can auto-enable future types.

= 1.0.0 =
* Initial release.
