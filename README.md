# Static Site Generator for WordPress — WPR Static

Turn WordPress into a static HTML frontend on the **same domain**, without moving your CMS. Pre-generate eligible public pages, validate the output, and serve static HTML while continuing to edit content in WordPress.

## Features

- One-click static frontend, with automatic rebuilds after content changes.
- Direct Apache delivery when live HTTP probes pass; early PHP or regular WordPress fallback otherwise.
- Anonymous public pages only; dynamic requests remain in WordPress.
- Readiness diagnostics, integrity checks, build history and WP-CLI.
- Optional portable HTML ZIP export and local publication.
- Updates via [ворд-пресс.рф](https://xn----ctbgf6biedka.xn--p1ai/), telemetry disabled.

## Installation

Once published, download the **wpr-static-v1.1.0.zip** asset from [Releases](../../releases), not GitHub's automatically generated source archives. Upload it via WordPress → Plugins → Add New → Upload Plugin. Activate on staging first, then open Static Site Generator and click Enable static mode.

WordPress 6.2+, PHP 8.1+, single-site, pretty permalinks, anonymous loopback HTTP, DOM/XML and private writable storage. See the plugin's `readme.txt` for details.

## Русский

Бесплатный генератор статических сайтов WordPress. Статический HTML на текущем домене без переноса CMS, автоматическая пересборка, диагностика и экспорт ZIP.

Инструкция по установке и отчёт о проверках включены в установочный архив.

## Status and limitations

Version 1.1.0 is intended for staging acceptance testing. Full WordPress/MySQL integration, theme/plugin compatibility, Nginx live mode, 1,000-page load testing and live Update Hub updates have not yet been verified. Pages with dynamic indicators and requests with cookies/query parameters may bypass static delivery. WooCommerce frontend mode is disabled in this release. No universal compatibility or zero-PHP delivery on every host is claimed.

## License

GPL-2.0-or-later.
