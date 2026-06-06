<?php
/**
 * Uninstall cleanup.
 *
 * @package TurnstileForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'turnstile_forms_settings' );

// Multisite: clean each site's option.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'turnstile_forms_settings' );
		restore_current_blog();
	}
}
