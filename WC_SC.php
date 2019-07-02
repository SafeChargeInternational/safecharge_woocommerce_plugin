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
        require_once 'SC_Versions_Resolver.php';
        
        $this->webMasterId .= WOOCOMMERCE_VERSION;
        $plugin_dir = basename(dirname(__FILE__));
        $this->plugin_path = plugin_dir_path( __FILE__ ) . $plugin_dir . DIRECTORY_SEPARATOR;
        $this->plugin_url = get_site_url() . DIRECTORY_SEPARATOR . 'wp-content'
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
        $_SESSION['SC_Variables']['merchantId']         = $this->merchantId;
        $_SESSION['SC_Variables']['merchantSiteId']     = $this->merchantSiteId;
        $_SESSION['SC_Variables']['currencyCode']       = get_woocommerce_currency();
        $_SESSION['SC_Variables']['languageCode']       = $this->formatLocation(get_locale());
        $_SESSION['SC_Variables']['payment_api']        = $this->payment_api;
        $_SESSION['SC_Variables']['transactionType']    = $this->transaction_type;
        $_SESSION['SC_Variables']['test']               = $this->test;
        $_SESSION['SC_Variables']['save_logs']          = $this->save_logs;
        $_SESSION['SC_Variables']['rewrite_dmn']        = $this->rewrite_dmn;
        
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

        SC_Versions_Resolver::process_admin_options($this);
    //    add_action('woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ));
        
		add_action('woocommerce_checkout_process', array($this, 'sc_checkout_process'));
        add_action('woocommerce_receipt_'.$this->id, array($this, 'generate_sc_form'));
        
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
                'default' => $this->set_notify_url(),
                'readonly' => true,
                'custom_attributes' => array('readonly' => 'readonly'),
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
        // prevent execute this method twice when use Cashier
        if($this->payment_api == 'cashier') {
            if( ! isset($_SESSION['SC_CASHIER_FORM_RENDED'])) {
                $_SESSION['SC_CASHIER_FORM_RENDED'] = false;
            }
            elseif($_SESSION['SC_CASHIER_FORM_RENDED'] === true) {
                $_SESSION['SC_CASHIER_FORM_RENDED'] = false;
                $this->create_log('second call of generate_sc_form() when use Cashier, stop here!');
                return;
            }
        }
        
        $this->create_log('generate_sc_form()');
        
        try {
            $TimeStamp = date('Ymdhis');
            $order = new WC_Order($order_id);
            $order_status = strtolower($order->get_status());

            $order->add_order_note(__("User is redicted to ".SC_GATEWAY_TITLE." Payment page.", 'sc'));
            $order->save();

            $this->set_environment();
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
            $params['user_token_id']    = SC_Versions_Resolver::get_order_data($order, 'billing_email');
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

            $params['user_token'] = "auto";

            $params['payment_method'] = '';
            if(isset($_SESSION['sc_subpayment']) && $_SESSION['sc_subpayment'] != '') {
                $params['payment_method'] = str_replace($this->id.'_', '', $_SESSION['sc_subpayment']);
            }

            $params['total_amount']     = SC_Versions_Resolver::get_order_data($order, 'order_total');
            $params['currency']         = get_woocommerce_currency();
            $params['merchantLocale']   = get_locale();
            $params['webMasterId']      = $this->webMasterId;
        }
        catch (Exception $ex) {
            $this->create_log($ex->getMessage(), 'Exception while preparing order parameters: ');
        }
        
        # Cashier payment
        if($this->payment_api == 'cashier') {
            $this->create_log('Cashier payment');
            
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
                $this->create_log($test_diff, 'Total diff, added to handling: ');
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

            $this->create_log($this->URL, 'Endpoint URL: ');
            $this->create_log($params, 'Order params');
            
            $info_msg = 
                '<table id="sc_pay_msg" style="border: 3px solid #aaa; cursor: wait; line-height: 32px;"><tr>'
                    .'<td style="padding: 0px; border: 0px; width: 100px;">'
                        . '<img src="'.$this->plugin_url.'icons/loading.gif" style="width:100px; float:left; margin-right: 10px;" />'
                    . '</td>'
                    .'<td style="text-align: left; border: 0px;">'
                        . '<span>'.__('Thank you for your order. We are now redirecting you to '. SC_GATEWAY_TITLE .' Payment Gateway to make payment.', 'sc').'</span>'
                    . '</td>'
                .'</tr></table';

            $html = '<form action="'.$this->URL.'" method="post" id="sc_payment_form">';

            if($this->cashier_in_iframe == 'yes') {
                $html = '<form action="'.$this->URL.'" method="post" id="sc_payment_form" target="i_frame">';
            }

            $html .=
                    implode('', $params_array)
                    .'<noscript>'
                        .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'.__('Pay via '. SC_GATEWAY_TITLE, 'sc').'" /><a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'sc').'</a>'
                    .'</noscript>'
                    .'<script type="text/javascript">'
                        .'jQuery(function(){'
                            .'jQuery("header.entry-header").prepend(\''.$info_msg.'\');'
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
        }
        # REST API payment
        elseif($this->payment_api == 'rest') {
            $this->create_log('REST API payment');
            
            // for the REST we do not care about details
            $params['handling'] = '0.00';
            $params['discount'] = '0.00';
            
            $params['items'][0] = array(
                'name'      => $order_id,
                'price'     => $params['total_amount'],
                'quantity'  => 1,
            );
            
            // map here variables names different for Cashier and REST
            $params['merchantId']           = $this->merchantId;
            $params['merchantSiteId']       = $this->merchantSiteId;
            $params['notify_url']           = $notify_url . 'sc_listener';
            
            // TODO this parameter does not present in REST docs !!!
        //    $params['site_url']             = get_site_url();
            
            $params['client_request_id']    = $TimeStamp .'_'. uniqid();
            
            $params['urlDetails'] = array(
                'successUrl'        => $this->get_return_url(),
                'failureUrl'        => $this->get_return_url(),
                'pendingUrl'        => $this->get_return_url(),
                'notificationUrl'   => $notify_url,
            );
            
            // set the payment method type
            $payment_method = 'apm';
            if(in_array(
                @$_SESSION['SC_Variables']['APM_data']['payment_method'],
                array('cc_card', 'dc_card')
            )) {
                $payment_method = 'd3d';
            }
                
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
            
            $this->create_log($params, 'params sent to REST: ');
        //    $this->create_log($_SESSION['SC_Variables'], 'SC_Variables: ');
            
            // ALWAYS CHECK USED PARAMS IN process_payment
            $resp = SC_REST_API::process_payment(
                $params
                ,$_SESSION['SC_Variables']
                ,$_REQUEST['order-pay']
                ,$payment_method
            );
            
            $this->create_log('REST API payment was sent.');
            
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
                    $error_txt .= ': ' . $resp['errCode'] . ' - ' . $resp['reason'] . '.';
                }
                elseif(@$resp['transactionStatus'] != '') {
                    $error_txt .= ': ' . $resp['transactionStatus'] . '.';
                }
                elseif(@$resp['threeDReason'] != '') {
                    $error_txt .= ': ' . $resp['threeDReason'] . '.';
                }
                
                $order->add_order_note($error_txt);
                $order->save();
                
                $this->create_log($resp['errCode'].': '.$resp['reason'], 'REST API Payment ERROR: ');
                
                echo 
                    '<script>'
                        .'window.location.href = "'. $params['error_url'] 
                        . (strpos($params['error_url'], '?') === false ? '?' : '&')
                        . 'Status=fail";'
                    .'</script>';
                exit;
            }
            
            // pay with redirect URL
            if($this->get_request_status($resp) == 'SUCCESS') {
                # The case with D3D and P3D
                // isDynamic3D is hardcoded to be 1, see SC_REST_API line 509
                // for the three cases see: https://www.safecharge.com/docs/API/?json#dynamic3D,
                // Possible Scenarios for Dynamic 3D (isDynamic3D = 1)
                
                // clear the old session data
                if(isset($_SESSION['SC_P3D_Params'])) {
                    unset($_SESSION['SC_P3D_Params']);
                }
                // prepare the new session data
                if($payment_method == 'd3d') {
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
                            'totalShipping'     => '0.00',
                            'totalHandling'     => $params['handling'],
                            'totalDiscount'     => $params['discount'],
                            'totalTax'          => @$params['total_tax'] ? $params['total_tax'] : '0.00',
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
                    
                    $_SESSION['SC_P3D_Params'] = $params_p3d;
                    
                    // case 1
                    if(
                        isset($resp['acsUrl'], $resp['threeDFlow'])
                        && !empty($resp['acsUrl'])
                        && intval($resp['threeDFlow']) == 1
                    ) {
                        $this->create_log('D3D case 1');
                        
                        // step 1 - go to acsUrl
                        $html =
                            '<table id="sc_pay_msg" style="border: 3px solid #aaa; cursor: wait; line-height: 32px;"><tr>'
                                .'<td style="padding: 0px; border: 0px; width: 100px;">'
                                    . '<img src="'.$this->plugin_url.'icons/loading.gif" style="width:100px; float:left; margin-right: 10px;" />'
                                . '</td>'
                                .'<td style="text-align: left; border: 0px;">'
                                    . '<span>'.__('Thank you for your order. We are now redirecting you to '. SC_GATEWAY_TITLE .' Payment Gateway to make payment.', 'sc').'</span>'
                                . '</td>'
                            .'</tr></table>'
                            
                            .'<form action="'. $resp['acsUrl'] .'" method="post" id="sc_payment_form">'
                                .'<input type="hidden" name="PaReq" value="'. @$resp['paRequest'] .'">'
                                .'<input type="hidden" name="TermUrl" value="'
                                    . $params['pending_url']
                                    . (strpos($params['pending_url'], '?') != false ? '&' : '?')
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
                        
                        echo $html;
                        exit;
                        
                        // step 2 - wait for the DMN
                    }
                    // case 2
                    elseif(isset($resp['threeDFlow']) && intval($resp['threeDFlow']) == 1) {
                        $this->create_log('', 'D3D case 2.');
                        $this->pay_with_d3d_p3d();
                    }
                    // case 3 do nothing
                }
                # The case with D3D and P3D END
                
                // in case we have redirectURL
                if(isset($resp['redirectURL']) && !empty($resp['redirectURL'])) {
                    $this->create_log($resp['redirectURL'], 'we have redirectURL: ');
                    
                    if(@$resp['gwErrorCode'] == -1 || @$resp['gwErrorReason']) {
                        $order->add_order_note(
                            __('Payment with redirect URL error: ' . @$resp['gwErrorReason'] . '.', 'sc'));
                        $order->save();
                        
                        echo 
                            '<script>'
                                .'window.location.href = "' . $params['error_url']
                                    . (strpos($params['error_url'], '?') === false ? '?' : '&')
                                    . 'Status=fail'
                            .'</script>';
                        exit;
                    }
                    
                    echo 
                        '<script>'
                            .'window.location.href = "' . $resp['redirectURL'] . '";'
                        .'</script>';
                    
                    exit;
                }
            }
            
            $order_status = strtolower($order->get_status());
            
            if(isset($resp['transactionId']) && $resp['transactionId'] != '') {
                $order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
            }
            else {
                $order->add_order_note(__('Payment succsess.'));
            }
            
            // save the response transactionType value
            if(isset($resp['transactionType']) && $resp['transactionType'] != '') {
                $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $resp['transactionType']);
            }
            
            $order->save();
            
            echo 
                '<script>'
                    .'window.location.href = "'
                        . $params['error_url']
                        . (strpos($params['error_url'], '?') === false ? '?' : '&')
                        . 'Status=success";'
                .'</script>';
            
            exit;
        }
        # ERROR - not existing payment api
        else {
            $this->create_log(
                'Wrong paiment api ('. $this->payment_api .').'
                , 'Payment form ERROR: '
            );
            
            echo 
                '<script>'
                    .'window.location.href = "'
                        . $params['error_url']
                        . (strpos($params['error_url'], '?') === false ? '?' : '&')
                        . 'Status=fail&invoice_id='
                        . $order_id.'&wc-api=sc_listener&reason=not-existing-payment-API'
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
        $p3d_resp = false;
        
        try {
            $order = new WC_Order(@$_SESSION['SC_P3D_Params']['clientUniqueId']);

            if(!$order) {
                echo 
                    '<script>'
                        .'window.location.href = "'.$this->get_return_url().'?Status=fail";'
                    .'</script>';
                exit;
            }

            // some corrections
            $_SESSION['SC_P3D_Params']['transactionType'] = $this->transaction_type;
            $_SESSION['SC_P3D_Params']['urlDetails']['notificationUrl'] = $_SESSION['SC_P3D_Params']['urlDetails']['notificationUrl']['notificationUrl'];

            $this->create_log('pay_with_d3d_p3d call to the REST API.');

            require_once 'SC_REST_API.php';

            $p3d_resp = SC_REST_API::call_rest_api(
                @$_SESSION['SC_Variables']['test'] == 'yes' ? SC_TEST_P3D_URL : SC_LIVE_P3D_URL
                ,@$_SESSION['SC_P3D_Params']
                ,$_SESSION['SC_P3D_Params']['checksum']
                ,array('webMasterId' => $this->webMasterId)
            );
        }
        catch (Exception $e) {
            $this->create_log($e->getMessage(), 'pay_with_d3d_p3d Exception: ');
            
            echo 
                '<script>'
                    .'window.location.href = "'.$this->get_return_url().'?Status=fail";'
                .'</script>';
            exit;
        }
        
        if(!$p3d_resp) {
            if($order_status == 'pending') {
                $order->set_status('failed');
            }

            $order->add_order_note(__('Payment 3D API response fails.', 'sc'));
            $order->save();

            $this->create_log($resp, 'REST API Payment 3D ERROR: ');

            echo 
                '<script>'
                    .'window.location.href = "'.$this->get_return_url().'?Status=fail";'
                .'</script>';
            exit;
        }
        
        // save the response type of transaction
        if(isset($p3d_resp['transactionType']) && $p3d_resp['transactionType'] != '') {
            $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $p3d_resp['transactionType']);
        }
        
        echo 
            '<script>'
                .'window.location.href = "'. $this->get_return_url() .'?Status=wait";'
            .'</script>';
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
        //    'redirect'	=> SC_Versions_Resolver::get_redirect_url($order),
            'redirect'	=> add_query_arg(
                array(
                    'order-pay' => $this->get_order_data($order, 'id'),
                    'key' => $this->get_order_data($order, 'order_key')
                ),
                wc_get_page_permalink('pay')
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
        $this->create_log(@$_REQUEST, 'Receive DMN with params: ');
        
        $req_status = $this->get_request_status();
        
        # Sale and Auth
        if(
            isset($_REQUEST['transactionType'], $_REQUEST['invoice_id'])
            && in_array($_REQUEST['transactionType'], array('Sale', 'Auth'))
            && $this->checkAdvancedCheckSum()
        ) {
            $this->create_log('A sale/auth.');
            $order_id = 0;
            
            // Cashier
            if(!empty($_REQUEST['invoice_id'])) {
                $this->create_log('Cashier sale.');
                
                try {
                    $arr = explode("_", $_REQUEST['invoice_id']);
                    $order_id  = intval($arr[0]);
                }
                catch (Exception $ex) {
                    $this->create_log($ex->getMessage(), 'Cashier DMN Exception when try to get Order ID: ');
                    echo 'DMN Exception: ' . $ex->getMessage();
                    exit;
                }
            }
            // REST
            else {
                $this->create_log('REST sale.');
                
                try {
                    $order_id = $_REQUEST['merchant_unique_id'];
                }
                catch (Exception $ex) {
                    $this->create_log($ex->getMessage(), 'REST DMN Exception when try to get Order ID: ');
                    echo 'DMN Exception: ' . $ex->getMessage();
                    exit;
                }
            }
            
            try {
                $order = new WC_Order($order_id);
                $order_status = strtolower($order->get_status());
                
                $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $_REQUEST['transactionType']);
                $this->save_update_order_numbers($order);
                
                if($order_status != 'completed') {
                    $this->change_order_status($order, $arr[0], $req_status, $_REQUEST['transactionType']);
                }
            }
            catch (Exception $ex) {
                $this->create_log($ex->getMessage(), 'Sale DMN Exception: ');
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
            $this->create_log('', $_REQUEST['transactionType']);
            
            try {
                $order = new WC_Order($_REQUEST['clientUniqueId']);
                
                if($_REQUEST['transactionType'] == 'Settle') {
                    $this->save_update_order_numbers($order);
                }
                
                $this->change_order_status($order, $_REQUEST['clientUniqueId'], $req_status, $_REQUEST['transactionType']);
                $order_id = @$_REQUEST['clientUniqueId'];
            }
            catch (Exception $ex) {
                $this->create_log(
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
            $this->create_log('Refund DMN.');
            
            $order = new WC_Order(@$_REQUEST['order_id']);

            if(!is_a($order, 'WC_Order')) {
                $this->create_log('DMN meassage: there is no Order!');
                
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
            $this->create_log('p3d.');
            
            // the DMN from case 1 - issuer/bank
            if(
                isset($_SESSION['SC_P3D_Params'], $_REQUEST['PaRes'])
                && is_array($_SESSION['SC_P3D_Params'])
            ) {
                $_SESSION['SC_P3D_Params']['paResponse'] = $_REQUEST['PaRes'];
                $this->pay_with_d3d_p3d();
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
                    $this->create_log(
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
            $this->create_log('', 'Other cases.');
            
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
        
        if($req_status == '') {
            echo 'Error: the DMN Status is empty!';
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
            //    $this->create_log($_SESSION['sc_last_refund_id'], 'we have session: ');
            //    $this->create_log($refund_data['id'], 'refund id: ');
                
                if(intval($_SESSION['sc_last_refund_id']) == intval($refund_data['id'])) {
                    unset($_SESSION['sc_last_refund_id']);
                    return;
                }
                else {
                    $_SESSION['sc_last_refund_id'] = $refund_data['id'];
                }
            }
            else {
            //    $this->create_log($refund_data['id'], 'create session: ');
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
            $this->create_log($ex->getMessage(), 'sc_create_refund() Exception: ');
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

        // call refund method
        require_once 'SC_REST_API.php';

        $notify_url = $this->set_notify_url();
        
        // execute refund, the response must be array('msg' => 'some msg', 'new_order_status' => 'some status')
        $resp = SC_REST_API::refund_order(
            $this->settings
            ,$refund_data
            ,$order_meta_data
            ,get_woocommerce_currency()
            ,$notify_url . '&action=refund&order_id=' . $order_id
        );
        
        $refund_url = SC_TEST_REFUND_URL;
        $cpanel_url = SC_TEST_CPANEL_URL;

        if($this->settings['test'] == 'no') {
            $refund_url = SC_LIVE_REFUND_URL;
            $cpanel_url = SC_LIVE_CPANEL_URL;
        }

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
            
            $this->create_log('Items were restocked.');
        }
        
        return;
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
        $this->create_log(
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
}

?>
