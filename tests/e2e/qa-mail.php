<?php
/**
 * QA only: pretend mail is sent (wp-env has no mail server) and log it.
 */
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	error_log( '[qa-mail] to=' . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . ' subject=' . $atts['subject'], 3, WP_CONTENT_DIR . '/qa-mail.log' );
	error_log( "\n", 3, WP_CONTENT_DIR . '/qa-mail.log' );
	return true;
}, 10, 2 );
