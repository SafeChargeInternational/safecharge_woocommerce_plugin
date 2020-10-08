<?php

/*
 * Put all Constants here.
 *
 * 2018
 *
 * SafeCharge
 */

define('SC_GATEWAY_TITLE', 'SafeCharge');

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
