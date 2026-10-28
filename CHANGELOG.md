# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-28

### Added

- Order status “Archived”: archived orders are hidden from “All” in the order list, count as paid and can be restored to their previous status.
- Daily archiving in the background via Action Scheduler, continued batch by batch until all orders are checked; cutoff period, order statuses and batch size (up to 200) configurable.
- Dry-run mode, “Run now” and an optional summary email after each automatic run.
- Rules: skip orders with refunds, above a total, of customers with selected roles or with products from selected categories.
- Bulk actions and order actions to archive and restore orders.
- Scheduled CSV export of archived orders by email.
- Retention: permanent deletion of archived orders after a retention period, with an optional CSV backup by email first.
- Webhook with HMAC-SHA256 signature for every archived order.
- Dashboard widget, WP-CLI commands (`wp wirecore-archive`) and the hooks `wirecore_oac_should_skip_order` and `wirecore_oac_after_archive_batch`.
- Settings page in the WireLayer design under WooCommerce → Order Archive.
- German translations (`de_DE`, `de_DE_formal`).
