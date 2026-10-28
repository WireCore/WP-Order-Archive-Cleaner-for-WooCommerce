# WireCore Order Archive Cleaner for WooCommerce

Archive old WooCommerce orders automatically to keep the order list focused – without deleting them. With rules, CSV export, retention and webhooks.

The plugin adds the order status “Archived” and moves qualifying orders into it, once a day in the background or manually from the order list. Archived orders disappear from “All”, but remain in WooCommerce and can be restored to their previous status at any time.

The user-facing description lives in [`readme.txt`](readme.txt), which is also shown on wordpress.org.

## Features

- Daily background run via Action Scheduler, continued batch by batch until all orders are checked; cutoff of 1–120 months, order statuses of your choice, batch size up to 200.
- Dry-run mode, “Run now” with the result of the batch, optional summary email.
- Rules: skip orders with refunds, above a total, of customers with selected roles or with products from selected categories.
- Bulk actions “Archive” / “Restore from archive” and order actions on the order screen; restoring returns the previous status.
- Scheduled CSV export of all archived orders by email (weekly/monthly).
- Retention: permanently delete archived orders after 1–20 years, optionally only after a CSV backup by email.
- Webhook on every archived order, signed with HMAC-SHA256.
- Dashboard widget, WP-CLI, HPOS compatible.
- Translations: English source strings, German (`de_DE`, `de_DE_formal`) bundled.

Requires WordPress 6.5+, WooCommerce 8.2+ and PHP 7.4+.

## Installation

From the WordPress plugin directory: _Plugins → Add New → “WireCore Order Archive Cleaner”_.

With Composer via [WPackagist](https://wpackagist.org/):

```bash
composer require wpackagist-plugin/circle3-order-archive-cleaner-for-woocommerce
```

The slug `circle3-order-archive-cleaner-for-woocommerce` dates from the former Circle3 brand and cannot be changed on wordpress.org. The settings are under _WooCommerce → Order Archive_.

## WP-CLI

```bash
wp wirecore-archive run [--all]
wp wirecore-archive restore <order-id> [--status=<status>]
wp wirecore-archive status [--format=table|json|csv]
wp wirecore-archive export
```

## Hooks

| Hook                               | Type   | Parameters                        | Purpose                                                      |
| ---------------------------------- | ------ | --------------------------------- | ------------------------------------------------------------ |
| `wirecore_oac_should_skip_order`   | filter | `bool $excluded, WC_Order $order` | Never archive an order automatically.                        |
| `wirecore_oac_after_archive_batch` | action | `array $result`                   | After every batch: `archived`, `skipped`, `dry_run`, `next`. |

## Webhook

On every archived order, a JSON `POST` request is sent to the configured HTTPS address:

```json
{
	"event": "order.archived",
	"order_id": 123,
	"order_number": "123",
	"previous_status": "completed",
	"customer_email": "customer@example.org",
	"total": "49.90",
	"currency": "EUR",
	"date_created": "2024-03-01T10:00:00+00:00",
	"site_url": "https://shop.example.org"
}
```

With a secret, the header `X-WireCore-Signature: sha256=<hex>` contains `hash_hmac('sha256', $raw_body, $secret)`.

## Development

Requirements: Docker. Node.js and Composer run in containers as well.

```bash
docker compose up -d   # WordPress on http://localhost:8094, phpMyAdmin on :8095
docker compose exec wordpress wp --allow-root core install --url=http://localhost:8094 --title=Dev --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email
docker compose exec wordpress wp --allow-root plugin install woocommerce --activate
docker compose exec wordpress wp --allow-root plugin activate circle3-order-archive-cleaner-for-woocommerce
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app node:22 npm install   # Prettier, @wirecore/wirelayer-css
```

WordPress itself lives in the Docker volume `wordpress`, so WooCommerce survives recreating the container. The plugin is mounted as `circle3-order-archive-cleaner-for-woocommerce`. The container runs the newest supported PHP version (8.5, see `Dockerfile`), so deprecations show up during development.

The settings page uses the [WireLayer](https://github.com/WireCore/WireLayer) components (`.wc-*` classes). `npm run build:css` writes the components listed in `package.json`, scoped to `#wirecore-oac`, with their fonts to `assets/wirelayer/`. The output is committed, so the plugin needs no build step; run the command again after adding a component or updating `@wirecore/wirelayer-css`.

WP-CLI is available in the container:

```bash
docker compose exec wordpress wp --allow-root wirecore-archive status
```

### Code style and checks

- Formatting: Prettier with tabs and single quotes – `npm run format` / `npm run format:check`.
- Plugin Check (the tool used by the wordpress.org review):

    ```bash
    docker compose exec wordpress wp --allow-root plugin install plugin-check --activate
    docker compose exec wordpress wp --allow-root plugin check circle3-order-archive-cleaner-for-woocommerce \
        --include-experimental \
        --exclude-directories=node_modules,db-data,.vscode,.github,vendor,tests \
        --exclude-files=.gitignore,.gitattributes,.distignore,.prettierrc,.prettierignore,CLAUDE.md,README.md,CHANGELOG.md,SECURITY.md,docker-compose.yml,Dockerfile,composer.json,composer.lock,package.json,package-lock.json,phpcs.xml.dist,phpunit.xml.dist
    ```

### Tests

PHPUnit integration tests run against the WordPress test suite (`wp-phpunit`, WordPress core from `roots/wordpress-no-content`) with WooCommerce from WPackagist and a separate database `wordpress_test` – never point them at a real database, the suite empties it.

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 composer install
docker compose exec db mariadb -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS wordpress_test"
docker compose exec -w /var/www/html/wp-content/plugins/circle3-order-archive-cleaner-for-woocommerce wordpress vendor/bin/phpunit
```

The database settings in [`tests/wp-tests-config.php`](tests/wp-tests-config.php) default to the Docker environment and can be overridden with `WP_TESTS_DB_HOST`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_NAME`.

The plugin supports PHP 7.4 – 8.5. To run the syntax check and the tests on every version like the CI does, use the official PHP images in the network of the Docker environment:

```bash
for v in 7.4 8.0 8.1 8.2 8.3 8.4 8.5; do
    docker run --rm --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]')_default" -v "$PWD":/app -w /app -e WP_TESTS_DB_PASSWORD=password php:$v-cli \
        sh -c "docker-php-ext-install mysqli >/dev/null && find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -exec php -l {} \; | grep -v 'No syntax errors'; vendor/bin/phpunit"
done
```

Composer installs the dependencies for PHP 7.4 (`config.platform` in `composer.json`), so the same `vendor/` works on every version.

By default the tests store orders as posts. With `WIRECORE_OAC_HPOS=1` they use the HPOS tables, the default of new shops – e.g. `docker compose exec -e WIRECORE_OAC_HPOS=1 -w … wordpress vendor/bin/phpunit` or `-e WIRECORE_OAC_HPOS=1` in the loop above. The CI runs both.

### Translations

All UI strings are English in the code (text domain `circle3-order-archive-cleaner-for-woocommerce`) and translated in `languages/`. After changing strings (`P=wp-content/plugins/circle3-order-archive-cleaner-for-woocommerce`):

```bash
docker compose exec wordpress wp --allow-root i18n make-pot $P $P/languages/circle3-order-archive-cleaner-for-woocommerce.pot --domain=circle3-order-archive-cleaner-for-woocommerce --exclude=node_modules,db-data,languages,vendor,tests
docker compose exec wordpress wp --allow-root i18n update-po $P/languages/circle3-order-archive-cleaner-for-woocommerce.pot $P/languages
# translate new or changed entries in both .po files, then:
docker compose exec wordpress wp --allow-root i18n make-mo $P/languages
docker compose exec wordpress wp --allow-root i18n make-php $P/languages
```

### Package contents

Only the files needed by the plugin are shipped. Development files are excluded via [`.distignore`](.distignore) and mirrored in [`.gitattributes`](.gitattributes) – add new development files to both.

### Continuous integration

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push to `main` and on pull requests:

- PHP syntax check on PHP 7.4 – 8.5
- PHP compatibility from PHP 7.4 up to 8.5 with PHPCompatibility 10 (`composer phpcs`, see [`phpcs.xml.dist`](phpcs.xml.dist))
- PHPUnit on PHP 7.4 – 8.5, each with HPOS and with post storage (`composer test`); deprecations fail the tests
- Prettier (`npm run format:check`) and WireLayer CSS up to date
- Plugin Check on the built package, warnings included

## Releasing

1. Bump `Version:` in `wirecore-order-archive-cleaner.php` and `Stable tag:` in `readme.txt`, and add the changelog entry to `readme.txt` and `CHANGELOG.md`.
2. Commit and push to `main`, wait for CI to pass.
3. Create and push a tag with the version number, without a `v` prefix:

    ```bash
    git tag 1.0.1 && git push origin 1.0.1
    ```

[`.github/workflows/release.yml`](.github/workflows/release.yml) then checks that tag, plugin header and stable tag match, deploys the package (files selected by `.distignore`, assets from `.wordpress-org/`) to the wordpress.org SVN repository and creates a GitHub release with the ZIP.

One-time setup: add the secrets `SVN_USERNAME` and `SVN_PASSWORD` (the SVN password from the wordpress.org profile under _Account & Security_) to the GitHub environment `wordpress.org`.

## License

[GPL-2.0-or-later](LICENSE) © WireCore GmbH
