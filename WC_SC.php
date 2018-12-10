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
    
    public function __construct()
    {
        require_once 'SC_Versions_Resolver.php';
        
        $plugin_dir = basename(dirname(__FILE__));
        $this->plugin_path = plugin_dir_path( __FILE__ ) . $plugin_dir . '/';
        $this->plugin_url = get_site_url() . '/wp-content/plugins/'.$plugin_dir.'/';
        
        # settings to get/save options
		$this->id                   = 'sc';
		$this->method_title         = SC_GATEWAY_TITLE;
		$this->method_description   = 'Pay with '. SC_GATEWAY_TITLE .'.';
        $this->icon                 = $this->plugin_url."icons/safecharge.png";
		$this->has_fields           = false;

		$this->init_form_fields();
		$this->init_settings();
        
        //var_dump($this->settings);die;
        
		$this->title            = @$this->settings['title'] ? $this->settings['title'] : '';
		$this->description      = @$this->settings['description'] ? $this->settings['description'] : '';
		$this->merchantId       = @$this->settings['merchantId'] ? $this->settings['merchantId'] : '';
		$this->merchantSiteId   = @$this->settings['merchantSiteId'] ? $this->settings['merchantSiteId'] : '';
        $this->secret           = @$this->settings['secret'] ? $this->settings['secret'] : '';
		$this->test             = @$this->settings['test'] ? $this->settings['test'] : 'yes';
		$this->use_http         = @$this->settings['use_http'] ? $this->settings['use_http'] : 'yes';
		$this->show_thanks_msg  = @$this->settings['show_thanks_msg'] ? $this->settings['show_thanks_msg'] : 'no';
        $this->save_logs        = @$this->settings['save_logs'] ? $this->settings['save_logs'] : 'yes';
        $this->hash_type        = @$this->settings['hash_type'] ? $this->settings['hash_type'] : 'sha256';
		$this->payment_api      = @$this->settings['payment_api'] ? $this->settings['payment_api'] : 'cashier';
		$this->transaction_type = @$this->settings['transaction_type'] ? $this->settings['transaction_type'] : 'sale';
		$this->rewrite_dmn      = @$this->settings['rewrite_dmn'] ? $this->settings['rewrite_dmn'] : 'no';
		$this->use_wpml_thanks_page =
            @$this->settings['use_wpml_thanks_page'] ? $this->settings['use_wpml_thanks_page'] : 'no';
        // to enable auto refund support
        $this->supports         = array('products', 'refunds');
        
        # set session variables for REST API, according REST variables names
        $_SESSION['SC_Variables']['merchantId']         = $this->merchantId;
        $_SESSION['SC_Variables']['merchantSiteId']     = $this->merchantSiteId;
        $_SESSION['SC_Variables']['currencyCode']       = get_woocommerce_currency();
        $_SESSION['SC_Variables']['languageCode']       = $this->formatLocation(get_locale());
        $_SESSION['SC_Variables']['payment_api']        = $this->payment_api;
        $_SESSION['SC_Variables']['transactionType']    = $this->transaction_type;
        $_SESSION['SC_Variables']['test']               = $this->test;
        $_SESSION['SC_Variables']['save_logs']          = $this->save_logs;
        $_SESSION['SC_Variables']['rewrite_dmn']        = $this->rewrite_dmn;
        
        $client = new WC_Customer();
        $_SESSION['SC_Variables']['sc_country'] = SC_Versions_Resolver::get_client_country(new WC_Customer);
    //    $_SESSION['SC_Variables']['sc_country'] = $client->get_billing_country();
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

        SC_Versions_Resolver::process_admin_options($this);
    //    add_action('woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ));
        
		add_action('woocommerce_checkout_process', array($this, 'sc_checkout_process'));
		add_action('woocommerce_receipt_'.$this->id, array($this, 'receipt_page'));
	//	add_action('woocommerce_api_wc_gateway_sc', array($this, 'process_sc_notification'));
	//	add_action('woocommerce_api_wc_sc_rest', array($this, 'process_sc_notification'));
		add_action('woocommerce_api_sc_listener', array($this, 'process_sc_notification'));
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
                'title' => __('Title:', 'sc'),
                'type'=> 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'sc'),
                'default' => __(SC_GATEWAY_TITLE, 'sc')
            ),
            'description' => array(
                'title' => __('Description:', 'sc'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'sc'),
                'default' => __('Pay securely by Credit or Debit card or local payment option through '
                    .SC_GATEWAY_TITLE .' secured payment page.', 'sc')
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
                'title' => __('Payment API', 'sc'),
                'type' => 'select',
                'description' => __('Select '. SC_GATEWAY_TITLE .' payment API', 'sc'),
                'options' => array(
                    'cashier' => 'Cashier',
                    'rest' => 'REST API',
                )
            ),
            'transaction_type' => array(
                'title' => __('Transaction Type', 'sc'),
                'type' => 'select',
                'description' => __('Select preferred Transaction Type.', 'sc'),
                'options' => array(
                    'a&s' => 'Auth and Settle',
                    'sale' => 'Sale',
                )
            ),
            'test' => array(
                'title' => __('Test mode', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'sc'),
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
                'label' => __('Works only if you have installed and configured WPML plugin.', 'sc'),
                'default' => 'no'
            ),
            'show_thanks_msg' => array(
                'title' => __('Show "Loading message"', 'sc'),
                'type' => 'checkbox',
                'label' => __('Show "Loading message" when redirect to secure Cashier. Does not work on the REST API.', 'sc'),
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
    public function generate_button_html( $key, $data ) {
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
        
        // echo here some html part if needed
    }

	/**
     * Receipt Page
     **/
    public function receipt_page($order_id)
    {
       $this->generate_sc_form($order_id);
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
        global $woocommerce;
        
		$TimeStamp = date('Ymdhis');
        $order = new WC_Order($order_id);
        $order_status = strtolower($order->get_status());
        
        $cust_fields = $order->get_meta_data();
        $cust_fields2 = get_post_meta($order_id, 'payment_method_sc', true);
        
    //    $this->create_log($order, "the order data: ");
        
        $order->add_order_note("User is redicted to ".SC_GATEWAY_TITLE." Payment page.");
        $order->save();
        
		$items = $order->get_items();
		$i = 1;
		
        $this->set_environment();
        
        $notify_url = $this->set_notify_url();
        
        $params['site_url'] = get_site_url();
        // easy way to pass them to REST API
        $params['items'] = array();
		
        foreach ( $items as $item ) {
			$params['item_name_'.$i]        = $item['name'];
			$params['item_number_'.$i]      = $item['product_id'];
            $params['item_quantity_'.$i]    = $item['qty'];
            
            // $item['line_total']  = price - discount
        //    $amount                         = number_format($item['line_total'] / (int) $item['qty'], 2, '.', '');
            // this is the real price
            $item_price                     = number_format($item['line_subtotal'] / (int) $item['qty'], 2, '.', '');
			$params['item_amount_'.$i]      = $item_price;
            
            // set product img url
            $prod_img_path = '';
            $prod_img_data = wp_get_attachment_image_src(get_post_thumbnail_id($item['product_id']));
            if(
                $prod_img_data
                && is_array($prod_img_data)
                && !empty($prod_img_data)
                && isset($prod_img_data[0])
                && $prod_img_data[0] != ''
            ) {
                $prod_img_path = str_replace($params['site_url'], '', $prod_img_data[0]);
            }
        //    $params['item_image_'.$i] = $prod_img_path;
            // set product img url END
			
            // Use this ONLY when the merchant is not using open amount and when there is an items table on their theme.
            //$params['item_discount_'.$i]    = number_format(($item_price - $amount), 2, '.', '');
            
            $params['items'][] = array(
                'name' => $item['name'],
                'price' => $item_price,
                'quantity' => $item['qty'],
            );
            
            $i++;
		}
        
        $params['numberofitems'] = $i-1;
        
        $params['handling'] = SC_Versions_Resolver::get_shipping($order);
    //    $params['handling'] = $order->get_shipping_total();
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
        
        $params['total_tax'] = number_format($total_tax_prec, 2, '.', '');
		$params['merchant_id'] = $this->merchantId;
		$params['merchant_site_id'] = $this->merchantSiteId;
		$params['time_stamp'] = $TimeStamp;
		$params['encoding'] ='utf8';
		$params['version'] = '4.0.0';

        $payment_page = SC_Versions_Resolver::get_page_id($order, 'pay');
    //    $payment_page = wc_get_page_permalink($page);
        
		if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
            $payment_page = str_replace( 'http:', 'https:', $payment_page );
        }
		
        $params['success_url']          = $this->get_return_url();
		$params['pending_url']          = $this->get_return_url();
		$params['error_url']            = $this->get_return_url();
		$params['back_url']             = $payment_page;
	//	$params['notify_url']           = $notify_url . 'WC_Gateway_SC';
		$params['notify_url']           = $notify_url . 'sc_listener';
		$params['invoice_id']           = $order_id.'_'.$TimeStamp;
		$params['merchant_unique_id']   = $order_id.'_'.$TimeStamp;
        
        // get and pass to cashier billing data
		$params['first_name'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_first_name')));
        //    urlencode(preg_replace("/[[:punct:]]/", '', $this->get_order_data($order, 'billing_first_name')));
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
        $params['user_token_id']    = SC_Versions_Resolver::get_order_data($order, 'billing_email');
        // get and pass to cashier billing data END
        
        // get and pass to cashier hipping data
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
        // get and pass to cashier hipping data END
        
        $params['user_token'] = "auto";
        
        $params['payment_method'] = '';
        if(isset($_SESSION['sc_subpayment']) && $_SESSION['sc_subpayment'] != '') {
            $params['payment_method'] = str_replace($this->id.'_', '', $_SESSION['sc_subpayment']);
        }
		
        $params['merchantLocale']   = $this->formatLocation(get_locale());
        $params['total_amount']     = SC_Versions_Resolver::get_order_data($order, 'order_total');
        $params['currency']         = get_woocommerce_currency();
        $params['merchantLocale']   = get_locale();
        
        $for_hash = '';
		foreach($params as $k => $v) {
            if(!is_array($v)) {
                $for_hash .= $v;
            }
		}
        
        # Cashier payment
        if($this->payment_api == 'cashier') {
            $params['checksum'] = hash($this->hash_type, stripslashes($this->secret . $for_hash));
            
            $params_array = array();
            foreach($params as $key => $value) {
                if(!is_array($value)) {
                    $params_array[] = "<input type='hidden' name='$key' value='$value'/>";
                }
            }

            $this->create_log($this->URL, 'Endpoint URL: ');
            $this->create_log($for_hash, '$for_hash: ');
            $this->create_log($this->hash_type, '$this->hash_type: ');
            $this->create_log($params, 'Order params');

            $html =
                '<form action="'.$this->URL.'" method="post" id="sc_payment_form">'
                    .implode('', $params_array)
                    .'<noscript>'
                        .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'.__('Pay via '. SC_GATEWAY_TITLE, 'sc').'" /><a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'sc').'</a>'
                    .'</noscript>'
                    .'<script type="text/javascript">'
                        .'jQuery(function(){';

            if(isset($this->show_thanks_msg) && $this->show_thanks_msg == 'yes') {
                $html .=
                            'jQuery("body").block({'
                                .'message: \'<img src="'.$this->plugin_url.'icons/loading.gif" alt="Redirecting!" style="width:100px; float:left; margin-right: 10px;" />'.__('Thank you for your order. We are now redirecting you to '. SC_GATEWAY_TITLE .' Payment Gateway to make payment.', 'sc').'\','
                                .'overlayCSS: {background: "#fff", opacity: 0.6},'
                                .'css: {'
                                    .'padding: 20,'
                                    .'textAlign: "center",'
                                    .'color: "#555",'
                                    .'border: "3px solid #aaa",'
                                    .'backgroundColor: "#fff",'
                                    .'cursor: "wait",'
                                    .'lineHeight: "32px"'
                                .'}'
                            .'});';
            }

            $html .=
                            'jQuery("#sc_payment_form").submit();'
                        .'});'
                    .'</script>'
                .'</form>';

            echo $html;
        }
        # REST API payment
        elseif($this->payment_api == 'rest') {
            // map here variables names different for Cashier and REST
            $params['merchantId'] = $this->merchantId;
            $params['merchantSiteId'] = $this->merchantSiteId;
        //    $params['notify_url'] = $notify_url . 'WC_SC_Rest';
            $params['notify_url'] = $notify_url . 'sc_listener';
            // map here variables names different for Cashier and REST END
            
            $params['client_request_id'] = $TimeStamp .'_'. uniqid();
            
            $params['urlDetails'] = array(
                'successUrl'        => $this->get_return_url(),
                'failureUrl'        => $this->get_return_url(),
                'pendingUrl'        => $this->get_return_url(),
            //    'notificationUrl'   => $notify_url . 'WC_SC_Rest',
                'notificationUrl'   => $notify_url . 'sc_listener',
            );
                
            $params['checksum'] = hash($this->settings['hash_type'], stripslashes(
                $_SESSION['SC_Variables']['merchantId']
                .$_SESSION['SC_Variables']['merchantSiteId']
                .$params['client_request_id']
                .$params['total_amount']
                .$params['currency']
                .$TimeStamp
                .$this->secret
            ));
            
            require_once 'SC_REST_API.php';
            
            // set the payment method type
            $payment_method = 'apm';
            if(@$_SESSION['SC_Variables']['APM_data']['payment_method'] == 'cc_card') {
                $payment_method = 'd3d';
            }
            
            $this->create_log($params, 'params sent to REST: ');
            $this->create_log($_SESSION['SC_Variables'], 'SC_Variables: ');
            
            // ALWAYS CHECK USED PARAMS IN process_payment
            $resp = SC_REST_API::process_payment(
                $params
                ,$_SESSION['SC_Variables']
                ,$_REQUEST['order-pay']
                ,$payment_method
            );
            
            if(!$resp) {
                if($order_status == 'pending') {
                    $order->set_status('failed');
                }
                
                $order->add_order_note(__('Payment API response is FALSE.', 'sc'));
                $order->save();
                
                $this->create_log($resp, 'REST API Payment ERROR: ');
                
                echo 
                    '<script>'
                        .'window.location.href = "'.$params['error_url'].'?Status=fail";'
                    .'</script>';
                exit;
            }
            
            // If we get Transaction ID save it as meta-data
            if(isset($resp['transactionId']) && $resp['transactionId']) {
                $order->update_meta_data(SC_GW_TRANS_ID_KEY, $resp['transactionId'], 0);
            }
            
            if(
                $this->get_request_status($resp) == 'ERROR'
                || @$resp['transactionStatus'] == 'ERROR'
            ) {
                if($order_status == 'pending') {
                    $order->set_status('failed');
                }
                
                $error_txt = 'Payment error';
                
                if(@$resp['reason'] != '') {
                    $error_txt = ': ' . $resp['reason'] . '.';
                }
                elseif(@$resp['transactionStatus'] != '') {
                    $error_txt = ': ' . $resp['transactionStatus'] . '.';
                }
                elseif(@$resp['threeDReason'] != '') {
                    $error_txt = ': ' . $resp['threeDReason'] . '.';
                }
                
                $order->add_order_note($error_txt);
                $order->save();
                
                $this->create_log($resp['errCode'].': '.$resp['reason'], 'REST API Payment ERROR: ');
                
                echo 
                    '<script>'
                        .'window.location.href = "'.$params['error_url'].'?Status=fail";'
                    .'</script>';
                exit;
            }
            
            // pay with redirect URL
            if($this->get_request_status($resp) == 'SUCCESS') {
                # The case with D3D and P3D
                // isDynamic3D is hardcoded to be 1, see SC_REST_API line 509
                // for the three cases see: https://www.safecharge.com/docs/API/?json#dynamic3D,
                // Possible Scenarios for Dynamic 3D (isDynamic3D = 1)
                
                unset($_SESSION['SC_P3D_Params']);
                
//                echo '<pre>'.print_r($params,true).'</pre>';
//                echo '<pre>'.print_r($resp,true).'</pre>';
//                echo '<pre>'.print_r($_SESSION,true).'</pre>';
                
                if($payment_method == 'd3d') {
                    /*
                    $params['transactionType']  = $_SESSION['SC_Variables']['transactionType'];
                    $params['orderId']          = $resp['orderId'];
                    $params['paResponse']       = '';
                    $params['urlDetails']       = array('notificationUrl' => $params['urlDetails']);
                    $params['sessionToken']     = $resp['sessionToken'];
                    $params['p3d_url']          = $_SESSION['SC_Variables']['test'] == 'yes'
                        ? SC_TEST_P3D_URL : SC_LIVE_P3D_URL;
                     */
                    
                    $params_p3d = array(
                        'sessionToken'      => $resp['sessionToken'],
                        'orderId'           => $resp['orderId'],
                        'merchantId'        => $resp['merchantId'],
                        'merchantSiteId'    => $resp['merchantSiteId'],
                        'userTokenId'       => $resp['userTokenId'],
                        'clientUniqueId'    => $resp['clientUniqueId'],
                        'clientRequestId'   => $resp['clientRequestId'],
                        'transactionType'   => $resp['transactionType'],
                        'currency'          => $params['currency'],
                        'amount'            => $params['total_amount'],
                        'amountDetails'     => array(
                            'totalShipping'     => '',
                            'totalHandling'     => $params['handling'],
                            'totalDiscount'     => $params['discount'],
                            'totalTax'          => $params['total_tax'],
                        ),
                        'items'             => $params['items'],
                        'deviceDetails'     => array(), // get them in SC_REST_API Class
                        'shippingAddress'   => array(
                            'firstName'         => $params['shippingFirstName'],
                            'lastName'          => $params['shippingLastName'],
                            'address'           => $params['shippingAddress'],
                            'phone'             => '',
                            'zip'               => $params['shippingZip'],
                            'city'              => $params['shippingCity'],
                            'country'           => $params['shippingCountry'],
                            'state'             => '',
                            'email'             => '',
                            'shippingCounty'    => '',
                        ),
                        'billingAddress'    => array(
                            'firstName'         => $params['first_name'],
                            'lastName'          => $params['last_name'],
                            'address'           => $params['address1'],
                            'phone'             => $params['phone1'],
                            'zip'               => $params['zip'],
                            'city'              => $params['city'],
                            'country'           => $params['country'],
                            'state'             => '',
                            'email'             => $params['email'],
                            'county'            => '',
                        ),
                        'cardData'          => array(
                            'ccTempToken'       => $_SESSION['SC_Variables']['APM_data']['apm_fields']['ccCardNumber'],
                            'CVV'               => $_SESSION['SC_Variables']['APM_data']['apm_fields']['CVV'],
                            'cardHolderName'    => $_SESSION['SC_Variables']['APM_data']['apm_fields']['ccNameOnCard'],
                        ),
                        'paResponse'        => '',
                        'urlDetails'        => array('notificationUrl' => $params['urlDetails']),
                        'timeStamp'         => $params['time_stamp'],
                        'checksum'          => $params['checksum'],
                    );
                    
                //    die('die');
                    
                    $_SESSION['SC_P3D_Params'] = $params_p3d;
                    
                    // case 1
                    if(
                        isset($resp['acsUrl']) && !empty($resp['acsUrl'])
                        && isset($resp['threeDFlow']) && intval($resp['threeDFlow']) == 1
                    ) {
                        $this->create_log($_SESSION['SC_P3D_Params'], '$_SESSION SC_P3D_Params 1: ');
                        
                        // step 1 - go to acsUrl
                        $html =
                            '<form action="'. $resp['acsUrl'] .'" method="post" id="sc_payment_form">'
                                .'<input type="hidden" name="PaReq" value="'. @$resp['paRequest'] .'">'
                                .'<input type="hidden" name="TermUrl" value="'
                                //    .$params['pending_url'] .'?wc-api=WC_SC_Rest&action=p3d">'
                                    .$params['pending_url'] .'?wc-api=sc_listener&action=p3d">'
                                .'<noscript>'
                                    .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'
                                        .__('Pay via '. SC_GATEWAY_TITLE, 'sc').'" /><a class="button cancel" href="'
                                        .$order->get_cancel_order_url().'">'
                                        .__('Cancel order &amp; restore cart', 'sc').'</a>'
                                .'</noscript>'

                                .'<script type="text/javascript">'
                                    .'jQuery(function(){'
                                        .'jQuery("#sc_payment_form").submit();'
                                    .'});'
                                .'</script>'
                            .'</form>';
                        
                        // step 2 - wait for the DMN
                    }
                    // case 2
                    elseif(isset($resp['threeDFlow']) && intval($resp['threeDFlow']) == 1) {
                        $this->pay_with_d3d_p3d();
                    }
                    // case 3 do nothing
                }
                # The case with D3D and P3D END
                
                // in case we have redirectURL
                if(isset($resp['redirectURL']) && !empty($resp['redirectURL'])) {
                    echo 
                        '<script>'
                            .'var newTab = window.open("'.$resp['redirectURL'].'", "_blank");'
                            .'newTab.focus();'
                            .'window.location.href = "'.$params['success_url'].'?Status=waiting";'
                        .'</script>';
                    
                    exit;
                }
            }
            
            $order_status = strtolower($order->get_status());
            if($order_status == 'pending') {
                $order->set_status('completed');
            }
            
            if(@$resp['transactionId']) {
                $order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
            }
            else {
                $order->add_order_note(__('Payment succsess.'));
            }
            $order->save();
            
            echo 
                '<script>'
                    .'window.location.href = "'
                        .$params['error_url'].'?Status=success";'
                .'</script>';
            
            exit;
        }
        # ERROR - not existing payment api
        else {
            $this->create_log('the payment api is set to: '.$this->payment_api, 'Payment form ERROR: ');
            
            echo 
                '<script>'
                    .'window.location.href = "'
                        .$params['error_url'].'?Status=fail&invoice_id='
                    //    .$order_id.'&wc-api=WC_SC_Rest&reason=not existing payment API'
                        .$order_id.'&wc-api=sc_listener&reason=not existing payment API'
                .'</script>';
            exit;
        }
    }
    
    /**
     * Function pay_with_d3d_p3d
     * After we get the DMN form the issuer/bank call this process
     * to continue the flow.
     */
    public function pay_with_d3d_p3d()
    {
        $this->create_log(@$_SESSION['SC_P3D_Params'], 'Data for pay_with_d3d_p3d(): ');
        
        $p3d_resp = false;
        $order = new WC_Order(@$_SESSION['SC_P3D_Params']['clientUniqueId']);
        
        if(!$order) {
            echo 
                '<script>'
                    .'window.location.href = "'.$this->get_return_url().'?Status=fail";'
                .'</script>';
            exit;
        }
        
        // call refund method
        require_once 'SC_REST_API.php';
        
        $p3d_resp = SC_REST_API::call_rest_api(
            @$_SESSION['SC_Variables']['test'] == 'yes' ? SC_TEST_P3D_URL : SC_LIVE_P3D_URL
            ,@$_SESSION['SC_P3D_Params']
            ,$_SESSION['SC_P3D_Params']['checksum']
        );
        
        if(!$resp) {
            if($order_status == 'pending') {
                $order->set_status('failed');
            }

            $order->add_order_note(__('Payment 3D API response is FALSE.', 'sc'));
            $order->save();

            $this->create_log($resp, 'REST API Payment 3D ERROR: ');

            echo 
                '<script>'
                    .'window.location.href = "'.$this->get_return_url().'?Status=fail";'
                .'</script>';
            exit;
        }
        
        
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
        // get AMP fields and add them to the session for future use
        if(isset($_POST, $_POST['payment_method_sc']) && !empty($_POST['payment_method_sc'])) {
            $_SESSION['SC_Variables']['APM_data']['payment_method'] = $_POST['payment_method_sc'];
            
            if(
                isset($_POST[$_POST['payment_method_sc']])
                && !empty($_POST[$_POST['payment_method_sc']]) 
                && is_array($_POST[$_POST['payment_method_sc']])
            ) {
                $_SESSION['SC_Variables']['APM_data']['apm_fields'] = $_POST[$_POST['payment_method_sc']];
            }
        }
        
        // lst parameter is passed from the form. It is the session token used for
        // card tokenization. We MUST use the same token for D3D Payment
        if(isset($_POST, $_POST['lst']) && !empty($_POST['lst'])) {
            $_SESSION['SC_Variables']['lst'] = $_POST['lst'];
        }
        
        $order = new WC_Order($order_id);
       
        return array(
            'result' 	=> 'success',
            'redirect'	=> SC_Versions_Resolver::get_redirect_url($order),
//            'redirect'	=> add_query_arg(
//                array(
//                    'order-pay' => $this->get_order_data($order, 'id'),
//                    'key' => $this->get_order_data($order, 'order_key')
//                ),
//            //    self::get_page_id('pay')
//                wc_get_page_permalink('pay')
//            )
        );
    }
    
    /**
     * Function process_dmns
     * Process information from the DMNs.
     * We call this method form index.php
     */
    public function process_dmns($do_not_call_api = false)
    {
        $this->create_log(@$_REQUEST, 'Receive DMN with params: ');
        
        $req_status = $this->get_request_status();
        
        # Sale - Cashier and may be REST
        if(isset($_REQUEST['transactionType']) && $_REQUEST['transactionType'] == 'Sale') {
            // Cashier
            if(
                isset($_REQUEST['invoice_id'])
                && !empty($_REQUEST['invoice_id'])
                && !empty($this->get_request_status())
                && $this->checkAdvancedCheckSum()
            ) {
                $arr = explode("_", $_REQUEST['invoice_id']);

                if(is_array($arr) && !empty($arr)) {
                    $order_id  = $arr[0];

                    try {
                        $order = new WC_Order($order_id);
                        $order_status = strtolower($order->get_status());

                        if(strtolower($this->get_request_status()) == 'pending' && $order_status == 'completed') {
                            // do nothing here
                        }
                        else {
                            $this->change_order_status($order, $order_id, $this->get_request_status(), @$_REQUEST['transactionType']);
                        }
                    }
                    catch (Exception $ex) {
                        $this->create_log($ex->getMessage(), 'process_dmns() Cashier DMN Exception: ');
                        echo 'Exception - probably invalid order number. ';
                        exit;
                        
                    }
                }
                else {
                    $this->create_log($_REQUEST["invoice_id"], '$_REQUEST["invoice_id"] is not proper format.');
                    echo '$_REQUEST["invoice_id"] is not proper format.';
                    exit;
                }
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # Void 
        // when we refund form CPanel we get transactionType = Void and Status = 'APPROVED'
        if(
            (@$_REQUEST['action'] == 'void' || @$_REQUEST['transactionType'] == 'Void')
            && !empty(@$_REQUEST['clientRequestId'])
            && ($req_status == 1 || $req_status == 0 || $req_status == 'APPROVED')
        ) {
            try {
                $order = new WC_Order($_REQUEST['clientRequestId']);

                if($order) {
                    $order_status = strtolower($order->get_status());

                    // change order status to canceld
                    if(
                        $this->get_request_status() == 1
                        && $order_status != 'canceled'
                        && $order_status != 'cancelled'
                    ) {
                        $order->add_order_note(__('DMN message: Your Void request was success, Order #'
                            .$_REQUEST['clientRequestId'] . ' was canceld.', 'sc'));

                        $order->update_status('cancelled');
                        $order->save();
                    }
                    else {
                        $msg = 'DMN message: Your Void request fail with message: "';

                        // in case DMN URL was rewrited all spaces were replaces with "_"
                        if(@$_REQUEST['wc_sc_redirected'] == 1) {
                            $msg .= str_replace('_', ' ', @$_REQUEST['msg']);
                        }
                        else {
                            $msg .= @$_REQUEST['msg'];
                        }

                        $msg .= '". Order #'  . $_REQUEST['clientRequestId']
                            .' was not canceld!';

                        $order -> add_order_note(__($msg, 'sc'));
                        $order->save();
                    }
                }
                else {
                    echo 'There is no Order. ';
                }
            }
            catch (Exception $ex) {
                $this->create_log(
                    $ex->getMessage(),
                    'process_dmns() REST API DMN DMN Exception: probably invalid order number'
                );
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # Refund
        // see https://www.safecharge.com/docs/API/?json#refundTransaction -> Output Parameters
        // when we refund form CPanel we get transactionType = Credit and Status = 'APPROVED'
        if(
            (@$_REQUEST['action'] == 'refund' || @$_REQUEST['transactionType'] == 'Credit')
            && !empty($req_status)
        ) {
            // CPanel DMN, from it we do not recieve $_GET['action'] parameter
            if(
                @$_REQUEST['action'] != 'refund'
                && $req_status == 'APPROVED'
                && @$_REQUEST['transactionType'] == 'Credit'
            ) {
                $this->create_log('', 'CPanel Refund DMN');
                
                $order_id = @current(explode('_', $_REQUEST['invoice_id']));
                
                $this->create_log($order_id, 'CPanel Refund DMN $order_id ');
                
                $order = new WC_Order($order_id);
                $resp = $this->sc_refund_order($order_id);
                
                if(is_a($resp, 'WP_Error')) {
                    $this->create_log($resp->errors['error'][0], 'Order was not refunded: ');

                    $order->add_order_note(__('DMN message: Your Refund request for Order #'
                        . $order_id . ', faild with ERROR: '
                        . $resp->errors['error'][0]));
                }
                elseif(is_a($resp, 'WC_Order_Refund')) {
                    $this->create_log('', 'Order was refunded.');

                    if(@$_REQUEST['requestedAmount'] == $order->get_total()) {
                        $order->update_status('refunded');
                    }
                    
                    $order -> add_order_note(__('DMN message: Your Refund #'
                        . $resp->get_id() .' was successful.', 'sc'));
                }

                $order->save();

                echo 'DMN received.';
                exit;
            }

            $order = new WC_Order(@$_REQUEST['order_id']);

            if(!is_a($order, 'WC_Order')) {
                $this->create_log('', 'DMN meassage: there is no Order!');
                
                echo 'There is no Order';
                exit;
            }
            $order_status = strtolower($order->get_status());

            // change to Refund if request is Approved and the Order status is not Refunded
            if(
                $order_status !== 'refunded'
                && $req_status == 'SUCCESS'
                && @$_REQUEST['transactionStatus'] == 'APPROVED'
            ) {
            //    $order->update_status('refunded');
                $order -> add_order_note(__('DMN message: Your request - Refund #' .
                    $_REQUEST['clientUniqueId'] . ', was successful.', 'sc'));

                $order->save();
            }
            elseif($req_status == 'ERROR') {
                $order -> add_order_note(__('DMN message: Your try to Refund #'
                    .$_REQUEST['clientUniqueId'] . ' faild with ERROR: "'
                    . @$_REQUEST['Reason'] . '".' , 'sc'));

                $order->save();
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
            
            echo 'DMN received.';
            exit;
        }
        
        # D3D and P3D payment
        // the idea here is to get $_REQUEST['paResponse'] and pass it to P3D
        elseif(@$_REQUEST['action'] == 'p3d') {
            $this->create_log($_REQUEST, 'DMN from issuer/bank: ');

            // the DMN from step 1 - issuer/bank
            if(
                isset($_SESSION['SC_P3D_Params'], $_REQUEST['paResponse'])
                && !is_array($_SESSION['SC_P3D_Params'])
            ) {
                $_SESSION['SC_P3D_Params']['paResponse'] = $_REQUEST['paResponse'];
                $this->pay_with_d3d_p3d();
            }
            // the DMN form step 2 - p3d
            elseif(isset($_REQUEST['merchantId'], $_REQUEST['merchantSiteId'])) {
                // TODO
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
                    $this->create_log(
                        $ex->getMessage(),
                        'process_dmns() REST API DMN DMN Exception: '
                    );
                }
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # other cases
        if(!isset($_REQUEST['action']) && $this->checkAdvancedCheckSum()) {
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
                $this->create_log(
                    $ex->getMessage(),
                    'process_dmns() REST API DMN Exception: '
                );
                
                echo 'Exception error.';
                exit;
            }
            
            echo 'DMN received.';
            exit;
        }
        
        echo 'DMN was not recognized.';
        exit;
    }
    
    public function sc_checkout_process()
    {
        $_SESSION['sc_subpayment'] = '';
        if(isset($_POST['payment_method_sc'])) {
            $_SESSION['sc_subpayment'] = $_POST['payment_method_sc'];
        }
        
		return true;
	}

    /**
     * Check for valid callback
     **/
    public function process_sc_notification()
    {
        $this->create_log($_REQUEST, 'call process_sc_notification()');
        
		try {
            // Cashier DMN
            if(@$_REQUEST['invoice_id'] !== '') {
                $arr = explode("_",$_REQUEST['invoice_id']);
                $order_id  = $arr[0];
                $order = new WC_Order($order_id);

                if ($order){
                    $verified = false;
                    // hash validation
                    if ($this->secret) {
                        $hash = hash(
                            $this->hash_type,
                            $this->secret . $_REQUEST['ppp_status'] . $_REQUEST['PPP_TransactionID']
                        );
                        
                        if ($hash == $_REQUEST['responsechecksum']) {
                            $verified = true;
                        }
                    }
                }

                if ($verified) {
                    $status = $this->get_request_status();
                    $transactionType = @$_REQUEST['transactionType'];

                    echo "Transaction Type: ".$transactionType;

                    if (
                        $transactionType == 'Void'
                        || $transactionType == 'Chargeback'
                        || $transactionType=='Credit'
                    ){
                        $status = 'CANCELED';
                    }
                    
                    $this->change_order_status($order, $order_id, $status, $transactionType);
                }
                else {
                    $this->msg['class'] = 'error';
                    $this->msg['message'] = "Security Error. Checksum validation faild.";
                    $order->update_status('failed');
                    $order->add_order_note('Failed');
                    $order->add_order_note($this->msg['message']);
                }

                add_action('the_content', array(&$this, 'showMessage'));
            }
            // REST API DMN
            else {
                $order_id  = $_REQUEST['clientUniqueId'];
                $order = new WC_Order($order_id);
                
                $status = $this->get_request_status();
                $transactionType = @$_REQUEST['transactionType'];

                echo "Transaction Type: " . $transactionType;

                if (
                    $transactionType == 'Void'
                    || $transactionType == 'Chargeback'
                    || $transactionType == 'Credit'
                ) {
                    $status = 'CANCELED';
                }
                
                $this->change_order_status($order, $order_id, $status, $transactionType);
            }
		}
        catch(Exception $e){
            $this->create_log($e, 'Error in process_sc_notification(): ');
			$msg = "Error.";
		}
        
        // clear session variables for the order
        if(isset($_SESSION['SC_Variables'])) {
            unset($_SESSION['SC_Variables']);
        }
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
        $protocol = '';
        $url = $_SERVER['HTTP_HOST'] . '/?wc-api=';
        
        // force Notification URL protocol
        if(isset($this->use_http) && $this->use_http == 'yes') {
            $protocol = 'http://';
        }
        else {
            if(
                (isset($_SERVER["HTTPS"]) && !empty($_SERVER["HTTPS"]) && strtolower ($_SERVER['HTTPS']) != 'off')
                || (isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTPS/') !== false)
            ) {
                $protocol = 'https://';
            }
            elseif(isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTP/') !== false) {
                $protocol = 'http://';
            }
        }
        
        return $protocol . $url;
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

            case 'order_currency':
                return $order->get_currency();

            case 'order_version':
                return $order->get_version();

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
        return false;
	}
    
    /**
     * Function sc_refund_order
     * Process Order Refund through Code
     * 
     * @param int $order_id
     * @param string $refund_reason
     * 
     * @return WC_Order_Refund|WP_Error
     */
    private function sc_refund_order($order_id, $refund_reason = '')
    {
        $this->create_log('', 'call sc_refund_order ');
        
        if(!$this->checkAdvancedCheckSum()) {
            return new WP_Error( 'error', __( 'The checkAdvancedCheckSum did not mutch!', 'sc' ) );
        }
        
        $order  = wc_get_order( $order_id );
        
        // If it's something else such as a WC_Order_Refund, we don't want that.
        if( ! is_a( $order, 'WC_Order') ) {
            return new WP_Error( 'error', __( 'Provided ID is not a WC Order', 'sc' ) );
        }
        
        if( $order->get_status() == 'refunded' ) {
            return new WP_Error( 'error', __( 'Order has been already refunded', 'sc' ) );
        }
        
        // Refund Amount
        $refund_amount = 0;
        $req_refund_amount = @$_REQUEST['totalAmount'];
        // Prepare items which we are refunding
        $items = array();
        // Get Items
        $order_items   = $order->get_items();
        
        if ( $order_items ) {
            foreach( $order_items as $item_id => $item ) {
                $tax_data = wc_get_order_item_meta($item_id, '_line_tax_data');
                $refund_tax = 0;
                
                if(is_array($tax_data) && isset($tax_data['total']) && !empty($tax_data['total'])) {
                    $refund_tax = wc_format_decimal($tax_data['total'] );
                }
                
                $refund_amount += wc_format_decimal( $refund_amount )
                    + wc_get_order_item_meta($item_id, '_line_total');
                
                $items[ $item_id ] = array( 
                    'qty' => wc_get_order_item_meta($item_id, '_qty'), 
                    'refund_total' => wc_format_decimal( wc_get_order_item_meta($item_id, '_line_total') ), 
                    'refund_tax' =>  $refund_tax
                );
            }
        }
        
        if($req_refund_amount && $req_refund_amount != $refund_amount) {
            $refund_amount = $req_refund_amount;
        }
        
        $refund = wc_create_refund( array(
            'amount'         => $refund_amount,
            'reason'         => $refund_reason,
            'order_id'       => $order_id,
        //    'line_items'     => $items,
            'refund_payment' => false,
        ));
        
        $this->create_log($refund_amount, 'Refund amount: ');
        $this->create_log($order_id, 'Refund $order_id: ');
        $this->create_log($items, 'Refund $items: ');
        
        return $refund;
    }
    
    /**
     * Function change_order_status
     * Change order status of the order.
     * 
     * @param object $order
     * @param int $order_id
     * @param string $status
     * @param string $transactionType - not mandatory for the DMN
     */
    private function change_order_status($order, $order_id, $status, $transactionType = '')
    {
        $this->create_log($status . ' - ' . $order_id, 'WC_SC change_order_status() status-order: ');
        
        global $woocommerce;
        
        switch($status) {
            case 'CANCELED':
                $message = 'Payment status changed to:' . $status
                    .'. PPP_TransactionID = '. @$_REQUEST['PPP_TransactionID']
                    .", Status = " .$status. ', GW_TransactionID = '
                    .@$_REQUEST['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->update_status('failed');
                $order->add_order_note('Failed');
                $order->add_order_note($this->msg['message']);
            break;

            case 'APPROVED':
                $message = 'The amount has been authorized and captured by '
                    .SC_GATEWAY_TITLE .'. PPP_TransactionID = '
                    .@$_REQUEST['PPP_TransactionID'] .", Status = ". $status;

                if($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '. @$_REQUEST['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->payment_complete($order_id);
                
                $order->update_status( 'completed' );
                
                $order->add_order_note(SC_GATEWAY_TITLE .' payment is successful<br/>Unique Id: '
                    .@$_REQUEST['PPP_TransactionID']);

                $order->add_order_note($this->msg['message']);
                $woocommerce->cart->empty_cart();
            break;

            case 'ERROR':
            case 'DECLINED':
                $message ='Payment failed. PPP_TransactionID = '. @$_REQUEST['PPP_TransactionID']
                    .", Status = ". $status .", Error code = ". @$_REQUEST['ErrCode']
                    .", Message = ". @$_REQUEST['message'];

                if($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '. $_REQUEST['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->update_status('failed');
                $order->add_order_note('Failed');
                $order->add_order_note($this->msg['message']);
            break;

            case 'PENDING':
                $ord_status = $order->get_status();
                if ($ord_status == 'processing' || $ord_status == 'completed') {
                    break;
                }

                $message ='Payment is still pending, PPP_TransactionID '
                    .@$_REQUEST['PPP_TransactionID'] .", Status = ". $status;

                if($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '. @$_REQUEST['TransactionID'];

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message woocommerce_message_info';
                $order->add_order_note(SC_GATEWAY_TITLE .' payment status is pending<br/>Unique Id: '
                    .@$_REQUEST['PPP_TransactionID']);

                $order->add_order_note($this->msg['message']);
                $order->update_status('on-hold');
                $woocommerce->cart->empty_cart();
            break;
        }
        
        $order->save();
        $this->save_update_order_numbers($order);
    }
    
    private function set_environment()
    {
		if ($this->test == 'yes'){
            $this->use_session_token_url    = SC_TEST_SESSION_TOKEN_URL;
            $this->use_merch_paym_meth_url  = SC_TEST_REST_PAYMENT_METHODS_URL;
            
            // set payment URL
            if($this->payment_api == 'cashier') {
                $this->URL = SC_TEST_CASHIER_URL;
            }
            elseif($this->payment_api == 'rest') {
                $this->URL = SC_TEST_PAYMENT_URL;
            }
		}
        else {
            $this->use_session_token_url    = SC_LIVE_SESSION_TOKEN_URL;
            $this->use_merch_paym_meth_url  = SC_LIVE_REST_PAYMENT_METHODS_URL;
            
            // set payment URL
            if($this->payment_api == 'cashier') {
                $this->URL = SC_LIVE_CASHIER_URL;
            }
            elseif($this->payment_api == 'rest') {
                $this->URL = SC_LIVE_PAYMENT_URL;
            }
		}
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
            $order->update_meta_data(SC_GW_TRANS_ID_KEY, $gw_transaction_id, 0);
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
    
    /**
     * Function create_log
     * Create logs. You MUST have defined SC_LOG_FILE_PATH const,
     * holding the full path to the log file.
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    private function create_log($data, $title = '')
    {
        if(
            !isset($this->save_logs)
            || $this->save_logs == 'no'
            || $this->save_logs === null
        ) {
            return;
        }
        
        $d = '';
        
        if(is_array($data) || is_object($data)) {
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
}

?>
