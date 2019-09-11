<?php
/*
Plugin Name: SafeCharge Payments
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.9.3
Author: SafeCharge
Author URI: http://safecharge.com
*/

defined('ABSPATH') || die;

require_once plugin_dir_path(__FILE__) . 'sc_config.php';

global $session;

$wc_sc          = null;
$sc_server     = filter_input_array(INPUT_SERVER, $_SERVER);
$sc_request    = isset($_REQUEST) ? $_REQUEST : [];
$sc_post       = get_post();

add_action('plugins_loaded', 'woocommerce_sc_init', 0);

function woocommerce_sc_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    require_once plugin_dir_path(__FILE__) . 'WC_SC.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    
    $wc_sc = new WC_SC();
    
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway');
    add_action('init', 'sc_enqueue');
    // replace the text at thank you page
    add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
    // eliminates the problem with different permalinks
    add_action('template_redirect', 'sc_iframe_redirect');
    
    // add void and/or settle buttons to completed orders, we check in the method is this order made via SC paygate
    add_action('woocommerce_order_item_add_action_buttons', 'sc_add_buttons');
    
    // those actions are valid only when the plugin is enabled
    if ($wc_sc->settings['enabled'] === 'yes') {
        // for WPML plugin
        if (
            is_plugin_active('sitepress-multilingual-cms' . DIRECTORY_SEPARATOR . 'sitepress.php')
            && $wc_sc->settings['use_wpml_thanks_page'] === 'yes'
        ) {
            add_filter('woocommerce_get_checkout_order_received_url', 'sc_wpml_thank_you_page', 10, 2);
        }

        // if the merchant needs to rewrite the DMN URL
        if (isset($wc_sc->settings['rewrite_dmn']) && $wc_sc->settings['rewrite_dmn'] === 'yes') {
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
    global $sc_request;
        
    # DMNs catch
    if (isset($sc_request['wc-api']) && $sc_request['wc-api'] === 'sc_listener') {
        $wc_sc->process_dmns();
    }
    
    # load external files
    $plugin_dir = basename(dirname(__FILE__));
    $url_path = get_site_url() . '/wp-content/plugins/' . $plugin_dir;
   
    // main JS
    wp_register_script("sc_js_script", $url_path . '/js/sc.js', array('jquery'));
    
    wp_localize_script(
        'sc_js_script',
        'myAjax',
        ['ajaxurl' => $url_path .'/sc_ajax.php']
    );
    wp_enqueue_script('sc_js_script');
    // main JS END
    
    // novo style
    wp_register_style('novo_style', $url_path . '/css/novo.css', '', '', 'all');
    wp_enqueue_style('novo_style');
    
    // the Tokenization script
    wp_register_script("sc_token_js", 'https://cdn.safecharge.com/js/v1/safecharge.js', array('jquery'));
    wp_enqueue_script('sc_token_js');
    # load external files END
}

// show final payment text
function sc_show_final_text()
{
    global $woocommerce;
    global $wc_sc;
    global $sc_request;
    global $session;
    
    $msg = __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
   
    // Cashier
    if (isset($sc_request['invoice_id'], $sc_request['ppp_status']) && $wc_sc->checkAdvancedCheckSum()) {
        try {
            $arr = explode("_", $sc_request['invoice_id']);
            $order_id  = $arr[0];
            $order = new WC_Order($order_id);

            if (strtolower($sc_request['ppp_status']) === 'fail') {
                $order->add_order_note('User order failed.');
                $order->update_status('failed', 'User order failed.');

                $msg = __("Your payment failed. Please, try again.", 'sc');
            } else {
                $transactionId = "TransactionId = "
                    . (isset($sc_request['TransactionID']) ? $sc_request['TransactionID'] : "");

                $pppTransactionId = "; PPPTransactionId = "
                    . (isset($sc_request['PPP_TransactionID']) ? $sc_request['PPP_TransactionID'] : "");

                $order->add_order_note("User returned from Safecharge Payment page; ". $transactionId. $pppTransactionId);
                $woocommerce->cart->empty_cart();
            }
            
            $order->save();
        } catch (Exception $ex) {
            create_log($ex->getMessage(), 'Cashier handle exception error: ');
        }
    }
    // REST API tahnk you page handler
    else {
        if (isset($sc_request['Status']) && strtolower($sc_request['Status']) === 'fail') {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        } else {
            $woocommerce->cart->empty_cart();
        }
    }
    
    // clear session variables for the order
    if (isset($session['SC_Variables'])) {
        unset($session['SC_Variables']);
    }
    // prevent generate_sc_form() to render form twice
    if (isset($session['SC_CASHIER_FORM_RENDED'])) {
        unset($session['SC_CASHIER_FORM_RENDED']);
    }
    
    return $msg;
}

function sc_iframe_redirect()
{
    global $sc_request;
    global $sc_server;
    
    if (!is_checkout()) {
        return;
    }
    
    if (!isset($sc_request['order-received'])) {
        $url_parts = explode('/', trim(wp_parse_url($sc_server['REQUEST_URI'], PHP_URL_PATH), '/'));
        
        if (!$url_parts || empty($url_parts)) {
            return;
        }
        
        if (!in_array('order-received', $url_parts, true)) {
            return;
        }
    }
    
    // when we use iframe
    if (isset($sc_request['use_iframe']) && $sc_request['use_iframe'] === 1) {
        echo
            '<table id="sc_pay_msg" style="border: 0px; cursor: wait; line-height: 32px; width: 100%;"><tr>'
                .'<td style="padding: 0px; border: 0px; width: 100px;">'
                    . '<img src="' . esc_url(get_site_url()) . '/wp-content/plugins/' . esc_html(basename(dirname(__FILE__))) . '/icons/loading.gif" style="width:100px; float:left; margin-right: 10px;" />'
                . '</td>'
                .'<td style="text-align: left; border: 0px;">'
                    . '<span>'. esc_html__('Thank you for your order. We are now redirecting you to '. SC_GATEWAY_TITLE .' Payment Gateway to make payment.', 'sc') .'</span>'
                . '</td>'
            .'</tr></table>'
            
            .'<script type="text/javascript">'
                . 'var scNewUrl = window.location.toLocaleString().replace("use_iframe=1&", "");'
                
                . 'parent.postMessage({'
                    . 'scAction: "scRedirect",'
                    . 'scUrl: scNewUrl'
                . '}, window.location.origin);'
            .'</script>';
        exit;
    }
}

function sc_check_checkout_apm()
{
    global $session;
    global $sc_post;
    
    // if custom fields are empty stop checkout process displaying an error notice.
    if (
        isset($session['SC_Variables']['payment_api'], $sc_post['payment_method_sc'])
        && $session['SC_Variables']['payment_api'] === 'rest'
        && empty($sc_post['payment_method_sc'])
    ) {
        $notice = __('Please select '. SC_GATEWAY_TITLE .' payment method to continue!', 'sc');
        wc_add_notice('<strong>' . $notice . '</strong>', 'error');
    }
}

function sc_add_buttons()
{
    global $session;
    global $sc_request;

    try {
        $order = new WC_Order($sc_request['post']);
        $order_status = strtolower($order->get_status());
        $order_payment_method = $order->get_meta('_paymentMethod');
        
        // hide Refund Button
        if (!in_array($order_payment_method, array('cc_card', 'dc_card', 'apmgw_expresscheckout'), true)) {
            echo '<script type="text/javascript">jQuery(\'.refund-items\').hide();</script>';
        }
    } catch (Exception $ex) {
        echo '<script type="text/javascript">console.error("'
            . esc_html($ex->getMessage()) . '")</script>';
        exit;
    }
    
    // to show SC buttons we must be sure the order is paid via SC Paygate
    if (!$order->get_meta(SC_AUTH_CODE_KEY) || !$order->get_meta(SC_GW_TRANS_ID_KEY)) {
        return;
    }
    
    if ($order_status === 'completed'|| $order_status === 'pending') {
        require_once plugin_dir_path(__FILE__) . 'SC_Versions_Resolver.php';
        global $wc_sc;

        $time = date('YmdHis', time());
        $order_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);
        $order_has_refund = $order->get_meta('_scHasRefund');
        $notify_url = $wc_sc->set_notify_url();
        $buttons_html = '';
        
        // common data
        $session['SC_Variables'] = array(
            'merchantId'            => $wc_sc->settings['merchantId'],
            'merchantSiteId'        => $wc_sc->settings['merchantSiteId'],
            'clientRequestId'       => $time . '_' . $order_tr_id,
            'clientUniqueId'        => $sc_request['post'],
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
                'notificationUrl'       => $notify_url . '&clientRequestId=' . $sc_request['post']
            ),
        );
        
        // Show VOID button
        if ($order_has_refund !== '1' && in_array($order_payment_method, array('cc_card', 'dc_card'), true)) {
            $buttons_html .=
                ' <button id="sc_void_btn" type="button" onclick="settleAndCancelOrder(\''
                . __('Are you sure, you want to Cancel Order #'. $sc_request['post'] .'?', 'sc') .'\', '
                . '\'void\', \''. WOOCOMMERCE_VERSION .'\')" class="button generate-items">'
                . __('Void', 'sc') .'</button>';
        }
        
        // show SETTLE button ONLY if setting transaction_type IS Auth AND P3D resonse transaction_type IS Auth
        if (
            $order_status === 'pending'
            && $order->get_meta(SC_GW_P3D_RESP_TR_TYPE) === 'Auth'
            && $wc_sc->settings['transaction_type'] === 'Auth'
        ) {
            $buttons_html .=
                ' <button id="sc_settle_btn" type="button" onclick="settleAndCancelOrder(\''
                . __('Are you sure, you want to Settle Order #'. $sc_request['post'] .'?', 'sc') .'\', '
                . '\'settle\', \'' . WOOCOMMERCE_VERSION .'\')" class="button generate-items">'
                . __('Settle', 'sc') .'</button>';
        }
        
        $checksum = hash(
            $wc_sc->settings['hash_type'],
            $wc_sc->settings['merchantId']
                . $wc_sc->settings['merchantSiteId']
                . $session['SC_Variables']['clientRequestId']
                . $session['SC_Variables']['clientUniqueId']
                . $session['SC_Variables']['amount']
                . $session['SC_Variables']['currency']
                . $session['SC_Variables']['relatedTransactionId']
                . $session['SC_Variables']['authCode']
                . $session['SC_Variables']['urlDetails']['notificationUrl']
                . $time
                . $wc_sc->settings['secret']
        );

        $session['SC_Variables']['checksum'] = $checksum;

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
function sc_rewrite_return_url()
{
    global $sc_server;
    global $sc_request;
    
    if (
        isset($sc_request['ppp_status']) && $sc_request['ppp_status'] !== ''
        && (!isset($sc_request['wc_sc_redirected']) || $sc_request['wc_sc_redirected'] === 0)
    ) {
        $new_url = '';
        $host = (strpos($sc_server["SERVER_PROTOCOL"], 'HTTP/') !== false ? 'http' : 'https')
            . '://' . $sc_server['HTTP_HOST'] . current(explode('?', $sc_server['REQUEST_URI']));
        
        if (isset($sc_server['QUERY_STRING']) && $sc_server['QUERY_STRING'] !== '') {
            $new_url = preg_replace('/\+|\s|\%20/', '_', $sc_server['QUERY_STRING']);
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
function sc_wpml_thank_you_page($order_received_url, $order)
{
    $lang_code = get_post_meta($order->id, 'wpml_language', true);
    $order_received_url = apply_filters('wpml_permalink', $order_received_url, $lang_code);
    
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
    global $session;
    
    if (
       !isset($session['SC_Variables']['save_logs'])
       || $session['SC_Variables']['save_logs'] === 'no'
       || $session['SC_Variables']['save_logs'] === null
   ) {
        return;
    }

    $d = '';

    if (is_array($data)) {
        foreach ($data as $k => $dd) {
            if (is_array($dd)) {
                if (isset($dd['cardData'], $dd['cardData']['CVV'])) {
                    $data[$k]['cardData']['CVV'] = md5($dd['cardData']['CVV']);
                }
                if (isset($dd['cardData'], $dd['cardData']['cardHolderName'])) {
                    $data[$k]['cardData']['cardHolderName'] = md5($dd['cardData']['cardHolderName']);
                }
            }
        }

        $d = print_r($data, true);
    } elseif (is_object($data)) {
        $d = print_r($data, true);
    } elseif (is_bool($data)) {
        $d = $data ? 'true' : 'false';
    } else {
        $d = $data;
    }

    if (!empty($title)) {
        $d = $title . "\r\n" . $d;
    }

    if (defined('SC_LOG_FILE_PATH')) {
        try {
            global $wp_filesystem;
            $wp_filesystem->put_contents(
                SC_LOG_FILE_PATH,
                date('H:i:s') . ': ' . $d . "\r\n"."\r\n"
            );
            
        } catch (Exception $exc) {
            echo
               '<script>'
                   .'error.log("Log file was not created, by reason: ' . esc_html($exc) . '");'
                   .'console.log("Log file was not created, by reason: ' . esc_html($data) . '");'
               .'</script>';
        }
    }
}
