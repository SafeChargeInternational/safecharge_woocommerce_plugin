<?php

/**
 * Work with Ajax.
 *
 * 2018
 *
 * @author SafeCharge
 */

if (!session_id()) {
	session_start();
}

require_once 'sc_config.php';
require_once 'SC_HELPER.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR
	. 'wp-includes' . DIRECTORY_SEPARATOR . 'formatting.php';

// The following fileds are MANDATORY for success
if (
	isset(
		$_SERVER['HTTP_X_REQUESTED_WITH']
		, $_SESSION['SC_Variables']['merchantId']
		, $_SESSION['SC_Variables']['merchantSiteId']
		, $_SESSION['SC_Variables']['payment_api']
		, $_SESSION['SC_Variables']['test']
	)
	&& 'XMLHttpRequest' == $_SERVER['HTTP_X_REQUESTED_WITH']
	&& !empty($_SESSION['SC_Variables']['merchantId'])
	&& !empty($_SESSION['SC_Variables']['merchantSiteId'])
	&& in_array($_SESSION['SC_Variables']['payment_api'], array('cashier', 'rest'))
) {
	// when enable or disable SC Checkout
	if (in_array(get_post('enableDisableSCCheckout'), array('enable', 'disable'))) {
		require dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-includes/plugin.php';
		
		if ('enable' == get_post('enableDisableSCCheckout')) {
			add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
			add_action('woocommerce_checkout_process', 'sc_check_checkout_apm');

			echo json_encode(array('status' => 1));
			exit;
		} else {
			remove_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
			remove_action('woocommerce_checkout_process', 'sc_check_checkout_apm');

			echo json_encode(array('status' => 1));
			exit;
		}

		echo json_encode(array('status' => 1, data => 'action error.'));
		exit;
	}
	
	// if there is no webMasterId in the session get it from the post
	if (
		!@$_SESSION['SC_Variables']['webMasterId']
		&& get_post('woVersion')
	) {
		$_SESSION['SC_Variables']['webMasterId'] = 'WoCommerce ' . get_post('woVersion');
	}
	
	// Void, Cancel
	if (1 == get_post('cancelOrder')) {
		$ord_status = 1;
		$url = 'no' == $_SESSION['SC_Variables']['test'] ? SC_LIVE_VOID_URL : SC_TEST_VOID_URL;
		
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
	if (1 == get_post('settleOrder')) {
		$ord_status = 1;
		$url = 'no' == $_SESSION['SC_Variables']['test'] ? SC_LIVE_SETTLE_URL : SC_TEST_SETTLE_URL;
		
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
		if (1 == get_post('needST')) {
			SC_HELPER::create_log('Ajax, get Session Token.');

			$session_data['test'] = @$_SESSION['SC_Variables']['test'];
			echo json_encode(array('status' => 1, 'data' => $session_data));
			exit;
		}
		
		// when we want APMs and UPOs
		if (
			!empty(get_post('country'))
			&& !empty(get_post('amount'))
			&& !empty(get_post('scCs'))
			&& !empty(get_post('userMail'))
		) {
			// if the Country come as POST variable
			if (empty($_SESSION['SC_Variables']['sc_country'])) {
				$_SESSION['SC_Variables']['sc_country'] = get_post('country');
			}
			
			# Open Order
			$oo_endpoint_url = 'yes' == $_SESSION['SC_Variables']['test']
				? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;
			
			$oo_params = array(
				'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
				'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
				'clientRequestId'   => $_SESSION['SC_Variables']['cri1'],
				'amount'            => get_post('amount'),
				'currency'          => $_SESSION['SC_Variables']['currencyCode'],
				'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri1'])),
				'checksum'          => get_post('scCs'),
				'urlDetails'        => array(
					'successUrl'        => $_SESSION['SC_Variables']['other_urls'],
					'failureUrl'        => $_SESSION['SC_Variables']['other_urls'],
					'pendingUrl'        => $_SESSION['SC_Variables']['other_urls'],
					'notificationUrl'   => $_SESSION['SC_Variables']['notify_url'],
				),
				'deviceDetails'     => SC_HELPER::get_device_details(),
				'userTokenId'       => get_post('userMail'),
				'billingAddress'    => array(
					'country' => get_post('country'),
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
							if (@$upo['paymentMethodName'] === @$pm['paymentMethod']) {
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
