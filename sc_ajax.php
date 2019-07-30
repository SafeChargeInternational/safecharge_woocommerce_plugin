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
    require_once 'SC_LOGGER.php';
    
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
    
    require_once 'SC_REST_API.php';
    
    // if there is no webMasterId in the session get it from the post
    if(
        !@$_SESSION['SC_Variables']['webMasterId']
        && isset($_POST['woVersion'])
        && $_POST['woVersion']
    ) {
        $_SESSION['SC_Variables']['webMasterId'] = 'WoCommerce ' . $_POST['woVersion'];
    }
    
    // when merchant cancel the order via Void button
    if(isset($_POST['cancelOrder']) && $_POST['cancelOrder'] == 1) {
        SC_REST_API::void_and_settle_order($_SESSION['SC_Variables'], 'void', true);
        unset($_SESSION['SC_Variables']);
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
        }
        else {
            echo json_encode(array('status' => 0, 'msg' => 'The log files are less than 30.'));
        }

        exit;
    }
    
    // when merchant settle the order via Settle button
    if(isset($_POST['settleOrder']) && $_POST['settleOrder'] == 1) {
        SC_REST_API::void_and_settle_order($_SESSION['SC_Variables'], 'settle', true);
        unset($_SESSION['SC_Variables']);
        exit;
    }
    
    if($_SESSION['SC_Variables']['payment_api'] == 'rest') {
        // when we want Session Token
        if(isset($_POST['needST']) && $_POST['needST'] == 1) {
            SC_REST_API::get_session_token($_SESSION['SC_Variables'], true);
        }
        // when we want APMs
        elseif(isset($_POST['country']) && $_POST['country'] != '') {
            // if the Country come as POST variable
            if(empty($_SESSION['SC_Variables']['sc_country'])) {
                $_SESSION['SC_Variables']['sc_country'] = @$_POST['country'];
            }

            # get UPOs
            $upos = array();

            if(isset($_SESSION['SC_Variables']['upos_data'])) {
                $upos_data = SC_REST_API::get_user_upos(
                    array(
                        'merchantId'        => $_SESSION['SC_Variables']['merchantId'],
                        'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
                        'userTokenId'       => $_SESSION['SC_Variables']['upos_data']['userTokenId'],
                        'clientRequestId'   => $_SESSION['SC_Variables']['upos_data']['clientRequestId'],
                        'timeStamp'         => $_SESSION['SC_Variables']['upos_data']['timestamp'],
                    ),
                    array(
                        'checksum'          => $_SESSION['SC_Variables']['upos_data']['checksum'],
                        'test'              => $_SESSION['SC_Variables']['test'],
                    )
                );
                
                if(isset($upos_data['paymentMethods']) && $upos_data['paymentMethods']) {
                    $upos = $upos_data['paymentMethods'];
                }
                else {
                    SC_LOGGER::create_log($upos_data, '$upos_data:');
                }
            }
            # get UPOs END
            
            # get APMs
            $apms_data = SC_REST_API::get_rest_apms($_SESSION['SC_Variables']);
            
            if(!is_array($apms_data) || !isset($apms_data['paymentMethods']) || empty($apms_data['paymentMethods'])) {
                SC_LOGGER::create_log($apms_data, '$apms_data:');
                
                echo json_encode(array('status' => 0));
                exit;
            }

            // set template data with the payment methods
            $payment_methods = $apms_data['paymentMethods'];
            # get APMs END
            
            // add icons for the upos
            $icons = array();
            $upos_final = array();

            if($upos && $payment_methods) {
                foreach($upos as $upo_key => $upo) {
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
                                && @$upo['upoData']['brand']
                            ) {
                                $icons[@$upo['upoData']['brand']] = str_replace(
                                    'default_cc_card',
                                    $upo['upoData']['brand'],
                                    $pm['logoURL']
                                );
                            }
                            else {
                                $icons[$pm['paymentMethod']] = $pm['logoURL'];
                            }
                            
                            $upos_final[] = $upo;
                            break;
                        }
                    }
                }
            }
            
            echo json_encode(array(
                'status'            => 1,
                'testEnv'           => $_SESSION['SC_Variables']['test'],
                'merchantSiteId'    => $_SESSION['SC_Variables']['merchantSiteId'],
                'langCode'          => $_SESSION['SC_Variables']['languageCode'],
                'sessionToken'      => $apms_data['sessionToken'],
                'data'              => array(
                    'upos'  => $upos_final,
                    'paymentMethods'=> $payment_methods,
                    'icons' => $icons
                )
            ));
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
        'msg' => $_SESSION['SC_Variables']['payment_api'] != 'rest'
            ? 'You are using Cashier API. APMs are not available with it.' : 'Missing some of conditions to using REST API.'
    ));
    exit;
}

echo json_encode(array('status' => 0));
exit;