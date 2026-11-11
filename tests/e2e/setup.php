<?php
// QA setup — run with: wp eval-file /tmp/setup.php
update_option( 'users_can_register', 1 );
update_option( 'default_role', 'subscriber' );
update_option( 'comment_moderation', 0 );
update_option( 'comment_previously_approved', 0 );
update_option( 'permalink_structure', '/%postname%/' );

// Plugin settings: Cloudflare "always passes" test keys, everything on except all-forms mode.
update_option( 'cwebts_settings', array(
	'site_key' => '1x00000000000000000000AA', 'secret_key' => '1x0000000000000000000000000000000AA',
	'theme' => 'auto', 'size' => 'flexible', 'appearance' => 'always', 'language' => 'auto', 'error_message' => '',
	'protect_elementor_all_forms' => 0, 'protect_login' => 1, 'protect_register' => 1, 'protect_lostpassword' => 1,
	'protect_comments' => 1, 'protect_wc_checkout' => 1, 'protect_wc_login' => 1, 'protect_wc_register' => 1,
	'protect_wc_account' => 1, 'failure_mode' => 'block',
) );

// Elementor page: form A with the Turnstile field, form B without.
$field = function ( $id, $type, $label, $req = '' ) { return array( '_id' => $id, 'custom_id' => $id, 'field_type' => $type, 'field_label' => $label, 'required' => $req, 'width' => '100' ); };
$form = function ( $wid, $name, $fields ) { return array( 'id' => $wid, 'elType' => 'widget', 'widgetType' => 'form', 'settings' => array( 'form_name' => $name, 'form_id' => $name, 'form_fields' => $fields, 'submit_actions' => array( 'save-to-database' ), 'button_text' => 'Send ' . $name, 'success_message' => 'QA-OK-' . $name ), 'elements' => array() ); };
$data = array( array( 'id' => 'qacont1', 'elType' => 'container', 'settings' => array(), 'isInner' => false, 'elements' => array(
	$form( 'qaformA', 'formA', array( $field( 'name', 'text', 'Name', 'true' ), $field( 'ts', 'turnstile', 'Captcha' ) ) ),
	$form( 'qaformB', 'formB', array( $field( 'name', 'text', 'Name', 'true' ) ) ),
) ) );
$page = get_page_by_path( 'qa-elementor' );
$pid  = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA Elementor', 'post_name' => 'qa-elementor' ) );
update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
update_post_meta( $pid, '_elementor_template_type', 'wp-page' );
update_post_meta( $pid, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $pid, '_wp_page_template', 'elementor_canvas' );
update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
delete_post_meta( $pid, '_elementor_element_cache' );

// Reused Elementor template: template T holds a protected form. Page A shows another
// protected form, then T through [elementor-template]; page C shows T alone. A first
// visit to A caches T while the Turnstile scripts are already queued (see qa.cjs).
$save = function ( $post_type, $slug, $title, $type, $data ) {
	$p  = get_page_by_path( $slug, OBJECT, $post_type );
	$id = $p ? $p->ID : wp_insert_post( array( 'post_type' => $post_type, 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug ) );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', $type );
	update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
	if ( 'page' === $post_type ) { update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); }
	if ( 'elementor_library' === $post_type ) { wp_set_object_terms( $id, $type, 'elementor_library_type' ); }
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	return $id;
};
$protected = array( $field( 'name', 'text', 'Name', 'true' ), $field( 'ts', 'turnstile', 'Captcha' ) );
$box       = function ( $id, $els ) { return array( array( 'id' => $id, 'elType' => 'container', 'settings' => array(), 'isInner' => false, 'elements' => $els ) ); };
$tpl       = $save( 'elementor_library', 'qa-template', 'QA template', 'section', $box( 'qatplc', array( $form( 'qaformT', 'formT', $protected ) ) ) );
$embed     = function ( $id ) use ( $tpl ) { return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[elementor-template id="' . $tpl . '"]' ), 'elements' => array() ); };
$save( 'page', 'qa-template-a', 'QA template A', 'wp-page', $box( 'qaac', array( $form( 'qaformA2', 'formA2', $protected ), $embed( 'qaas' ) ) ) );
$save( 'page', 'qa-template-c', 'QA template C', 'wp-page', $box( 'qacc', array( $embed( 'qacs' ) ) ) );
delete_post_meta_by_key( '_elementor_element_cache' );

// WooCommerce: classic (shortcode) checkout, open store, registration on My account, COD, one virtual product.
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
update_option( 'woocommerce_registration_generate_password', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Cash on delivery', 'description' => 'Pay on delivery', 'instructions' => '', 'enable_for_methods' => array(), 'enable_for_virtual' => 'yes' ) );
WC_Install::create_pages();
foreach ( array( 'checkout' => '[woocommerce_checkout]', 'cart' => '[woocommerce_cart]', 'myaccount' => '[woocommerce_my_account]' ) as $k => $sc ) {
	wp_update_post( array( 'ID' => wc_get_page_id( $k ), 'post_content' => '<!-- wp:shortcode -->' . $sc . '<!-- /wp:shortcode -->' ) );
}
if ( ! get_page_by_path( 'qa-product', OBJECT, 'product' ) ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'QA product' ); $p->set_slug( 'qa-product' ); $p->set_regular_price( '10' ); $p->set_virtual( true ); $p->set_status( 'publish' );
	$p->save();
}
$prod = get_page_by_path( 'qa-product', OBJECT, 'product' );
if ( ! get_user_by( 'login', 'qacustomer' ) ) { wp_create_user( 'qacustomer', 'QaCustomer!2026', 'qacustomer@example.test' ); }
flush_rewrite_rules();
echo wp_json_encode( array( 'elementor_page' => $pid, 'template' => $tpl, 'product' => $prod->ID, 'checkout' => wc_get_page_id( 'checkout' ), 'myaccount' => wc_get_page_id( 'myaccount' ), 'hello_post' => 1 ) ), "\n";
