# QA — 1.1.0

Build prepared for staging acceptance testing, not a certification of compatibility with all WordPress sites.

## Verified locally
- PHP syntax: all bundled PHP files, including the generated advanced-cache.php fixture.
- JavaScript syntax: admin.js.
- 42 existing URL, storage, publishing and lock assertions.
- 46 new PHP assertions with isolated WordPress stubs: routing and bypass conditions; dynamic HTML eligibility; foreign advanced-cache protection; owned drop-in and wp-config edits/restoration; invalidation and debounce; active marker removal.
- Actual HTTP testing on Apache 2.4.68: direct activation with live guard and HTML probes, homepage and nested page, HEAD, query/cookie/crawler/authentication bypass, direct/percent-encoded storage path rejection, original homepage after disable. 9 HTTP checks + 1 post-disable check.
- SDK included unchanged from version 1.0.0, telemetry disabled, stable slug and Update URI.

## Not verified here
Full WordPress/MySQL/PHP DOM + ZipArchive runtime, wp-cron behavior on the target hosting, early PHP execution within actual wp-settings.php, compatibility with themes/SEO plugins, 1,000-page load test, Nginx live mode, and update via deployed WPR Hub. The local environment has PHP CLI but no DOM/ZipArchive/database extension or running WordPress instance. Test on staging first.
