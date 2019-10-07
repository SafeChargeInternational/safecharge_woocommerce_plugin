<?php
/*
Plugin Name: SafeCharge Payments
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 2.2
Author: SafeCharge
Author URI: http://safecharge.com
*/

defined('ABSPATH') || die('die');

if (!session_id()) {
	session_start();
}

require_once 'sc_config.php';
require_once 'SC_Versions_Resolver.php';
require_once 'SC_HELPER.php';

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
	add_filter( 'woocommerce_enqueue_styles', 'sc_enqueue_wo_files' );
	// replace the text at thank you page
	add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
	// eliminates the problem with different permalinks
	add_action('template_redirect', 'sc_iframe_redirect');
	// add void and/or settle buttons to completed orders, we check in the method is this order made via SC paygate
	add_action('woocommerce_order_item_add_action_buttons',	'sc_add_buttons');
	// on the checkout page get the order total amount
	add_action('woocommerce_checkout_before_order_review', array($wc_sc, 'checkout_open_order'));
	// show custom final text
	add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
	// handle Ajax calls
	add_action('wp_ajax_sc-ajax-action', 'sc_ajax_action');
	add_action('wp_ajax_nopriv_sc-ajax-action', 'sc_ajax_action');
	
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
	}
}

/**
 * Function sc_ajax_action
 * Main function for the Ajax requests.
 */
function sc_ajax_action() {
	if (!check_ajax_referer('sc-security-nonce', 'security')) {
		wp_send_json_error( __('Invalid security token sent.') );
		wp_die('Invalid security token sent');
	}
	
	global $wc_sc;
	
	if (empty($wc_sc->payment_api) || empty($wc_sc->test)) {
		wp_send_json_error( __('Invalid payment api or/and site mode.') );
		wp_die('Invalid payment api or/and site mode.');
	}
	
	$country = '';
	if (isset($_POST['country'])) {
		$country = sanitize_text_field($_POST['country']);
	}

	$amount = '';
	if (isset($_POST['amount'])) {
		$amount = sanitize_text_field($_POST['amount']);
	}

	$userMail = '';
	if (isset($_POST['userMail'])) {
		$userMail = sanitize_text_field($_POST['userMail']);
	}

	$payment_method_sc = '';
	if (isset($_POST['payment_method_sc'])) {
		$payment_method_sc = sanitize_text_field($_POST['payment_method_sc']);
	}

	// Void (Cancel)
	if (!empty($_POST['cancelOrder']) && !empty($_POST['orderId'])) {
		$wc_sc->create_settle_void(sanitize_text_field($_POST['orderId']), 'void');
	}

	// Settle
	if (isset($_POST['settleOrder'], $_POST['orderId']) && 1 == $_POST['settleOrder']) {
		$wc_sc->create_settle_void(sanitize_text_field($_POST['orderId']), 'settle');
	}

	if ('rest' == $wc_sc->payment_api) {
		// when we use the REST - Open order and get APMs and UPOs
		if (!empty($country) && !empty($amount) && !empty($userMail)) {
			$wc_sc->prepare_rest_payment($amount, $userMail, $country);
		}

		wp_die();
	} else {
		// here we no need APMs
		$msg = 'Missing some of conditions to using REST API.';

		if ('rest' != $wc_sc->payment_api) {
			$msg = 'You are using Cashier API. APMs are not available with it.';
		}

		wp_send_json(array(
			'status'    => 2,
			'msg'       =>  $msg
		));
		wp_die();
	}
}

/**
* Add the Gateway to WooCommerce
**/
function woocommerce_add_sc_gateway( $methods) {
	$methods[] = 'WC_SC';
	return $methods;
}

function sc_enqueue_wo_files() {
	global $wc_sc;
	
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
		'novo_style',
		$plugin_url . '/' . $plugin_dir . '/css/novo.css',
		'',
		'2.2',
		'all'
	);
	wp_enqueue_style('novo_style');
	
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

	// put translations here into the array
	wp_localize_script(
		'sc_js_public',
		'scTrans',
		array(
			'ajaxurl'	=> admin_url('admin-ajax.php'),
			'security'	=> wp_create_nonce('sc-security-nonce'),
			
			'paymentDeclined'	=> __('Your Payment was DECLINED. Please try another payment method!'),
			'paymentError'		=> __('Error with your Payment. Please try again later!'),
			'unexpectedError'	=> __('Unexpected error, please try again later!'),
			'choosePM'			=> __('Please, choose payment method, and fill all fields!'),
			'fillFields'		=> __('Please fill all fields marked with * !'),
			'errorWithPMs'		=> __('Error when try to get the Payment Methods. Please try again later or use different Payment Option!'),
			'missData'			=> __('Mandatory data is missing, please try again later!'),
			'proccessError'		=> __('Error in the proccess. Please, try again later!'),
			'chooseUPO'			=> __('Choose from you prefered payment methods'),
			'chooseAPM'			=> __('Choose from the other payment methods'),
		)
	);

	// connect the translations with some of the JS files
	wp_enqueue_script('sc_js_public');
}

// first method we come in
function sc_enqueue( $hook) {
	global $wc_sc;
		
	# DMNs catch
	if (isset($_REQUEST['wc-api']) && 'sc_listener' == $_REQUEST['wc-api']) {
		$wc_sc->process_dmns();
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
		
		// put translations here into the array
		wp_localize_script(
			'sc_js_admin',
			'scTrans',
			array(
				'ajaxurl'	=> admin_url('admin-ajax.php'),
				'security'	=> wp_create_nonce('sc-security-nonce'),
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
	
	$msg = __('Thank you. Your payment process is completed. Your order status will be updated soon.', 'sc');
   
	// Cashier
	if (
		!empty($_REQUEST['invoice_id'])
		&& !empty($_REQUEST['ppp_status'])
		&& $wc_sc->checkAdvancedCheckSum()
	) {
		try {
			$arr      = explode('_', sanitize_text_field($_REQUEST['invoice_id']));
			$order_id = $arr[0];
			$order    = new WC_Order($order_id);

			if (strtolower(sanitize_text_field($_REQUEST['ppp_status'])) == 'fail') {
				$order->add_order_note('User order failed.');
				$order->update_status('failed', 'User order failed.');

				$msg = __('Your payment failed. Please, try again.', 'sc');
			} else {
				$TransactionID = '';
				if (isset($_REQUEST['TransactionID'])) {
					$TransactionID = sanitize_text_field($_REQUEST['TransactionID']);
				}
				
				$transactionId = 'TransactionId = ' . $TransactionID;

				$PPPTransactionId = '';
				if (isset($_REQUEST['PPP_TransactionID'])) {
					$PPPTransactionId = sanitize_text_field($_REQUEST['PPP_TransactionID']);
				}
				
				$pppTransactionId = '; PPPTransactionId = ' . $PPPTransactionId;

				$order->add_order_note('User returned from Safecharge Payment page; ' . $transactionId . $pppTransactionId);
				$woocommerce->cart->empty_cart();
			}
			
			$order->save();
		} catch (Exception $ex) {
			SC_HELPER::create_log($ex->getMessage(), 'Cashier handle exception error: ');
		}
	} else {
		// REST API tahnk you page handler
		if (
			!empty($_REQUEST['Status'])
			&& 'error' == sanitize_text_field($_REQUEST['Status'])) {
			$msg = __('Your payment failed. Please, try again.', 'sc');
		} else {
			$woocommerce->cart->empty_cart();
		}
	}
	
	// clear session variables for the order
	if (isset($_SESSION['SC_Variables'])) {
		unset($_SESSION['SC_Variables']);
	}
	
	return $msg;
}

function sc_iframe_redirect() {
	global $wp;
	global $wc_sc;
	
	if (!is_checkout()) {
		return;
	}
	
	if (!isset($_REQUEST['order-received'])) {
		$request_uri = '';
		if (isset($_SERVER['REQUEST_URI'])) {
			$request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
		}
		
		$url_parts = explode('/', trim(parse_url($request_uri, PHP_URL_PATH), '/'));
		
		if (!$url_parts || empty($url_parts)) {
			return;
		}
		
		if (!in_array('order-received', $url_parts)) {
			return;
		}
	}
	
	// when we use iframe
	if (
		!empty($_REQUEST['use_iframe'])
		&& 1 == sanitize_text_field($_REQUEST['use_iframe'])
	) {
		echo
			'<table id="sc_pay_msg" style="border: 0px; cursor: wait; line-height: 32px; width: 100%;"><tr>'
				. '<td style="padding: 0px; border: 0px; width: 100px;">'
					. '<img src="' . esc_url(get_site_url()) . '/wp-content/plugins/' . esc_html(basename(dirname(__FILE__))) . '/icons/loading.gif" style="width:100px; float:left; margin-right: 10px;" />'
				. '</td>'
				. '<td style="text-align: left; border: 0px;">'
					. '<span>' . esc_html__('Thank you for your order. We are now redirecting you to ' . SC_GATEWAY_TITLE . ' Payment Gateway to make payment.', 'sc') . '</span>'
				. '</td>'
			. '</tr></table>'
			
			. '<script type="text/javascript">'
				. 'var scNewUrl = window.location.toLocaleString().replace("use_iframe=1&", "");'
				
				. 'parent.postMessage({'
					. 'scAction: "scRedirect",'
					. 'scUrl: scNewUrl'
				. '}, window.location.origin);'
			. '</script>';
		
		wp_die();
	}
}

function sc_add_buttons() {
	$order_id = false;
	
	if (!empty($_GET['post'])) {
		$order_id = sanitize_text_field($_GET['post']);
	}
	
	try {
		$order                = new WC_Order($order_id);
		$order_status         = strtolower($order->get_status());
		$order_payment_method = $order->get_meta('_paymentMethod');
		
		// hide Refund Button
		if (!in_array($order_payment_method, array('cc_card', 'dc_card', 'apmgw_expresscheckout'))) {
			echo '<script type="text/javascript">jQuery(\'.refund-items\').prop("disabled", true);</script>';
		}
	} catch (Exception $ex) {
		echo '<script type="text/javascript">console.error("'
			. esc_js($ex->getMessage()) . '")</script>';
		exit;
	}
	
	// to show SC buttons we must be sure the order is paid via SC Paygate
	if (!$order->get_meta(SC_AUTH_CODE_KEY) || !$order->get_meta(SC_GW_TRANS_ID_KEY)) {
		return;
	}
	
	if ('completed' == $order_status || 'pending' == $order_status) {
		global $wc_sc;

		$time             = date('YmdHis', time());
		$order_tr_id      = $order->get_meta(SC_GW_TRANS_ID_KEY);
		$order_has_refund = $order->get_meta('_scHasRefund');
		$notify_url       = $wc_sc->set_notify_url();
		
		// Show VOID button
		if ('1' != $order_has_refund && in_array($order_payment_method, array('cc_card', 'dc_card'))) {
			echo 
				'<button id="sc_void_btn" type="button" onclick="settleAndCancelOrder(\''
				. esc_html__('Are you sure, you want to Cancel Order #' . $order_id . '?', 'sc') . '\', '
				. '\'void\', ' . esc_html($order_id) . ')" class="button generate-items">'
				. esc_html__('Void', 'sc') . '</button>';
		}
		
		// show SETTLE button ONLY if setting transaction_type IS Auth AND P3D resonse transaction_type IS Auth
		if (
			'pending' == $order_status
			&& 'Auth' == $order->get_meta(SC_GW_P3D_RESP_TR_TYPE)
			&& 'Auth' == $wc_sc->settings['transaction_type']
		) {
			echo 
				'<button id="sc_settle_btn" type="button" onclick="settleAndCancelOrder(\''
				. esc_html__('Are you sure, you want to Settle Order #' . $order_id . '?', 'sc') . '\', '
				. '\'settle\', \'' . esc_html($order_id) . '\')" class="button generate-items">'
				. esc_html__('Settle', 'sc') . '</button>';
		}
		
		// add loading screen
		//echo '<div id="custom_loader" class="blockUI blockOverlay"></div>';
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
	
	SC_HELPER::create_log($order_received_url, 'sc_wpml_thank_you_page: ');
 
	return $order_received_url;
}
