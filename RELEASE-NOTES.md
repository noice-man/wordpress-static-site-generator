## WPR Static 1.1.0

First same-domain static frontend release for staging acceptance testing.

- One-click static frontend on the current WordPress domain.
- Automatic debounced rebuilds, conservative invalidation and safe fallback to WordPress.
- Apache static routing with HTTP probes, early PHP mode and regular WordPress fallback.
- Integrity checks, portable ZIP export, WP-CLI and existing WPR Update Hub SDK (telemetry disabled).

**Installation:** download the attached `wpr-static-v1.1.0.zip` asset and upload it in WordPress. Do not install the automatic GitHub source ZIP.

**Testing:** local checks and Apache HTTP tests passed; full WordPress/MySQL integration and live Hub updates remain unverified. Test on staging before production.
