<?php

/**
 * Work with Ajax.
 *
 * 2018
 *
 * @author SafeCharge
 */

require_once 'sc_config.php';

global $session;

$sc_request = isset($_REQUEST) ? $sc_request : [];
$sc_post = isset($_POST) ? $_POST : [];

// The following fileds are MANDATORY for success
if (
    isset(
        $sc_server['HTTP_X_REQUESTED_WITH']
        ,$session['SC_Variables']['merchantId']
        ,$session['SC_Variables']['merchantSiteId']
        ,$session['SC_Variables']['payment_api']
        ,$session['SC_Variables']['test']
    )
    && $sc_server['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
    && !empty($session['SC_Variables']['merchantId'])
    && !empty($session['SC_Variables']['merchantSiteId'])
    && in_array($session['SC_Variables']['payment_api'], array('cashier', 'rest'), true)
) {
    // when enable or disable SC Checkout
    if (
        isset($sc_post['enableDisableSCCheckout'])
        && in_array($sc_post['enableDisableSCCheckout'], array('enable', 'disable'), true)
    ) {
        require dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-includes/plugin.php';
        
        if ($sc_post['enableDisableSCCheckout'] === 'enable') {
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
    
    require_once 'SC_REST_API.php';
    
    // if there is no webMasterId in the session get it from the post
    if (
        (!isset($session['SC_Variables']['webMasterId']) || !$session['SC_Variables']['webMasterId'])
        && isset($sc_post['woVersion'])
        && $sc_post['woVersion']
    ) {
        $session['SC_Variables']['webMasterId'] = 'WoCommerce ' . $sc_post['woVersion'];
    }
    
    // when merchant cancel the order via Void button
    if (isset($sc_post['cancelOrder']) && $sc_post['cancelOrder'] === 1) {
        SC_REST_API::void_and_settle_order($session['SC_Variables'], 'void', true);
        unset($session['SC_Variables']);
        exit;
    }

    // when merchant settle the order via Settle button
    if (isset($sc_post['settleOrder']) && $sc_post['settleOrder'] === 1) {
        SC_REST_API::void_and_settle_order($session['SC_Variables'], 'settle', true);
        unset($session['SC_Variables']);
        exit;
    }
    
    if ($session['SC_Variables']['payment_api'] === 'rest') {
        // when we want Session Token
        if (isset($sc_post['needST']) && $sc_post['needST'] === 1) {
            SC_REST_API::get_session_token($session['SC_Variables'], true);
        }
        // when we want APMs
        elseif (isset($sc_post['country']) && $sc_post['country'] !== '') {
            // if the Country come as POST variable
            if (empty($session['SC_Variables']['sc_country'])) {
                $session['SC_Variables']['sc_country'] = $sc_post['country'];
            }

            SC_REST_API::get_rest_apms($session['SC_Variables'], true);
        }
        
        exit;
    }
    // here we no need APMs
    else {
        echo json_encode(array(
            'status' => 2,
            'msg' => $session['SC_Variables']['payment_api'] !== 'rest'
                ? 'You are using Cashier API. APMs are not available with it.'
                    : 'Missing some of conditions to using REST API.'
        ));
        exit;
    }
} elseif (
    isset($sc_server['HTTP_X_REQUESTED_WITH'])
    && $sc_server['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
    // don't come here when try to refund!
    && isset($sc_request['action'])
    && $sc_request['action'] !== 'woocommerce_refund_line_items'
) {
    echo json_encode(array(
        'status' => 2,
        'msg' => $session['SC_Variables']['payment_api'] !== 'rest'
            ? 'You are using Cashier API. APMs are not available with it.' : 'Missing some of conditions to using REST API.'
    ));
    exit;
}

echo json_encode(array('status' => 0));
exit;
