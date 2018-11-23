<?php
/*
Plugin Name: SafeCharge WooCommerce PlugIn
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.5
Author: SafeCharge
Author URI:http://safecharge.com
*/

if(!defined('ABSPATH')) {
    $die = file_get_contents(dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'die.html');
    echo $die;
    die;
}

require_once 'sc_config.php';

add_action('plugins_loaded', 'woocommerce_sc_init', 0);

function woocommerce_sc_init()
{
    if(!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    require_once 'WC_SC.php';
 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway' );
	add_action('init', 'sc_enqueue');
	add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
    add_action('woocommerce_create_refund', 'sc_create_refund');
    // Check checkout for selected apm ONLY when payment api is REST
    add_action( 'woocommerce_checkout_process', 'sc_check_checkout_apm', 20 );
    // add void button to completed orders
    add_action( 'woocommerce_order_item_add_action_buttons', 'sc_add_void_button');
}

/**
* Add the Gateway to WooCommerce
**/
function woocommerce_add_sc_gateway($methods)
{
    $methods[] = 'WC_SC';
    return $methods;
}

// first method we come in
function sc_enqueue($hook)
{
    # DMNs catch
    if(isset($_REQUEST['wc-api']) && !empty($_REQUEST['wc-api'])) {
        /* Cashier DMN
         * Skip order status update if currentlly received status is 'pending' and curent order status is 'completed'.
         * For the rest of the cases the status should be updated. 
        */
        if(
            strtolower($_REQUEST['wc-api']) == 'wc_gateway_sc'
            && isset($_REQUEST['Status'], $_REQUEST['invoice_id'])
            && !empty($_REQUEST['Status'])
            && !empty($_REQUEST['invoice_id'])
        ) {
            $arr = explode("_", $_REQUEST['invoice_id']);

            if(is_array($arr) && $arr) {
                $order_id  = $arr[0];
                $order = new WC_Order($order_id);

                if($order_id && $order && strtolower($_REQUEST['Status']) == 'pending') {
                    $order_status = strtolower($order->get_status());

                    if ($order_status != 'completed') {
                        $order->set_status($_REQUEST['Status']);
                    }
                }
            }
        }
        // REST API DMN
        elseif(strtolower($_REQUEST['wc-api']) == 'rest') {
            // catch Void 
            if(
                isset($_REQUEST['action'], $_REQUEST['clientRequestId'], $_REQUEST['Status'])
                && $_REQUEST['action'] == 'void'
                && !empty($_REQUEST['clientRequestId'])
                && !empty($_REQUEST['Status'])
                && is_numeric($_REQUEST['clientRequestId'])
            ) {
                $order = new WC_Order($_REQUEST['clientRequestId']);
                $order_status = strtolower($order->get_status());
                
                // change order status to canceld
                if(
                    $_REQUEST['Status'] == 1
                    && $order_status != 'canceled'
                    && $order_status != 'cancelled'
                ) {
                    $order->add_order_note(__('Your Order #' . $_REQUEST['clientRequestId'] . ' was canceld.', 'sc'));
                    $order->set_status('cancelled');
                    $order->save();
                }
                elseif($_REQUEST['Status'] == 2) {
                    $order -> add_order_note(__('Your Order #' . $_REQUEST['clientRequestId'] . ' was not canceld!', 'sc'));
                    $order->save();
                }
            }
        }
    }
    # DMNs catch END
    
    # load external files
    $plugin_dir = basename(dirname(__FILE__));
   
    // main JS, use min on production
    if(!defined('WP_DEBUG') || WP_DEBUG === false) {
        wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.min.js', array('jquery') );
    }
    else {
        wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.js', array('jquery') );
    }
    
    wp_localize_script(
        'sc_js_script',
        'myAjax',
        array(
            'ajaxurl' => WP_PLUGIN_URL . '/' . $plugin_dir .'/sc_ajax.php',
        )
    );  
    wp_enqueue_script( 'sc_js_script' );
    
    // novo style
    wp_register_style ('novo_style', WP_PLUGIN_URL. '/'. $plugin_dir. '/css/novo.css', '' , '', 'all' );
    wp_enqueue_style( 'novo_style' );
    
    // the Tokenization script
    wp_register_script("sc_token_js", 'https://cdn.safecharge.com/js/v1/safecharge.js', array('jquery') );
    wp_enqueue_script( 'sc_token_js' );
    # load external files END
}

// show final payment text
function sc_show_final_text()
{
    global $woocommerce;
    $msg = __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
    
    // REST API
    if(isset($_REQUEST['wc-api']) && strtolower($_REQUEST['wc-api']) == 'wc_sc_rest') {
        if ( strtolower($_REQUEST['status']) == 'failed' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        elseif(strtolower($_REQUEST['status']) == 'success') {
            $woocommerce -> cart -> empty_cart();
        }
        else {
            $woocommerce -> cart -> empty_cart();
        }
    }
    // Cashier
    elseif(@$_REQUEST['invoice_id'] && @$_REQUEST['ppp_status']) {
        $g = new WC_SC;
        $arr = explode("_",$_REQUEST['invoice_id']);
        $order_id  = $arr[0];
        $order = new WC_Order($order_id);
        
        if (
            strtolower($_REQUEST['ppp_status']) == 'failed'
            || strtolower($_REQUEST['ppp_status']) == 'fail'
        ) {
            $order -> add_order_note('User order failed.');
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        elseif ($g->checkAdvancedCheckSum()) {
            $transactionId = "TransactionId = " . (isset($_GET['TransactionID']) ? $_GET['TransactionID'] : "");
            $pppTransactionId = "; PPPTransactionId = " . (isset($_GET['PPP_TransactionID']) ? $_GET['PPP_TransactionID'] : "");

            $order->add_order_note("User returned from Safecharge Payment page; ". $transactionId. $pppTransactionId);
            $woocommerce -> cart -> empty_cart();
        }
        
        $order->save();
    }
    else {
        $woocommerce -> cart -> empty_cart();
        
        if ( strtolower(@$_REQUEST['status']) == 'failed' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
    }
    
    if(strtolower(@$_REQUEST['status']) == 'waiting') {
        $msg = __("Thank you. If you completed your payment, the order status will be updated soon", 'sc');
        $woocommerce -> cart -> empty_cart();
    }
    
    // clear session variables for the order
    if(isset($_SESSION['SC_Variables'])) {
        unset($_SESSION['SC_Variables']);
    }
    
    echo $msg;
}

function sc_create_refund()
{
    // get GW so we can use its settings
    require_once 'WC_SC.php';
    $gateway = new WC_SC();
    
    $notify_url = $gateway->set_notify_url();
    
    // get order refunds
    $order = new WC_Order( (int)$_REQUEST['order_id'] );
    $refunds = $order->get_refunds();
    
    if(!$refunds || !isset($refunds[0]) || !is_array($refunds[0]->data)) {
        $order -> add_order_note(__('There are no refunds data. If refund was made, delete it manually!', 'sc'));
        $order->save();
        wp_send_json_success();
    }
    
    $order_meta_data = array(
        'order_tr_id' => $order->get_meta(SC_GW_TRANS_ID_KEY),
        'auth_code' => $order->get_meta(SC_AUTH_CODE_KEY),
    );
    
    // call refund method
    require_once 'SC_REST_API.php';
    $sc_api = new SC_REST_API();
    
    // execute refund, the response must be array('msg' => 'some msg', 'new_order_status' => 'some status')
    $resp = $sc_api->refund_order(
        $gateway->settings
        ,$refunds[0]->data
        ,$order_meta_data
        ,get_woocommerce_currency()
        ,$notify_url . 'Rest'
    );
    
    $order -> add_order_note(__($resp['msg'], 'sc'));
    
    if(!empty($resp['new_order_status'])) {
        $order->set_status($resp['new_order_status']);
    }
    
    $order->save();
    wp_send_json_success();
}

function sc_check_checkout_apm()
{
    // if custom fields are empty stop checkout process displaying an error notice.
    if(
        isset($_SESSION['SC_Variables']['payment_api'])
        && $_SESSION['SC_Variables']['payment_api'] == 'rest'
        && empty($_POST['payment_method_sc'])
    ) {
        $notice = __( 'Please select '. SC_GATEWAY_TITLE .' payment method to continue!', 'sc' );
        wc_add_notice( '<strong>' . $notice . '</strong>', 'error' );
    }
}

function sc_add_void_button()
{
    $order = new WC_Order($_REQUEST['post']);
    $order_status = strtolower($order->get_status());
    
    if($order_status == 'completed') {
        require_once 'WC_SC.php';
        require_once 'SC_REST_API.php';
        require_once 'SC_Versions_Resolver.php';
        
        $wc_sc = new WC_SC();
        $sc_api = new SC_REST_API();
        $sc_v_res = new SC_Versions_Resolver();
        
        $time = date('YmdHis', time());
        $order_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);
        $notify_url = $wc_sc->set_notify_url();
        
        $_SESSION['SC_Variables'] = array(
            'merchantId'            => $wc_sc->settings['merchantId'],
            'merchantSiteId'        => $wc_sc->settings['merchantSiteId'],
            'clientRequestId'       => $time . '_' . $order_tr_id,
            'clientUniqueId'        => $order_tr_id,
            'amount'                => $sc_v_res->get_order_data($order, 'order_total'),
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $order_tr_id,
            'authCode'              => $order->get_meta(SC_AUTH_CODE_KEY),
            
            'urlDetails'            => array('notificationUrl' => $notify_url
                .'Rest&action=void&clientRequestId=' . $_REQUEST['post']),
            
            'timeStamp'             => $time,
            // optional fields for sc_ajax.php
            'test'                  => $wc_sc->settings['test'],
            'payment_api'           => 'rest',
            'save_logs'             => $wc_sc->settings['save_logs'],
        );
        
        $checksum = hash(
            $wc_sc->settings['hash_type'],
            $wc_sc->settings['merchantId'] . $wc_sc->settings['merchantSiteId']
                .($time . '_' . $order_tr_id) . $order_tr_id . $_SESSION['SC_Variables']['amount']
                .$_SESSION['SC_Variables']['currency'] . $order_tr_id
                .$_SESSION['SC_Variables']['authCode']
                .$_SESSION['SC_Variables']['urlDetails']['notificationUrl']
                .$time . $wc_sc->settings['secret']
        );
        
        $_SESSION['SC_Variables']['checksum'] = $checksum;
        
        echo
            ' <button type="button" onclick="cancelOrder(\''
            . __( 'Are you sure, you want to Cancel Order #'. $_REQUEST['post'] .'?', 'sc' ) .'\', '
            . $_REQUEST['post'] .')" class="button generate-items">'. __( 'Void', 'sc' ) .'</button>'
            .'<div id="custom_loader" class="blockUI blockOverlay"></div>';
    }
}