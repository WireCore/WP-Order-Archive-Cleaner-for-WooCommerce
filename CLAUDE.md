# WireCore Order Archive Cleaner for WooCommerce

WooCommerce plugin, published on wordpress.org. The slug `circle3-order-archive-cleaner-for-woocommerce` is fixed – it was assigned at the review under the former Circle3 name and cannot be changed; plugin name and code use the WireCore brand. All repository content – code comments, UI source strings, docs – is in English; German UI texts exist only in `languages/`.

## Conventions

- Static classes `WireCore_OAC_*` in `includes/class-wirecore-oac-*.php`, no autoloader, no namespaces. Classes with hooks have an `init()` that registers them; the main file calls them on `plugins_loaded` once WooCommerce is loaded.
- Prefix everything global: functions/options/hooks/nonces/order meta (`_wirecore_oac_`) `wirecore_oac_`, constants `WIRECORE_OAC_`, CSS classes/IDs `wirecore-oac-`, Action Scheduler group `wirecore-oac`, WP-CLI command `wp wirecore-archive`.
- The text domain is the slug `circle3-order-archive-cleaner-for-woocommerce` (wordpress.org requires it), always as a literal string.
- Admin page in plain PHP with `admin-post.php` forms, nonces, `wp_safe_redirect()` plus a notice query arg. No JavaScript, no build step except `npm run build:css` (below). Capability `manage_woocommerce`.
- UI in the WireLayer corporate design: the markup uses the `.wc-*` classes of `@wirecore/wirelayer-css` (class reference: `node_modules/@wirecore/wirelayer-css/llms-full.txt`). `npm run build:css` writes the used components, scoped to `#wirecore-oac`, to `assets/wirelayer/` – committed, CI checks it is up to date. Add a component to the list in `package.json` before using its classes, and run the command again after updating the package. `wirecore-oac-*` classes are for page layout in `assets/admin.css` (scoped to `#wirecore-oac`, colors and spacing from WireLayer variables). Icons are inline Lucide SVGs (`WireCore_OAC_Admin::icon()`).
- Orders only through the WooCommerce order APIs (`wc_get_orders()`, `WC_Order`), never SQL – the plugin supports HPOS and the legacy post storage.
- Supported versions: WordPress 6.5+, WooCommerce 8.2+, PHP 7.4 – 8.5 (verified with PHPCompatibility 10 and the tests on every PHP version) – do not use newer functions without raising the minimum, and avoid anything deprecated up to PHP 8.5 (e.g. `ReflectionProperty::setAccessible()` only below PHP 8.1). The Docker environment runs PHP 8.5.
- Formatting with Prettier (tabs, single quotes). Plugin Check must stay free of errors and warnings; justified exceptions are marked with `phpcs:ignore`/`phpcs:disable` plus a comment explaining why.

## Architecture

- `WireCore_OAC_Settings` – one option `wirecore_oac_settings`; everything that is stored (form, WP-CLI, tests) goes through `sanitize()`. Order statuses are stored without `wc-`.
- `WireCore_OAC_Archiver` – the status `wc-archived` (hidden from "All", counts as paid), `archive_order()`/`restore_order()` (previous status in `_wirecore_oac_previous_status`), the rules in `excluded()`, bulk and order actions. `run_batch($offset)` checks one batch: skipped orders stay in the query result, archived ones drop out – the next batch starts at `$offset` plus the skipped orders (`next`, `null` when done). Never change that without keeping skipped orders from blocking the queue.
- `WireCore_OAC_Scheduler` – Action Scheduler jobs: the daily run continues with async batches (`wirecore_oac_batch`, args offset and running totals) until `next` is `null`, then logs the run (`WireCore_OAC_Archiver::log_run()`) and sends the optional summary. Recurring jobs are synced with the settings in `schedule()` (activation, `admin_init`, after saving).
- `WireCore_OAC_Export` (CSV by email, formula-safe cells), `WireCore_OAC_Retention` (deletes archived orders after the retention period, never without a sent backup if it is enabled), `WireCore_OAC_Webhook` (POST on `woocommerce_order_status_archived`, `wp_safe_remote_post()`, HMAC signature).
- `WireCore_OAC_Admin` – page under WooCommerce → Order Archive with the tabs Archiving, Rules, Export & retention, Integrations. Each tab saves only its fields (`TAB_FIELDS`). `WireCore_OAC_Dashboard`, `WireCore_OAC_Cli` – dashboard widget and WP-CLI.

## Translations

All UI strings are English in the code and translated in `languages/`: `de_DE` (informal) and `de_DE_formal` (formal). WP-CLI output stays English and untranslated. `load_plugin_textdomain()` is kept for the bundled translations until language packs exist on translate.wordpress.org.

After changing strings (inside the Docker container, `P=wp-content/plugins/circle3-order-archive-cleaner-for-woocommerce`):

```bash
docker compose exec wordpress wp --allow-root i18n make-pot $P $P/languages/circle3-order-archive-cleaner-for-woocommerce.pot --domain=circle3-order-archive-cleaner-for-woocommerce --exclude=node_modules,db-data,languages,vendor,tests
docker compose exec wordpress wp --allow-root i18n update-po $P/languages/circle3-order-archive-cleaner-for-woocommerce.pot $P/languages
# translate new or changed entries in both .po files, then:
docker compose exec wordpress wp --allow-root i18n make-mo $P/languages
docker compose exec wordpress wp --allow-root i18n make-php $P/languages
```

`.po`, `.mo` and `.l10n.php` are part of the package. The files are created as root inside the container – `chown` them to your user if needed.

## Package contents

Only `wirecore-order-archive-cleaner.php`, `uninstall.php`, `index.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/` and `languages/` are shipped. New development or configuration files must be added to both `.distignore` and `.gitattributes` (`export-ignore`).

## Checks

CI (`.github/workflows/ci.yml`) must stay green: PHP lint 7.4–8.5, `composer phpcs` (PHPCompatibilityWP, `phpcs.xml.dist`), PHPUnit on PHP 7.4–8.5, each with HPOS and with post storage (see README for the loop over all versions in Docker), `npm run format:check`, `assets/wirelayer/` up to date with `npm run build:css`, Plugin Check in strict mode on the package built from `.distignore`.

Tests live in `tests/` (`test-*.php`, base class `WireCore_OAC_TestCase` with the helpers `settings()` and `order()`). They are integration tests against the WordPress test suite with WooCommerce from Composer (`wpackagist-plugin/woocommerce`, installed in `vendor/wpackagist-plugin/`). Run them inside the Docker container (see README) against the separate database `wordpress_test`; orders are stored as posts, with `WIRECORE_OAC_HPOS=1` in the HPOS tables – changes to order handling must pass both. Mails are caught by the test suite's mock mailer (`tests_retrieve_phpmailer_instance()`), HTTP requests with `pre_http_request`.

## Release

Bump `Version:` in `wirecore-order-archive-cleaner.php` and `Stable tag:` in `readme.txt` together, and add the entry to the changelog in `readme.txt` and `CHANGELOG.md`. Pushing a tag like `1.0.1` (no `v`) runs `.github/workflows/release.yml`, which verifies the version numbers and deploys to the wordpress.org SVN repository.
