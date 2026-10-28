<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}

// Archived orders keep their status and meta fields: deleting them is up to
// the shop, restoring them is possible in the order list before uninstalling.
function wirecore_oac_uninstall_site()
{
	delete_option('wirecore_oac_settings');
	delete_option('wirecore_oac_last_run');
	if (function_exists('as_unschedule_all_actions')) {
		as_unschedule_all_actions('', [], 'wirecore-oac');
	}
}

function wirecore_oac_uninstall()
{
	// Settings are stored per site on multisite
	if (!is_multisite()) {
		wirecore_oac_uninstall_site();
		return;
	}
	foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
		switch_to_blog($site_id);
		wirecore_oac_uninstall_site();
		restore_current_blog();
	}
}

wirecore_oac_uninstall();
