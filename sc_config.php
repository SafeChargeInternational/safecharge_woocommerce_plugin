<?php

/* 
 * Put all Constants here
 */

define('SC_GATEWAY_TITLE', 'SafeCharge');

// URLs for session token
define('SC_LIVE_SESSION_TOKEN_URL', 'https://secure.safecharge.com/ppp/api/v1/getSessionToken.do');
if(in_array(@$_SERVER['HTTP_HOST'], array('woocommerce-test.gw-4u.com', 'wordpress.mdev'))) {
//    define('SC_TEST_SESSION_TOKEN_URL', 'https://192.168.103.237/ppp/api/getSessionToken.do');
    define('SC_TEST_SESSION_TOKEN_URL', 'https://apmtest.gate2shop.com/ppp/api/getSessionToken.do');
}
else {
    define('SC_TEST_SESSION_TOKEN_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getSessionToken.do');
}

// get merchant payment methods URLs for REST API
define('SC_LIVE_REST_PAYMENT_METHODS_URL', 'https://secure.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
if(in_array(@$_SERVER['HTTP_HOST'], array('woocommerce-test.gw-4u.com', 'wordpress.mdev'))) {
//    define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://192.168.103.237/ppp/api/getMerchantPaymentMethods.do');
    define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://apmtest.gate2shop.com/ppp/api/getMerchantPaymentMethods.do');
}
else {
    define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
}

// refund REST URLs
define('SC_LIVE_REFUND_URL', 'https://secure.safecharge.com/ppp/api/v1/refundTransaction.do');
if(in_array(@$_SERVER['HTTP_HOST'], array('woocommerce-test.gw-4u.com', 'wordpress.mdev'))) {
//    define('SC_TEST_REFUND_URL', 'https://192.168.103.237/ppp/api/v1/refundTransaction.do');
    define('SC_TEST_REFUND_URL', 'https://apmtest.gate2shop.com/ppp/api/v1/refundTransaction.do');
}
else {
    define('SC_TEST_REFUND_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do');
}

// payment URLs
define('SC_LIVE_PAYMENT_URL', 'https://secure.safecharge.com/ppp/api/v1/paymentAPM.do');
if(in_array(@$_SERVER['HTTP_HOST'], array('woocommerce-test.gw-4u.com', 'wordpress.mdev'))) {
//    define('SC_TEST_PAYMENT_URL', 'https://192.168.103.237/ppp/api/paymentAPM.do');
    define('SC_TEST_PAYMENT_URL', 'https://apmtest.gate2shop.com/ppp/api/paymentAPM.do');
}
else {
    define('SC_TEST_PAYMENT_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/paymentAPM.do');
}

// Cashier payments URLs
define('SC_LIVE_CASHIER_URL', 'https://secure.safecharge.com/ppp/purchase.do');
if(in_array(@$_SERVER['HTTP_HOST'], array('woocommerce-test.gw-4u.com', 'wordpress.mdev'))) {
//    define('SC_TEST_CASHIER_URL', 'https://192.168.103.237/ppp/purchase.do');
    define('SC_TEST_CASHIER_URL', 'https://apmtest.gate2shop.com/ppp/purchase.do');
}
else {
    define('SC_TEST_CASHIER_URL', 'https://ppp-test.safecharge.com/ppp/purchase.do');
}

// user CPanel URLs
define('SC_LIVE_CPANEL_URL', 'cpanel.safecharge.com');
define('SC_TEST_CPANEL_URL', 'sandbox.safecharge.com');

# OPTIONAL CONSTANTS FOR WC

// some keys for order metadata, we make them hiden when starts with underscore
define('SC_AUTH_CODE_KEY', '_authCode');
define('SC_GW_TRANS_ID_KEY', '_relatedTransactionId');
define('SC_LOG_FILE_PATH', dirname( __FILE__ ). DIRECTORY_SEPARATOR . 'logs'. DIRECTORY_SEPARATOR. date("Y-m-d"). '.txt');

// common notify URL for the WC plugin, set parameter by the api we use
 if(
    (isset($_SERVER["HTTPS"]) && !empty($_SERVER["HTTPS"]) && strtolower ($_SERVER['HTTPS']) != 'off')
    || (isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTPS/') == 0)
) {
    define('SC_NOTIFY_URL', 'https://'. $_SERVER['HTTP_HOST'] . '/?wc-api=');
}
elseif(isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTP/') == 0) {
    define('SC_NOTIFY_URL', 'http://'. $_SERVER['HTTP_HOST'] . '/?wc-api=');
}

// use it for creating logs
define('SC_DEBUG', true);
