<?php

/*
 * Put all Constants here.
 *
 * 2018
 *
 * SafeCharge
 */

define('SC_GATEWAY_TITLE', 'SafeCharge');

// list of endpoints Test URLs
//$sc_test_endpoint_host = 'http://192.168.103.237:8080';
//$sc_test_endpoint_host = 'https://192.168.103.237';
//$sc_test_endpoint_host = 'https://apmtest.gate2shop.com';
$sc_test_endpoint_host = 'https://ppp-test.safecharge.com/ppp/api/v1';
//$sc_test_endpoint_host = 'https://srv-bsf-devppptrunk.gw-4u.com/ppp/api';

if (!empty($_SERVER['HTTP_HOST'])) {
	// URLs for session token
	define('SC_LIVE_SESSION_TOKEN_URL', 'https://secure.safecharge.com/ppp/api/v1/getSessionToken.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_SESSION_TOKEN_URL', $sc_test_endpoint_host . '/getSessionToken.do');
	} else {
		define('SC_TEST_SESSION_TOKEN_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getSessionToken.do');
	}

	// get merchant payment methods URLs for REST API
	define('SC_LIVE_REST_PAYMENT_METHODS_URL', 'https://secure.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_REST_PAYMENT_METHODS_URL', $sc_test_endpoint_host . '/getMerchantPaymentMethods.do');
	} else {
		define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
	}

	// get user UPOs
	define('SC_LIVE_USER_UPOS_URL', 'https://secure.safecharge.com/ppp/api/v1/getUserUPOs.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_USER_UPOS_URL', $sc_test_endpoint_host . '/getUserUPOs.do');
	} else {
		define('SC_TEST_USER_UPOS_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getUserUPOs.do');
	}

	// refund REST URLs
	define('SC_LIVE_REFUND_URL', 'https://secure.safecharge.com/ppp/api/v1/refundTransaction.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_REFUND_URL', $sc_test_endpoint_host . '/refundTransaction.do');
	} else {
		define('SC_TEST_REFUND_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do');
	}

	// void URLs
	define('SC_LIVE_VOID_URL', 'https://secure.safecharge.com/ppp/api/v1/voidTransaction.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_VOID_URL', $sc_test_endpoint_host . '/voidTransaction.do');
	} else {
		define('SC_TEST_VOID_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/voidTransaction.do');
	}

	// payment URLs
	define('SC_LIVE_PAYMENT_URL', 'https://secure.safecharge.com/ppp/api/v1/paymentAPM.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_PAYMENT_URL', $sc_test_endpoint_host . '/paymentAPM.do');
	} else {
		define('SC_TEST_PAYMENT_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/paymentAPM.do');
	}

	// Cashier payments URLs
	define('SC_LIVE_CASHIER_URL', 'https://secure.safecharge.com/ppp/purchase.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_CASHIER_URL', 'https://ppp-test.safecharge.com/ppp/purchase.do');
	} else {
		define('SC_TEST_CASHIER_URL', 'https://ppp-test.safecharge.com/ppp/purchase.do');
	}

	// dynamic 3D payment
	define('SC_LIVE_D3D_URL', 'https://secure.safecharge.com/ppp/api/v1/dynamic3D.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_D3D_URL', $sc_test_endpoint_host . '/dynamic3D.do');
	} else {
		define('SC_TEST_D3D_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/dynamic3D.do');
	}

	// payment 3D
	define('SC_LIVE_P3D_URL', 'https://secure.safecharge.com/ppp/api/v1/payment3D.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_P3D_URL', $sc_test_endpoint_host . '/payment3D.do');
	} else {
		define('SC_TEST_P3D_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/payment3D.do');
	}

	// payment with CC (cards) - not used at the moment
	define('SC_LIVE_PAYMENT_CC_URL', 'https://secure.safecharge.com/ppp/api/v1/paymentCC.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_PAYMENT_CC_URL', $sc_test_endpoint_host . '/paymentCC.do');
	} else {
		define('SC_TEST_PAYMENT_CC_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/paymentCC.do');
	}

	// Settle the Order
	define('SC_LIVE_SETTLE_URL', 'https://secure.safecharge.com/ppp/api/v1/settleTransaction.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_SETTLE_URL', $sc_test_endpoint_host . '/settleTransaction.do');
	} else {
		define('SC_TEST_SETTLE_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/settleTransaction.do');
	}

	// Open Order URL
	define('SC_LIVE_OPEN_ORDER_URL', 'https://secure.safecharge.com/ppp/api/v1/openOrder.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_OPEN_ORDER_URL', $sc_test_endpoint_host . '/openOrder.do');
	} else {
		define('SC_TEST_OPEN_ORDER_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/openOrder.do');
	}
	
	// new payment URL
	define('SC_LIVE_PAYMENT_NEW_URL', 'https://secure.safecharge.com/ppp/api/v1/payment.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_PAYMENT_NEW_URL', $sc_test_endpoint_host . '/payment.do');
	} else {
		define('SC_TEST_PAYMENT_NEW_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/payment.do');
	}
	
	// delete user UPOs
	define('SC_LIVE_DELETE_UPO_URL', 'https://secure.safecharge.com/ppp/api/v1/deleteUPO.do');
	if (strpos(sanitize_text_field($_SERVER['HTTP_HOST']), 'gw-4u.com') !== false) {
		define('SC_TEST_DELETE_UPO_URL', $sc_test_endpoint_host . '/deleteUPO.do');
	} else {
		define('SC_TEST_DELETE_UPO_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/deleteUPO.do');
	}
}

// user CPanel URLs
define('SC_LIVE_CPANEL_URL', 'cpanel.safecharge.com');
define('SC_TEST_CPANEL_URL', 'sandbox.safecharge.com');

// payment card methods - array of methods, converted to json
define('SC_PAYMENT_CARDS_METHODS', json_encode(array('cc_card')));

// list of devices
define('SC_DEVICES', json_encode(array('iphone', 'ipad', 'android', 'silk', 'blackberry', 'touch', 'linux', 'windows', 'mac')));

// list of browsers
define('SC_BROWSERS', json_encode(array('ucbrowser', 'firefox', 'chrome', 'opera', 'msie', 'edge', 'safari', 'blackberry', 'trident')));

// list of devices types
define('SC_DEVICES_TYPES', json_encode(array('macintosh', 'tablet', 'mobile', 'tv', 'windows', 'linux', 'tv', 'smarttv', 'googletv', 'appletv', 'hbbtv', 'pov_tv', 'netcast.tv', 'bluray')));

// list of devices OSs
define('SC_DEVICES_OS', json_encode(array('android', 'windows', 'linux', 'mac os')));

// some keys for order metadata, we make them hiden when starts with underscore
define('SC_AUTH_CODE_KEY', '_authCode');
define('SC_TRANS_ID', '_transactionId');
define('SC_RESP_TRANS_TYPE', '_transactionType');
define('SC_PAYMENT_METHOD', '_paymentMethod');
define('SC_ORDER_HAS_REFUND', '_scHasRefund');
define('SC_SOURCE_APPLICATION', 'WOOCOMMERCE_PLUGIN');
define('SC_LOGS_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);
