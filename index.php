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
	// replace the text at thank you page
	add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
	// eliminates the problem with different permalinks
	add_action('template_redirect', 'sc_iframe_redirect');
	// add void and/or settle buttons to completed orders, we check in the method is this order made via SC paygate
	add_action('woocommerce_order_item_add_action_buttons',	'sc_add_buttons');
	// redirect when show receipt page
	add_action('woocommerce_receipt_' . $wc_sc->id, array($wc_sc, 'generate_sc_form'));
	// on the checkout page get the order total amount
	add_action('woocommerce_checkout_before_order_review', array($wc_sc, 'checkout_open_order'));
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
		wp_send_json_error( 'Invalid security token sent.' );
		wp_die('Invalid security token sent');
	}
	
	if (
		!empty($_SESSION['SC_Variables']['merchantId'])
		&& !empty($_SESSION['SC_Variables']['merchantSiteId'])
		&& isset(
			$_SESSION['SC_Variables']['payment_api'],
			$_SESSION['SC_Variables']['test']
		)
		&& in_array($_SESSION['SC_Variables']['payment_api'], array('cashier', 'rest'))
	) {
		// post variables
		$enableDisableSCCheckout = '';
		if (isset($_POST['enableDisableSCCheckout'])) {
			$enableDisableSCCheckout = sanitize_text_field($_POST['enableDisableSCCheckout']);
		}
		
		$woVersion = '';
		if (isset($_POST['woVersion'])) {
			$woVersion = sanitize_text_field($_POST['woVersion']);
		}
		
		$country = '';
		if (isset($_POST['country'])) {
			$country = sanitize_text_field($_POST['country']);
		}
		
		$amount = '';
		if (isset($_POST['amount'])) {
			$amount = sanitize_text_field($_POST['amount']);
		}
		
		$scCs = '';
		if (isset($_POST['scCs'])) {
			$scCs = sanitize_text_field($_POST['scCs']);
		}
		
		$userMail = '';
		if (isset($_POST['userMail'])) {
			$userMail = sanitize_text_field($_POST['userMail']);
		}
		
		$payment_method_sc = '';
		if (isset($_POST['payment_method_sc'])) {
			$payment_method_sc = sanitize_text_field($_POST['payment_method_sc']);
		}

		// when enable or disable SC Checkout
		if (in_array($enableDisableSCCheckout, array('enable', 'disable'))) {
			if ('enable' == $enableDisableSCCheckout) {
				add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
				add_action('woocommerce_checkout_process', function () {
					// if custom fields are empty stop checkout process displaying an error notice.
					if (
						isset($_SESSION['SC_Variables']['payment_api'])
						&& 'rest' == $_SESSION['SC_Variables']['payment_api']
						&& empty($payment_method_sc)
					) {
						$notice = __('Please select ' . SC_GATEWAY_TITLE . ' payment method to continue!', 'sc');
						wc_add_notice('<strong>' . $notice . '</strong>', 'error');
					}
				});

				echo json_encode(array('status' => 1));
				exit;
			}

			echo json_encode(array('status' => 1, data => 'action error.'));
			exit;
		}

		// if there is no webMasterId in the session get it from the post
		if ( empty($_SESSION['SC_Variables']['webMasterId']) && $woVersion ) {
			$_SESSION['SC_Variables']['webMasterId'] = 'WoCommerce ' . $woVersion;
		}

		// Void, Cancel
		if (isset($_POST['cancelOrder']) && 1 == sanitize_text_field($_POST['cancelOrder'])) {
			$ord_status = 1;
			$url        = 'no' == $_SESSION['SC_Variables']['test'] ? SC_LIVE_VOID_URL : SC_TEST_VOID_URL;

			$resp = SC_HELPER::call_rest_api($url, $_SESSION['SC_Variables']);

			if (
				!$resp || !is_array($resp)
				|| 'ERROR' == @$resp['status']
				|| 'ERROR' == @$resp['transactionStatus']
				|| 'DECLINED' == @$resp['transactionStatus']
			) {
				$ord_status = 0;
			}

			unset($_SESSION['SC_Variables']);

			echo json_encode(array('status' => $ord_status, 'data' => $resp));
			exit;
		}

		// Settle
		if (isset($_POST['settleOrder']) && 1 == sanitize_text_field($_POST['settleOrder'])) {
			$ord_status = 1;
			$url        = 'no' == $_SESSION['SC_Variables']['test'] ? SC_LIVE_SETTLE_URL : SC_TEST_SETTLE_URL;

			$resp = SC_HELPER::call_rest_api($url, $_SESSION['SC_Variables']);

			if (
				!$resp || !is_array($resp)
				|| 'ERROR' == @$resp['status']
				|| 'ERROR' == $resp['transactionStatus']
				|| 'DECLINED' == @$resp['transactionStatus']
			) {
				$ord_status = 0;
			}

			unset($_SESSION['SC_Variables']);

			echo json_encode(array('status' => $ord_status, 'data' => $resp));
			exit;
		}

		if ('rest' == $_SESSION['SC_Variables']['payment_api']) {
			// when we want Session Token only
			if (isset($_POST['needST']) && 1 == sanitize_text_field($_POST['needST'])) {
				SC_HELPER::create_log('Ajax, get Session Token.');

				$session_data['test'] = @$_SESSION['SC_Variables']['test'];
				echo json_encode(array('status' => 1, 'data' => $session_data));
				exit;
			}

			// when we want APMs and UPOs
			if (!empty($country) && !empty($amount) && !empty($scCs) && !empty($userMail)) {
				// if the Country come as POST variable
				if (empty($_SESSION['SC_Variables']['sc_country'])) {
					$_SESSION['SC_Variables']['sc_country'] = $country;
				}

				# Open Order
				$oo_endpoint_url = 'yes' == $_SESSION['SC_Variables']['test']
					? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;

				$oo_params = array(
					'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
					'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
					'clientRequestId'   => $_SESSION['SC_Variables']['cri1'],
					'amount'            => $amount,
					'currency'          => $_SESSION['SC_Variables']['currencyCode'],
					'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri1'])),
					'checksum'          => $scCs,
					'urlDetails'        => array(
						'successUrl'        => $_SESSION['SC_Variables']['other_urls'],
						'failureUrl'        => $_SESSION['SC_Variables']['other_urls'],
						'pendingUrl'        => $_SESSION['SC_Variables']['other_urls'],
						'notificationUrl'   => $_SESSION['SC_Variables']['notify_url'],
					),
					'deviceDetails'     => SC_HELPER::get_device_details(),
					'userTokenId'       => $userMail,
					'billingAddress'    => array(
						'country' => $country,
					),
				);

				$resp = SC_HELPER::call_rest_api($oo_endpoint_url, $oo_params);

				if (
					empty($resp['status'])
					|| 'SUCCESS' != $resp['status']
					|| empty($resp['sessionToken'])
				) {
					echo json_encode(array(
						'status' => 0,
						'callResp' => $resp
					));
					exit;
				}
				# Open Order END

				# get APMs
				$apms_params = array(
					'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
					'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
					'clientRequestId'   => $_SESSION['SC_Variables']['cri2'],
					'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri2'])),
					'checksum'          => $_SESSION['SC_Variables']['cs2'],
					'sessionToken'      => $resp['sessionToken'],
					'currencyCode'      => $_SESSION['SC_Variables']['currencyCode'],
					'countryCode'       => $_SESSION['SC_Variables']['sc_country'],
					'languageCode'      => $_SESSION['SC_Variables']['languageCode'],
				);

				$endpoint_url = 'yes' == $_SESSION['SC_Variables']['test']
					? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL;

				$apms_data = SC_HELPER::call_rest_api($endpoint_url, $apms_params);

				if (!is_array($apms_data) || !isset($apms_data['paymentMethods']) || empty($apms_data['paymentMethods'])) {
					SC_HELPER::create_log($apms_data, 'getting APMs error: ');

					echo json_encode(array(
						'status' => 0,
						'apmsData' => $apms_data
					));
					exit;
				}

				// set template data with the payment methods
				$payment_methods = $apms_data['paymentMethods'];
				# get APMs END

				# get UPOs
				$upos  = array();
				$icons = array();

				if (isset($_SESSION['SC_Variables']['upos_data'])) {
					$endpoint_url = 'yes' == $_SESSION['SC_Variables']['test']
						? SC_TEST_USER_UPOS_URL : SC_LIVE_USER_UPOS_URL;

					$upos_params = array(
						'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
						'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
						'userTokenId'       => $_SESSION['SC_Variables']['upos_data']['userTokenId'],
						'clientRequestId'   => $_SESSION['SC_Variables']['upos_data']['clientRequestId'],
						'timeStamp'         => $_SESSION['SC_Variables']['upos_data']['timestamp'],
						'checksum'          => $_SESSION['SC_Variables']['upos_data']['checksum'],
					);

					$upos_data = SC_HELPER::call_rest_api($endpoint_url, $upos_params);

					if (isset($upos_data['paymentMethods']) && $upos_data['paymentMethods']) {
						foreach ($upos_data['paymentMethods'] as $upo_key => $upo) {
							if (
								'enabled' != @$upo['upoStatus']
								|| ( isset($upo['upoData']['ccCardNumber'])
									&& empty($upo['upoData']['ccCardNumber']) )
								|| ( isset($upo['expiryDate'])
									&& strtotime($upo['expiryDate']) < strtotime(date('Ymd')) )
							) {
								continue;
							}

							// search in payment methods
							foreach ($payment_methods as $pm) {
								if (
									isset($upo['paymentMethodName'], $pm['paymentMethod'])
									&& $upo['paymentMethodName'] == $pm['paymentMethod']
								) {
									if (
										in_array(@$upo['paymentMethodName'], array('cc_card', 'dc_card'))
										&& @$upo['upoData']['brand'] && @$pm['logoURL']
									) {
										$icons[@$upo['upoData']['brand']] = str_replace(
											'default_cc_card',
											$upo['upoData']['brand'],
											$pm['logoURL']
										);
									} elseif (@$pm['logoURL']) {
										$icons[$pm['paymentMethod']] = $pm['logoURL'];
									}

									$upos[] = $upo;
									break;
								}
							}
						}
					} else {
						SC_HELPER::create_log($upos_data, '$upos_data:');
					}
				}
				# get UPOs END

				echo json_encode(array(
					'status'            => 1,
					'testEnv'           => $_SESSION['SC_Variables']['test'],
					'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
					'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
					'langCode'          => $_SESSION['SC_Variables']['languageCode'],
					'sessionToken'      => $resp['sessionToken'],
					'currency'          => $_SESSION['SC_Variables']['currencyCode'],
					'data'              => array(
						'upos'              => $upos,
						'paymentMethods'    => $payment_methods,
						'icons'             => $icons
					)
				));

				exit;
			}

			exit;
		} else {
			// here we no need APMs
			$msg = 'Missing some of conditions to using REST API.';

			if ('rest' != $_SESSION['SC_Variables']['payment_api']) {
				$msg = 'You are using Cashier API. APMs are not available with it.';
			}

			echo json_encode(array(
				'status'    => 2,
				'msg'       =>  $msg
			));
			exit;
		}
	} elseif (
		( isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'XMLHttpRequest' === sanitize_text_field($_SERVER['HTTP_X_REQUESTED_WITH']) )
		// don't come here when try to refund!
		&& ( isset($_REQUEST['action']) && 'woocommerce_refund_line_items' !== sanitize_text_field($_REQUEST['action']) )
	) {
		$msg = 'Missing some of conditions to using REST API.';

		if ('rest' != @$_SESSION['SC_Variables']['payment_api']) {
			$msg = 'You are using Cashier API. APMs are not available with it.';
		}

		echo json_encode(array(
			'status'    => 2,
			'msg'       =>$msg
		));
		exit;
	}

	echo json_encode(array('status' => 0));
	exit;
}

/**
* Add the Gateway to WooCommerce
**/
function woocommerce_add_sc_gateway( $methods) {
	$methods[] = 'WC_SC';
	return $methods;
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
	
	// main JS
	wp_register_script(
		'sc_js_script',
		$plugin_url . '/' . $plugin_dir . '/js/sc.js',
		array('jquery'),
		'2.2'
	);
	
	wp_localize_script(
		'sc_js_script',
		'scAjax',
		array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'security' => wp_create_nonce('sc-security-nonce')
		)
	);
	wp_enqueue_script('sc_js_script');
	// main JS END
	
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
		'yes' == $wc_sc->test
			? 'https://dev-mobile.safecharge.com/cdn/WebSdk/dist/safecharge.js'
				: 'https://cdn-int.safecharge.com/safecharge_resources/v1/websdk/safecharge.js',
		array('jquery'),
		'1'
	);
	wp_enqueue_script('sc_websdk');
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
		if ('error' == get_query_var('Status')) {
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
		exit;
	}
}

function sc_add_buttons() {
	try {
		$order                = new WC_Order(get_query_var('post'));
		$order_status         = strtolower($order->get_status());
		$order_payment_method = $order->get_meta('_paymentMethod');
		
		// hide Refund Button
		if (!in_array($order_payment_method, array('cc_card', 'dc_card', 'apmgw_expresscheckout'))) {
			echo '<script type="text/javascript">jQuery(\'.refund-items\').hide();</script>';
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
		$buttons_html     = '';
		
		// common data
		$_SESSION['SC_Variables'] = array(
			'merchantId'            => $wc_sc->settings['merchantId'],
			'merchantSiteId'        => $wc_sc->settings['merchantSiteId'],
			'clientRequestId'       => $time . '_' . $order_tr_id,
			'clientUniqueId'        => get_query_var('post'),
			'amount'                => $wc_sc->get_order_data($order, 'order_total'),
			'currency'              => get_woocommerce_currency(),
			'relatedTransactionId'  => $order_tr_id,
			'authCode'              => $order->get_meta(SC_AUTH_CODE_KEY),
			'timeStamp'             => $time,
			// optional fields for sc_ajax.php
			'test'                  => $wc_sc->settings['test'],
			'payment_api'           => 'rest',
			'save_logs'             => $wc_sc->settings['save_logs'],
			'urlDetails'            => array(
				'notificationUrl'       => $notify_url . '&clientRequestId=' . get_query_var('post')
			),
		);
		
		// Show VOID button
		if ('1' != $order_has_refund && in_array($order_payment_method, array('cc_card', 'dc_card'))) {
			$buttons_html .=
				' <button id="sc_void_btn" type="button" onclick="settleAndCancelOrder(\''
				. __('Are you sure, you want to Cancel Order #' . get_query_var('post') . '?', 'sc') . '\', '
				. '\'void\', \'' . WOOCOMMERCE_VERSION . '\')" class="button generate-items">'
				. __('Void', 'sc') . '</button>';
		}
		
		// show SETTLE button ONLY if setting transaction_type IS Auth AND P3D resonse transaction_type IS Auth
		if (
			'pending' == $order_status
			&& 'Auth' == $order->get_meta(SC_GW_P3D_RESP_TR_TYPE)
			&& 'Auth' == $wc_sc->settings['transaction_type']
		) {
			$buttons_html .=
				' <button id="sc_settle_btn" type="button" onclick="settleAndCancelOrder(\''
				. __('Are you sure, you want to Settle Order #' . get_query_var('post') . '?', 'sc') . '\', '
				. '\'settle\', \'' . WOOCOMMERCE_VERSION . '\')" class="button generate-items">'
				. __('Settle', 'sc') . '</button>';
		}
		
		$checksum = hash(
			$wc_sc->settings['hash_type'],
			$wc_sc->settings['merchantId']
				. $wc_sc->settings['merchantSiteId']
				. $_SESSION['SC_Variables']['clientRequestId']
				. $_SESSION['SC_Variables']['clientUniqueId']
				. $_SESSION['SC_Variables']['amount']
				. $_SESSION['SC_Variables']['currency']
				. $_SESSION['SC_Variables']['relatedTransactionId']
				. $_SESSION['SC_Variables']['authCode']
				. $_SESSION['SC_Variables']['urlDetails']['notificationUrl']
				. $time
				. $wc_sc->settings['secret']
		);

		$_SESSION['SC_Variables']['checksum'] = $checksum;

		// add loading screen
		echo esc_html($buttons_html) . '<div id="custom_loader" class="blockUI blockOverlay"></div>';
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
