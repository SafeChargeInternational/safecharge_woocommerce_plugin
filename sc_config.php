<?php

/*
 * Put all Constants here.
 *
 * 2018
 *
 * SafeCharge
 */

if(!isset($sc_server)) {
    $sc_server = filter_input_array(INPUT_SERVER, $_SERVER);
}

define('SC_GATEWAY_TITLE', 'SafeCharge');

// list of endpoints Test URLs
$sc_test_endpoint_host = 'https://ppp-test.safecharge.com/ppp/api/v1';

// URLs for session token
define('SC_LIVE_SESSION_TOKEN_URL', 'https://secure.safecharge.com/ppp/api/v1/getSessionToken.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_SESSION_TOKEN_URL', $sc_test_endpoint_host . '/getSessionToken.do');
} else {
    define('SC_TEST_SESSION_TOKEN_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getSessionToken.do');
}

// get merchant payment methods URLs for REST API
define('SC_LIVE_REST_PAYMENT_METHODS_URL', 'https://secure.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_REST_PAYMENT_METHODS_URL', $sc_test_endpoint_host . '/getMerchantPaymentMethods.do');
} else {
    define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
}

// refund REST URLs
define('SC_LIVE_REFUND_URL', 'https://secure.safecharge.com/ppp/api/v1/refundTransaction.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_REFUND_URL', $sc_test_endpoint_host . '/refundTransaction.do');
} else {
    define('SC_TEST_REFUND_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do');
}

// void URLs
define('SC_LIVE_VOID_URL', 'https://secure.safecharge.com/ppp/api/v1/voidTransaction.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_VOID_URL', $sc_test_endpoint_host . '/voidTransaction.do');
} else {
    define('SC_TEST_VOID_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/voidTransaction.do');
}

// payment URLs
define('SC_LIVE_PAYMENT_URL', 'https://secure.safecharge.com/ppp/api/v1/paymentAPM.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_PAYMENT_URL', $sc_test_endpoint_host . '/paymentAPM.do');
} else {
    define('SC_TEST_PAYMENT_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/paymentAPM.do');
}

// Cashier payments URLs
define('SC_LIVE_CASHIER_URL', 'https://secure.safecharge.com/ppp/purchase.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_CASHIER_URL', 'https://ppp-test.safecharge.com/ppp/purchase.do');
} else {
    define('SC_TEST_CASHIER_URL', 'https://ppp-test.safecharge.com/ppp/purchase.do');
}

// dynamic 3D payment
define('SC_LIVE_D3D_URL', 'https://secure.safecharge.com/ppp/api/v1/dynamic3D.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_D3D_URL', $sc_test_endpoint_host . '/dynamic3D.do');
} else {
    define('SC_TEST_D3D_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/dynamic3D.do');
}

// payment 3D
define('SC_LIVE_P3D_URL', 'https://secure.safecharge.com/ppp/api/v1/payment3D.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_P3D_URL', $sc_test_endpoint_host . '/payment3D.do');
} else {
    define('SC_TEST_P3D_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/payment3D.do');
}

// payment with CC (cards) - not used at the moment
define('SC_LIVE_PAYMENT_CC_URL', 'https://secure.safecharge.com/ppp/api/v1/paymentCC.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_PAYMENT_CC_URL', $sc_test_endpoint_host . '/paymentCC.do');
} else {
    define('SC_TEST_PAYMENT_CC_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/paymentCC.do');
}

// Settle the Order
define('SC_LIVE_SETTLE_URL', 'https://secure.safecharge.com/ppp/api/v1/settleTransaction.do');
if (isset($sc_server['HTTP_HOST']) && strpos($sc_server['HTTP_HOST'], 'gw-4u.com') !== false) {
    define('SC_TEST_SETTLE_URL', $sc_test_endpoint_host . '/settleTransaction.do');
} else {
    define('SC_TEST_SETTLE_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/settleTransaction.do');
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
define('SC_DEVICES_TYPES', json_encode(array('tablet', 'mobile', 'tv', 'windows', 'linux')));

// list of devices OSs
define('SC_DEVICES_OS', json_encode(array('android', 'windows', 'linux', 'mac os')));

// some keys for order metadata, we make them hiden when starts with underscore
define('SC_AUTH_CODE_KEY', '_authCode');
define('SC_GW_TRANS_ID_KEY', '_relatedTransactionId');
define('SC_GW_P3D_RESP_TR_TYPE', '_p3dRespTransactionType');

define('SC_LOG_FILE_PATH', dirname(__FILE__). DIRECTORY_SEPARATOR . 'logs'. DIRECTORY_SEPARATOR. date("Y-m-d"). '.txt');
