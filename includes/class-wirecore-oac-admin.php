<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Settings page under WooCommerce → Order Archive with the tabs Archiving,
 * Rules, Export & retention and Integrations.
 */
class WireCore_OAC_Admin
{
	const PAGE = 'wirecore-order-archive-cleaner';
	const CAPABILITY = 'manage_woocommerce';
	// Settings saved by the form of each tab
	const TAB_FIELDS = [
		'archiving' => [
			'enabled',
			'cutoff_months',
			'statuses',
			'batch_size',
			'dry_run',
			'notify',
		],
		'rules' => [
			'exclude_refunded',
			'exclude_above_total',
			'exclude_roles',
			'exclude_categories',
		],
		'export' => [
			'export_enabled',
			'export_frequency',
			'export_email',
			'retention_enabled',
			'retention_years',
			'retention_export',
		],
		'integrations' => ['webhook_enabled', 'webhook_url', 'webhook_secret'],
	];
	const LISTS = ['statuses', 'exclude_roles', 'exclude_categories'];

	public static function init()
	{
		add_action('admin_menu', [self::class, 'menu']);
		add_filter(
			'plugin_action_links_' . plugin_basename(WIRECORE_OAC_FILE),
			[self::class, 'action_links']
		);
		add_action('admin_enqueue_scripts', [self::class, 'assets']);
		add_action('admin_post_wirecore_oac_settings', [
			self::class,
			'handle_settings',
		]);
		add_action('admin_post_wirecore_oac_run', [self::class, 'handle_run']);
		add_action('admin_init', [self::class, 'privacy_policy']);
	}

	public static function menu()
	{
		add_submenu_page(
			'woocommerce',
			__(
				'Order Archive',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			__(
				'Order Archive',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			self::CAPABILITY,
			self::PAGE,
			[self::class, 'page']
		);
	}

	public static function action_links($links)
	{
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url(self::page_url()),
				esc_html__(
					'Settings',
					'circle3-order-archive-cleaner-for-woocommerce'
				)
			)
		);
		return $links;
	}

	public static function assets($hook)
	{
		if ($hook !== 'woocommerce_page_' . self::PAGE) {
			return;
		}
		// WireLayer components, scoped to #wirecore-oac (npm run build:css)
		wp_enqueue_style(
			'wirecore-oac-wirelayer',
			plugins_url('assets/wirelayer/wirelayer.css', WIRECORE_OAC_FILE),
			[],
			WIRECORE_OAC_VERSION
		);
		wp_enqueue_style(
			'wirecore-oac-admin',
			plugins_url('assets/admin.css', WIRECORE_OAC_FILE),
			['wirecore-oac-wirelayer'],
			WIRECORE_OAC_VERSION
		);
	}

	public static function privacy_policy()
	{
		wp_add_privacy_policy_content(
			'WireCore Order Archive Cleaner for WooCommerce',
			wp_kses_post(
				wpautop(
					__(
						'If the webhook is enabled by an administrator, every archived order is sent to the configured HTTPS address, including the order ID, order number, previous status, customer email address, total, currency, order date and the address of this site. Scheduled exports and the backup before deletion send archived orders with customer names and email addresses as CSV file to the configured email address.',
						'circle3-order-archive-cleaner-for-woocommerce'
					)
				)
			)
		);
	}

	public static function handle_settings()
	{
		self::require_capability();
		check_admin_referer('wirecore_oac_settings');

		$post = wp_unslash($_POST);
		$tab = isset($post['tab']) ? sanitize_key($post['tab']) : '';
		if (!isset(self::TAB_FIELDS[$tab])) {
			self::redirect('archiving', '');
		}

		// Every value is validated by WireCore_OAC_Settings::sanitize()
		$settings = WireCore_OAC_Settings::get();
		foreach (self::TAB_FIELDS[$tab] as $field) {
			if (in_array($field, WireCore_OAC_Settings::FLAGS, true)) {
				$settings[$field] = !empty($post[$field]);
			} elseif (in_array($field, self::LISTS, true)) {
				$settings[$field] = isset($post[$field])
					? (array) $post[$field]
					: [];
			} else {
				$settings[$field] = isset($post[$field]) ? $post[$field] : '';
			}
		}
		WireCore_OAC_Settings::save($settings);
		WireCore_OAC_Scheduler::reschedule();

		$saved = WireCore_OAC_Settings::get();
		self::redirect(
			$tab,
			$tab === 'integrations' &&
			trim((string) $settings['webhook_url']) !== '' &&
			!WireCore_OAC_Webhook::allowed_url($saved['webhook_url'])
				? 'invalid_url'
				: 'saved'
		);
	}

	public static function handle_run()
	{
		self::require_capability();
		check_admin_referer('wirecore_oac_run');

		$result = WireCore_OAC_Archiver::run_batch();
		WireCore_OAC_Archiver::log_run(
			$result['archived'],
			$result['skipped'],
			$result['dry_run']
		);
		wp_safe_redirect(
			add_query_arg(
				[
					'wirecore_oac_notice' => $result['dry_run']
						? 'dry_run'
						: 'run',
					'archived' => $result['archived'],
					'skipped' => $result['skipped'],
				],
				self::page_url()
			)
		);
		exit();
	}

	private static function require_capability()
	{
		if (!current_user_can(self::CAPABILITY)) {
			wp_die(
				esc_html__(
					'Insufficient permissions.',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				'',
				['response' => 403]
			);
		}
	}

	private static function redirect($tab, $notice)
	{
		wp_safe_redirect(
			add_query_arg(
				['tab' => $tab, 'wirecore_oac_notice' => $notice],
				self::page_url()
			)
		);
		exit();
	}

	public static function page_url($tab = '')
	{
		$url = admin_url('admin.php?page=' . self::PAGE);
		return $tab !== '' ? add_query_arg('tab', $tab, $url) : $url;
	}

	/**
	 * The "Archived" view of the order list, with or without HPOS.
	 */
	public static function orders_url()
	{
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? admin_url(
				'admin.php?page=wc-orders&status=wc-' .
					WireCore_OAC_Archiver::STATUS
			)
			: admin_url(
				'edit.php?post_type=shop_order&post_status=wc-' .
					WireCore_OAC_Archiver::STATUS
			);
	}

	public static function page()
	{
		if (!current_user_can(self::CAPABILITY)) {
			return;
		}

		$tabs = [
			'archiving' => __(
				'Archiving',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			'rules' => __(
				'Rules',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			'export' => __(
				'Export & retention',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			'integrations' => __(
				'Integrations',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
		];
		// Read-only display parameters, they do not change anything.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab = isset($_GET['tab'])
			? sanitize_key(wp_unslash($_GET['tab']))
			: '';
		if (!isset($tabs[$tab])) {
			$tab = 'archiving';
		}
		$notice = isset($_GET['wirecore_oac_notice'])
			? sanitize_key(wp_unslash($_GET['wirecore_oac_notice']))
			: '';
		$archived = isset($_GET['archived']) ? absint($_GET['archived']) : 0;
		$skipped = isset($_GET['skipped']) ? absint($_GET['skipped']) : 0;
		// phpcs:enable
		$notices = [
			'saved' => [
				'success',
				__(
					'Settings saved.',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
			],
			'invalid_url' => [
				'warn',
				__(
					'Saved. The webhook URL was discarded – it has to be a public HTTPS address.',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
			],
			'run' => [
				'success',
				sprintf(
					/* translators: 1: number of archived orders, 2: number of skipped orders */
					__(
						'Batch complete. Archived: %1$d, skipped by rules: %2$d.',
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					$archived,
					$skipped
				),
			],
			'dry_run' => [
				'info',
				sprintf(
					/* translators: 1: number of orders, 2: number of skipped orders */
					__(
						'Dry run: %1$d orders would be archived, %2$d skipped by rules. No order was changed.',
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					$archived,
					$skipped
				),
			],
		];
		?>
		<div class="wrap wirecore-oac">
			<?php // WordPress moves the notices of other plugins here, outside the WireLayer area
		?>
			<hr class="wp-header-end" />
			<div id="wirecore-oac" data-mode="light">
				<div class="wc-page-header">
					<div class="wc-page-header-row">
						<div class="wc-page-header-heading">
							<h1 class="wc-page-header-title"><?php echo esc_html(
       	get_admin_page_title()
       ); ?></h1>
						</div>
					</div>
					<nav class="wc-tabs-list wc-page-header-tabs" aria-label="<?php echo esc_attr(
     	get_admin_page_title()
     ); ?>">
						<?php foreach ($tabs as $key => $label): ?>
							<a href="<?php echo esc_url(
       	self::page_url($key)
       ); ?>" class="wc-tabs-trigger"<?php echo $key === $tab
	? ' data-selected aria-current="page"'
	: ''; ?>><?php echo esc_html($label); ?></a>
						<?php endforeach; ?>
					</nav>
				</div>

				<?php if (isset($notices[$notice])) {
    	self::alert($notices[$notice][0], $notices[$notice][1]);
    } ?>

				<?php
    $method = 'tab_' . $tab;
    self::$method();?>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline Lucide icon (ISC license), hidden from assistive technology.
	 */
	private static function icon($name, $class)
	{
		$paths = [
			'check' => '<path d="M20 6 9 17l-5-5"/>',
			'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
			'info' =>
				'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
			'success' =>
				'<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>',
			'warn' =>
				'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'danger' =>
				'<path d="m15 9-6 6"/><path d="M2.586 16.726A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2h6.624a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586z"/><path d="m9 9 6 6"/>',
		];
		printf(
			'<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
			esc_attr($class),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup from the list above
			$paths[$name]
		);
	}

	/**
	 * Status message; $status is info, success, warn or danger.
	 */
	private static function alert($status, $text)
	{
		?>
		<div class="wc-alert wc-alert-<?php echo esc_attr(
  	$status
  ); ?>" role="<?php echo in_array($status, ['warn', 'danger'], true) ? 'alert' : 'status'; ?>">
			<span class="wc-alert-icon-box"><?php self::icon(
   	$status,
   	'wc-alert-icon'
   ); ?></span>
			<div class="wc-alert-content">
				<div class="wc-alert-description"><?php echo esc_html($text); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Title and optional description at the top of a card.
	 */
	private static function card_header($title, $subtitle = '')
	{
		?>
		<div class="wc-card-parts-header">
			<div class="wc-card-parts-heading">
				<h2 class="wc-card-parts-title"><?php echo esc_html($title); ?></h2>
				<?php if ($subtitle !== ''): ?>
					<p class="wc-card-parts-subtitle"><?php echo esc_html($subtitle); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Checkbox with a native input; $attributes are the input's attributes
	 * (name, value …), $title an optional bold line above the label.
	 */
	private static function checkbox(
		array $attributes,
		$checked,
		$label,
		$title = ''
	) {
		?>
		<label class="wc-checkbox">
			<input type="checkbox" class="wc-checkbox-input"<?php
   foreach ($attributes as $attribute => $value) {
   	printf(' %s="%s"', esc_attr($attribute), esc_attr($value));
   }
   checked($checked);
   ?> />
			<span class="wc-checkbox-control"><?php self::icon(
   	'check',
   	'wc-checkbox-icon'
   ); ?></span>
			<span class="wc-checkbox-label">
				<?php if ($title !== ''): ?>
					<span class="wirecore-oac-checkbox-title"><?php echo esc_html($title); ?></span>
				<?php endif; ?>
				<?php echo esc_html($label); ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Checkboxes for a list setting, $options value => label.
	 */
	private static function checkbox_group(
		$name,
		$legend,
		array $options,
		array $selected,
		$help = ''
	) {
		?>
		<fieldset class="wc-checkbox-group wc-checkbox-group-grid"<?php echo $help !==
  ''
  	? ' aria-describedby="wirecore-oac-' . esc_attr($name) . '-help"'
  	: ''; ?>>
			<legend class="wc-checkbox-group-label"><?php echo esc_html(
   	$legend
   ); ?></legend>
			<div class="wc-checkbox-group-items">
				<?php foreach ($options as $value => $label) {
    	self::checkbox(
    		['name' => $name . '[]', 'value' => $value],
    		in_array($value, $selected, false),
    		$label
    	);
    } ?>
			</div>
			<?php if ($help !== ''): ?>
				<span class="wc-checkbox-group-helper-text" id="wirecore-oac-<?php echo esc_attr(
    	$name
    ); ?>-help"><?php echo esc_html($help); ?></span>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Text, number, email, URL or password field; $attributes are additional
	 * attributes of the input (min, max, step, placeholder …).
	 */
	private static function input(
		$name,
		$type,
		$value,
		$label,
		$help = '',
		$suffix = '',
		array $attributes = []
	) {
		$id = 'wirecore-oac-' . str_replace('_', '-', $name); ?>
		<div class="wc-field<?php echo $type === 'number'
  	? ' wirecore-oac-narrow'
  	: ''; ?>">
			<label class="wc-field-label" for="<?php echo esc_attr(
   	$id
   ); ?>"><?php echo esc_html($label); ?></label>
			<div class="wc-input">
				<input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr(
	$id
); ?>" name="<?php echo esc_attr(
	$name
); ?>" class="wc-input-control" value="<?php echo esc_attr($value); ?>"<?php
foreach ($attributes as $attribute => $attribute_value) {
	printf(' %s="%s"', esc_attr($attribute), esc_attr($attribute_value));
}
if ($help !== '') {
	printf(' aria-describedby="%s-help"', esc_attr($id));
}
?> />
				<?php if ($suffix !== ''): ?>
					<span class="wc-input-suffix"><?php echo esc_html($suffix); ?></span>
				<?php endif; ?>
			</div>
			<?php if ($help !== ''): ?>
				<span class="wc-field-helper-text" id="<?php echo esc_attr(
    	$id
    ); ?>-help"><?php echo esc_html($help); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Start of a settings form; the submit button is in form_end().
	 */
	private static function form_start($tab)
	{
		?>
		<form method="post" action="<?php echo esc_url(
  	admin_url('admin-post.php')
  ); ?>" class="wirecore-oac-stack">
			<input type="hidden" name="action" value="wirecore_oac_settings" />
			<input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
			<?php wp_nonce_field('wirecore_oac_settings'); ?>
		<?php
	}

	private static function form_end()
	{
		?>
			<div class="wirecore-oac-row">
				<button type="submit" class="wc-button"><?php esc_html_e(
    	'Save settings',
    	'circle3-order-archive-cleaner-for-woocommerce'
    ); ?></button>
			</div>
		</form>
		<?php
	}

	private static function tab_archiving()
	{
		$settings = WireCore_OAC_Settings::get();
		$last_run = get_option(WireCore_OAC_Archiver::LAST_RUN);
		$next_run = function_exists('as_next_scheduled_action')
			? as_next_scheduled_action(
				WireCore_OAC_Scheduler::DAILY,
				null,
				WireCore_OAC_Scheduler::GROUP
			)
			: false;
		$format = get_option('date_format') . ' ' . get_option('time_format');
		$stats = [
			[
				__(
					'Archived orders',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				number_format_i18n(WireCore_OAC_Archiver::count()),
				'',
			],
			// Relative times as value, the date in the description: the
			// tabular figures of the value would space out its punctuation
			[
				__('Last run', 'circle3-order-archive-cleaner-for-woocommerce'),
				is_array($last_run)
					? sprintf(
						/* translators: %s: time span, e.g. "2 hours" */
						__(
							'%s ago',
							'circle3-order-archive-cleaner-for-woocommerce'
						),
						human_time_diff($last_run['time'])
					)
					: __(
						'Never',
						'circle3-order-archive-cleaner-for-woocommerce'
					),
				is_array($last_run)
					? wp_date($format, $last_run['time']) .
						' · ' .
						sprintf(
							/* translators: 1: number of archived orders, 2: number of skipped orders */
							__(
								'Archived: %1$d, skipped by rules: %2$d',
								'circle3-order-archive-cleaner-for-woocommerce'
							),
							$last_run['archived'],
							$last_run['skipped']
						) .
						($last_run['dry_run']
							? ' ' .
								__(
									'(dry run)',
									'circle3-order-archive-cleaner-for-woocommerce'
								)
							: '')
					: '',
			],
			[
				__(
					'Next automatic run',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				is_int($next_run)
					? sprintf(
						/* translators: %s: time span, e.g. "2 hours" */
						__(
							'in %s',
							'circle3-order-archive-cleaner-for-woocommerce'
						),
						human_time_diff(time(), $next_run)
					)
					: __(
						'Not scheduled',
						'circle3-order-archive-cleaner-for-woocommerce'
					),
				is_int($next_run) ? wp_date($format, $next_run) : '',
			],
		];
		?>
		<?php if ($settings['enabled'] && !$settings['statuses']) {
  	self::alert(
  		'warn',
  		__(
  			'No order status is selected – no orders will be archived.',
  			'circle3-order-archive-cleaner-for-woocommerce'
  		)
  	);
  } ?>

		<section class="wc-card">
			<?php self::card_header(
   	__('Overview', 'circle3-order-archive-cleaner-for-woocommerce')
   ); ?>
			<div class="wirecore-oac-stats">
				<?php foreach ($stats as list($label, $value, $description)): ?>
					<div class="wc-statistic wc-statistic-sm wc-statistic-bordered">
						<div class="wc-statistic-header">
							<span class="wc-statistic-label"><?php echo esc_html($label); ?></span>
						</div>
						<div class="wc-statistic-value"><?php echo esc_html($value); ?></div>
						<?php if ($description !== ''): ?>
							<div class="wc-statistic-description"><?php echo esc_html(
       	$description
       ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<form method="post" action="<?php echo esc_url(
   	admin_url('admin-post.php')
   ); ?>" class="wc-card-parts-footer">
				<input type="hidden" name="action" value="wirecore_oac_run" />
				<?php wp_nonce_field('wirecore_oac_run'); ?>
				<span class="wirecore-oac-hint"><?php esc_html_e(
    	'Processes one batch now with the saved settings. Respects the dry-run mode.',
    	'circle3-order-archive-cleaner-for-woocommerce'
    ); ?></span>
				<a class="wc-button wc-button-outline wc-button-sm" href="<?php echo esc_url(
    	self::orders_url()
    ); ?>"><?php esc_html_e(
	'View archived orders',
	'circle3-order-archive-cleaner-for-woocommerce'
); ?></a>
				<button type="submit" class="wc-button wc-button-sm"><?php esc_html_e(
    	'Run now',
    	'circle3-order-archive-cleaner-for-woocommerce'
    ); ?></button>
			</form>
		</section>

		<?php self::form_start('archiving'); ?>
			<section class="wc-card">
				<?php self::card_header(
    	__(
    		'Automatic archiving',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__(
    		'Archived orders get the status “Archived”: they disappear from “All” in the order list, but remain in WooCommerce and can be restored at any time.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::checkbox(
    	['name' => 'enabled', 'value' => '1'],
    	$settings['enabled'],
    	__(
    		'Archive old orders once a day in the background',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__(
    		'Automatic archiving',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::input(
    	'cutoff_months',
    	'number',
    	$settings['cutoff_months'],
    	__(
    		'Archive orders older than',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__(
    		'Based on the order date. 1 to 120 months.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__('months', 'circle3-order-archive-cleaner-for-woocommerce'),
    	['min' => 1, 'max' => 120, 'step' => 1, 'required' => 'required']
    ); ?>
				<?php self::checkbox_group(
    	'statuses',
    	__(
    		'Order statuses to archive',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	WireCore_OAC_Settings::archivable_statuses(),
    	$settings['statuses']
    ); ?>
				<?php self::input(
    	'batch_size',
    	'number',
    	$settings['batch_size'],
    	__('Batch size', 'circle3-order-archive-cleaner-for-woocommerce'),
    	sprintf(
    		/* translators: %d: maximum batch size */
    		__(
    			'Orders checked per batch, at most %d. The daily run continues batch by batch until all orders are checked.',
    			'circle3-order-archive-cleaner-for-woocommerce'
    		),
    		WireCore_OAC_Settings::MAX_BATCH_SIZE
    	),
    	'',
    	[
    		'min' => 1,
    		'max' => WireCore_OAC_Settings::MAX_BATCH_SIZE,
    		'step' => 1,
    		'required' => 'required',
    	]
    ); ?>
			</section>

			<section class="wc-card">
				<?php self::card_header(
    	__('Options', 'circle3-order-archive-cleaner-for-woocommerce')
    ); ?>
				<div class="wirecore-oac-stack">
					<?php self::checkbox(
     	['name' => 'dry_run', 'value' => '1'],
     	$settings['dry_run'],
     	__(
     		'Only count which orders would be archived, without changing them',
     		'circle3-order-archive-cleaner-for-woocommerce'
     	),
     	__('Dry run', 'circle3-order-archive-cleaner-for-woocommerce')
     ); ?>
					<?php self::checkbox(
     	['name' => 'notify', 'value' => '1'],
     	$settings['notify'],
     	__(
     		'Email a summary to the site admin after each automatic run that archived orders',
     		'circle3-order-archive-cleaner-for-woocommerce'
     	),
     	__('Summary email', 'circle3-order-archive-cleaner-for-woocommerce')
     ); ?>
				</div>
			</section>
		<?php self::form_end();
	}

	private static function tab_rules()
	{
		$settings = WireCore_OAC_Settings::get();
		$roles = array_map('translate_user_role', wp_roles()->get_names());
		$categories = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'fields' => 'id=>name',
		]);
		self::form_start('rules');
		?>
			<section class="wc-card">
				<?php self::card_header(
    	__('Exclusions', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__(
    		'Orders matching one of these rules are never archived automatically. Manual archiving in the order list is not affected.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::checkbox(
    	['name' => 'exclude_refunded', 'value' => '1'],
    	$settings['exclude_refunded'],
    	__(
    		'Skip orders with at least one refund',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__('Refunds', 'circle3-order-archive-cleaner-for-woocommerce')
    ); ?>
				<?php self::input(
    	'exclude_above_total',
    	'number',
    	$settings['exclude_above_total'] ?: '',
    	__(
    		'Skip orders with a total above',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__(
    		'Empty or 0 = no limit.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	get_woocommerce_currency_symbol(),
    	['min' => 0, 'step' => '0.01']
    ); ?>
				<?php self::checkbox_group(
    	'exclude_roles',
    	__(
    		'Skip orders of customers with these roles',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	$roles,
    	$settings['exclude_roles']
    ); ?>
				<?php if (is_array($categories) && $categories) {
    	self::checkbox_group(
    		'exclude_categories',
    		__(
    			'Skip orders containing products from these categories',
    			'circle3-order-archive-cleaner-for-woocommerce'
    		),
    		$categories,
    		$settings['exclude_categories']
    	);
    } ?>
			</section>
		<?php self::form_end();
	}

	private static function tab_export()
	{
		$settings = WireCore_OAC_Settings::get();
		self::form_start('export');
		?>
			<section class="wc-card">
				<?php self::card_header(
    	__('Scheduled export', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__(
    		'Sends all archived orders as CSV file by email.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::checkbox(
    	['name' => 'export_enabled', 'value' => '1'],
    	$settings['export_enabled'],
    	__(
    		'Send the export on a schedule',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__('Scheduled export', 'circle3-order-archive-cleaner-for-woocommerce')
    ); ?>
				<div class="wc-field wirecore-oac-narrow">
					<label class="wc-field-label" for="wirecore-oac-export-frequency"><?php esc_html_e(
     	'Frequency',
     	'circle3-order-archive-cleaner-for-woocommerce'
     ); ?></label>
					<span class="wc-native-select">
						<select class="wc-native-select-select" id="wirecore-oac-export-frequency" name="export_frequency">
							<option value="weekly"<?php selected(
       	$settings['export_frequency'],
       	'weekly'
       ); ?>><?php esc_html_e(
	'Weekly',
	'circle3-order-archive-cleaner-for-woocommerce'
); ?></option>
							<option value="monthly"<?php selected(
       	$settings['export_frequency'],
       	'monthly'
       ); ?>><?php esc_html_e(
	'Monthly',
	'circle3-order-archive-cleaner-for-woocommerce'
); ?></option>
						</select>
						<?php self::icon('chevron-down', 'wc-native-select-icon'); ?>
					</span>
				</div>
				<?php self::input(
    	'export_email',
    	'email',
    	$settings['export_email'],
    	__('Email address', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__(
    		'Empty = email address of the site admin.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	'',
    	['placeholder' => get_option('admin_email')]
    ); ?>
			</section>

			<section class="wc-card">
				<?php self::card_header(
    	__('Retention', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__(
    		'Permanently deletes archived orders whose order date is older than the retention period – once a month, up to 200 orders per run. Deleted orders cannot be restored. Check your statutory retention periods first.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::checkbox(
    	['name' => 'retention_enabled', 'value' => '1'],
    	$settings['retention_enabled'],
    	__(
    		'Permanently delete archived orders after the retention period',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__(
    		'Delete automatically',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::input(
    	'retention_years',
    	'number',
    	$settings['retention_years'],
    	__('Retention period', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__('1 to 20 years.', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__('years', 'circle3-order-archive-cleaner-for-woocommerce'),
    	['min' => 1, 'max' => 20, 'step' => 1, 'required' => 'required']
    ); ?>
				<?php self::checkbox(
    	['name' => 'retention_export', 'value' => '1'],
    	$settings['retention_export'],
    	__(
    		'Email the orders as CSV to the site admin before deleting them – if the email cannot be sent, nothing is deleted',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__(
    		'Backup before deletion',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
			</section>
		<?php self::form_end();
	}

	private static function tab_integrations()
	{
		$settings = WireCore_OAC_Settings::get();
		self::form_start('integrations');
		?>
			<section class="wc-card">
				<?php self::card_header(
    	__('Webhook', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__(
    		'Sends a JSON POST request whenever an order is archived, e.g. to Zapier, Make or your own systems.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?>
				<?php self::checkbox(
    	['name' => 'webhook_enabled', 'value' => '1'],
    	$settings['webhook_enabled'],
    	__(
    		'Send a request for every archived order',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	__('Webhook', 'circle3-order-archive-cleaner-for-woocommerce')
    ); ?>
				<?php self::input(
    	'webhook_url',
    	'url',
    	$settings['webhook_url'],
    	__('URL', 'circle3-order-archive-cleaner-for-woocommerce'),
    	__(
    		'Public HTTPS address only.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	),
    	'',
    	['placeholder' => 'https://']
    ); ?>
				<?php self::input(
    	'webhook_secret',
    	'password',
    	$settings['webhook_secret'],
    	__('Secret', 'circle3-order-archive-cleaner-for-woocommerce'),
    	sprintf(
    		/* translators: %s: name of the HTTP header */
    		__(
    			'Optional. Signs every request with HMAC-SHA256 in the header %s, so the receiver can verify it.',
    			'circle3-order-archive-cleaner-for-woocommerce'
    		),
    		WireCore_OAC_Webhook::SIGNATURE_HEADER
    	),
    	'',
    	['autocomplete' => 'new-password']
    ); ?>
			</section>
		<?php self::form_end(); ?>

		<section class="wc-card">
			<?php self::card_header(
   	__('For developers', 'circle3-order-archive-cleaner-for-woocommerce')
   ); ?>
			<ul class="wirecore-oac-list">
				<li><?php self::code_text(
    	__(
    		'<code>wirecore_oac_should_skip_order</code> – filters whether an order is never archived automatically (parameters: excluded by the rules, order).',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?></li>
				<li><?php self::code_text(
    	__(
    		'<code>wirecore_oac_after_archive_batch</code> – runs after every batch with the counts archived and skipped.',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?></li>
				<li><?php self::code_text(
    	__(
    		'WP-CLI: <code>wp wirecore-archive</code> (run, restore, status, export).',
    		'circle3-order-archive-cleaner-for-woocommerce'
    	)
    ); ?></li>
			</ul>
		</section>
		<?php
	}

	/**
	 * Text with <code> from a translation, the code styled by WireLayer.
	 */
	private static function code_text($text)
	{
		echo wp_kses(
			str_replace('<code>', '<code class="wc-inline-code">', $text),
			['code' => ['class' => []]]
		);
	}
}
