<?php

/**
 * WC_SC Class
 * 
 * Main class for the SafeCharge Plugin
 * 
 * 2018
 * 
 * @author SafeCharge
 */

if (!session_id()) {
    session_start();
}

class WC_SC extends WC_Payment_Gateway
{
    # payments URL
    private $URL = '';
    private $webMasterId = 'WooCommerce ';
    
    public function __construct()
    {
        $plugin_dir         = basename(dirname(__FILE__));
        $this->plugin_path  = plugin_dir_path( __FILE__ ) . $plugin_dir . DIRECTORY_SEPARATOR;
        $this->plugin_url   = get_site_url() . DIRECTORY_SEPARATOR . 'wp-content'
            . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $plugin_dir
            . DIRECTORY_SEPARATOR;
        
        # settings to get/save options
		$this->id                   = 'sc';
		$this->method_title         = SC_GATEWAY_TITLE;
		$this->method_description   = 'Pay with '. SC_GATEWAY_TITLE .'.';
        $this->icon                 = $this->plugin_url."icons/safecharge.png";
		$this->has_fields           = false;

		$this->init_settings();
        
		$this->title            = @$this->settings['title'] ? $this->settings['title'] : '';
		$this->description      = @$this->settings['description'] ? $this->settings['description'] : '';
		$this->merchantId       = @$this->settings['merchantId'] ? $this->settings['merchantId'] : '';
		$this->merchantSiteId   = @$this->settings['merchantSiteId'] ? $this->settings['merchantSiteId'] : '';
        $this->secret           = @$this->settings['secret'] ? $this->settings['secret'] : '';
		$this->test             = @$this->settings['test'] ? $this->settings['test'] : 'yes';
		$this->use_http         = @$this->settings['use_http'] ? $this->settings['use_http'] : 'yes';
        $this->save_logs        = @$this->settings['save_logs'] ? $this->settings['save_logs'] : 'yes';
        $this->hash_type        = @$this->settings['hash_type'] ? $this->settings['hash_type'] : 'sha256';
		$this->payment_api      = @$this->settings['payment_api'] ? $this->settings['payment_api'] : 'cashier';
		$this->transaction_type = @$this->settings['transaction_type'] ? $this->settings['transaction_type'] : 'sale';
		$this->rewrite_dmn      = @$this->settings['rewrite_dmn'] ? $this->settings['rewrite_dmn'] : 'no';
		
        $this->use_wpml_thanks_page =
            @$this->settings['use_wpml_thanks_page'] ? $this->settings['use_wpml_thanks_page'] : 'no';
        $this->cashier_in_iframe    =
            @$this->settings['cashier_in_iframe'] ? $this->settings['cashier_in_iframe'] : 'no';
        
        $this->supports[] = 'refunds'; // to enable auto refund support
        
        $this->init_form_fields();
        
        # set session variables for REST API, according REST variables names
        $_SESSION['SC_Variables']['webMasterId']        = $this->webMasterId .= WOOCOMMERCE_VERSION;
        $_SESSION['SC_Variables']['merchantId']         = $this->merchantId;
        $_SESSION['SC_Variables']['merchantSiteId']     = $this->merchantSiteId;
        $_SESSION['SC_Variables']['currencyCode']       = get_woocommerce_currency();
        $_SESSION['SC_Variables']['languageCode']       = $this->formatLocation(get_locale());
        $_SESSION['SC_Variables']['payment_api']        = $this->payment_api;
        $_SESSION['SC_Variables']['transactionType']    = $this->transaction_type;
        $_SESSION['SC_Variables']['test']               = $this->test;
        $_SESSION['SC_Variables']['rewrite_dmn']        = $this->rewrite_dmn;
        $_SESSION['SC_Variables']['sc_create_logs']                     = $this->save_logs;
        $_SESSION['SC_Variables']['notify_url']                         = $this->set_notify_url();
        
        // prepare the data for the UPOs
        if(is_user_logged_in()) {
            $_SESSION['SC_Variables']['upos_data']['userTokenId'] =
                @wp_get_current_user()->data->user_email;
            
            $_SESSION['SC_Variables']['upos_data']['clientRequestId'] = uniqid();
            $_SESSION['SC_Variables']['upos_data']['timestamp'] = date('YmdHis', time());
            
            $_SESSION['SC_Variables']['upos_data']['checksum'] =
                @hash(
                    $this->hash_type,
                    $this->merchantId . $this->merchantSiteId . $_SESSION['SC_Variables']['upos_data']['userTokenId']
                        . $_SESSION['SC_Variables']['upos_data']['clientRequestId']
                        . $_SESSION['SC_Variables']['upos_data']['timestamp']
                        . $this->secret
                );
        }
        
        $_SESSION['SC_Variables']['sc_country'] = SC_Versions_Resolver::get_client_country(new WC_Customer);
        if(isset($_POST["billing_country"]) && !empty($_POST["billing_country"])) {
            $_SESSION['SC_Variables']['sc_country'] = $_POST["billing_country"];
        }
        
        # Client Request ID 1 and Checksum 1 for Session Token 1
        // client request id 1
        $time = date('YmdHis', time());
        $_SESSION['SC_Variables']['cri1'] = $time. '_' .uniqid();
        
        // checksum 1 - checksum for session token
        $_SESSION['SC_Variables']['cs1'] = hash(
            $this->hash_type,
            $this->merchantId . $this->merchantSiteId
                . $_SESSION['SC_Variables']['cri1'] . $time . $this->secret
        );
        # Client Request ID 1 and Checksum 1 END
        
        # Client Request ID 2 and Checksum 2 to get AMPs
        // client request id 2
        $time = date('YmdHis', time());
        $_SESSION['SC_Variables']['cri2'] = $time. '_' .uniqid();
        
        // checksum 2 - checksum for get apms
        $time = date('YmdHis', time());
        $_SESSION['SC_Variables']['cs2'] = hash(
            $this->hash_type,
            $this->merchantId . $this->merchantSiteId
                . $_SESSION['SC_Variables']['cri2'] . $time . $this->secret
        );
        # set session variables for future use END
        
		$this->msg['message'] = "";
		$this->msg['class'] = "";
        
    //    echo '<pre>' . print_r($this, true) . '</pre>';

        SC_Versions_Resolver::process_admin_options($this);
        
	//	add_action('woocommerce_checkout_process', array($this, 'sc_checkout_process'));
        
        /* Refun hook, when create refund from WC, we do not want this to be activeted from DMN,
        we check in the method is this order made via SC paygate */
        add_action('woocommerce_create_refund', array($this, 'create_refund_in_wc'));
        // This crash Refund action
        add_action('woocommerce_order_after_calculate_totals', array($this, 'sc_return_sc_settle_btn'));
        add_action('woocommerce_order_status_refunded', array($this, 'sc_restock_on_refunded_status'));
	}
    
    /**
     * Function init_form_fields
     * Set all fields for admin settings page.
     */
	public function init_form_fields()
    {
       $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable '. SC_GATEWAY_TITLE .' Payment Module.', 'sc'),
                'default' => 'no'
            ),
           'title' => array(
                'title' => __('Default title:', 'sc'),
                'type'=> 'text',
                'description' => __('This is the payment method which the user sees during checkout.', 'sc'),
                'default' => __('Secure Payment with SafeCharge', 'sc')
            ),
            'description' => array(
                'title' => __('Description:', 'sc'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'sc'),
                'default' => __('Place order to get to our secured payment page to select your payment option', 'sc')
            ),
            'merchantId' => array(
                'title' => __('Merchant ID', 'sc'),
                'type' => 'text',
                'description' => __('Merchant ID is provided by '. SC_GATEWAY_TITLE .'.')
            ),
            'merchantSiteId' => array(
                'title' => __('Merchant Site ID', 'sc'),
                'type' => 'text',
                'description' => __('Merchant Site ID is provided by '. SC_GATEWAY_TITLE .'.')
            ),
            'secret' => array(
                'title' => __('Secret key', 'sc'),
                'type' => 'text',
                'description' =>  __('Secret key is provided by '. SC_GATEWAY_TITLE, 'sc'),
            ),
            'hash_type' => array(
                'title' => __('Hash type', 'sc'),
                'type' => 'select',
                'description' => __('Choose Hash type provided by '. SC_GATEWAY_TITLE, 'sc'),
                'options' => array(
                    'sha256' => 'sha256',
                    'md5' => 'md5',
                )
            ),
            'payment_api' => array(
                'title' => __('Payment solution', 'sc'),
                'type' => 'select',
                'description' => __('Select '. SC_GATEWAY_TITLE .' payment API', 'sc'),
                'options' => array(
                    'cashier' => 'Hosted payment page',
                    'rest' => 'SafeCharge API',
                )
            ),
            'transaction_type' => array(
                'title' => __('Payment action', 'sc'),
                'type' => 'select',
                'description' => __('Select preferred Transaction Type.<br/>Just in case goto WooCommerce > Settings > Products > Inventory and remove any value that is present in the Hold Stock (minutes) field.', 'sc'),
                'options' => array(
                    'Auth' => 'Authorize',
                    'Sale' => 'Authorize & Capture',
                )
            ),
            'notify_url' => array(
                'title' => __('Notify URL', 'sc'),
                'type' => 'text',
                'default' => '',
                'description' => $this->set_notify_url(),
                'type' => 'hidden'
            ),
            'test' => array(
                'title' => __('Test mode', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'sc'),
                'default' => 'no'
            ),
            'cashier_in_iframe' => array(
                'title' => __('Cashier in IFrame', 'sc'),
                'type' => 'checkbox',
                'label' => __('When use Cashier as Payment API, open it in iFrame, instead redirecting.', 'sc'),
                'default' => 'no'
            ),
            'use_http' => array(
                'title' => __('Use HTTP', 'sc'),
                'type' => 'checkbox',
                'label' => __('Force protocol where receive DMNs to be HTTP. You must have valid certificate for HTTPS! In case the checkbox is not set the default Protocol will be used.', 'sc'),
                'default' => 'no'
            ),
            // actually this is not for the DMN, but for return URL after Cashier page
            'rewrite_dmn' => array(
                'title' => __('Rewrite DMN', 'sc'),
                'type' => 'checkbox',
                'label' => __('Check this option ONLY when URL symbols like "+", " " and "%20" in the DMN cause error 404 - Page not found.', 'sc'),
                'default' => 'no'
            ),
            'use_wpml_thanks_page' => array(
                'title' => __('Use WPML "Thank you" page.', 'sc'),
                'type' => 'checkbox',
                'label' => __('Works only if you have installed and configured WPML plugin. Please, use it careful, this option can brake your "Thank you" page and DMN recieve page!', 'sc'),
                'default' => 'no'
            ),
            'save_logs' => array(
                'title' => __('Save logs', 'sc'),
                'type' => 'checkbox',
                'label' => __('Create and save daily log files. This can help for debugging and catching bugs.', 'sc'),
                'default' => 'yes'
            ),
            'delete_logs' => array(
                'title' => __('Delete oldest logs.', 'sc'),
                'type' => 'button',
                'custom_attributes' => array(
                    'onclick' => "deleteOldestLogs()",
                ),
                'description' => __( 'Only the logs for last 30 days will be kept.', 'sc' ),
                'default' => 'Delete Logs.',
            ),
        );
    }
    
    /**
     * Function generate_button_html
     * Generate Button HTML.
     * Custom function to generate beautiful button in admin settings.
     * Thanks to https://gist.github.com/BFTrick/31de2d2235b924e853b0
     *
     * @access public
     * @param mixed $key
     * @param mixed $data
     * 
     * @since 1.0.0
     * 
     * @return string
     */
    public function generate_button_html( $key, $data )
    {
        $field    = $this->plugin_id . $this->id . '_' . $key;
        $defaults = array(
            'class'             => 'button-secondary',
            'css'               => '',
            'custom_attributes' => array(),
            'desc_tip'          => false,
            'description'       => '',
            'title'             => '',
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
                <?php echo $this->get_tooltip_html( $data ); ?>
            </th>
            <td class="forminp" style="position: relative;">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
                    <button class="<?php echo esc_attr( $data['class'] ); ?>" type="button" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->get_custom_attribute_html( $data ); ?>><?php echo wp_kses_post( $data['title'] ); ?></button>
                    <?php echo $this->get_description_html( $data ); ?>
                </fieldset>
                <div id="custom_loader" class="blockUI blockOverlay" style="margin-left: -3.5em;"></div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function admin_options()
    {
        // Generate the HTML For the settings form.
        echo
            '<h3>'.__(SC_GATEWAY_TITLE .' ', 'sc').'</h3>'
            .'<p>'.__('SC payment option').'</p>'
            .'<table class="form-table">';
                $this->generate_settings_html();
        echo '</table>';
    }

	/**
     * Function payment_fields
     * 
     *  Add fields on the payment page. Because we get APMs with Ajax
     * here we add only AMPs fields modal.
     **/
    public function payment_fields()
    {
		if($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // echo here some html if needed
    }

	/**
      * Function generate_sc_form
      * 
      * The function generates form form the order fields and prepare to send
      * them to the SC PPP.
      * We can send this data to the cashier generating pay button link and form,
      * or to the REST API as curl post.
      * 
      * @param int $order_id
     **/
    public function generate_sc_form($order_id)
    {
        SC_HELPER::create_log('generate_sc_form()');
        
        $url = $this->get_return_url();
        
        $loading_table_html =
            '<table id="sc_pay_msg" style="border: 3px solid #aaa; cursor: wait; line-height: 32px;"><tr>'
                .'<td style="padding: 0px; border: 0px; width: 100px;">'
                    . '<img src="'. $this->plugin_url .'icons/loading.gif" style="width:100px; float:left; margin-right: 10px;" />'
                . '</td>'
                .'<td style="text-align: left; border: 0px;">'
                    . '<span>'.__('Thank you for your order. We are now redirecting you to '. SC_GATEWAY_TITLE .' Payment Gateway to make payment.', 'sc').'</span>'
                . '</td>'
            .'</tr></table>';
        
        // try to prepend the loading table first
        echo 
            '<script type="text/javascript">'
                . 'jQuery("header.entry-header").prepend(\''.$loading_table_html.'\');'
            .'</script>'
            .'<noscript>'. $loading_table_html .'</noscript>';
        
        // Order error
        if(@$_REQUEST['status'] == 'error') {
            echo
                '<script type="text/javascript">'
                    . 'window.location.href = "'. $url .'?Status=error"'
                .'</script>'
                .'<noscript>'
                    . '<a href="' . $url .'">' . __('Click to continue!', 'sc').'</a>'
                .'</noscript>';
            
            exit;
        }
        
        $order = new WC_Order($order_id);
        
        // Order with Redirect URL
        if(@$_REQUEST['redirectUrl'] == 1) {
            echo 
                '<form action="'. @$_SESSION['SC_P3D_acsUrl'] .'" method="post" id="sc_payment_form">'
                    .'<input type="hidden" name="PaReq" value="'. @$_SESSION['SC_P3D_PaReq'] .'">'
                    .'<input type="hidden" name="TermUrl" value="'
                        . $url . (strpos($url, '?') != false ? '&' : '?')
                        . 'wc-api=sc_listener&action=p3d">'
                    .'<noscript>'
                        . '<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'
                            . __('Pay via '. SC_GATEWAY_TITLE, 'sc').'" />'
                        . '<a class="button cancel" href="' .$order->get_cancel_order_url().'">'
                            . __('Cancel order &amp; restore cart', 'sc').'</a>'
                    .'</noscript>'

                    .'<script type="text/javascript">'
                        .'jQuery(function(){'
                            .'jQuery("#sc_payment_form").submit();'
                        .'});'
                    .'</script>'
                .'</form>';
            
            exit;
        }
        
        // when we will pay with the Cashier
        try {
            $TimeStamp = date('Ymdhis');
            $order = new WC_Order($order_id);
            $order_status = strtolower($order->get_status());

            $order->add_order_note(__("User is redicted to ".SC_GATEWAY_TITLE." Payment page.", 'sc'));
            $order->save();

            $notify_url = $this->set_notify_url();
            
            $items = $order->get_items();
            $params['numberofitems'] = count($items);

            $params['handling'] = number_format(
                (SC_Versions_Resolver::get_shipping($order) + $this->get_order_data($order, 'order_shipping_tax')),
                2, '.', ''
            );
            
            $params['discount'] = number_format($order->get_discount_total(), 2, '.', '');

            if ($params['handling'] < 0) {
                $params['discount'] += abs($params['handling']); 
            }
            
            // we are not sure can woocommerce support more than one tax.
            // if it can, may be sum them is not the correct aproch, this must be tested
            $total_tax_prec = 0;
            $taxes = WC_Tax::get_rates();
            foreach($taxes as $data) {
                $total_tax_prec += $data['rate'];
            }

            $params['merchant_id'] = $this->merchantId;
            $params['merchant_site_id'] = $this->merchantSiteId;
            $params['time_stamp'] = $TimeStamp;
            $params['encoding'] ='utf-8';
            $params['version'] = '4.0.0';

            $payment_page = wc_get_cart_url();

            if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
                $payment_page = str_replace( 'http:', 'https:', $payment_page );
            }

            $return_url = $this->get_return_url();
            if($this->cashier_in_iframe == 'yes') {
                if(strpos($return_url, '?') !== false) {
                    $return_url .= '&use_iframe=1';
                }
                else {
                    $return_url .= '?use_iframe=1';
                }
            }

            $params['success_url']          = $return_url;
            $params['pending_url']          = $return_url;
            $params['error_url']            = $return_url;
            $params['back_url']             = $payment_page;
            $params['notify_url']           = $notify_url;
            $params['invoice_id']           = $order_id.'_'.$TimeStamp;
            $params['merchant_unique_id']   = $order_id;

            // get and pass billing data
            $params['first_name'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_first_name')));
            $params['last_name'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_last_name')));
            $params['address1'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_address_1')));
            $params['address2'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_address_2')));
            $params['zip'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_zip')));
            $params['city'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_city')));
            $params['state'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_state')));
            $params['country'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_country')));
            $params['phone1'] =
                urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_phone')));

            $params['email']            = SC_Versions_Resolver::get_order_data($order, 'billing_email');
            $params['user_token_id']    = is_user_logged_in() ? $params['email'] : '';
            
            // get and pass billing data END

            // get and pass shipping data
            $sh_f_name = urlencode(preg_replace(
                "/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_first_name')));

            if(empty(trim($sh_f_name))) {
                $sh_f_name = $params['first_name'];
            }
            $params['shippingFirstName'] = $sh_f_name;

            $sh_l_name = urlencode(preg_replace(
                "/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_last_name')));

            if(empty(trim($sh_l_name))) {
                $sh_l_name = $params['last_name'];
            }
            $params['shippingLastName'] = $sh_l_name;

            $sh_addr = urlencode(preg_replace(
                "/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_address_1')));
            if(empty(trim($sh_addr))) {
                $sh_addr = $params['address1'];
            }
            $params['shippingAddress'] = $sh_addr;

            $sh_city = urlencode(preg_replace(
                "/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_city')));
            if(empty(trim($sh_city))) {
                $sh_city = $params['city'];
            }
            $params['shippingCity'] = $sh_city;

            $sh_country = urlencode(preg_replace(
                "/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_country')));
            if(empty(trim($sh_country))) {
                $sh_city = $params['country'];
            }
            $params['shippingCountry'] = $sh_country;

            $sh_zip = urlencode(preg_replace(
                "/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_postcode')));
            if(empty(trim($sh_zip))) {
                $sh_zip = $params['zip'];
            }
            $params['shippingZip'] = $sh_zip;
            // get and pass shipping data END

            $params['user_token']       = "auto";
            $params['total_amount']     = SC_Versions_Resolver::get_order_data($order, 'order_total');
            $params['currency']         = get_woocommerce_currency();
            $params['merchantLocale']   = get_locale();
            $params['webMasterId']      = $this->webMasterId;
        }
        catch (Exception $ex) {
            SC_HELPER::create_log($ex->getMessage(), 'Exception while preparing order parameters: ');
            
            echo
                '<script type="text/javascript">'
                    . 'window.location.href = "'. $url .'?Status=error"'
                .'</script>'
                .'<noscript>'
                    . '<a href="' . $url .'">' . __('Click to continue!', 'sc').'</a>'
                .'</noscript>';
            
            exit;
        }
        
        # Cashier payment
        SC_HELPER::create_log('Cashier payment');

        $_SESSION['SC_CASHIER_FORM_RENDED'] = true;

        $i = $test_sum = 0; // use it for the last check of the total

        # Items calculations
        foreach ( $items as $item ) {
            $i++;

            $params['item_name_'.$i]        = urlencode($item['name']);
            $params['item_number_'.$i]      = $item['product_id'];
            $params['item_quantity_'.$i]    = $item['qty'];

            // this is the real price
            $item_qty   = intval($item['qty']);
        //    $item_price = ($item['line_subtotal'] + $item['line_subtotal_tax']) / $item_qty;
            $item_price = $item['line_total'] / $item_qty;

            $params['item_amount_'.$i] = number_format($item_price, 2, '.', '');

            $test_sum += ($item_qty * $params['item_amount_'.$i]);
        }

        // last check for correct calculations
        $test_sum -= $params['discount'];

        $test_diff = $params['total_amount'] - $params['handling'] - $test_sum;
        if($test_diff != 0) {
            $params['handling'] += $test_diff;
            SC_HELPER::create_log($test_diff, 'Total diff, added to handling: ');
        }
        # Items calculations END

        // be sure there are no array elements in $params !!!
        $params['checksum'] = hash($this->hash_type, stripslashes($this->secret . implode('', $params)));

        $params_array = array();
        foreach($params as $key => $value) {
            if(!is_array($value)) {
                $params_array[] = "<input type='hidden' name='$key' value='$value'/>";
            }
        }
        
        $url = $this->test == 'yes' ? SC_TEST_CASHIER_URL : SC_LIVE_CASHIER_URL;

        SC_HELPER::create_log($url, 'Endpoint URL: ');
        SC_HELPER::create_log($params, 'Order params');
        
        $html = '<form action="'. $url .'" method="post" id="sc_payment_form">';

        if($this->cashier_in_iframe == 'yes') {
            $html = '<form action="'. $url .'" method="post" id="sc_payment_form" target="i_frame">';
        }

        $html .=
                implode('', $params_array)
                .'<noscript>'
                    .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'.__('Pay via '. SC_GATEWAY_TITLE, 'sc').'" /><a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'sc').'</a>'
                .'</noscript>'
                .'<script type="text/javascript">'
                    .'jQuery(function(){'
                        .'jQuery("#sc_payment_form").submit();'

                        .'if(jQuery("#i_frame").length > 0) {'
                            .'jQuery("#i_frame")[0].scrollIntoView();'
                        .'}'
                    .'});'
                .'</script>'
            .'</form>';

        if($this->cashier_in_iframe == 'yes') {
            $html .= '<iframe id="i_frame" name="i_frame" onLoad=""; style="width: 100%; height: 1000px;"></iframe>';
        }

        echo $html;
        exit;
    }
    
	/**
      * Function process_payment
      * Process the payment and return the result. This is the place where site
      * POST the form and then redirect. Here we will get our custom fields.
      * 
      * @param int $order_id
     **/
    public function process_payment($order_id)
    {
        $order = new WC_Order($order_id);
        $order_status = strtolower($order->get_status());
        
        # when use Cashier - redirect to receipt page
        if($this->payment_api == 'cashier') {
            return array(
                'result' 	=> 'success',
                'redirect'	=> add_query_arg(
                    array(
                        'order-pay' => $order_id,
                        'key' => $this->get_order_data($order, 'order_key')
                    ),
                    wc_get_page_permalink('pay')
                )
            );
        }
        
        # when use REST - call the API
        // when we have Approved from the SDK we complete the order here
        if(
            !empty($_POST['sc_transaction_id'])
            && !empty($_POST['sc_payment_method'])
            && in_array($_POST['sc_payment_method'], array('cc_card', 'dc_card'), true)
        ) {
            // If we get Transaction ID save it as meta-data
            if(!empty($resp['transactionId'])) {
                $order->update_meta_data(SC_GW_TRANS_ID_KEY, $resp['transactionId'], 0);
            }
            
            return array(
                'result' 	=> 'success',
                'redirect'	=> add_query_arg(array(), $this->get_return_url())
            );
        }
        
        $time           = date('Ymdhis');
        $endpoint_url   = '';
        $is_apm_payment = false;
        
        $params = array(
            'merchantId'        => $this->merchantId,
            'merchantSiteId'    => $this->merchantSiteId,
            'userTokenId'       => isset($_POST['billing_email']) ? $_POST['billing_email'] : '',
            'clientUniqueId'    => $order_id,
            'clientRequestId'   => $time .'_'. uniqid(),
            'currency'          => $order->get_currency(),
            'amount'            => (string) $order->get_total(),
            'amountDetails'     => array(
                'totalShipping'     => '0.00',
                'totalHandling'     => '0.00',
                'totalDiscount'     => '0.00',
                'totalTax'          => '0.00',
            ),
            'shippingAddress'   => array(
                'firstName'         => urlencode(preg_replace("/[[:punct:]]/", '', @$_POST['shipping_first_name'])),
                'lastName'          => urlencode(preg_replace("/[[:punct:]]/", '', @$_POST['shipping_last_name'])),
                'address'           => urlencode(preg_replace("/[[:punct:]]/", '', @$_POST['shipping_address_1'])),
                'cell'              => '',
                'phone'             => '',
                'zip'               => urlencode(preg_replace("/[[:punct:]]/", '', @$_POST['shipping_postcode'])),
                'city'              => urlencode(preg_replace("/[[:punct:]]/", '', @$_POST['shipping_city'])),
                'country'           => urlencode(preg_replace("/[[:punct:]]/", '', @$_POST['shipping_country'])),
                'state'             => '',
                'email'             => '',
                'shippingCounty'    => '',
            ),
            'urlDetails'        => array(
                'successUrl'        => $this->get_return_url(),
                'failureUrl'        => $this->get_return_url(),
                'pendingUrl'        => $this->get_return_url(),
                'notificationUrl'   => $this->set_notify_url(),
            ),
            'timeStamp'         => $time,
            'webMasterId'       => $this->webMasterId,
            'deviceDetails'     => SC_HELPER::get_device_details(),
            'sessionToken'      => @$_POST['lst'],
        );
        
        // for the session token
        if(!$params['sessionToken']) {
            $st_endpoint_url = $this->test == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL;

            $st_params = array(
                'merchantId'        => $params['merchantId'],
                'merchantSiteId'    => $params['merchantSiteId'],
                'clientRequestId'   => uniqid(),
                'timeStamp'         => $time,
            );

            $st_params['checksum'] = hash(
                $this->hash_type,
                implode('', $st_params) . $this->secret
            );

            SC_HELPER::create_log('Try to get sessionToken.');
            $session_token_data = SC_HELPER::call_rest_api($st_endpoint_url, $st_params);

            if(
                !$session_token_data || !is_array($session_token_data)
                || !isset($session_token_data['status'])
                || $session_token_data['status'] != 'SUCCESS'
            ) {
                SC_HELPER::create_log($session_token_data, '$session_token_data problem:');

                wc_add_notice(__('Payment failed, please try again later!', 'sc' ), 'error');
                return array(
                    'result' 	=> 'error',
                    'redirect'	=> add_query_arg(
                        array(),
                        wc_get_page_permalink('checkout')
                    )
                );
            }

            $params['sessionToken'] = $session_token_data['sessionToken'];
        }
        // for the session token END
        
        $params['userDetails'] = array(
            'firstName'         => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_first_name'])),
            'lastName'          => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_last_name'])),
            'address'           => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_address_1'])),
            'phone'             => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_phone'])),
            'zip'               => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_postcode'])),
            'city'              => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_city'])),
            'country'           => urlencode(preg_replace("/[[:punct:]]/", '', $_POST['billing_country'])),
            'state'             => '',
            'email'             => $_POST['billing_email'],
            'county'            => '',
        );
        
        $params['billingAddress'] = array(
            'firstName'         => $params['userDetails']['firstName'],
            'lastName'          => $params['userDetails']['lastName'],
            'address'           => $params['userDetails']['address'],
            'cell'              => '',
            'phone'             => $params['userDetails']['phone'],
            'zip'               => $params['userDetails']['zip'],
            'city'              => $params['userDetails']['city'],
            'country'           => $params['userDetails']['country'],
            'state'             => $params['userDetails']['state'],
            'email'             => $params['userDetails']['email'],
            'county'            => $params['userDetails']['county'],
        );
        
        $params['items'][0] = array(
            'name'      => $order_id,
            'price'     => $params['amount'],
            'quantity'  => 1,
        );
        
        $params['checksum'] = hash(
            $this->hash_type,
            $params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
                . $params['amount'] . $params['currency'] . $time . $this->secret
        );
        
        // in case of UPO
        if(is_numeric(@$_POST['sc_payment_method'])) {
            $params['userPaymentOption'] = array(
                'userPaymentOptionId'   => $_POST['sc_payment_method'],
                'CVV'                   => @$_POST['upo_cvv_field_' . $_POST['sc_payment_method']],
            );
            
            $params['isDynamic3D'] = 1;
            $endpoint_url = $this->test == 'no' ? SC_LIVE_D3D_URL : SC_TEST_D3D_URL;
        }
        // in case of Card
        elseif(in_array(@$_POST['sc_payment_method'], array('cc_card', 'dc_card'))) {
            if(isset($_POST[@$_POST['sc_payment_method']]['ccTempToken'])) {
                $params['cardData']['ccTempToken'] = $_POST[$_POST['sc_payment_method']]['ccTempToken'];
            }
            
            if(isset($_POST[@$_POST['sc_payment_method']]['CVV'])) {
                $params['cardData']['CVV'] = $_POST[$_POST['sc_payment_method']]['CVV'];
            }
            
            if(isset($_POST[@$_POST['sc_payment_method']]['cardHolderName'])) {
                $params['cardData']['cardHolderName'] = $_POST[$_POST['sc_payment_method']]['cardHolderName'];
            }
            
            $params['isDynamic3D'] = 1;
            $endpoint_url = $this->test == 'no' ? SC_LIVE_D3D_URL : SC_TEST_D3D_URL;
        }
        // in case of APM
        elseif(@$_POST['sc_payment_method']) {
            $is_apm_payment = true;
            $params['paymentMethod'] = $_POST['sc_payment_method'];
            
            if(isset($_POST[$_POST['sc_payment_method']])) {
                $params['userAccountDetails'] = $_POST[$_POST['sc_payment_method']];
            }
            
            $endpoint_url = $this->test == 'no' ? SC_LIVE_PAYMENT_URL : SC_TEST_PAYMENT_URL;
        }
        
        SC_HELPER::create_log('Try to create a payment with Payment Method:');
        $resp = SC_HELPER::call_rest_api($endpoint_url, $params);
        
        if(!$resp) {
            $order->add_order_note(__('There is no response for the Order.', 'sc' ));
            $order->save();
            
            return array(
                'result' 	=> 'success',
                'redirect'	=> add_query_arg(
                    array('Status' => 'error'),
                    $this->get_return_url()
                )
            );
        }

        // If we get Transaction ID save it as meta-data
        if(isset($resp['transactionId']) && $resp['transactionId']) {
            $order->update_meta_data(SC_GW_TRANS_ID_KEY, $resp['transactionId'], 0);
        }

        if(@$resp['transactionStatus'] == 'DECLINED') {
            $order->add_order_note(__('Order Declined.', 'sc' ));
            $order->set_status('cancelled');
            $order->save();
            
            return array(
                'result' 	=> 'success',
                'redirect'	=> add_query_arg(
                    array('Status' => 'error'),
                    $this->get_return_url()
                )
            );
        }
        
        if($this->get_request_status($resp) == 'ERROR' || @$resp['transactionStatus'] == 'ERROR') {
            if($order_status == 'pending') {
                $order->set_status('failed');
            }

            $error_txt = 'Payment error';

            if(@$resp['reason'] != '') {
                $error_txt .= ': ' . $resp['errCode'] . ' - ' . $resp['reason'] . '.';
            }
            elseif(@$resp['threeDReason'] != '') {
                $error_txt .= ': ' . $resp['threeDReason'] . '.';
            }
            
            $order->add_order_note(__($error_txt, 'sc' ));
            $order->save();
            
            return array(
                'result' 	=> 'success',
                'redirect'	=> add_query_arg(
                    array('Status' => 'error'),
                    $this->get_return_url()
                )
            );
        }
        
        if($this->get_request_status($resp) == 'SUCCESS') {
            # The case with D3D and P3D
            // isDynamic3D is hardcoded to be 1, see SC_REST_API line 509
            // for the three cases see: https://www.safecharge.com/docs/API/?json#dynamic3D,
            // Possible Scenarios for Dynamic 3D (isDynamic3D = 1)

            // prepare the new session data
            if(!$is_apm_payment) {
                $params_p3d = $params;
                
                $params_p3d['orderId']          = $resp['orderId'];
                $params_p3d['transactionType']  = @$resp['transactionType'];
                $params_p3d['paResponse']       = '';
                
                $_SESSION['SC_P3D_Params'] = $params_p3d;
                
                // case 1
                if(!empty(@$resp['acsUrl']) && intval(@$resp['threeDFlow']) == 1) {
                    SC_HELPER::create_log('D3D case 1');
                    
                    $_SESSION['SC_P3D_PaReq']   = @$resp['paRequest'];
                    $_SESSION['SC_P3D_acsUrl']  = @$resp['acsUrl'];

                    // step 1 - go to acsUrl
                    return array(
                        'result' 	=> 'success',
                        'redirect'	=> add_query_arg(
                            array(
                                'order-pay' => $order_id,
                                'key' => $this->get_order_data($order, 'order_key'),
                                'redirectUrl' => 1
                            ),
                            wc_get_page_permalink('pay')
                        )
                    );

                    // step 2 - wait for the DMN
                }
                // case 2
                elseif(intval(@$resp['threeDFlow']) == 1) {
                    SC_HELPER::create_log('D3D case 2.');
                    $resp = $this->pay_with_d3d_p3d();
                    
                    return array(
                        'result' 	=> 'success',
                        'redirect'	=> add_query_arg(
                            !$resp ? array('Status' => 'error') : array(),
                            $this->get_return_url()
                        )
                    );
                }
                // case 3 do nothing
            }
            # The case with D3D and P3D END

            // in case we have redirectURL
            if(isset($resp['redirectURL']) && !empty($resp['redirectURL'])) {
                SC_HELPER::create_log($resp['redirectURL'], 'we have redirectURL: ');

                if(@$resp['gwErrorCode'] == -1 || @$resp['gwErrorReason']) {
                    $msg = __('Error with the Payment: ' . @$resp['gwErrorReason'] . '.', 'sc');
                    
                    $order->add_order_note($msg);
                    $order->save();
                    
                    return array(
                        'result' 	=> 'success',
                        'redirect'	=> add_query_arg(
                            array('Status' => 'error'),
                            $this->get_return_url()
                        )
                    );
                }
                
                $_SESSION['SC_P3D_acsUrl'] = $resp['redirectURL'];

                return array(
                    'result' 	=> 'success',
                    'redirect'	=> add_query_arg(
                        array(
                            'order-pay' => $order_id,
                            'key' => $this->get_order_data($order, 'order_key'),
                            'redirectURL' => 1,
                        ),
                        wc_get_page_permalink('pay')
                    )
                );
            }
        } // when SUCCESS
        
        if(isset($resp['transactionId']) && $resp['transactionId'] != '') {
            $order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
        }
        else {
            $order->add_order_note(__('Payment succsess.', 'sc'));
        }

        // save the response transactionType value
        if(isset($resp['transactionType']) && $resp['transactionType'] != '') {
            $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $resp['transactionType']);
        }

        $order->save();
        
        return array(
            'result' 	=> 'success',
            'redirect'	=> add_query_arg(
                array(),
                $this->get_return_url()
            )
        );
    }
    
    /**
     * Function process_dmns
     * Process information from the DMNs.
     * We call this method form index.php
     */
    public function process_dmns($do_not_call_api = false)
    {
        SC_HELPER::create_log(@$_REQUEST, 'Receive DMN with params: ');
        
        $req_status = $this->get_request_status();
        
        # Sale and Auth
        if(
            isset($_REQUEST['transactionType'], $_REQUEST['invoice_id'], $_REQUEST['TransactionID'])
            && in_array($_REQUEST['transactionType'], array('Sale', 'Auth'))
            && $this->checkAdvancedCheckSum()
        ) {
            SC_HELPER::create_log('A sale/auth.');
            
            
            // Cashier
            if (!empty($_REQUEST['invoice_id'])) {
                SC_HELPER::create_log('Cashier sale.');
                
                try {
                    $arr = explode("_", $_REQUEST['invoice_id']);
                    $order_id  = intval($arr[0]);
                    
                    $order = new WC_Order($order_id);
                }
                catch (Exception $ex) {
                    SC_HELPER::create_log($ex->getMessage(), 'Cashier DMN Exception when try to get Order ID: ');
                    echo 'DMN Exception: ' . $ex->getMessage();
                    exit;
                }
            }
            // REST
            else {
                SC_HELPER::create_log('REST sale.');
                
                try {
                    $order = current( wc_get_orders( array(
                        'limit'     => 1,
                        'orderby'   => 'date',
                        'order'     => 'DESC',
                        'meta_query' => array(
                            array(
                                'key' => SC_GW_TRANS_ID_KEY,
                                'compare' => '=',
                                'value'   => $_REQUEST['TransactionID'],
                            )
                        )
                    )));
                    
                    $order_id = $order->get_id();
                }
                catch (Exception $ex) {
                    SC_HELPER::create_log($ex->getMessage(), 'REST DMN Exception when try to get the Order: ');
                    echo 'DMN Exception: ' . $ex->getMessage();
                    exit;
                }
            }
            
            try {
                $order_status = strtolower($order->get_status());
                
                $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $_REQUEST['transactionType']);
                $this->save_update_order_numbers($order);
                
                if($order_status != 'completed') {
                    $this->change_order_status($order, $arr[0], $req_status, $_REQUEST['transactionType']);
                }
            }
            catch (Exception $ex) {
                SC_HELPER::create_log($ex->getMessage(), 'Sale DMN Exception: ');
                echo 'DMN Exception: ' . $ex->getMessage();
                exit;
            }
            
            $order->add_order_note(
                __('DMN for Order #' . $order_id . ', was received.', 'sc')
            );
            $order->save();
            
            echo 'DMN received.';
            exit;
        }
        
        # Void, Settle
        if(
            isset($_REQUEST['clientUniqueId'], $_REQUEST['transactionType'])
            && $_REQUEST['clientUniqueId'] != ''
            && ($_REQUEST['transactionType'] == 'Void' || $_REQUEST['transactionType'] == 'Settle')
            && $this->checkAdvancedCheckSum()
        ) {
            SC_HELPER::create_log('', $_REQUEST['transactionType']);
            
            try {
                $order = new WC_Order($_REQUEST['clientUniqueId']);
                
                if($_REQUEST['transactionType'] == 'Settle') {
                    $this->save_update_order_numbers($order);
                }
                
                $this->change_order_status($order, $_REQUEST['clientUniqueId'], $req_status, $_REQUEST['transactionType']);
                $order_id = @$_REQUEST['clientUniqueId'];
            }
            catch (Exception $ex) {
                SC_HELPER::create_log(
                    $ex->getMessage(),
                    'process_dmns() REST API DMN DMN Exception: probably invalid order number'
                );
            }
            
            $msg = __('DMN for Order #' . $order_id . ', was received.', 'sc');
            
            if(@$_REQUEST['Reason'] && !empty($_REQUEST['Reason'])) {
                $msg .= ' ' . __($_REQUEST['Reason'] . '.', 'sc');
            }
            
            $order->add_order_note($msg);
            $order->save();
            
            echo 'DMN received.';
            exit;
        }
        
        # Refund
        // see https://www.safecharge.com/docs/API/?json#refundTransaction -> Output Parameters
        // when we refund form CPanel we get transactionType = Credit and Status = 'APPROVED'
        if(
            (@$_REQUEST['action'] == 'refund'
                || in_array(@$_REQUEST['transactionType'], array('Credit', 'Refund')))
            && !empty($req_status)
            && $this->checkAdvancedCheckSum()
        ) {
            SC_HELPER::create_log('Refund DMN.');
            
            $order = new WC_Order(@$_REQUEST['order_id']);

            if(!is_a($order, 'WC_Order')) {
                SC_HELPER::create_log('DMN meassage: there is no Order!');
                
                echo 'There is no Order';
                exit;
            }
            
            // change to Refund if request is Approved and the Order status is not Refunded
            if($req_status == 'APPROVED') {
                $this->change_order_status(
                    $order, $order->get_id(),
                    'APPROVED',
                    'Credit',
                    array(
                        'resp_id'       => @$_REQUEST['clientUniqueId'],
                        'totalAmount'   => @$_REQUEST['totalAmount']
                    )
                );
            }
            elseif(
                @$_REQUEST['transactionStatus'] == 'DECLINED'
                || @$_REQUEST['transactionStatus'] == 'ERROR'
            ) {
                $msg = 'DMN message: Your try to Refund #' . $_REQUEST['clientUniqueId']
                    .' faild with ERROR: "';

                // in case DMN URL was rewrited all spaces were replaces with "_"
                if(@$_REQUEST['wc_sc_redirected'] == 1) {
                    $msg .= str_replace('_', ' ', @$_REQUEST['gwErrorReason']);
                }
                else {
                    $msg .= @$_REQUEST['gwErrorReason'];
                }

                $msg .= '".';

                $order -> add_order_note(__($msg, 'sc'));
                $order->save();
            }
            
            echo 'DMN received - Refund.';
            exit;
        }
        
        # D3D and P3D payment
        // the idea here is to get $_REQUEST['paResponse'] and pass it to P3D
        elseif(@$_REQUEST['action'] == 'p3d') {
            SC_HELPER::create_log('p3d.');
            
            // the DMN from case 1 - issuer/bank
            if(
                isset($_SESSION['SC_P3D_Params'], $_REQUEST['PaRes'])
                && is_array($_SESSION['SC_P3D_Params'])
            ) {
                $_SESSION['SC_P3D_Params']['paResponse'] = $_REQUEST['PaRes'];
                $resp = $this->pay_with_d3d_p3d();
                $url = $this->get_return_url();
                
                if(!$resp) {
                    $url .= '?Status=error';
                }
                
                echo 
                    '<script>'
                        .'window.location.href = "'. $url .'";'
                    .'</script>';
                exit;
            }
            // the DMN from case 2 - p3d
            elseif(isset($_REQUEST['merchantId'], $_REQUEST['merchantSiteId'])) {
                // here we must unset $_SESSION['SC_P3D_Params'] as last step
                try {
                    $order = new WC_Order(@$_REQUEST['clientUniqueId']);

                    $this->change_order_status(
                        $order
                        ,@$_REQUEST['clientUniqueId']
                        ,$this->get_request_status()
                        ,@$_REQUEST['transactionType']
                    );
                }
                catch (Exception $ex) {
                    SC_HELPER::create_log(
                        $ex->getMessage(),
                        'process_dmns() REST API DMN DMN Exception: '
                    );
                }
            }
            
            if(isset($_SESSION['SC_P3D_Params'])) {
                unset($_SESSION['SC_P3D_Params']);
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # other cases
        if(!isset($_REQUEST['action']) && $this->checkAdvancedCheckSum()) {
            SC_HELPER::create_log('', 'Other cases.');
            
            try {
                $order = new WC_Order(@$_REQUEST['clientUniqueId']);

                $this->change_order_status(
                    $order
                    ,@$_REQUEST['clientUniqueId']
                    ,$this->get_request_status()
                    ,@$_REQUEST['transactionType']
                );
            }
            catch (Exception $ex) {
                SC_HELPER::create_log(
                    $ex->getMessage(),
                    'process_dmns() REST API DMN Exception: '
                );
                
                echo 'Exception error.';
                exit;
            }
            
            echo 'DMN received.';
            exit;
        }
        
        if($req_status == '') {
            echo 'Error: the DMN Status is empty!';
            exit;
        }
        
        echo 'DMN was not recognized.';
        exit;
    }
    
    public function sc_checkout_process()
    {
        SC_HELPER::create_log($_POST, 'post sc_checkout_process:');
        
        $_SESSION['sc_subpayment'] = '';
        if(isset($_POST['sc_payment_method'])) {
            $_SESSION['sc_subpayment'] = $_POST['sc_payment_method'];
        }
        
		return true;
	}

	public function showMessage($content)
    {
        return '<div class="box '.$this->msg['class'].'-box">'.$this->msg['message'].'</div>'.$content;
    }

    /**
     * @return boolean
     */
	public function checkAdvancedCheckSum()
    {
        $str = hash(
            $this->hash_type,
            $this->secret . @$_REQUEST['totalAmount'] . @$_REQUEST['currency']
                . @$_REQUEST['responseTimeStamp'] . @$_REQUEST['PPP_TransactionID']
                . $this->get_request_status() . @$_REQUEST['productId']
        );

        if ($str == @$_REQUEST['advanceResponseChecksum']) {
            return true;
        }
        
        return false;
	}
    
    public function set_notify_url()
    {
        $url_part = get_site_url();
            
        $url = $url_part
            . (strpos($url_part, '?') != false ? '&' : '?') . 'wc-api=sc_listener';
        
        // some servers needs / before ?
        if(strpos($url, '?') !== false && strpos($url, '/?') === false) {
		$url = str_replace('?', '/?', $url);
	}
        
        // force Notification URL protocol to http
        if(isset($this->use_http) && $this->use_http == 'yes' && strpos($url, 'https://') !== false) {
            $url = str_replace('https://', '', $url);
            $url = 'http://' . $url;
        }
        
        return $url;
    }
    
    /**
     * Function get_order_data
     * Extract the data from the order.
     * We use this function in index.php so it must be public.
     * 
     * @param WC_Order $order
     * @param string $key - a key name to extract
     */
    public function get_order_data($order, $key = 'completed_date')
    {
        switch($key) {
            case 'completed_date':
                return $order->get_date_completed() ?
                    gmdate( 'Y-m-d H:i:s', $order->get_date_completed()->getOffsetTimestamp() ) : '';

            case 'paid_date':
                return $order->get_date_paid() ?
                    gmdate( 'Y-m-d H:i:s', $order->get_date_paid()->getOffsetTimestamp() ) : '';

            case 'modified_date':
                return $order->get_date_modified() ?
                    gmdate( 'Y-m-d H:i:s', $order->get_date_modified()->getOffsetTimestamp() ) : '';

            case 'order_date';
                return $order->get_date_created() ?
                    gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getOffsetTimestamp() ) : '';

            case 'id':
                return $order->get_id();

            case 'post':
                return get_post( $order->get_id() );

            case 'status':
                return $order->get_status();

            case 'post_status':
                return get_post_status( $order->get_id() );

            case 'customer_message':
            case 'customer_note':
                return $order->get_customer_note();

            case 'user_id':
            case 'customer_user':
                return $order->get_customer_id();

            case 'tax_display_cart':
                return get_option( 'woocommerce_tax_display_cart' );

            case 'display_totals_ex_tax':
                return 'excl' === get_option( 'woocommerce_tax_display_cart' );

            case 'display_cart_ex_tax':
                return 'excl' === get_option( 'woocommerce_tax_display_cart' );

            case 'cart_discount':
                return $order->get_total_discount();

            case 'cart_discount_tax':
                return $order->get_discount_tax();

            case 'order_tax':
                return $order->get_cart_tax();

            case 'order_shipping_tax':
                return $order->get_shipping_tax();

            case 'order_shipping':
                return $order->get_shipping_total();

            case 'order_total':
                return $order->get_total();

            case 'order_type':
                return $order->get_type();

            case 'order_currency':
                return $order->get_currency();

            case 'order_version':
                return $order->get_version();

            default:
                return get_post_meta( $order->get_id(), '_' . $key, true );
        }

        // try to call {get_$key} method
        if ( is_callable( array( $order, "get_{$key}" ) ) ) {
            return $order->{"get_{$key}"}();
        }
    }
    
    /**
     * Function process_refund
     * A overwrite original function to enable auto refund in WC.
     * 
     * @param  int    $order_id Order ID.
	 * @param  float  $amount Refund amount.
	 * @param  string $reason Refund reason.
     * 
	 * @return boolean
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        if($_POST['api_refund'] == 'true') {
			return true;
		}
		
		return false;
	}
    
    /**
     * Function create_refund
     * Create Refund in SC by Refund from WC, after the merchant
     * click refund button or set Status to Refunded
     * 
     * @param object $refund_data
     */
    public function create_refund_in_wc($refund)
    {
        if(@$_POST['api_refund'] == 'false' || !$refund) {
			return false;
        }
        
        // get order refunds
        try {
            $refund_data = $refund->get_data();
            $refund_data['webMasterId'] = $this->webMasterId; // need this param for the API
            
            // the hooks calling this method, fired twice when change status
            // to Refunded, but we do not want to try more than one SC Refunds
            if(isset($_SESSION['sc_last_refund_id'])) {
            //    SC_HELPER::create_log($_SESSION['sc_last_refund_id'], 'we have session: ');
            //    SC_HELPER::create_log($refund_data['id'], 'refund id: ');
                
                if(intval($_SESSION['sc_last_refund_id']) == intval($refund_data['id'])) {
                    unset($_SESSION['sc_last_refund_id']);
                    return;
                }
                else {
                    $_SESSION['sc_last_refund_id'] = $refund_data['id'];
                }
            }
            else {
            //    SC_HELPER::create_log($refund_data['id'], 'create session: ');
                $_SESSION['sc_last_refund_id'] = $refund_data['id'];
            }
            
            $order_id = intval(@$_REQUEST['order_id']);
            // when we set status to Refunded
            if(isset($_REQUEST['post_ID'])) {
                $order_id = intval($_REQUEST['post_ID']);
            }
            
            $order = new WC_Order($order_id);
            
            $order_meta_data = array(
                'order_tr_id'   => $order->get_meta(SC_GW_TRANS_ID_KEY),
                'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
            );
        }
        catch (Exception $ex) {
            SC_HELPER::create_log($ex->getMessage(), 'sc_create_refund() Exception: ');
            return;
        }
        
        if(!$order_meta_data['order_tr_id'] && !$order_meta_data['auth_code']) {
            $order->add_order_note(__('Missing Auth code and Transaction ID.', 'sc'));
            $order->save();
            
            return;
        }

        if(!is_array($refund_data)) {
            $order->add_order_note(__('There is no refund data. If refund was made, delete it manually!', 'sc'));
            $order->save();
            
            return;
        }

        $notify_url     = $this->set_notify_url();
        $notify_url     .= '&action=refund&order_id=' . $order_id;
        
        $refund_url     = SC_TEST_REFUND_URL;
        $cpanel_url     = SC_TEST_CPANEL_URL;

        if($this->settings['test'] == 'no') {
            $refund_url = SC_LIVE_REFUND_URL;
            $cpanel_url = SC_LIVE_CPANEL_URL;
        }
        
        $time = date('YmdHis', time());
        
        $ref_parameters = array(
            'merchantId'            => $this->settings['merchantId'],
            'merchantSiteId'        => $this->settings['merchantSiteId'],
            'clientRequestId'       => $time . '_' . $order_meta_data['order_tr_id'],
            'clientUniqueId'        => $refund_data['id'],
            'amount'                => number_format($refund_data['amount'], 2, '.', ''),
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $order_meta_data['order_tr_id'], // GW Transaction ID
            'authCode'              => $order_meta_data['auth_code'],
            'comment'               => $refund_data['reason'], // optional
            'url'                   => $notify_url,
            'timeStamp'             => $time,
        );
        
        $checksum_str = implode('', $ref_parameters);
        
        $checksum = hash(
            $this->settings['hash_type'],
            $checksum_str . $this->settings['secret']
        );
        
        $ref_parameters['checksum']     = $checksum;
        $ref_parameters['urlDetails']   = array(
            'notificationUrl' => $notify_url
        );
        $ref_parameters['webMasterId']  = $refund_data['webMasterId'];
        
        $resp = SC_HELPER::call_rest_api($refund_url, $ref_parameters);

        $msg = '';
        $error_note = 'Please manually delete request Refund #'
            .$refund_data['id'].' form the order or login into <i>'. $cpanel_url
            .'</i> and refund Transaction ID '.$order_meta_data['order_tr_id'];

        if($resp === false) {
            $msg = 'The REST API retun false. ' . $error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        $json_arr = $resp;
        if(!is_array($resp)) {
            parse_str($resp, $json_arr);
        }

        if(!is_array($json_arr)) {
            $msg = 'Invalid API response. ' . $error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        // in case we have message but without status
        if(!isset($json_arr['status']) && isset($json_arr['msg'])) {
            // save response message in the History
            $msg = 'Request Refund #' . $refund_data['id'] . ' problem: ' . $json_arr['msg'];

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        // the status of the request is ERROR
        if(@$json_arr['status'] == 'ERROR') {
            $msg = 'Request ERROR - "' . $json_arr['reason'] .'" '. $error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
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
            return;
        }

        if(@$json_arr['transactionStatus'] == 'DECLINED') {
            $msg = 'The refund was declined. ' .$error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        if(@$json_arr['transactionStatus'] == 'APPROVED') {
            return;
        }

        $msg = 'The status of request - Refund #' . $refund_data['id'] . ', is UNKONOWN.';

        $order->add_order_note(__($msg, 'sc'));
        $order->save();
        
        return;
    }
    
    public function sc_return_sc_settle_btn($args) {
        // revert buttons on Recalculate
        if(!isset($_REQUEST['refund_amount']) && isset($_REQUEST['items'])) {
            echo '<script type="text/javascript">returnSCBtns();</script>';
        }
    }

    /**
     * 
     * @param int $order_id
     * @return void
     */
    public function sc_restock_on_refunded_status($order_id)
    {
        $order = new WC_Order($order_id);
        $items = $order->get_items();
        $is_order_restock = $order->get_meta('_scIsRestock');
        
        // do restock only once
        if($is_order_restock != 1) {
            wc_restock_refunded_items($order, $items);
            $order->update_meta_data('_scIsRestock', 1);
            $order->save();
            
            SC_HELPER::create_log('Items were restocked.');
        }
        
        return;
    }
    
    /**
     * Function checkout_open_order
     * On Checkout page when the user use REST API, prepare
     * and open an order.
     * 
     * @global type $woocommerce
     * @return void
     */
    public function checkout_open_order()
    {
        if($this->payment_api == 'cashier') {
            return;
        }
        
        global $woocommerce;
        
        if(empty($woocommerce->cart->get_totals())) {
            echo
                '<script type="text/javascript">'
                    . 'alert("Error with you Cart data. Please try again later!");'
                . '</script>';
            
            return;
        }
        
        $_SESSION['SC_Variables']['other_urls'] = $this->get_return_url(); // put this in _construct and site will crash :)
        $cart_totals            = $woocommerce->cart->get_totals();
        
//        $params = array(
//            'merchantId'        => $this->merchantId,
//            'merchantSiteId'    => $this->merchantSiteId,
//            'clientRequestId'   => $_SESSION['SC_Variables']['cri1'],
//            'amount'            => $cart_totals['total'],
//            'currency'          => $_SESSION['SC_Variables']['currencyCode'],
//            'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri1'])),
//        );
//        
//        $params['checksum']     = hash(
//            $this->hash_type,
//            implode('', $params) . $this->secret
//        );
        
            $checksum = hash(
                $this->hash_type,
                $this->merchantId . $this->merchantSiteId . $_SESSION['SC_Variables']['cri1']
                    . $cart_totals['total'] . $_SESSION['SC_Variables']['currencyCode']
                    . current(explode('_', $_SESSION['SC_Variables']['cri1']))
                    . $this->secret
            );
            
            echo
                '<script type="text/javascript">'
                    . 'scOrderAmount    = "' . $cart_totals['total'] . '"; '
                    . 'scOOChecksum     = "' . $checksum . '"; '
                . '</script>';
    }
    
    /**
     * Function change_order_status
     * Change the status of the order.
     * 
     * @param object $order
     * @param int $order_id
     * @param string $status
     * @param string $transactionType - not mandatory for the DMN
     * @param array $res_args - we must use $res_args instead $_REQUEST, if not empty
     */
    private function change_order_status($order, $order_id, $status, $transactionType = '', $res_args = array())
    {
        SC_HELPER::create_log(
            'Order ' . $order_id .' has Status: ' . $status,
            'WC_SC change_order_status() status-order: '
        );
        
        $request = @$_REQUEST;
        if(!empty($res_args)) {
            $request = $res_args;
        }
        
        switch($status) {
            case 'CANCELED':
                $message = 'Payment status changed to:' . $status
                    .'. PPP_TransactionID = '. @$request['PPP_TransactionID']
                    .", Status = " .$status. ', GW_TransactionID = '
                    .@$request['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->update_status('failed');
                $order->add_order_note('Failed');
                $order->add_order_note($this->msg['message']);
            break;

            case 'APPROVED':
                if($transactionType == 'Void') {
                    $order->add_order_note(__('DMN message: Your Void request was success, Order #'
                        . @$_REQUEST['clientUniqueId'] . ' was canceld. Plsese check your stock!', 'sc'));

                    $order->update_status('cancelled');
                    break;
                }
                
                // Refun Approved
                if($transactionType == 'Credit') {
                    $order->add_order_note(
                        __('DMN message: Your Refund #' . $res_args['resp_id']
                            .' was successful. Refund Transaction ID is: ', 'sc')
                            . @$_REQUEST['TransactionID'] . '.'
                    );
                    
                    // set flag that order has some refunds
                    $order->update_meta_data('_scHasRefund', 1);
                    $order->save();
                    break;
                }
                
                $message = 'The amount has been authorized and captured by '
                    . SC_GATEWAY_TITLE . '. ';
                
                if($transactionType == 'Auth') {
                    $message = 'The amount has been authorized and wait to for Settle. ';
                }
                elseif($transactionType == 'Settle') {
                    $message = 'The amount has been captured by ' . SC_GATEWAY_TITLE . '. ';
                }
                
                $message .= 'PPP_TransactionID = ' . @$request['PPP_TransactionID']
                    . ", Status = ". $status;

                if($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '. @$request['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->payment_complete($order_id);
                
                if($transactionType == 'Auth') {
                    $order->update_status('pending');
                }
                // Settle - do two steps
                else {
                    $order->update_status('processing');
                    $order->save();
                    
                    $order->update_status('completed');
                }
            //    $order->update_status($transactionType == 'Auth' ? 'pending' : 'completed');
                
                if($transactionType != 'Auth') {
                    $order->add_order_note(SC_GATEWAY_TITLE . ' payment is successful<br/>Unique Id: '
                        . @$request['PPP_TransactionID']);
                }

                $order->add_order_note($this->msg['message']);
            break;

            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $reason = ', Reason = ';
                if(isset($request['reason']) && $request['reason'] != '') {
                    $reason .= $request['reason'];
                }
                elseif(isset($request['Reason']) && $request['Reason'] != '') {
                    $reason .= $request['Reason'];
                }
                
                $message = 'Payment failed. PPP_TransactionID = '. @$request['PPP_TransactionID']
                    . ", Status = ". $status .", Error code = ". @$request['ErrCode']
                    . ", Message = ". @$request['message']
                    . $reason;
                
                if($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '. @$request['TransactionID'];
                
                // do not change status
                if($transactionType == 'Void') {
                    $message = 'DMN message: Your Void request fail with message: "';

                    // in case DMN URL was rewrited all spaces were replaces with "_"
                    if(@$_REQUEST['wc_sc_redirected'] == 1) {
                        $message .= str_replace('_', ' ', @$_REQUEST['message']);
                    }
                    else {
                        $message .= @$_REQUEST['msg'];
                    }

                    $message .= '". Order #'  . $_REQUEST['clientUniqueId']
                        .' was not canceld!';
                    
                    $order->add_order_note(__($message, 'sc'));
                    $order->save();
                    break;
                }
                
                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                
                $order->update_status('failed');
                $order->add_order_note(__($message, 'sc'));
                $order->save();
            break;

            case 'PENDING':
                $ord_status = $order->get_status();
                if ($ord_status == 'processing' || $ord_status == 'completed') {
                    break;
                }

                $message ='Payment is still pending, PPP_TransactionID '
                    .@$request['PPP_TransactionID'] .", Status = ". $status;

                if($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '. @$request['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message woocommerce_message_info';
                $order->add_order_note(SC_GATEWAY_TITLE .' payment status is pending<br/>Unique Id: '
                    .@$request['PPP_TransactionID']);

                $order->add_order_note($this->msg['message']);
                $order->update_status('on-hold');
            break;
        }
        
        $order->save();
    }
    
    private function formatLocation($locale)
    {
		switch ($locale){
            case 'de_DE':
				return 'de';
                
            case 'zh_CN':
				return 'zh';
                
            case 'en_GB':
            default:
                return 'en';
		}
	}
    
    /**
     * Function save_update_order_numbers
     * Save or update order AuthCode and TransactionID on status change.
     * 
     * @param object $order
     */
    private function save_update_order_numbers($order)
    {
        // save or update AuthCode and GW Transaction ID
        $auth_code = isset($_REQUEST['AuthCode']) ? $_REQUEST['AuthCode'] : '';
        $saved_ac = $order->get_meta(SC_AUTH_CODE_KEY);

        if(!$saved_ac || empty($saved_ac) || $saved_ac !== $auth_code) {
            $order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
        }

        $gw_transaction_id = isset($_REQUEST['TransactionID']) ? $_REQUEST['TransactionID'] : '';
        $saved_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);

        if(!$saved_tr_id || empty($saved_tr_id) || $saved_tr_id !== $gw_transaction_id) {
            $order->update_meta_data(SC_GW_TRANS_ID_KEY, $gw_transaction_id);
        }
        
        if(isset($_REQUEST['payment_method']) && $_REQUEST['payment_method']) {
            $order->update_meta_data('_paymentMethod', $_REQUEST['payment_method']);
        }

        $order->save();
    }
    
    /**
     * Function get_request_status
     * We need this stupid function because as response request variable
     * we get 'Status' or 'status'...
     * 
     * @return string
     */
    private function get_request_status($params = array())
    {
        if(empty($params)) {
            if(isset($_REQUEST['Status'])) {
                return $_REQUEST['Status'];
            }

            if(isset($_REQUEST['status'])) {
                return $_REQUEST['status'];
            }
        }
        else {
            if(isset($params['Status'])) {
                return $params['Status'];
            }

            if(isset($params['status'])) {
                return $params['status'];
            }
        }
        
        return '';
    }
    
}

?>
