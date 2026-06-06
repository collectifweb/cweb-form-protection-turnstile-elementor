<?php
/**
 * Uninstall cleanup.
 *
 * @package CWebTS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cwebts_settings' );

// Multisite: clean each site's option.
if ( is_multisite() ) {
	$cwebts_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $cwebts_site_ids as $cwebts_site_id ) {
		switch_to_blog( $cwebts_site_id );
		delete_option( 'cwebts_settings' );
		restore_current_blog();
	}
}
