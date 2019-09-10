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

// The following fileds are MANDATORY for success
if(
    isset(
        $_SERVER['HTTP_X_REQUESTED_WITH']
        ,$_SESSION['SC_Variables']['merchantId']
        ,$_SESSION['SC_Variables']['merchantSiteId']
        ,$_SESSION['SC_Variables']['payment_api']
        ,$_SESSION['SC_Variables']['test']
    )
    && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'
    && !empty($_SESSION['SC_Variables']['merchantId'])
    && !empty($_SESSION['SC_Variables']['merchantSiteId'])
    && in_array($_SESSION['SC_Variables']['payment_api'], array('cashier', 'rest'))
) {
    // when enable or disable SC Checkout
    if(in_array(@$_POST['enableDisableSCCheckout'], array('enable', 'disable'))) {
        require dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-includes/plugin.php';
        
        if($_POST['enableDisableSCCheckout'] == 'enable') {
            add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
            add_action( 'woocommerce_checkout_process', 'sc_check_checkout_apm');

            echo json_encode(array('status' => 1));
            exit;
        }
        else {
            remove_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
            remove_action( 'woocommerce_checkout_process', 'sc_check_checkout_apm');

            echo json_encode(array('status' => 1));
            exit;
        }

        echo json_encode(array('status' => 1, data => 'action error.'));
        exit;
    }
    
    // if there is no webMasterId in the session get it from the post
    if(
        !@$_SESSION['SC_Variables']['webMasterId']
        && isset($_POST['woVersion'])
        && $_POST['woVersion']
    ) {
        $_SESSION['SC_Variables']['webMasterId'] = 'WoCommerce ' . $_POST['woVersion'];
    }
    
    // Void, Cancel
    if(isset($_POST['cancelOrder']) && $_POST['cancelOrder'] == 1) {
        $status = 1;
        $url = $_SESSION['SC_Variables']['test'] == 'no' ? SC_LIVE_VOID_URL : SC_TEST_VOID_URL;
        
        $resp = SC_HELPER::call_rest_api($url, $_SESSION['SC_Variables']);
        
        if(
            !$resp || !is_array($resp)
            || @$resp['status'] == 'ERROR'
            || @$resp['transactionStatus'] == 'ERROR'
            || @$resp['transactionStatus'] == 'DECLINED'
        ) {
            $status = 0;
        }
        
        unset($_SESSION['SC_Variables']);
        
        echo json_encode(array('status' => $status, 'data' => $resp));
        exit;
    }
    
    // Settle
    if(isset($_POST['settleOrder']) && $_POST['settleOrder'] == 1) {
        $status = 1;
        $url = $_SESSION['SC_Variables']['test'] == 'no' ? SC_LIVE_SETTLE_URL : SC_TEST_SETTLE_URL;
        
        $resp = SC_HELPER::call_rest_api($url, $_SESSION['SC_Variables']);
        
        if(
            !$resp || !is_array($resp)
            || @$resp['status'] == 'ERROR'
            || @$resp['transactionStatus'] == 'ERROR'
            || @$resp['transactionStatus'] == 'DECLINED'
        ) {
            $status = 0;
        }
        
        unset($_SESSION['SC_Variables']);
        
        echo json_encode(array('status' => $status, 'data' => $resp));
        exit;
    }
    
    // When user want to delete logs.
    if(isset($_POST['deleteLogs']) && $_POST['deleteLogs'] == 1) {
        $logs = array();
        $logs_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;

        foreach(scandir($logs_dir) as $file) {
            if($file != '.' && $file != '..' && $file != '.htaccess') {
                $logs[] = $file;
            }
        }

        if(count($logs) > 30) {
            sort($logs);

            for($cnt = 0; $cnt < 30; $cnt++) {
                if(is_file($logs_dir . $logs[$cnt])) {
                    if(!unlink($logs_dir . $logs[$cnt])) {
                        echo json_encode(array(
                            'status' => 0,
                            'msg' => 'Error when try to delete file: ' . $logs[$cnt]
                        ));
                        exit;
                    }
                }
            }

            echo json_encode(array('status' => 1, 'msg' => ''));
            exit;
        }
        
        echo json_encode(array('status' => 0, 'msg' => 'The log files are less than 30.'));
        exit;
    }
    
    if($_SESSION['SC_Variables']['payment_api'] == 'rest') {
        // prepare Session Token
        $st_endpoint_url = $_SESSION['SC_Variables']['test'] == 'yes'
            ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL;

        $st_params = array(
            'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
            'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
            'clientRequestId'   => $_SESSION['SC_Variables']['cri1'],
            'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri1'])),
            'checksum'          => $_SESSION['SC_Variables']['cs1']
        );

        $session_data = SC_HELPER::call_rest_api($st_endpoint_url, $st_params);
        
        if(
            !$session_data || !is_array($session_data)
            || !isset($session_data['status']) || $session_data['status'] != 'SUCCESS'
        ) {
            SC_HELPER::create_log($session_data, 'getting getSessionToken error: ');

            echo json_encode(array('status' => 0));
            exit;
        }
        
        // when we want Session Token only
        if(isset($_POST['needST']) && $_POST['needST'] == 1) {
            SC_HELPER::create_log('Ajax, get Session Token.');
            
            $session_data['test'] = @$_SESSION['SC_Variables']['test'];
            echo json_encode(array('status' => 1, 'data' => $session_data));
            exit;
        }
        
        // when we want APMs and UPOs
        if(isset($_POST['country']) && $_POST['country'] != '') {
            // if the Country come as POST variable
            if(empty($_SESSION['SC_Variables']['sc_country'])) {
                $_SESSION['SC_Variables']['sc_country'] = @$_POST['country'];
            }

            # get APMs
            $apms_params = array(
                'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
                'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
                'clientRequestId'   => $_SESSION['SC_Variables']['cri2'],
                'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri2'])),
                'checksum'          => $_SESSION['SC_Variables']['cs2'],
                'sessionToken'      => $session_data['sessionToken'],
                'currencyCode'      => $_SESSION['SC_Variables']['currencyCode'],
                'countryCode'       => $_SESSION['SC_Variables']['sc_country'],
                'languageCode'      => $_SESSION['SC_Variables']['languageCode'],
            );
            
            $endpoint_url = $_SESSION['SC_Variables']['test'] == 'yes'
                ? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL;
            
            $apms_data = SC_HELPER::call_rest_api($endpoint_url, $apms_params);
            
            if(!is_array($apms_data) || !isset($apms_data['paymentMethods']) || empty($apms_data['paymentMethods'])) {
                SC_HELPER::create_log($apms_data, 'getting APMs error: ');

                echo json_encode(array('status' => 0));
                exit;
            }
            
            // set template data with the payment methods
            $payment_methods = $apms_data['paymentMethods'];
            # get APMs END
            
            # get UPOs
            $upos = array();
            $icons = array();

            if(isset($_SESSION['SC_Variables']['upos_data'])) {
                $endpoint_url = $_SESSION['SC_Variables']['test'] == 'yes'
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
                
                if(isset($upos_data['paymentMethods']) && $upos_data['paymentMethods']) {
                    foreach($upos_data['paymentMethods'] as $upo_key => $upo) {
                        if(
                            @$upo['upoStatus'] != 'enabled'
                            || (isset($upo['upoData']['ccCardNumber'])
                                && empty($upo['upoData']['ccCardNumber']))
                            || (isset($upo['expiryDate'])
                                && strtotime($upo['expiryDate']) < strtotime(date('Ymd')))
                        ) {
                            continue;
                        }

                        // search in payment methods
                        foreach($payment_methods as $pm) {
                            if(@$pm['paymentMethod'] == @$upo['paymentMethodName']) {
                                if(
                                    in_array(@$upo['paymentMethodName'], array('cc_card', 'dc_card'))
                                    && @$upo['upoData']['brand'] && @$pm['logoURL']
                                ) {
                                    $icons[@$upo['upoData']['brand']] = str_replace(
                                        'default_cc_card',
                                        $upo['upoData']['brand'],
                                        $pm['logoURL']
                                    );
                                }
                                elseif(@$pm['logoURL']) {
                                    $icons[$pm['paymentMethod']] = $pm['logoURL'];
                                }

                                $upos[] = $upo;
                                break;
                            }
                        }
                    }
                }
                else {
                    SC_HELPER::create_log($upos_data, '$upos_data:');
                }
            }
            # get UPOs END
            
            echo json_encode(array(
                'status'            => 1,
                'testEnv'           => $_SESSION['SC_Variables']['test'],
                'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
                'langCode'          => $_SESSION['SC_Variables']['languageCode'],
                'sessionToken'      => $apms_data['sessionToken'],
                'data'              => array(
                    'upos'              => $upos,
                    'paymentMethods'    => $payment_methods,
                    'icons'             => $icons
                )
            ));
            
            exit;
        }
        
        exit;
    }
    
    // here we no need APMs
    else {
        echo json_encode(array(
            'status' => 2,
            'msg' => $_SESSION['SC_Variables']['payment_api'] != 'rest'
                ? 'You are using Cashier API. APMs are not available with it.' : 'Missing some of conditions to using REST API.'
        ));
        exit;
    }
}
elseif(
    @$_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'
    // don't come here when try to refund!
    && @$_REQUEST['action'] != 'woocommerce_refund_line_items'
) {
    echo json_encode(array(
        'status' => 2,
        'msg' => @$_SESSION['SC_Variables']['payment_api'] != 'rest'
            ? 'You are using Cashier API. APMs are not available with it.'
                : 'Missing some of conditions to using REST API.'
    ));
    exit;
}

echo json_encode(array('status' => 0));
exit;