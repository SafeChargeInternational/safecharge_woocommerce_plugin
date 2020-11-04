<?php
/*
 * Plugin Name: SafeCharge Payments
 * Plugin URI: https://github.com/SafeChargeInternational/safecharge_woocommerce_plugin
 * Description: SafeCharge gateway for WooCommerce
 * Version: 3.5
 * Author: SafeCharge
 * Author URI: https://safecharge.com
 * Require at least: 4.7
 * Tested up to: 5.5.1
 * WC requires at least: 3.0
 * WC tested up to: 4.5.2
*/

defined('ABSPATH') || die('die');

if (!session_id()) {
	session_start();
}

require_once 'sc_config.php';
require_once 'SC_Versions_Resolver.php';
require_once 'SC_CLASS.php';

$wc_sc = null;

add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway');
add_action('plugins_loaded', 'woocommerce_sc_init', 0);

function woocommerce_sc_init() {
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}
	
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once 'WC_SC.php';
	
	global $wc_sc;
	$wc_sc = new WC_SC();
	
	add_action('init', 'sc_enqueue');
	// load WC styles
	add_filter('woocommerce_enqueue_styles', 'sc_enqueue_wo_files');
	// add admin style
	add_action( 'admin_enqueue_scripts', function($hook) {
		if( 'post.php' != $hook ) {
			return;
		}
		
		wp_register_style('sc_admin_style', plugins_url('/css/sc_admin_style.css', __FILE__), '', 1, 'all');
		wp_enqueue_style('sc_admin_style');
	});
	// add void and/or settle buttons to completed orders, we check in the method is this order made via SC paygate
	add_action('woocommerce_order_item_add_action_buttons', 'sc_add_buttons');
	
	// handle custom Ajax calls
	add_action('wp_ajax_sc-ajax-action', 'sc_ajax_action');
	add_action('wp_ajax_nopriv_sc-ajax-action', 'sc_ajax_action');
	
	// insert Custom Merchat style
	add_action( 'woocommerce_checkout_after_order_review',  array($wc_sc, 'sc_insert_merchant_style'), 10, 1 );
	
	// if validation success get order details
	add_action('woocommerce_after_checkout_validation', function($data, $errors) {
//		SC_CLASS::create_log($data, 'woocommerce_after_checkout_validation');
		SC_CLASS::create_log($errors->errors, 'woocommerce_after_checkout_validation errors');
		
		if( empty( $errors->errors ) && 'sc' == $data['payment_method'] ) {
			if (!isset($_POST['sc_payment_method']) || empty($_POST['sc_payment_method'])) {
				global $wc_sc;
				$content = $wc_sc->sc_get_payment_methods('');
			} 
		}
	}, 9999, 2);
	
	// use this to change button text, because of the cache the jQuery not always works
	add_filter('woocommerce_order_button_text', 'sc_edit_order_buttons');
	
	// those actions are valid only when the plugin is enabled
	if ('yes' == $wc_sc->settings['enabled']) {
		// for WPML plugin
		if (
			is_plugin_active('sitepress-multilingual-cms' . DIRECTORY_SEPARATOR . 'sitepress.php')
			&& 'yes' == $wc_sc->settings['use_wpml_thanks_page']
		) {
			add_filter('woocommerce_get_checkout_order_received_url', 'sc_wpml_thank_you_page', 10, 2);
		}

		// if the merchant needs to rewrite the DMN URL
		if (isset($wc_sc->settings['rewrite_dmn']) && 'yes' == $wc_sc->settings['rewrite_dmn']) {
			add_action('template_redirect', 'sc_rewrite_return_url'); // need WC_SC
		}
		
		// show admin product data Subscription tab
		add_filter( 'woocommerce_product_data_tabs', 'sc_filter_woocommerce_product_data_tabs', 10, 1 );
		// add admin product data Subscription tab content
		add_action( 'woocommerce_product_data_panels', 'sc_add_product_subscr_data_fields' );
		// save product Subscription data as meta data
		add_action( 'woocommerce_process_product_meta', 'sc_save_product_custom_fields', 10, 3 );
	}
	
	// change Thank-you page title and text
	if ('error' === strtolower($wc_sc->get_request_status())) {
		add_filter('the_title', function ( $title, $id) {
			if (
				function_exists('is_order_received_page')
				&& is_order_received_page()
				&& get_the_ID() === $id
			) {
				$title = esc_html__('Order error', 'sc');
			}

			return $title;
		}, 10, 2);
		
		add_filter(
			'woocommerce_thankyou_order_received_text',
		
			function ( $str, $order) {
				return esc_html__('There is an error with your order. Please, check if the order was recieved or what is the status!', 'sc');
			}, 10, 2);
	}
	elseif ('canceled' === strtolower($wc_sc->get_request_status())) {
		add_filter('the_title', function ( $title, $id) {
			if (
				function_exists('is_order_received_page')
				&& is_order_received_page()
				&& get_the_ID() === $id
			) {
				$title = esc_html__('Order canceled', 'sc');
			}

			return $title;
		}, 10, 2);
		
		add_filter('woocommerce_thankyou_order_received_text', function ( $str, $order) {
			return esc_html__('Please, check the order for details!', 'sc');
		}, 10, 2);
	}
	
	add_filter('woocommerce_pay_order_after_submit', 'nuvei_user_orders', 10, 2);
	
	if (!empty($_GET['sc_msg'])) {
		add_filter('woocommerce_before_cart', 'nuvei_show_message_on_cart', 10, 2);
	}
}

/**
 * Function sc_ajax_action
 * Main function for the Ajax requests.
 */
function sc_ajax_action() {
	if (!check_ajax_referer('sc-security-nonce', 'security')) {
		wp_send_json_error(__('Invalid security token sent.'));
		wp_die('Invalid security token sent');
	}
	
	global $wc_sc;
	
	if (empty($wc_sc->test)) {
		wp_send_json_error(__('Invalid site mode.'));
		wp_die('Invalid site mode.');
	}
	
	$payment_method_sc = '';
	if (!empty($wc_sc->get_param('payment_method_sc'))) {
		$payment_method_sc = sanitize_text_field($_POST['payment_method_sc']);
	}

	// recognize the action:
	// Void (Cancel)
	if ($wc_sc->get_param('cancelOrder', 'int') == 1 && $wc_sc->get_param('orderId', 'int') > 0) {
		$wc_sc->create_settle_void(sanitize_text_field($_POST['orderId']), 'void');
	}

	// Settle
	if ($wc_sc->get_param('settleOrder', 'int') == 1 && $wc_sc->get_param('orderId', 'int') > 0) {
		$wc_sc->create_settle_void(sanitize_text_field($_POST['orderId']), 'settle');
	}
	
	// Refund
	if ( isset($_POST['refAmount']) ) {
		$wc_sc->create_refund_request($wc_sc->get_param('postId', 'int'), $wc_sc->get_param('refAmount', 'float'));
	}
	
	// when we use the REST - Open order and get APMs
	if ($wc_sc->get_param('sc_request') == 'OpenOrder') {
		$wc_sc->sc_create_open_order(true);
	}
	
	// delete UPO
	if ($wc_sc->get_param('scUpoId', 'int') > 0) {
		$wc_sc->delete_user_upo();
	}
	
	// when Reorder
	if ($wc_sc->get_param('sc_request') == 'scReorder') {
		$wc_sc->sc_reorder();
	}
	
	// download Subscriptions Plans
	if ($wc_sc->get_param('downloadPlans', 'int') == 1) {
		$wc_sc->sc_download_subscr_pans();
	}

	wp_send_json_error(__('Not recognized Ajax call.'));
	wp_die();
}

/**
* Add the Gateway to WooCommerce
**/
function woocommerce_add_sc_gateway( $methods) {
	$methods[] = 'WC_SC';
	return $methods;
}

function sc_enqueue_wo_files( $styles) {
	global $wc_sc;
	global $wpdb;
	
	$plugin_dir = basename(dirname(__FILE__));
	$plugin_url = WP_PLUGIN_URL;
	
	if (
		( isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS'] )
		&& ( isset($_SERVER['REQUEST_SCHEME']) && 'https' == $_SERVER['REQUEST_SCHEME'] )
	) {
		if (strpos($plugin_url, 'https') === false) {
			$plugin_url = str_replace('http:', 'https:', $plugin_url);
		}
	}
		
	// novo style
	wp_register_style(
		'sc_style',
		$plugin_url . '/' . $plugin_dir . '/css/sc_style.css',
		'',
		'2.2',
		'all'
	);
	wp_enqueue_style('sc_style');
	
	// WebSDK URL for integration and production
	wp_register_script(
		'sc_websdk',
		'https://cdn.safecharge.com/safecharge_resources/v1/websdk/safecharge.js',
		array('jquery'),
		'1'
	);
	wp_enqueue_script('sc_websdk');

	// main JS
	wp_register_script(
		'sc_js_public',
		$plugin_url . '/' . $plugin_dir . '/js/sc_public.js',
		array('jquery'),
		'1'
	);
	
	// reorder js
	wp_register_script(
		'sc_js_reorder',
		$plugin_url . '/' . $plugin_dir . '/js/sc_reorder.js',
		array('jquery'),
		'1'
	);
	wp_enqueue_script('sc_js_reorder');
	
	// get selected WC price separators
	$wcThSep  = '';
	$wcDecSep = '';
	
	$res = $wpdb->get_results(
		'SELECT option_name, option_value '
			. "FROM {$wpdb->prefix}options "
			. "WHERE option_name LIKE 'woocommerce%_sep' ;",
		ARRAY_N
	);
			
	if (!empty($res)) {
		foreach ($res as $row) {
			if (false != strpos($row[0], 'thousand_sep') && !empty($row[1])) {
				$wcThSep = $row[1];
			}

			if (false != strpos($row[0], 'decimal_sep') && !empty($row[1])) {
				$wcDecSep = $row[1];
			}
		}
	}
	
	// put translations here into the array
	wp_localize_script(
		'sc_js_public',
		'scTrans',
		array(
			'ajaxurl'            => admin_url('admin-ajax.php'),
			'security'            => wp_create_nonce('sc-security-nonce'),
			'webMasterId'        => 'WooCommerce ' . WOOCOMMERCE_VERSION,
			'sourceApplication'    => SC_SOURCE_APPLICATION,
			'plugin_dir_url'    => plugin_dir_url(__FILE__),
			'wcThSep'            => $wcThSep,
			'wcDecSep'            => $wcDecSep,
			
			// translations
			'paymentDeclined'    => __('Your Payment was DECLINED. Please try another payment method!', 'nuvei'),
			'paymentError'        => __('Error with your Payment. Please try again later!', 'nuvei'),
			'unexpectedError'    => __('Unexpected error, please try again later!', 'nuvei'),
			'choosePM'            => __('Please, choose payment method, and fill all fields!', 'nuvei'),
			'fillFields'        => __('Please fill all fields marked with * !', 'nuvei'),
			'errorWithPMs'        => __('Error when try to get the Payment Methods. Please try again later or use different Payment Option!', 'nuvei'),
			'errorWithSToken'    => __('Error when try to get the Session Token. Please try again later', 'nuvei'),
			'missData'            => __('Mandatory data is missing, please try again later!', 'nuvei'),
			'proccessError'        => __('Error in the proccess. Please, try again later!', 'nuvei'),
	//          'chooseUPO'         => __('Choose from you preferred payment methods', 'nuvei'),
	//          'chooseAPM'         => __('Choose from the payment options', 'nuvei'),
			'goBack'            => __('Go back', 'nuvei'),
			'CCNameIsEmpty'        => __('Card Holder Name is empty.', 'nuvei'),
			'CCNumError'        => __('Card Number is empty or wrong.', 'nuvei'),
			'CCExpDateError'    => __('Card Expiry Date is not correct.', 'nuvei'),
			'CCCvcError'        => __('Card CVC is not correct.', 'nuvei'),
			'AskDeleteUpo'        => __('Do you want to delete this UPO?', 'nuvei'),
		)
	);

	// connect the translations with some of the JS files
	wp_enqueue_script('sc_js_public');
	
	return $styles;
}

// first method we come in
function sc_enqueue( $hook) {
	global $wc_sc;
		
	# DMNs catch
	if (isset($_REQUEST['wc-api']) && 'sc_listener' == $_REQUEST['wc-api']) {
		$wc_sc->process_dmns();
	}
	
	// second checkout step process order
	if (
		isset($_REQUEST['wc-api'])
		&& 'process-order' == $_REQUEST['wc-api']
		&& !empty($_REQUEST['order_id'])
	) {
		$wc_sc->process_payment($wc_sc->get_param('order_id', 'int', 0));
	}
	
	# load external files
	$plugin_dir = basename(dirname(__FILE__));
	$plugin_url = WP_PLUGIN_URL;
	
	if (
		( isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS'] )
		&& ( isset($_SERVER['REQUEST_SCHEME']) && 'https' == $_SERVER['REQUEST_SCHEME'] )
	) {
		if (strpos($plugin_url, 'https') === false) {
			$plugin_url = str_replace('http:', 'https:', $plugin_url);
		}
	}
	
	// load admin JS file
	if (is_admin()) {
		// main JS
		wp_register_script(
			'sc_js_admin',
			$plugin_url . '/' . $plugin_dir . '/js/sc_admin.js',
			array('jquery'),
			'1'
		);
		
		$sc_plans_last_mod_time = '';
		if(is_readable(plugin_dir_path(__FILE__) . '/tmp/sc_plans.json')) {
			$sc_plans_last_mod_time = date('Y-m-d H:i:s', filemtime(plugin_dir_path(__FILE__) . '/tmp/sc_plans.json'));
		}
		
		// put translations here into the array
		wp_localize_script(
			'sc_js_admin',
			'scTrans',
			array(
				'ajaxurl'				=> admin_url('admin-ajax.php'),
				'security'				=> wp_create_nonce('sc-security-nonce'),
				'scPlansLastModTime'	=> $sc_plans_last_mod_time,
				// translations
				'refundQuestion'		=> __('Are you sure about this Refund?', 'nuvei'),
				'LastDownload'			=> __('Last Download', 'nuvei'),
			)
		);
		
		wp_enqueue_script('sc_js_admin');
	}
	# load external files END
}

// show final payment text
function sc_show_final_text() {
	global $woocommerce;
	global $wc_sc;
	
	$msg = __('Your payment is being processed. Your order status will be updated soon.', 'sc');
   
	// REST API tahnk you page handler
	if (
		!empty($_REQUEST['Status'])
		&& 'error' == sanitize_text_field($_REQUEST['Status'])) {
		$msg = __('Your payment failed. Please, try again.', 'sc');
	} else {
		$woocommerce->cart->empty_cart();
	}
	
	// clear session variables for the order
	if (isset($_SESSION['SC_Variables'])) {
		unset($_SESSION['SC_Variables']);
	}
	
	return $msg;
}

function sc_add_buttons() {
	$order_id = false;
	
	if (!empty($_GET['post'])) {
		$order_id = sanitize_text_field($_GET['post']);
	}
	
	try {
		$order                = wc_get_order($order_id);
		$order_status         = strtolower($order->get_status());
		$order_payment_method = $order->get_meta('_paymentMethod');
	} catch (Exception $ex) {
		echo '<script type="text/javascript">console.error("'
			. esc_js($ex->getMessage()) . '")</script>';
		exit;
	}
	
	// hide Refund Button
	if (
		!in_array($order_payment_method, array('cc_card', 'dc_card', 'apmgw_expresscheckout'))
		|| 'processing' == $order_status
	) {
		echo '<script type="text/javascript">jQuery(\'.refund-items\').prop("disabled", true);</script>';
	}
	
	// to show SC buttons we must be sure the order is paid via SC Paygate
	if (!$order->get_meta(SC_AUTH_CODE_KEY) || !$order->get_meta(SC_TRANS_ID)) {
		return;
	}
	
	if ('completed' == $order_status || 'pending' == $order_status) {
		global $wc_sc;

		$time				= gmdate('YmdHis', time());
		$order_tr_id		= $order->get_meta(SC_TRANS_ID);
		// we do not set this meta anymore, keep it only because of the orders made before v3.5 of the plugin
		$order_has_refund	= $order->get_meta(SC_ORDER_HAS_REFUND);
		$refunds			= json_decode($order->get_meta('_sc_refunds'), true);
		$notify_url			= $wc_sc->set_notify_url();
		
		// Show VOID button
//		if ('1' != $order_has_refund && in_array($order_payment_method, array('cc_card', 'dc_card'))) {
		if (
			'cc_card' == $order_payment_method
			/**
			 * before v3.5 we put a flag on refund - $order_has_refund
			 * since v3.5 we save some of the refund parameters as json in "_sc_refunds" meta data
			 * and do not save $order_has_refund flag anymore
			 */
			&& ( '1' != $order_has_refund || (!empty($refunds) && is_array($refunds)) )
		) {
			echo
				'<button id="sc_void_btn" type="button" onclick="settleAndCancelOrder(\''
				. esc_html__('Are you sure, you want to Cancel Order #' . $order_id . '?', 'sc') . '\', '
				. '\'void\', ' . esc_html($order_id) . ')" class="button generate-items">'
				. esc_html__('Void', 'sc') . '</button>';
		}
		
		// show SETTLE button ONLY if P3D resonse transaction_type IS Auth
		if ('pending' == $order_status && 'Auth' == $order->get_meta(SC_RESP_TRANS_TYPE)) {
			echo
				'<button id="sc_settle_btn" type="button" onclick="settleAndCancelOrder(\''
				. esc_html__('Are you sure, you want to Settle Order #' . $order_id . '?', 'sc') . '\', '
				. '\'settle\', \'' . esc_html($order_id) . '\')" class="button generate-items">'
				. esc_html__('Settle', 'sc') . '</button>';
		}
		
		// add loading screen
		echo '<div id="custom_loader" class="blockUI blockOverlay" style="height: 100%; position: absolute; top: 0px; width: 100%; z-index: 10; background-color: rgba(255,255,255,0.5); display: none;"></div>';
	}
}

/**
 * Function sc_rewrite_return_url
 * When user have problem with white spaces in the URL, it have option to
 * rewrite the return URL and redirect to new one.
 *
 * @global WC_SC $wc_sc
 */
function sc_rewrite_return_url() {
	if (
		isset($_REQUEST['ppp_status']) && '' != $_REQUEST['ppp_status']
		&& ( !isset($_REQUEST['wc_sc_redirected']) || 0 ==  $_REQUEST['wc_sc_redirected'] )
	) {
		$query_string = '';
		if (isset($_SERVER['QUERY_STRING'])) {
			$query_string = sanitize_text_field($_SERVER['QUERY_STRING']);
		}
		
		$server_protocol = '';
		if (isset($_SERVER['SERVER_PROTOCOL'])) {
			$server_protocol = sanitize_text_field($_SERVER['SERVER_PROTOCOL']);
		}
		
		$http_host = '';
		if (isset($_SERVER['HTTP_HOST'])) {
			$http_host = sanitize_text_field($_SERVER['HTTP_HOST']);
		}
		
		$request_uri = '';
		if (isset($_SERVER['REQUEST_URI'])) {
			$request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
		}
		
		$new_url = '';
		$host    = ( strpos($server_protocol, 'HTTP/') !== false ? 'http' : 'https' )
			. '://' . $http_host . current(explode('?', $request_uri));
		
		if ('' != $query_string) {
			$new_url = preg_replace('/\+|\s|\%20/', '_', $query_string);
			// put flag the URL was rewrited
			$new_url .= '&wc_sc_redirected=1';
			
			wp_redirect($host . '?' . $new_url);
			exit;
		}
	}
}

/**
 * Function sc_wpml_thank_you_page
 * Fix for WPML plugin "Thank you" page
 *
 * @param string $order_received_url
 * @param WC_Order $order
 * @return string $order_received_url
 */
function sc_wpml_thank_you_page( $order_received_url, $order) {
	$lang_code          = get_post_meta($order->id, 'wpml_language', true);
	$order_received_url = apply_filters('wpml_permalink', $order_received_url, $lang_code);
	
	SC_CLASS::create_log($order_received_url, 'sc_wpml_thank_you_page: ');
 
	return $order_received_url;
}

function sc_edit_order_buttons() {
	$default_text          = __('Place order', 'woocommerce');
	$sc_continue_text      = __('Continue', 'woocommerce');
	$chosen_payment_method = WC()->session->get('chosen_payment_method');
	
	// save default text into button attribute ?><script>
		(function($){
			$('#place_order')
				.attr('data-default-text', '<?php echo esc_attr($default_text); ?>')
				.attr('data-sc-text', '<?php echo esc_attr($sc_continue_text); ?>');
		})(jQuery);
	</script>
	<?php

	if ('sc' == $chosen_payment_method) {
		return $sc_continue_text;
	}

	return $default_text;
}

function nuvei_change_title_order_received( $title, $id) {
	if (
		function_exists('is_order_received_page')
		&& is_order_received_page()
		&& get_the_ID() === $id
	) {
		$title = esc_html__('Order error', 'sc');
	}
	
	return $title;
}

/**
 * Function nuvei_user_orders
 * Call this on Store when the logged user is in My Account section
 * 
 * @global type $wp
 * @global WC_SC $wc_sc
 * @global type $woocommerce
 */
function nuvei_user_orders() {
	global $wp, $wc_sc, $woocommerce;
	
	$url_key              = $wc_sc->get_param('key');
	$order                = wc_get_order($wp->query_vars['order-pay']);
	$order_status         = strtolower($order->get_status());
	$order_payment_method = $order->get_meta('_paymentMethod');
	$order_key            = $order->get_order_key();
	
	if ('sc' != $order->get_payment_method()) {
		return;
	}
	
	if ($wc_sc->get_param('key') != $order_key) {
		return;
	}
	
	$prods_ids = array();
	
	foreach ($order->get_items() as $prod_id => $data) {
		$prods_ids[] = $data->get_product_id();
	}
	
	echo '<script>'
		. 'var scProductsIdsToReorder = ' . wp_kses_post(json_encode($prods_ids)) . ';'
		. 'scOnPayOrderPage();'
	. '</script>';
}

// on reorder, show warning message to the cart if need to
function nuvei_show_message_on_cart( $data) {
	global $wc_sc;
	
	echo '<script>jQuery("#content .woocommerce:first").append("<div class=\'woocommerce-warning\'>'
		. wp_kses_post($wc_sc->get_param('sc_msg')) . '</div>");</script>';
}

/**
 * Function sc_filter_woocommerce_product_data_tabs
 * Add Subscription tab to the product data
 * 
 * @param array $tabs
 * @return array
 */
function sc_filter_woocommerce_product_data_tabs( $tabs ) { 
	$tabs['sc_subscr'] = array(
		'label'		=> __( 'SafeCharge Subscription', 'sc' ),
		'priority' 	=> 10000,
		'target'	=> 'sc_subscr_settings', // the container div ID
	);
	
    return $tabs; 
}; 

/**
 * Function sc_add_product_subscr_data_fields
 * Add Subscription settings
 */
function sc_add_product_subscr_data_fields() {
	$units = array(
		'day'	=> __( 'DAY', 'nuvei' ),
		'month'	=> __( 'MONTH', 'nuvei' ),
		'year'	=> __( 'YEAR', 'nuvei' ),
	);
	$currency = get_woocommerce_currency_symbol();
	
	echo '<div id="sc_subscr_settings" class="panel woocommerce_options_panel">';
		# _sc_subscr_enabled
		$sc_subscr_enabled = 0;
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_enabled'))) {
			$sc_subscr_enabled = $tmp[0];
		}
		
		var_dump($sc_subscr_enabled);
		
//		woocommerce_wp_checkbox(array( 
//			'id'            => '_sc_subscr_enabled', 
////			'wrapper_class' => 'show_if_simple', 
//			'label'         => __( 'Enable Subscription', 'nuvei' ),
////			'description'   => __( 'My Custom Field Description', 'my_text_domain' ),
////			'default'  		=> 'no',
//			'value'			=> $sc_subscr_enabled,
////			'desc_tip'    	=> false,
//		));
		woocommerce_wp_select(array(
			'id'		=> '_sc_subscr_enabled', 
			'label'		=> __( 'Enable Subscription', 'nuvei' ),
			'value'		=> $sc_subscr_enabled,
			'options'	=> array(
				0	=> __( 'NO', 'nuvei' ),
				1	=> __( 'YES', 'nuvei' ),
			),
		));
		# _sc_subscr_enabled END
		
		# _sc_subscr_plan_id
		$plans_list = array('' => __('Select a Plan', 'nuvei'));
		
		if(is_readable(plugin_dir_path(__FILE__) . '/tmp/sc_plans.json')) {
			$plans = json_decode(file_get_contents(plugin_dir_path(__FILE__) . '/tmp/sc_plans.json', true));
			
			if(is_array($plans)) {
				foreach($plans as $data) {
					$plans_list[$data->planId] = $data->name;
				}
			}
		}
		
		$sc_subscr_plan_id = '';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_plan_id'))) {
			$sc_subscr_plan_id = $tmp[0];
		}
		
		woocommerce_wp_select(array(
			'id'		=> '_sc_subscr_plan_id', 
			'label'		=> __( 'Subscription Plan', 'nuvei' ),
			'value'		=> $sc_subscr_plan_id,
			'options'	=> $plans_list,
		));
		# _sc_subscr_plan_id END
		
		# _sc_subscr_initial_amount
		$sc_subscr_initial_amount = '';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_initial_amount'))) {
			$sc_subscr_initial_amount = $tmp[0];
		}
		
		woocommerce_wp_text_input(array(
			'id'	=> '_sc_subscr_initial_amount', 
			'label'	=> __( 'Initial Amount', 'nuvei' ) . ' (' . $currency . ')',
			'value'	=> $sc_subscr_initial_amount,
			'class'	=> 'wc_input_price'
		));
		# _sc_subscr_initial_amount END
		
		# _sc_subscr_recurr_amount
		$sc_subscr_recurr_amount = '';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_recurr_amount'))) {
			$sc_subscr_recurr_amount = $tmp[0];
		}
		
		woocommerce_wp_text_input(array(
			'id'	=> '_sc_subscr_recurr_amount', 
			'label'	=> __( 'Recurring Amount', 'nuvei' ) . ' (' . $currency . ')',
			'value'	=> $sc_subscr_recurr_amount,
			'class'	=> 'wc_input_price'
		));
		# _sc_subscr_recurr_amount END
		
		# _sc_subscr_recurr_units
		$sc_subscr_recurr_units = 'day';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_recurr_units'))) {
			$sc_subscr_recurr_units = $tmp[0];
		}
		
		woocommerce_wp_select(array(
			'id'		=> '_sc_subscr_recurr_units', 
			'label'		=> __( 'Recurring Units', 'nuvei' ),
			'value'		=> $sc_subscr_recurr_units,
			'options'	=> $units,
		));
		# _sc_subscr_recurr_units END
		
		# _sc_subscr_recurr_period
		$sc_subscr_recurr_period = '';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_recurr_period'))) {
			$sc_subscr_recurr_period = $tmp[0];
		}
		
		woocommerce_wp_text_input(array(
			'id'	=> '_sc_subscr_recurr_period', 
			'label'	=> __( 'Recurring Period', 'nuvei' ),
			'value'	=> $sc_subscr_recurr_period,
			'class'	=> 'wc_input_price'
		));
		# _sc_subscr_recurr_period END
		
		# _sc_subscr_trial_units
		$sc_subscr_trial_units = 'day';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_trial_units'))) {
			$sc_subscr_trial_units = $tmp[0];
		}
		
		woocommerce_wp_select(array(
			'id'		=> '_sc_subscr_trial_units', 
			'label'		=> __( 'Trial Units', 'nuvei' ),
			'value'		=> $sc_subscr_trial_units,
			'options'	=> $units,
		));
		# _sc_subscr_trial_units END
		
		# _sc_subscr_trial_period
		$sc_subscr_trial_period = '';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_trial_period'))) {
			$sc_subscr_trial_period = $tmp[0];
		}
		
		woocommerce_wp_text_input(array(
			'id'	=> '_sc_subscr_trial_period', 
			'label'	=> __( 'Trial Period', 'nuvei' ),
			'value'	=> $sc_subscr_trial_period,
			'class'	=> 'wc_input_price'
		));
		# _sc_subscr_recurr_period END
		
		# _sc_subscr_end_after_units
		$sc_subscr_end_after_units = 'day';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_end_after_units'))) {
			$sc_subscr_end_after_units = $tmp[0];
		}
		
		woocommerce_wp_select(array(
			'id'		=> '_sc_subscr_end_after_units', 
			'label'		=> __( 'End After Units', 'nuvei' ),
			'value'		=> $sc_subscr_end_after_units,
			'options'	=> $units,
		));
		# _sc_subscr_end_after_units END
		
		# _sc_subscr_end_after_period
		$sc_subscr_end_after_period = '';
		if(!empty($tmp = get_post_meta($_GET['post'], '_sc_subscr_end_after_period'))) {
			$sc_subscr_end_after_period = $tmp[0];
		}
		
		woocommerce_wp_text_input(array(
			'id'	=> '_sc_subscr_end_after_period', 
			'label'	=> __( 'End After Period', 'nuvei' ),
			'value'	=> $sc_subscr_end_after_period,
			'class'	=> 'wc_input_price'
		));
		# _sc_subscr_end_after_period END
	echo '</div>';
}

/**
 * Function sc_save_product_custom_fields
 * Save SC product meta fields - Subscription fields
 * 
 * @param int $post_id
 */
function sc_save_product_custom_fields($post_id) {
	global $wc_sc;
	
	foreach($wc_sc->get_subscr_fields() as $field) {
		if(isset($_POST[$field])) {
			update_post_meta( $post_id, $field, esc_attr( $_POST[$field] ) );
		}
	}
}