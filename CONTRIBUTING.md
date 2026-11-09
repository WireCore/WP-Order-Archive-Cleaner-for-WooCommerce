# Contributing

Thank you for helping with WireCore Order Archive Cleaner for WooCommerce. Bug reports, ideas and pull requests are welcome.

## Before you start

- For anything bigger than a small fix, open an issue first so we can agree on the approach before you spend time on it.
- Questions about using the plugin belong in the [support forum on wordpress.org](https://wordpress.org/support/plugin/circle3-order-archive-cleaner-for-woocommerce/).
- Security problems go through the private channel in [SECURITY.md](SECURITY.md), not through issues.

## Development

The setup – Docker for WordPress and WooCommerce, Node.js for Prettier and the WireLayer CSS – is described in [README.md](README.md#development).

Before a pull request:

```bash
npm run format:check
composer phpcs
docker compose exec -w /var/www/html/wp-content/plugins/circle3-order-archive-cleaner-for-woocommerce wordpress vendor/bin/phpunit
docker compose exec -e WIRECORE_OAC_HPOS=1 -w /var/www/html/wp-content/plugins/circle3-order-archive-cleaner-for-woocommerce wordpress vendor/bin/phpunit
```

The CI runs the same on PHP 7.4 – 8.5, each with HPOS and with post storage, plus Plugin Check on the built package. Add or adjust tests for what you change.

## Guidelines

- Code, comments, UI source strings and commit messages are in English. The German translations (`de_DE` informal, `de_DE_formal` formal) are bundled in `languages/`; after changing UI strings, update the template and both `.po` files and regenerate the `.mo` and `.l10n.php` files (see [README.md](README.md#translations)).
- The plugin supports WordPress 6.5+, WooCommerce 8.2+ and PHP 7.4 – 8.5: no newer functions, nothing deprecated up to PHP 8.5.
- Everything global carries the prefix `wirecore_oac_` / `WIRECORE_OAC_` / `wirecore-oac-`.
- Orders are read and changed only through the WooCommerce order APIs (`wc_get_orders()`, `WC_Order`), never with SQL – changes must work with HPOS and with post storage.
- Everything that is stored goes through `WireCore_OAC_Settings::sanitize()`.
- `WireCore_OAC_Archiver::run_batch()` continues after the orders it skipped – skipped orders must never block the daily run.
- Plugin Check stays free of errors and warnings.

## License

By contributing you agree that your contribution is licensed under the [GPL-2.0-or-later](LICENSE), like the rest of the project.

Every commit carries a `Signed-off-by` line (`git commit -s`). With it you confirm the [Developer Certificate of Origin](https://developercertificate.org): you wrote the change yourself, or it is based on work under a compatible open source license, and you have the right to contribute it under the GPL. The DCO check on the pull request fails without it. To add it afterwards: `git commit --amend -s` for the last commit, `git rebase --signoff <base>` for several, then push with `--force-with-lease`.
