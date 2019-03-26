<?php
/*
Plugin Name: SafeCharge WooCommerce PlugIn
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.8.2
Author: SafeCharge
Author URI: http://safecharge.com
*/

if(!defined('ABSPATH')) {
    $die = file_get_contents(dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'die.html');
    echo $die;
    die;
}

require_once 'sc_config.php';

$wc_sc = null;

add_action('plugins_loaded', 'woocommerce_sc_init', 0);

function woocommerce_sc_init()
{
    if(!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    require_once 'WC_SC.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    
    global $wc_sc;
    $wc_sc = new WC_SC();
    
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway' );
	add_action('init', 'sc_enqueue');
    // add void and/or settle buttons to completed orders, we check in the method is this order made via SC paygate
    add_action( 'woocommerce_order_item_add_action_buttons', 'sc_add_buttons');
    // Refun hook, when create refund from WC, we do not want this to be activeted from DMN,
    // we check in the method is this order made via SC paygate
    add_action('woocommerce_create_refund', 'sc_create_refund');
    
    // those actions are valid only when the plugin is enabled
    if($wc_sc->settings['enabled'] == 'yes') {
        // for WPML plugin
        if(
            is_plugin_active('sitepress-multilingual-cms' . DIRECTORY_SEPARATOR . 'sitepress.php')
            && $wc_sc->settings['use_wpml_thanks_page'] == 'yes'
        ) {
            add_filter( 'woocommerce_get_checkout_order_received_url', 'sc_wpml_thank_you_page', 10, 2 );
        }

        // if the merchant needs to rewrite the DMN URL
        if(isset($wc_sc->settings['rewrite_dmn']) && $wc_sc->settings['rewrite_dmn'] == 'yes') {
            add_action('template_redirect', 'sc_rewrite_return_url'); // need WC_SC
        }
    }
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
    global $wc_sc;
        
    # DMNs catch
    if(isset($_REQUEST['wc-api']) && $_REQUEST['wc-api'] == 'sc_listener') {
        $wc_sc->process_dmns();
    }
    
    # load external files
    $plugin_dir = basename(dirname(__FILE__));
   
    // main JS
    wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.js', array('jquery') );
    
    wp_localize_script(
        'sc_js_script',
        'myAjax',
        array(
            'ajaxurl' => WP_PLUGIN_URL . '/' . $plugin_dir .'/sc_ajax.php',
        )
    );  
    wp_enqueue_script( 'sc_js_script' );
    // main JS END
    
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
    global $wc_sc;
    
    $msg = __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
   
    // Cashier
    if(@$_REQUEST['invoice_id'] && @$_REQUEST['ppp_status'] && $wc_sc->checkAdvancedCheckSum()) {
        try {
            $arr = explode("_",$_REQUEST['invoice_id']);
            $order_id  = $arr[0];
            $order = new WC_Order($order_id);

            if (strtolower($_REQUEST['ppp_status']) == 'fail') {
                $order->add_order_note('User order failed.');
                $order->update_status('failed', 'User order failed.');

                $msg = __("Your payment failed. Please, try again.", 'sc');
            }
            else {
                $transactionId = "TransactionId = "
                    . (isset($_REQUEST['TransactionID']) ? $_REQUEST['TransactionID'] : "");

                $pppTransactionId = "; PPPTransactionId = "
                    . (isset($_REQUEST['PPP_TransactionID']) ? $_REQUEST['PPP_TransactionID'] : "");

                $order->add_order_note("User returned from Safecharge Payment page; ". $transactionId. $pppTransactionId);
                $woocommerce -> cart -> empty_cart();
            }
            
            $order->save();
        }
        catch (Exception $ex) {
            create_log($ex->getMessage(), 'Cashier handle exception error: ');
        }
        
    }
    // REST API tahnk you page handler
    else{
        if ( strtolower(@$_REQUEST['Status']) == 'fail' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        else {
            $woocommerce -> cart -> empty_cart();
        }
    }
    
    // clear session variables for the order
    if(isset($_SESSION['SC_Variables'])) {
        unset($_SESSION['SC_Variables']);
    }
    // prevent generate_sc_form() to render form twice
    if(isset($_SESSION['SC_CASHIER_FORM_RENDED'])) {
        unset($_SESSION['SC_CASHIER_FORM_RENDED']);
    }
    
    // when we use iframe
    if(@$_REQUEST['use_iframe'] == 1 && $wc_sc->checkAdvancedCheckSum()) {
        echo
            '<script type="text/javascript">'
                .'window.top.location.href = window.location.href.replace("use_iframe=1&", "");'
            .'</script>';
        exit;
    }
    
    echo $msg;
}

function sc_create_refund()
{
    if(!isset($_REQUEST['wc-api'])) {
        // get GW so we can use its settings
        global $wc_sc;

        $notify_url = $wc_sc->set_notify_url();

        // get order refunds
        if(isset($_REQUEST['order_id']) && $_REQUEST['order_id'] != '') {
            try {
                $order = new WC_Order( (int)$_REQUEST['order_id'] );
                
                // to show SC buttons we must be sure the order is paid via SC Paygate
                if(
                    !$order->get_meta(SC_AUTH_CODE_KEY)
                    && !$order->get_meta(SC_GW_TRANS_ID_KEY)
                    && !$order->get_meta(SC_GW_P3D_RESP_TR_TYPE)
                ) {
                    echo json_encode(array('status' => 0));
                    exit;
                }
                
                $refunds = $order->get_refunds();
            }
            catch (Exception $ex) {
                create_log($ex->getMessage(), 'sc_create_refund() Exception: ');
                wp_send_json_success();
            }
            
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

            // execute refund, the response must be array('msg' => 'some msg', 'new_order_status' => 'some status')
            $resp = SC_REST_API::refund_order(
                $wc_sc->settings
                ,$refunds[0]->data
                ,$order_meta_data
                ,get_woocommerce_currency()
                ,$notify_url . 'sc_listener&action=refund&order_id=' . $_REQUEST['order_id']
            );
            
            $refund_url = SC_TEST_REFUND_URL;
            $cpanel_url = SC_TEST_CPANEL_URL;

            if($wc_sc->settings['test'] == 'no') {
                $refund_url = SC_LIVE_REFUND_URL;
                $cpanel_url = SC_LIVE_CPANEL_URL;
            }
            
            $msg = '';
            $error_note = 'Please manually delete request Refund #'
                .$refunds[0]->data['id'].' form the order or login into <i>'. $cpanel_url
                .'</i> and refund Transaction ID '.$order_meta_data['order_tr_id'];

            if($json_arr === false) {
                $msg = 'The REST API retun false. ' . $error_note;
                
                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }

            if(!is_array($json_arr)) {
                parse_str($resp, $json_arr);
            }

            if(!is_array($json_arr)) {
                $msg = 'Invalid API response. ' . $error_note;
                
                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }
            
            // in case we have message but without status
            if(!isset($json_arr['status']) && isset($json_arr['msg'])) {
                // save response message in the History
                $msg = 'Request Refund #' . $refunds[0]->data['id'] . ' problem: ' . $json_arr['msg'];

                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }

            // the status of the request is ERROR
            if(@$json_arr['status'] == 'ERROR') {
                $msg = 'Request ERROR - "' . $json_arr['reason'] .'" '. $error_note;
                
                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }

            // the status of the request is SUCCESS, check the transaction status
            if(@$json_arr['transactionStatus'] == 'ERROR') {
                if(isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
                    $msg = $json_arr['gwErrorReason'];
                }
                elseif(isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
                    $msg = $json_arr['paymentMethodErrorReason'];
                }
                else {
                    $msg = 'Transaction error';
                }
                
                $msg .= '. ' .$error_note;
                
                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }

            if(@$json_arr['transactionStatus'] == 'DECLINED') {
                $msg = 'The refun was declined. ' .$error_note;
                
                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }

            if(@$json_arr['transactionStatus'] == 'APPROVED') {
                $msg = 'Your request - Refund #' . $refunds[0]->data['id'] . ', was successful.';
                
                $order->add_order_note(__($msg, 'sc'));
                $order->save();
                wp_send_json_success();
            }

            $msg = 'The status of request - Refund #' . $refunds[0]->data['id'] . ', is UNKONOWN.';
                
            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            wp_send_json_success();
        }
    }
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

function sc_add_buttons()
{
    try {
        $order = new WC_Order($_REQUEST['post']);
        $order_status = strtolower($order->get_status());
    }
    catch (Exception $ex) {
        echo '<script type="text/javascript">console.error("'
            . $ex->getMessage() . '")</script>';
        exit;
    }
    
    // to show SC buttons we must be sure the order is paid via SC Paygate
    if(
        !$order->get_meta(SC_AUTH_CODE_KEY)
        && !$order->get_meta(SC_GW_TRANS_ID_KEY)
        && !$order->get_meta(SC_GW_P3D_RESP_TR_TYPE)
    ) {
        exit;
    }
    
    if($order_status == 'completed'|| $order_status == 'pending') {
        require_once 'SC_Versions_Resolver.php';
        global $wc_sc;

        $time = date('YmdHis', time());
        $order_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);
        $notify_url = $wc_sc->set_notify_url();
        $buttons_html = '';

        // common data
        $_SESSION['SC_Variables'] = array(
            'merchantId'            => $wc_sc->settings['merchantId'],
            'merchantSiteId'        => $wc_sc->settings['merchantSiteId'],
            'clientRequestId'       => $time . '_' . $order_tr_id,
            'clientUniqueId'        => $_REQUEST['post'],
        //    'amount'                => SC_Versions_Resolver::get_order_data($order, 'order_total'),
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
                'notificationUrl'       => $notify_url . 'sc_listener&clientRequestId=' . $_REQUEST['post']
            ),
        );
        
        // Show VOID button only if the order is completed.
        if($order_status == 'completed') {
            $buttons_html .= 
                ' <button type="button" onclick="settleAndCancelOrder(\''
                . __( 'Are you sure, you want to Cancel Order #'. $_REQUEST['post'] .'?', 'sc' ) .'\', '
                . '\'void\', ' . $_REQUEST['post'] .')" class="button generate-items">'
                . __( 'Void', 'sc' ) .'</button>';
        }
        
        // show SETTLE button ONLY if setting transaction_type IS Auth AND P3D resonse transaction_type IS Auth
        if(
            $order_status == 'pending'
            && $order->get_meta(SC_GW_P3D_RESP_TR_TYPE) == 'Auth'
            && $wc_sc->settings['transaction_type'] == 'Auth'
        ) {
            $buttons_html .=
                ' <button type="button" onclick="settleAndCancelOrder(\''
                . __( 'Are you sure, you want to Settle Order #'. $_REQUEST['post'] .'?', 'sc' ) .'\', '
                . '\'settle\', ' . $_REQUEST['post'] .')" class="button generate-items">'
                . __( 'Settle', 'sc' ) .'</button>';
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
        echo $buttons_html . '<div id="custom_loader" class="blockUI blockOverlay"></div>';
    }
}

/**
 * Function sc_rewrite_return_url
 * When user have problem with white spaces in the URL, it have option to
 * rewrite the return URL and redirect to new one.
 * 
 * @global WC_SC $wc_sc
 */
function sc_rewrite_return_url()
{
    if(
        isset($_REQUEST['ppp_status']) && $_REQUEST['ppp_status'] != ''
        && (!isset($_REQUEST['wc_sc_redirected']) || $_REQUEST['wc_sc_redirected'] == 0)
    ) {
        $new_url = '';
        $host = (strpos($_SERVER["SERVER_PROTOCOL"], 'HTTP/') !== false ? 'http' : 'https')
            . '://' . $_SERVER['HTTP_HOST'] . current(explode('?', $_SERVER['REQUEST_URI']));
        
        if(isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '') {
            $new_url = preg_replace('/\+|\s|\%20/', '_', $_SERVER['QUERY_STRING']);
            // put flag the URL was rewrited
            $new_url .= '&wc_sc_redirected=1';
            
            wp_redirect( $host . '?' . $new_url );
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
function sc_wpml_thank_you_page( $order_received_url, $order )
{
    $lang_code = get_post_meta( $order->id, 'wpml_language', true );
    $order_received_url = apply_filters( 'wpml_permalink', $order_received_url , $lang_code );
    
    create_log($order_received_url, 'sc_wpml_thank_you_page: ');
 
    return $order_received_url;
}

/**
* Function create_log
* Create logs. You MUST have defined SC_LOG_FILE_PATH const,
* holding the full path to the log file.
* 
* @param mixed $data
* @param string $title - title of the printed log
*/
function create_log($data, $title = '')
{
   if(
       !isset($_SESSION['SC_Variables']['save_logs'])
       || $_SESSION['SC_Variables']['save_logs'] == 'no'
       || $_SESSION['SC_Variables']['save_logs'] === null
   ) {
       return;
   }

   $d = '';

   if(is_array($data)) {
        foreach($data as $k => $dd) {
            if(is_array($dd)) {
                if(isset($dd['cardData'], $dd['cardData']['CVV'])) {
                    $data[$k]['cardData']['CVV'] = md5($dd['cardData']['CVV']);
                }
                if(isset($dd['cardData'], $dd['cardData']['cardHolderName'])) {
                    $data[$k]['cardData']['cardHolderName'] = md5($dd['cardData']['cardHolderName']);
                }
            }
        }

        $d = print_r($data, true);
    }
    elseif(is_object($data)) {
        $d = print_r($data, true);
    }
   elseif(is_bool($data)) {
       $d = $data ? 'true' : 'false';
   }
   else {
       $d = $data;
   }

   if(!empty($title)) {
       $d = $title . "\r\n" . $d;
   }

   if(defined('SC_LOG_FILE_PATH')) {
       try {
           file_put_contents(SC_LOG_FILE_PATH, date('H:i:s') . ': ' . $d . "\r\n"."\r\n", FILE_APPEND);
       }
       catch (Exception $exc) {
           echo
               '<script>'
                   .'error.log("Log file was not created, by reason: '.$exc.'");'
                   .'console.log("Log file was not created, by reason: '.$data.'");'
               .'</script>';
       }
   }
}