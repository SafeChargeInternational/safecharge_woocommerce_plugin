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

class WC_SC extends WC_Payment_Gateway
{
    # payments URL
    private $URL = '';
    private $webMasterId = 'WooCommerce ';
    
    public function __construct()
    {
        global $session;
        
        $post = $_POST;
        
        require_once plugin_dir_path(__FILE__) . 'SC_Versions_Resolver.php';
        
        $session['SC_Variables']['webMasterId'] = $this->webMasterId .= WOOCOMMERCE_VERSION;
        
        $plugin_dir = basename(dirname(__FILE__));
        $this->plugin_path = plugin_dir_path(__FILE__) . $plugin_dir . DIRECTORY_SEPARATOR;
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
        
        $this->title            = isset($this->settings['title']) ? $this->settings['title'] : '';
        $this->description      = isset($this->settings['description']) ? $this->settings['description'] : '';
        $this->merchantId       = isset($this->settings['merchantId']) ? $this->settings['merchantId'] : '';
        $this->merchantSiteId   = isset($this->settings['merchantSiteId']) ? $this->settings['merchantSiteId'] : '';
        $this->secret           = isset($this->settings['secret']) ? $this->settings['secret'] : '';
        $this->test             = isset($this->settings['test']) ? $this->settings['test'] : 'yes';
        $this->use_http         = isset($this->settings['use_http']) ? $this->settings['use_http'] : 'yes';
        $this->save_logs        = isset($this->settings['save_logs']) ? $this->settings['save_logs'] : 'yes';
        $this->hash_type        = isset($this->settings['hash_type']) ? $this->settings['hash_type'] : 'sha256';
        $this->payment_api      = isset($this->settings['payment_api']) ? $this->settings['payment_api'] : 'cashier';
        $this->transaction_type = isset($this->settings['transaction_type']) ? $this->settings['transaction_type'] : 'sale';
        $this->rewrite_dmn      = isset($this->settings['rewrite_dmn']) ? $this->settings['rewrite_dmn'] : 'no';
        
        $this->use_wpml_thanks_page =
            isset($this->settings['use_wpml_thanks_page']) ? $this->settings['use_wpml_thanks_page'] : 'no';
        $this->cashier_in_iframe    =
            isset($this->settings['cashier_in_iframe']) ? $this->settings['cashier_in_iframe'] : 'no';
        
        $this->supports[] = 'refunds'; // to enable auto refund support
        
        $this->init_form_fields();
        
        # set session variables for REST API, according REST variables names
        $session['SC_Variables']['merchantId']         = $this->merchantId;
        $session['SC_Variables']['merchantSiteId']     = $this->merchantSiteId;
        $session['SC_Variables']['currencyCode']       = get_woocommerce_currency();
        $session['SC_Variables']['languageCode']       = $this->formatLocation(get_locale());
        $session['SC_Variables']['payment_api']        = $this->payment_api;
        $session['SC_Variables']['transactionType']    = $this->transaction_type;
        $session['SC_Variables']['test']               = $this->test;
        $session['SC_Variables']['save_logs']          = $this->save_logs;
        $session['SC_Variables']['rewrite_dmn']        = $this->rewrite_dmn;
        
        $session['SC_Variables']['sc_country'] = SC_Versions_Resolver::get_client_country(new WC_Customer);
        if (isset($post["billing_country"]) && !empty($post["billing_country"])) {
            $session['SC_Variables']['sc_country'] = $post["billing_country"];
        }
        
        # Client Request ID 1 and Checksum 1 for Session Token 1
        // client request id 1
        $time = date('YmdHis', time());
        $session['SC_Variables']['cri1'] = $time. '_' .uniqid();
        
        // checksum 1 - checksum for session token
        $session['SC_Variables']['cs1'] = hash(
            $this->hash_type,
            $this->merchantId . $this->merchantSiteId
                . $session['SC_Variables']['cri1'] . $time . $this->secret
        );
        # Client Request ID 1 and Checksum 1 END
        
        # Client Request ID 2 and Checksum 2 to get AMPs
        // client request id 2
        $time = date('YmdHis', time());
        $session['SC_Variables']['cri2'] = $time. '_' .uniqid();
        
        // checksum 2 - checksum for get apms
        $time = date('YmdHis', time());
        $session['SC_Variables']['cs2'] = hash(
            $this->hash_type,
            $this->merchantId . $this->merchantSiteId
                . $session['SC_Variables']['cri2'] . $time . $this->secret
        );
        # set session variables for future use END
        
        $this->msg['message'] = "";
        $this->msg['class'] = "";

        SC_Versions_Resolver::process_admin_options($this);
        
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
    public function generate_button_html($key, $data)
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

        $data = wp_parse_args($data, $defaults);

        ob_start(); ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html(wp_kses_post($data['title'])); ?></label>
                <?php echo esc_html($this->get_tooltip_html($data)); ?>
            </th>
            <td class="forminp" style="position: relative;">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo esc_html(wp_kses_post($data['title'])); ?></span></legend>
                    <button class="<?php echo esc_attr($data['class']); ?>"
                            type="button" 
                            name="<?php echo esc_attr($field); ?>" 
                            id="<?php echo esc_attr($field); ?>" 
                            style="<?php echo esc_attr($data['css']); ?>" 
                            <?php echo esc_attr($this->get_custom_attribute_html($data)); ?>>
                                <?php echo esc_html(wp_kses_post($data['title'])); ?>
                    </button>
                    <?php echo esc_html($this->get_description_html($data)); ?>
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
            '<h3>' . esc_html__(SC_GATEWAY_TITLE .' ', 'sc') . '</h3>'
            . '<p>' . esc_html__('SC payment option') . '</p>'
            . '<table class="form-table">';
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
        if ($this->description) {
            echo esc_html(wpautop(wptexturize($this->description)));
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
        global $session;
        global $sc_request;
        
        // prevent execute this method twice when use Cashier
        if ($this->payment_api === 'cashier') {
            if (! isset($session['SC_CASHIER_FORM_RENDED'])) {
                $session['SC_CASHIER_FORM_RENDED'] = false;
            } elseif ($session['SC_CASHIER_FORM_RENDED'] === true) {
                $session['SC_CASHIER_FORM_RENDED'] = false;
                $this->create_log('second call of generate_sc_form() when use Cashier, stop here!');
                return;
            }
        }
        
        $this->create_log('generate_sc_form()');
        
        $params = array();
        
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
                2,
                '.',
                ''
            );
            
            $params['discount'] = number_format($order->get_discount_total(), 2, '.', '');

            if ($params['handling'] < 0) {
                $params['discount'] += abs($params['handling']);
            }
            
            // we are not sure can woocommerce support more than one tax.
            // if it can, may be sum them is not the correct aproch, this must be tested
            $total_tax_prec = 0;
            $taxes = WC_Tax::get_rates();
            foreach ($taxes as $data) {
                $total_tax_prec += $data['rate'];
            }

            $params['merchant_id']      = $this->merchantId;
            $params['merchant_site_id'] = $this->merchantSiteId;
            $params['time_stamp']       = $TimeStamp;
            $params['encoding']         ='utf-8';
            $params['version']          = '4.0.0';

            $payment_page = wc_get_cart_url();

            if (get_option('woocommerce_force_ssl_checkout') === 'yes') {
                $payment_page = str_replace('http:', 'https:', $payment_page);
            }

            $return_url = $this->get_return_url();
            if ($this->cashier_in_iframe === 'yes') {
                if (strpos($return_url, '?') !== false) {
                    $return_url .= '&use_iframe=1';
                } else {
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
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_first_name')));
            $params['last_name'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_last_name')));
            $params['address1'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_address_1')));
            $params['address2'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_address_2')));
            $params['zip'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_zip')));
            $params['city'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_city')));
            $params['state'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_state')));
            $params['country'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_country')));
            $params['phone1'] =
                rawurlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_phone')));

            $params['email']            = SC_Versions_Resolver::get_order_data($order, 'billing_email');
            $params['user_token_id']    = is_user_logged_in() ? $params['email'] : '';
            
            // get and pass billing data END

            // get and pass shipping data
            $sh_f_name = rawrawurlencode(preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_first_name')
            ));

            if (empty(trim($sh_f_name))) {
                $sh_f_name = $params['first_name'];
            }
            $params['shippingFirstName'] = $sh_f_name;

            $sh_l_name = rawrawurlencode(preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_last_name')
            ));

            if (empty(trim($sh_l_name))) {
                $sh_l_name = $params['last_name'];
            }
            $params['shippingLastName'] = $sh_l_name;

            $sh_addr = rawrawurlencode(preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_address_1')
            ));
            if (empty(trim($sh_addr))) {
                $sh_addr = $params['address1'];
            }
            $params['shippingAddress'] = $sh_addr;

            $sh_city = rawrawurlencode(preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_city')
            ));
            if (empty(trim($sh_city))) {
                $sh_city = $params['city'];
            }
            $params['shippingCity'] = $sh_city;

            $sh_country = rawrawurlencode(preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_country')
            ));
            if (empty(trim($sh_country))) {
                $sh_city = $params['country'];
            }
            $params['shippingCountry'] = $sh_country;

            $sh_zip = rawrawurlencode(preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_postcode')
            ));
            if (empty(trim($sh_zip))) {
                $sh_zip = $params['zip'];
            }
            $params['shippingZip'] = $sh_zip;
            // get and pass shipping data END

            $params['user_token'] = "auto";

            $params['payment_method'] = '';
            if (isset($session['sc_subpayment']) && $session['sc_subpayment'] !== '') {
                $params['payment_method'] = str_replace($this->id.'_', '', $session['sc_subpayment']);
            }

            $params['total_amount']     = SC_Versions_Resolver::get_order_data($order, 'order_total');
            $params['currency']         = get_woocommerce_currency();
            $params['merchantLocale']   = get_locale();
            $params['webMasterId']      = $this->webMasterId;
        } catch (Exception $ex) {
            $this->create_log($ex->getMessage(), 'Exception while preparing order parameters: ');
        }
        
        # Cashier payment
        if ($this->payment_api === 'cashier') {
            $this->create_log('Cashier payment');
            
            $session['SC_CASHIER_FORM_RENDED'] = true;
            
            $i = $test_sum = 0; // use it for the last check of the total
            
            # Items calculations
            foreach ($items as $item) {
                $i++;
                
                $params['item_name_'.$i]        = $item['name'];
                $params['item_number_'.$i]      = $item['product_id'];
                $params['item_quantity_'.$i]    = $item['qty'];

                // this is the real price
                $item_qty   = intval($item['qty']);
                $item_price = $item['line_total'] / $item_qty;
                
                $params['item_amount_'.$i] = number_format($item_price, 2, '.', '');
                
                $test_sum += ($item_qty * $params['item_amount_'.$i]);
            }
            
            // last check for correct calculations
            $test_sum -= $params['discount'];
            
            $test_diff = $params['total_amount'] - $params['handling'] - $test_sum;
            if ($test_diff !== 0) {
                $params['handling'] += $test_diff;
                $this->create_log($test_diff, 'Total diff, added to handling: ');
            }
            # Items calculations END

            // be sure there are no array elements in $params !!!
            $params['checksum'] = hash($this->hash_type, stripslashes($this->secret . implode('', $params)));

            $params_array = array();
            foreach ($params as $key => $value) {
                if (!is_array($value)) {
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

            if ($this->cashier_in_iframe === 'yes') {
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

            if ($this->cashier_in_iframe === 'yes') {
                $html .= '<iframe id="i_frame" name="i_frame" onLoad=""; style="width: 100%; height: 1000px;"></iframe>';
            }

            echo esc_html($html);
        }
        # REST API payment
        elseif ($this->payment_api === 'rest') {
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
            if (in_array(
                isset($session['SC_Variables']['APM_data']['payment_method']) ? $session['SC_Variables']['APM_data']['payment_method'] : array(),
                array('cc_card', 'dc_card'),
                true
            )) {
                $payment_method = 'd3d';
            }
                
            $params['checksum'] = hash($this->settings['hash_type'], stripslashes(
                $session['SC_Variables']['merchantId']
                .$session['SC_Variables']['merchantSiteId']
                .$params['client_request_id']
                .$params['total_amount']
                .$params['currency']
                .$TimeStamp
                .$this->secret
            ));
            
            require_once plugin_dir_path(__FILE__) . 'SC_REST_API.php';
            
            $this->create_log($params, 'params sent to REST: ');
            
            // ALWAYS CHECK USED PARAMS IN process_payment
            $resp = SC_REST_API::process_payment(
                $params,
                $session['SC_Variables'],
                $sc_request['order-pay'],
                $payment_method
            );
            
            $this->create_log('REST API payment was sent.');
            
            if (!$resp) {
                if ($order_status === 'pending') {
                    $order->set_status('failed');
                }
                
                $order->add_order_note(__('Payment API response is FALSE.', 'sc'));
                $order->save();
                
                $this->create_log($resp, 'REST API Payment ERROR: ');
                
                echo
                    '<script>'
                        .'window.location.href = "' . esc_url($params['error_url']) . '?Status=fail";'
                    .'</script>';
                exit;
            }
            
            // If we get Transaction ID save it as meta-data
            if (!empty($resp['transactionId'])) {
                $order->update_meta_data(SC_GW_TRANS_ID_KEY, $resp['transactionId'], 0);
            }
            
            if (
                $this->get_request_status($resp) === 'ERROR'
                || (isset($resp['transactionStatus']) && $resp['transactionStatus'] === 'ERROR')
            ) {
                if ($order_status === 'pending') {
                    $order->set_status('failed');
                }
                
                $error_txt = 'Payment error';
                
                if (!empty($resp['reason'])) {
                    $error_txt .= ': ' . $resp['errCode'] . ' - ' . $resp['reason'] . '.';
                } elseif (!empty($resp['transactionStatus'])) {
                    $error_txt .= ': ' . $resp['transactionStatus'] . '.';
                } elseif (!empty($resp['threeDReason'])) {
                    $error_txt .= ': ' . $resp['threeDReason'] . '.';
                }
                
                $order->add_order_note($error_txt);
                $order->save();
                
                $this->create_log($resp['errCode'].': '.$resp['reason'], 'REST API Payment ERROR: ');
                
                echo
                    '<script>'
                        .'window.location.href = "'. esc_url($params['error_url'])
                        . esc_attr((strpos($params['error_url'], '?') === false ? '?' : '&'))
                        . 'Status=fail";'
                    .'</script>';
                exit;
            }
            
            // pay with redirect URL
            if ($this->get_request_status($resp) === 'SUCCESS') {
                # The case with D3D and P3D
                // isDynamic3D is hardcoded to be 1, see SC_REST_API line 509
                // for the three cases see: https://www.safecharge.com/docs/API/?json#dynamic3D,
                // Possible Scenarios for Dynamic 3D (isDynamic3D = 1)
                
                // clear the old session data
                if (isset($session['SC_P3D_Params'])) {
                    unset($session['SC_P3D_Params']);
                }
                // prepare the new session data
                if ($payment_method === 'd3d') {
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
                            'totalTax'          => isset($params['total_tax']) ? $params['total_tax'] : '0.00',
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
                            'ccTempToken'       => $session['SC_Variables']['APM_data']['apm_fields']['ccCardNumber'],
                            'CVV'               => $session['SC_Variables']['APM_data']['apm_fields']['CVV'],
                            'cardHolderName'    => $session['SC_Variables']['APM_data']['apm_fields']['ccNameOnCard'],
                        ),
                        'paResponse'        => '',
                        'urlDetails'        => array('notificationUrl' => $params['urlDetails']),
                        'timeStamp'         => $params['time_stamp'],
                        'checksum'          => $params['checksum'],
                    );
                    
                    $session['SC_P3D_Params'] = $params_p3d;
                    
                    // case 1
                    if (
                        isset($resp['acsUrl'], $resp['threeDFlow'])
                        && !empty($resp['acsUrl'])
                        && intval($resp['threeDFlow']) === 1
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
                                .'<input type="hidden" name="PaReq" value="'. (isset($resp['paRequest']) ? $resp['paRequest'] : '') .'">'
                                .'<input type="hidden" name="TermUrl" value="'
                                    . $params['pending_url']
                                    . (strpos($params['pending_url'], '?') !== false ? '&' : '?')
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
                        
                        echo esc_html($html);
                        exit;
                        
                        // step 2 - wait for the DMN
                    }
                    // case 2
                    elseif (isset($resp['threeDFlow']) && intval($resp['threeDFlow']) === 1) {
                        $this->create_log('', 'D3D case 2.');
                        $this->pay_with_d3d_p3d();
                    }
                    // case 3 do nothing
                }
                # The case with D3D and P3D END
                
                // in case we have redirectURL
                if (isset($resp['redirectURL']) && !empty($resp['redirectURL'])) {
                    $this->create_log($resp['redirectURL'], 'we have redirectURL: ');
                    
                    if (
                        (isset($resp['gwErrorCode']) && $resp['gwErrorCode'] === -1)
                        || !empty($resp['gwErrorReason'])
                    ) {
                        $order->add_order_note(
                            esc_html__('Payment with redirect URL error: ' . (isset($resp['gwErrorReason']) ? $resp['gwErrorReason'] : '') . '.', 'sc')
                        );
                        $order->save();
                        
                        echo
                            '<script>'
                                .'window.location.href = "' . esc_url($params['error_url'])
                                    . esc_attr((strpos($params['error_url'], '?') === false ? '?' : '&'))
                                    . 'Status=fail'
                            .'</script>';
                        exit;
                    }
                    
                    echo
                        '<script>'
                            .'window.location.href = "' . esc_url($resp['redirectURL']) . '";'
                        .'</script>';
                    
                    exit;
                }
            }
            
            $order_status = strtolower($order->get_status());
            
            if (isset($resp['transactionId']) && $resp['transactionId'] !== '') {
                $order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
            } else {
                $order->add_order_note(__('Payment succsess.'));
            }
            
            // save the response transactionType value
            if (isset($resp['transactionType']) && $resp['transactionType'] !== '') {
                $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $resp['transactionType']);
            }
            
            $order->save();
            
            echo
                '<script>'
                    .'window.location.href="' . esc_url($params['error_url'])
                        . esc_attr((strpos($params['error_url'], '?') === false ? '?' : '&'))
                        . 'Status=success";'
                .'</script>';
            
            exit;
        }
        # ERROR - not existing payment api
        else {
            $this->create_log(
                'Wrong paiment api ('. $this->payment_api .').',
                'Payment form ERROR: '
            );
            
            echo
                '<script>'
                    .'window.location.href="' . esc_url($params['error_url'])
                        . esc_attr(
                            (strpos($params['error_url'], '?') === false ? '?' : '&')
                            . 'Status=fail&invoice_id='
                            . $order_id
                        ) . '&wc-api=sc_listener&reason=not-existing-payment-API"'
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
        global $session;
        
        $p3d_resp = false;
        
        try {
            $order = new WC_Order(isset($session['SC_P3D_Params']['clientUniqueId']) ? $session['SC_P3D_Params']['clientUniqueId'] : 0);

            if (!$order || !isset($session['SC_Variables']['test'], $session['SC_P3D_Params'])) {
                echo
                    '<script>'
                        .'window.location.href = "' . esc_url($this->get_return_url()) . '?Status=fail";'
                    .'</script>';
                exit;
            }

            // some corrections
            $session['SC_P3D_Params']['transactionType'] = $this->transaction_type;
            $session['SC_P3D_Params']['urlDetails']['notificationUrl'] = $session['SC_P3D_Params']['urlDetails']['notificationUrl']['notificationUrl'];

            $this->create_log('', 'pay_with_d3d_p3d call to the REST API.');

            require_once plugin_dir_path(__FILE__) . 'SC_REST_API.php';

            $p3d_resp = SC_REST_API::call_rest_api(
                $session['SC_Variables']['test'] === 'yes' ? SC_TEST_P3D_URL : SC_LIVE_P3D_URL,
                $session['SC_P3D_Params'],
                $session['SC_P3D_Params']['checksum'],
                array('webMasterId' => $this->webMasterId)
            );
        } catch (Exception $e) {
            $this->create_log($e->getMessage(), 'pay_with_d3d_p3d Exception: ');
            
            echo
                '<script>'
                    .'window.location.href = "' . esc_url($this->get_return_url()) . '?Status=fail";'
                .'</script>';
            exit;
        }
        
        if (!$p3d_resp) {
            $order->set_status('failed');
            $order->add_order_note(__('Payment 3D API response is false.', 'sc'));
            $order->save();

            echo
                '<script>'
                    .'window.location.href = "' . esc_url($this->get_return_url()) . '?Status=fail";'
                .'</script>';
            exit;
        }
        
        // save the response type of transaction
        if (isset($p3d_resp['transactionType']) && $p3d_resp['transactionType'] !== '') {
            $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $p3d_resp['transactionType']);
        }
        
        echo
            '<script>'
                .'window.location.href = "'. esc_url($this->get_return_url()) .'";'
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
        global $session;
        $post = $_POST;
        
        // get AMP fields and add them to the session for future use
        if (isset($post['payment_method_sc']) && !empty($post['payment_method_sc'])) {
            $session['SC_Variables']['APM_data']['payment_method'] = $post['payment_method_sc'];
            
            if (
                isset($post[$post['payment_method_sc']])
                && !empty($post[$post['payment_method_sc']])
                && is_array($post[$post['payment_method_sc']])
            ) {
                $session['SC_Variables']['APM_data']['apm_fields'] = $post[$post['payment_method_sc']];
            }
        }
        
        // lst parameter is passed from the form. It is the session token used for
        // card tokenization. We MUST use the same token for D3D Payment
        if (isset($post, $post['lst']) && !empty($post['lst'])) {
            $session['SC_Variables']['lst'] = $post['lst'];
        }
        
        $order = new WC_Order($order_id);
       
        return array(
            'result'    => 'success',
            'redirect'    => add_query_arg(
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
        global $sc_request;
        global $session;
        
        $this->create_log($sc_request, 'Receive DMN with params: ');
        
        $req_status = $this->get_request_status();
        
        # Sale and Auth
        if (
            isset($sc_request['transactionType'], $sc_request['invoice_id'])
            && in_array($sc_request['transactionType'], array('Sale', 'Auth'), true)
            && $this->checkAdvancedCheckSum()
        ) {
            $this->create_log('A sale/auth.');
            $order_id = 0;
            
            // Cashier
            if (!empty($sc_request['invoice_id'])) {
                $this->create_log('Cashier sale.');
                
                try {
                    $arr = explode("_", $sc_request['invoice_id']);
                    $order_id  = intval($arr[0]);
                } catch (Exception $ex) {
                    $this->create_log($ex->getMessage(), 'Cashier DMN Exception when try to get Order ID: ');
                    echo 'DMN Exception: ' . esc_html($ex->getMessage());
                    exit;
                }
            }
            // REST
            else {
                $this->create_log('REST sale.');
                
                try {
                    $order_id = $sc_request['merchant_unique_id'];
                } catch (Exception $ex) {
                    $this->create_log($ex->getMessage(), 'REST DMN Exception when try to get Order ID: ');
                    echo 'DMN Exception: ' . esc_html($ex->getMessage());
                    exit;
                }
            }
            
            try {
                $order = new WC_Order($order_id);
                $order_status = strtolower($order->get_status());
                
                $order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $sc_request['transactionType']);
                $this->save_update_order_numbers($order);
                
                if ($order_status !== 'completed') {
                    $this->change_order_status($order, $arr[0], $req_status, $sc_request['transactionType']);
                }
            } catch (Exception $ex) {
                $this->create_log($ex->getMessage(), 'Sale DMN Exception: ');
                echo 'DMN Exception: ' . esc_html($ex->getMessage());
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
        if (
            isset($sc_request['clientUniqueId'], $sc_request['transactionType'])
            && $sc_request['clientUniqueId'] !== ''
            && ($sc_request['transactionType'] === 'Void' || $sc_request['transactionType'] === 'Settle')
            && $this->checkAdvancedCheckSum()
        ) {
            $this->create_log('', $sc_request['transactionType']);
            
            try {
                $order = new WC_Order($sc_request['clientUniqueId']);
                
                if ($sc_request['transactionType'] === 'Settle') {
                    $this->save_update_order_numbers($order);
                }
                
                $this->change_order_status(
                    $order,
                    $sc_request['clientUniqueId'],
                    $req_status,
                    $sc_request['transactionType']
                );
                
                $order_id = isset($sc_request['clientUniqueId']) ? $sc_request['clientUniqueId'] : 0;
            } catch (Exception $ex) {
                $this->create_log(
                    $ex->getMessage(),
                    'process_dmns() REST API DMN DMN Exception: probably invalid order number'
                );
            }
            
            $msg = __('DMN for Order #' . $order_id . ', was received.', 'sc');
            
            if (!empty($sc_request['Reason'])) {
                $msg .= ' ' . __($sc_request['Reason'] . '.', 'sc');
            }
            
            $order->add_order_note($msg);
            $order->save();
            
            echo 'DMN received.';
            exit;
        }
        
        # Refund
        // see https://www.safecharge.com/docs/API/?json#refundTransaction -> Output Parameters
        // when we refund form CPanel we get transactionType = Credit and Status = 'APPROVED'
        if (
            (
                (isset($sc_request['action']) && $sc_request['action'] === 'refund')
                || (
                    isset($sc_request['transactionType'])
                    && in_array($sc_request['transactionType'], array('Credit', 'Refund'), true)
                )
            )
            && !empty($req_status)
            && $this->checkAdvancedCheckSum()
        ) {
            $this->create_log('Refund DMN.');
            
            $order = new WC_Order(isset($sc_request['order_id']) ? $sc_request['order_id'] : '');

            if (!is_a($order, 'WC_Order')) {
                $this->create_log('DMN meassage: there is no Order!');
                
                echo 'There is no Order';
                exit;
            }
            
            // change to Refund if request is Approved and the Order status is not Refunded
            if ($req_status === 'APPROVED') {
                $this->change_order_status(
                    $order,
                    $order->get_id(),
                    'APPROVED',
                    'Credit',
                    array(
                        'resp_id'       => isset($sc_request['clientUniqueId']) ? $sc_request['clientUniqueId'] : '',
                        'totalAmount'   => isset($sc_request['totalAmount']) ? $sc_request['totalAmount'] : ''
                    )
                );
            } elseif (
                (isset($sc_request['transactionStatus']) && $sc_request['transactionStatus'] === 'DECLINED')
                || (isset($sc_request['transactionStatus']) && $sc_request['transactionStatus'] === 'ERROR')
            ) {
                $msg = 'DMN message: Your try to Refund #' . $sc_request['clientUniqueId']
                    .' faild with ERROR: "';

                // in case DMN URL was rewrited all spaces were replaces with "_"
                if (
                    isset($sc_request['wc_sc_redirected'], $sc_request['gwErrorReason'])
                    && $sc_request['wc_sc_redirected'] === 1
                ) {
                    $msg .= str_replace('_', ' ', $sc_request['gwErrorReason']);
                } else {
                    $msg .= isset($sc_request['gwErrorReason']) ? $sc_request['gwErrorReason'] : '';
                }

                $msg .= '".';

                $order -> add_order_note(__($msg, 'sc'));
                $order->save();
            }
            
            echo 'DMN received - Refund.';
            exit;
        }
        
        # D3D and P3D payment
        // the idea here is to get $sc_request['paResponse'] and pass it to P3D
        elseif (isset($sc_request['action']) && $sc_request['action'] === 'p3d') {
            $this->create_log('p3d.');
            
            // the DMN from case 1 - issuer/bank
            if (
                isset($session['SC_P3D_Params'], $sc_request['PaRes'])
                && is_array($session['SC_P3D_Params'])
            ) {
                $session['SC_P3D_Params']['paResponse'] = $sc_request['PaRes'];
                $this->pay_with_d3d_p3d();
            }
            // the DMN from case 2 - p3d
            elseif (isset($sc_request['merchantId'], $sc_request['merchantSiteId'])) {
                // here we must unset $_SESSION['SC_P3D_Params'] as last step
                try {
                    $order = new WC_Order(isset($sc_request['clientUniqueId']) ? $sc_request['clientUniqueId'] : 0);

                    $this->change_order_status(
                        $order,
                        isset($sc_request['clientUniqueId']) ? $sc_request['clientUniqueId'] : '',
                        $this->get_request_status(),
                        isset($sc_request['transactionType']) ? $sc_request['transactionType'] : ''
                    );
                } catch (Exception $ex) {
                    $this->create_log(
                        $ex->getMessage(),
                        'process_dmns() REST API DMN DMN Exception: '
                    );
                }
            }
            
            if (isset($session['SC_P3D_Params'])) {
                unset($session['SC_P3D_Params']);
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # other cases
        if (!isset($sc_request['action']) && $this->checkAdvancedCheckSum()) {
            $this->create_log('', 'Other cases.');
            
            try {
                $order = new WC_Order(isset($sc_request['clientUniqueId']) ? $sc_request['clientUniqueId'] : 0);

                $this->change_order_status(
                    $order,
                    isset($sc_request['clientUniqueId']) ? $sc_request['clientUniqueId'] : '',
                    $this->get_request_status(),
                    isset($sc_request['transactionType']) ? $sc_request['transactionType'] : ''
                );
            } catch (Exception $ex) {
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
        
        if ($req_status === '') {
            echo 'Error: the DMN Status is empty!';
            exit;
        }
        
        echo 'DMN was not recognized.';
        exit;
    }
    
    public function sc_checkout_process()
    {
        global $session;
        $post = $_POST;
        
        $session['sc_subpayment'] = '';
        if (isset($post['payment_method_sc'])) {
            $session['sc_subpayment'] = $post['payment_method_sc'];
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
        global $sc_request;
        
        if(
            !isset($sc_request['totalAmount'])
            || !isset($sc_request['currency'])
            || !isset($sc_request['responseTimeStamp'])
            || !isset($sc_request['PPP_TransactionID'])
            || !isset($sc_request['productId'])
            || !isset($sc_request['advanceResponseChecksum'])
        ) {
            return false;
        }
        
        $str = hash(
            $this->hash_type,
            $this->secret . $sc_request['totalAmount'] . $sc_request['currency']
                . $sc_request['responseTimeStamp'] . $sc_request['PPP_TransactionID']
                . $this->get_request_status() . $sc_request['productId']
        );

        if ($str === $sc_request['advanceResponseChecksum']) {
            return true;
        }
        
        return false;
    }
    
    public function set_notify_url()
    {
        $url_part = get_site_url();
            
        $url = $url_part
            . (strpos($url_part, '?') !== false ? '&' : '?') . 'wc-api=sc_listener';
        
        // some servers needs / before ?
        if (strpos($url, '?') !== false && strpos($url, '/?') === false) {
            $url = str_replace('?', '/?', $url);
        }
        
        // force Notification URL protocol to http
        if (isset($this->use_http) && $this->use_http === 'yes' && strpos($url, 'https://') !== false) {
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
        switch ($key) {
            case 'completed_date':
                return $order->get_date_completed() ?
                    gmdate('Y-m-d H:i:s', $order->get_date_completed()->getOffsetTimestamp()) : '';

            case 'paid_date':
                return $order->get_date_paid() ?
                    gmdate('Y-m-d H:i:s', $order->get_date_paid()->getOffsetTimestamp()) : '';

            case 'modified_date':
                return $order->get_date_modified() ?
                    gmdate('Y-m-d H:i:s', $order->get_date_modified()->getOffsetTimestamp()) : '';

            case 'order_date':
                return $order->get_date_created() ?
                    gmdate('Y-m-d H:i:s', $order->get_date_created()->getOffsetTimestamp()) : '';

            case 'id':
                return $order->get_id();

            case 'post':
                return get_post($order->get_id());

            case 'status':
                return $order->get_status();

            case 'post_status':
                return get_post_status($order->get_id());

            case 'customer_message':
            case 'customer_note':
                return $order->get_customer_note();

            case 'user_id':
            case 'customer_user':
                return $order->get_customer_id();

            case 'tax_display_cart':
                return get_option('woocommerce_tax_display_cart');

            case 'display_totals_ex_tax':
                return 'excl' === get_option('woocommerce_tax_display_cart');

            case 'display_cart_ex_tax':
                return 'excl' === get_option('woocommerce_tax_display_cart');

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
                return get_post_meta($order->get_id(), '_' . $key, true);
        }

        // try to call {get_$key} method
        if (is_callable(array( $order, "get_{$key}" ))) {
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
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $post = $_POST;
        
        if ($post['api_refund'] === 'true') {
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
        global $session;
        global $sc_request;
        
        $post = $_POST;
        
        if (
            (isset($post['api_refund']) && $post['api_refund'] === 'false')
            || !$refund
        ) {
            return false;
        }
        
        // get order refunds
        try {
            $refund_data = $refund->get_data();
            $refund_data['webMasterId'] = $this->webMasterId; // need this param for the API
            
            // the hooks calling this method, fired twice when change status
            // to Refunded, but we do not want to try more than one SC Refunds
            if (isset($session['sc_last_refund_id'])) {
                if (intval($session['sc_last_refund_id']) === intval($refund_data['id'])) {
                    unset($session['sc_last_refund_id']);
                    return;
                } else {
                    $session['sc_last_refund_id'] = $refund_data['id'];
                }
            } else {
                $session['sc_last_refund_id'] = $refund_data['id'];
            }
            
            $order_id = isset($sc_request['order_id']) ? intval($sc_request['order_id']) : 0;
            // when we set status to Refunded
            if (isset($sc_request['post_ID'])) {
                $order_id = intval($sc_request['post_ID']);
            }
            
            $order = new WC_Order($order_id);
            
            $order_meta_data = array(
                'order_tr_id'   => $order->get_meta(SC_GW_TRANS_ID_KEY),
                'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
            );
        } catch (Exception $ex) {
            $this->create_log($ex->getMessage(), 'sc_create_refund() Exception: ');
            return;
        }
        
        if (!$order_meta_data['order_tr_id'] && !$order_meta_data['auth_code']) {
            $order->add_order_note(__('Missing Auth code and Transaction ID.', 'sc'));
            $order->save();
            
            return;
        }

        if (!is_array($refund_data)) {
            $order->add_order_note(__('There is no refund data. If refund was made, delete it manually!', 'sc'));
            $order->save();
            
            return;
        }

        // call refund method
        require_once plugin_dir_path(__FILE__) . 'SC_REST_API.php';

        $notify_url = $this->set_notify_url();
        
        // execute refund, the response must be array('msg' => 'some msg', 'new_order_status' => 'some status')
        $resp = SC_REST_API::refund_order(
            $this->settings,
            $refund_data,
            $order_meta_data,
            get_woocommerce_currency(),
            $notify_url . '&action=refund&order_id=' . $order_id
        );
        
        $cpanel_url = SC_TEST_CPANEL_URL;

        if ($this->settings['test'] === 'no') {
            $cpanel_url = SC_LIVE_CPANEL_URL;
        }

        $msg = '';
        $error_note = 'Please manually delete request Refund #'
            .$refund_data['id'].' form the order or login into <i>'. $cpanel_url
            .'</i> and refund Transaction ID '.$order_meta_data['order_tr_id'];

        if ($resp === false) {
            $msg = 'The REST API retun false. ' . $error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        $json_arr = $resp;
        if (!is_array($resp)) {
            parse_str($resp, $json_arr);
        }

        if (!is_array($json_arr)) {
            $msg = 'Invalid API response. ' . $error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        // in case we have message but without status
        if (!isset($json_arr['status']) && isset($json_arr['msg'])) {
            // save response message in the History
            $msg = 'Request Refund #' . $refund_data['id'] . ' problem: ' . $json_arr['msg'];

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        // the status of the request is ERROR
        if (isset($json_arr['status']) && $json_arr['status'] === 'ERROR') {
            $msg = 'Request ERROR - "' . $json_arr['reason'] .'" '. $error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        // the status of the request is SUCCESS, check the transaction status
        if (isset($json_arr['transactionStatus']) && $json_arr['transactionStatus'] === 'ERROR') {
            if (isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
                $msg = $json_arr['gwErrorReason'];
            } elseif (isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
                $msg = $json_arr['paymentMethodErrorReason'];
            } else {
                $msg = 'Transaction error';
            }

            $msg .= '. ' .$error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            return;
        }

        if (isset($json_arr['transactionStatus']) && $json_arr['transactionStatus'] === 'DECLINED') {
            $msg = 'The refund was declined. ' .$error_note;

            $order->add_order_note(__($msg, 'sc'));
            $order->save();
            
            return;
        }

        if (isset($json_arr['transactionStatus']) && $json_arr['transactionStatus'] === 'APPROVED') {
            return;
        }

        $msg = 'The status of request - Refund #' . $refund_data['id'] . ', is UNKONOWN.';

        $order->add_order_note(__($msg, 'sc'));
        $order->save();
        
        return;
    }
    
    public function sc_return_sc_settle_btn($args)
    {
        global $sc_request;
        
        // revert buttons on Recalculate
        if (!isset($sc_request['refund_amount']) && isset($sc_request['items'])) {
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
        if ($is_order_restock !== 1) {
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
        global $sc_request;
        
        $this->create_log(
            'Order ' . $order_id .' has Status: ' . $status,
            'WC_SC change_order_status() status-order: '
        );
        
        $request = $sc_request;
        if (!empty($res_args)) {
            $request = $res_args;
        }
        
        switch ($status) {
            case 'CANCELED':
                $message = 'Payment status changed to:' . $status
                    . '. PPP_TransactionID = '
                    . (isset($request['PPP_TransactionID']) ? $request['PPP_TransactionID'] : '')
                    . ", Status = " .$status. ', GW_TransactionID = '
                    . (isset($request['TransactionID']) ? $request['TransactionID'] : '');

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->update_status('failed');
                $order->add_order_note('Failed');
                $order->add_order_note($this->msg['message']);
            break;

            case 'APPROVED':
                if ($transactionType === 'Void') {
                    $order->add_order_note(__('DMN message: Your Void request was success, Order #'
                        . (isset($request['clientUniqueId']) ? $request['clientUniqueId'] : '')
                        . ' was canceld. Plsese check your stock!', 'sc'));

                    $order->update_status('cancelled');
                    break;
                }
                
                // Refun Approved
                if ($transactionType === 'Credit') {
                    $order->add_order_note(
                        __('DMN message: Your Refund #' . $res_args['resp_id']
                            .' was successful. Refund Transaction ID is: ', 'sc')
                            . (isset($request['TransactionID']) ? $request['TransactionID'] : '') . '.'
                    );
                    
                    // set flag that order has some refunds
                    $order->update_meta_data('_scHasRefund', 1);
                    $order->save();
                    break;
                }
                
                $message = 'The amount has been authorized and captured by '
                    . SC_GATEWAY_TITLE . '. ';
                
                if ($transactionType === 'Auth') {
                    $message = 'The amount has been authorized and wait to for Settle. ';
                } elseif ($transactionType === 'Settle') {
                    $message = 'The amount has been captured by ' . SC_GATEWAY_TITLE . '. ';
                }
                
                $message .= 'PPP_TransactionID = '
                    . (isset($request['PPP_TransactionID']) ? $request['PPP_TransactionID'] : '')
                    . ", Status = ". $status;

                if ($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '
                    . (isset($request['TransactionID']) ? $request['TransactionID'] : '');

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->payment_complete($order_id);
                
                if ($transactionType === 'Auth') {
                    $order->update_status('pending');
                }
                // Settle - do two steps
                else {
                    $order->update_status('processing');
                    $order->save();
                    
                    $order->update_status('completed');
                }
                
                if ($transactionType !== 'Auth') {
                    $order->add_order_note(SC_GATEWAY_TITLE . ' payment is successful<br/>Unique Id: '
                        . (isset($request['PPP_TransactionID']) ? $request['PPP_TransactionID'] : ''));
                }

                $order->add_order_note($this->msg['message']);
            break;

            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $reason = ', Reason = ';
                if (isset($request['reason']) && $request['reason'] !== '') {
                    $reason .= $request['reason'];
                } elseif (isset($request['Reason']) && $request['Reason'] !== '') {
                    $reason .= $request['Reason'];
                }
                
                $message = 'Payment failed. PPP_TransactionID = '
                    . (isset($request['PPP_TransactionID']) ? $request['PPP_TransactionID'] : '')
                    . ", Status = ". $status .", Error code = "
                    . (isset($request['ErrCode']) ? $request['ErrCode'] : '')
                    . ", Message = "
                    . (isset($request['message']) ? $request['message'] : '')
                    . $reason;
                
                if ($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = '
                    . (isset($request['TransactionID']) ? $request['TransactionID'] : '');
                
                // do not change status
                if ($transactionType === 'Void') {
                    $message = 'DMN message: Your Void request fail with message: "';

                    // in case DMN URL was rewrited all spaces were replaces with "_"
                    if (isset($request['wc_sc_redirected']) && $request['wc_sc_redirected'] === 1) {
                        if(isset($request['message'])) {
                            $message .= str_replace('_', ' ', $request['message']);
                        }
                    } elseif (isset($request['msg'])) {
                        $message .= $request['msg'];
                    }

                    $message .= '". Order #'  . $request['clientUniqueId']
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
                if ($ord_status === 'processing' || $ord_status === 'completed') {
                    break;
                }

                $message ='Payment is still pending, PPP_TransactionID '
                    . (isset($request['PPP_TransactionID']) ? $request['PPP_TransactionID'] : '')
                    . ", Status = ". $status;

                if ($transactionType) {
                    $message .= ", TransactionType = ". $transactionType;
                }

                $message .= ', GW_TransactionID = ' . isset($request['TransactionID'])
                    ? $request['TransactionID'] : '';

                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message woocommerce_message_info';
                $order->add_order_note(SC_GATEWAY_TITLE .' payment status is pending<br/>Unique Id: '
                    . isset($request['PPP_TransactionID'])) ? $request['PPP_TransactionID'] : '';

                $order->add_order_note($this->msg['message']);
                $order->update_status('on-hold');
            break;
        }
        
        $order->save();
    }
    
    private function set_environment()
    {
        if ($this->test === 'yes') {
            $this->use_session_token_url    = SC_TEST_SESSION_TOKEN_URL;
            $this->use_merch_paym_meth_url  = SC_TEST_REST_PAYMENT_METHODS_URL;
            
            // set payment URL
            if ($this->payment_api === 'cashier') {
                $this->URL = SC_TEST_CASHIER_URL;
            } elseif ($this->payment_api === 'rest') {
                $this->URL = SC_TEST_PAYMENT_URL;
            }
        } else {
            $this->use_session_token_url    = SC_LIVE_SESSION_TOKEN_URL;
            $this->use_merch_paym_meth_url  = SC_LIVE_REST_PAYMENT_METHODS_URL;
            
            // set payment URL
            if ($this->payment_api === 'cashier') {
                $this->URL = SC_LIVE_CASHIER_URL;
            } elseif ($this->payment_api === 'rest') {
                $this->URL = SC_LIVE_PAYMENT_URL;
            }
        }
    }
    
    private function formatLocation($locale)
    {
        switch ($locale) {
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
        global $sc_request;
        
        // save or update AuthCode and GW Transaction ID
        $auth_code = isset($sc_request['AuthCode']) ? $sc_request['AuthCode'] : '';
        $saved_ac = $order->get_meta(SC_AUTH_CODE_KEY);

        if (!$saved_ac || empty($saved_ac) || $saved_ac !== $auth_code) {
            $order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
        }

        $gw_transaction_id = isset($sc_request['TransactionID']) ? $sc_request['TransactionID'] : '';
        $saved_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);

        if (!$saved_tr_id || empty($saved_tr_id) || $saved_tr_id !== $gw_transaction_id) {
            $order->update_meta_data(SC_GW_TRANS_ID_KEY, $gw_transaction_id);
        }
        
        if (isset($sc_request['payment_method']) && $sc_request['payment_method']) {
            $order->update_meta_data('_paymentMethod', $sc_request['payment_method']);
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
        global $sc_request;
        
        if (empty($params)) {
            if (isset($sc_request['Status'])) {
                return $sc_request['Status'];
            }

            if (isset($sc_request['status'])) {
                return $sc_request['status'];
            }
        } else {
            if (isset($params['Status'])) {
                return $params['Status'];
            }

            if (isset($params['status'])) {
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
        if (
            !isset($this->save_logs)
            || $this->save_logs === 'no'
            || $this->save_logs === null
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
}

?>
