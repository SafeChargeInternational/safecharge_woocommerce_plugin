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

class WC_SC extends WC_Payment_Gateway {

	# payments URL
	private $URL         = '';
	private $webMasterId = 'WooCommerce ';
	
	public function __construct() {
		$plugin_dir        = basename(dirname(__FILE__));
		$this->plugin_path = plugin_dir_path(__FILE__) . $plugin_dir . DIRECTORY_SEPARATOR;
		$this->plugin_url  = get_site_url() . DIRECTORY_SEPARATOR . 'wp-content'
			. DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $plugin_dir
			. DIRECTORY_SEPARATOR;
		
		# settings to get/save options
		$this->id                 = 'sc';
		$this->method_title       = SC_GATEWAY_TITLE;
		$this->method_description = 'Pay with ' . SC_GATEWAY_TITLE . '.';
		$this->icon               = $this->plugin_url . 'icons/safecharge.png';
		$this->has_fields         = false;

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
		$this->webMasterId     .= WOOCOMMERCE_VERSION;
		
		$_SESSION['SC_Variables']['sc_create_logs'] = $this->save_logs;
		
		$this->use_wpml_thanks_page =
			@$this->settings['use_wpml_thanks_page'] ? $this->settings['use_wpml_thanks_page'] : 'no';
		$this->cashier_in_iframe    =
			@$this->settings['cashier_in_iframe'] ? $this->settings['cashier_in_iframe'] : 'no';
		
		$this->supports[] = 'refunds'; // to enable auto refund support
		
		$this->init_form_fields();
		
		$this->msg['message'] = '';
		$this->msg['class']   = '';
		
		SC_Versions_Resolver::process_admin_options($this);
		
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
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'sc'),
				'type' => 'checkbox',
				'label' => __('Enable ' . SC_GATEWAY_TITLE . ' Payment Module.', 'sc'),
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
				'default' => 'Place order to get to our secured payment page to select your payment option'
			),
			'merchantId' => array(
				'title' => __('Merchant ID', 'sc'),
				'type' => 'text',
				'description' => __('Merchant ID is provided by ' . SC_GATEWAY_TITLE . '.')
			),
			'merchantSiteId' => array(
				'title' => __('Merchant Site ID', 'sc'),
				'type' => 'text',
				'description' => __('Merchant Site ID is provided by ' . SC_GATEWAY_TITLE . '.')
			),
			'secret' => array(
				'title' => __('Secret key', 'sc'),
				'type' => 'text',
				'description' =>  __('Secret key is provided by ' . SC_GATEWAY_TITLE, 'sc'),
			),
			'hash_type' => array(
				'title' => __('Hash type', 'sc'),
				'type' => 'select',
				'description' => __('Choose Hash type provided by ' . SC_GATEWAY_TITLE, 'sc'),
				'options' => array(
					'sha256' => 'sha256',
					'md5' => 'md5',
				)
			),
			'payment_api' => array(
				'title' => __('Payment solution', 'sc'),
				'type' => 'select',
				'description' => __('Select ' . SC_GATEWAY_TITLE . ' payment API', 'sc'),
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
	 * @param mixed $key
	 * @param mixed $data
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function generate_button_html( $key, $data) {
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
				<label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($data['title']); ?></label>
				<?php echo esc_html($this->get_tooltip_html($data)); ?>
			</th>
			
			<td class="forminp" style="position: relative;">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php echo wp_kses_post($data['title']); ?></span>
					</legend>
					
					<button class="<?php echo esc_attr($data['class']); ?>" 
							type="button" 
							name="<?php echo esc_attr($field); ?>" 
							id="<?php echo esc_attr($field); ?>" 
							style="<?php echo esc_attr($data['css']); ?>" 
							<?php echo esc_attr($this->get_custom_attribute_html($data)); ?>
					>
						<?php echo esc_html($data['title']); ?>
					</button>
					
						<?php echo esc_html($this->get_description_html($data)); ?>
				</fieldset>
				
				<div id="custom_loader" class="blockUI blockOverlay" style="margin-left: -3.5em;"></div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	// Generate the HTML For the settings form.
	public function admin_options() {
		echo
			'<h3>' . esc_html(SC_GATEWAY_TITLE . ' ', 'sc') . '</h3>'
				. '<p>' . esc_html__('SC payment option') . '</p>'
				. '<table class="form-table">';
		
					$this->generate_settings_html();
		
		echo
				'</table>';
	}

	/**
	 * Function payment_fields
	 *
	 *  Add fields on the payment page. Because we get APMs with Ajax
	 * here we add only AMPs fields modal.
	 **/
	public function payment_fields() {
		if ($this->description) {
			echo wp_kses_post(wpautop(wptexturize($this->description)));
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
	public function generate_sc_form( $order_id) {
		SC_HELPER::create_log('generate_sc_form()');
		
		$url = $this->get_return_url();
		
		$loading_table_html =
			'<table id="sc_pay_msg" style="border: 3px solid #aaa; cursor: wait; line-height: 32px;"><tr>'
				. '<td style="padding: 0px; border: 0px; width: 100px;">'
					. '<img src="' . esc_attr($this->plugin_url) . 'icons/loading.gif" style="width:100px; float:left; margin-right: 10px;" />'
				. '</td>'
				. '<td style="text-align: left; border: 0px;">'
					. '<span>' . __('Thank you for your order. We are now redirecting you to ' . esc_html(SC_GATEWAY_TITLE) . ' Payment Gateway to make payment.', 'sc') . '</span>'
				. '</td>'
			. '</tr></table>';
		
		// try to prepend the loading table first
		echo
			'<script type="text/javascript">'
				. 'jQuery("header.entry-header").prepend(\'' . wp_kses_post($loading_table_html) . '\');'
			. '</script>'
			. '<noscript>' . wp_kses_post($loading_table_html) . '</noscript>';
		
		// Order error
		if (isset($_REQUEST['status']) && 'error' == $_REQUEST['status']) {
			echo
				'<script type="text/javascript">'
					. 'window.location.href = "' . esc_url($url) . '?Status=error"'
				. '</script>'
				. '<noscript>'
					. '<a href="' . esc_url($url) . '">' . esc_html__('Click to continue!', 'sc') . '</a>'
				. '</noscript>';
			
			exit;
		}
		
		$order = new WC_Order($order_id);
		
		// Order with Redirect URL
		if (isset($_REQUEST['redirectUrl']) && 1 === sanitize_text_field($_REQUEST['redirectUrl'])) {
			echo
				'<form action="' . esc_attr(@$_SESSION['SC_P3D_acsUrl']) . '" method="post" id="sc_payment_form">'
					. '<input type="hidden" name="PaReq" value="' . esc_attr(@$_SESSION['SC_P3D_PaReq']) . '">'
					. '<input type="hidden" name="TermUrl" value="'
						. esc_url($url . ( false !== strpos($url, '?') ? '&' : '?' ))
						. 'wc-api=sc_listener&action=p3d">'
					. '<noscript>'
						. '<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'
							. esc_attr(__('Pay via ' . SC_GATEWAY_TITLE, 'sc')) . '" />'
						. '<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">'
							. esc_html__('Cancel order &amp; restore cart', 'sc') . '</a>'
					. '</noscript>'
					. '<script type="text/javascript">'
						. 'jQuery(function(){'
							. 'jQuery("#sc_payment_form").submit();'
						. '});'
					. '</script>'
				. '</form>';
			
			wp_die();
		}
		
		// when we will pay with the Cashier
		try {
			$TimeStamp    = date('Ymdhis');
			$order        = new WC_Order($order_id);
			$order_status = strtolower($order->get_status());

			$order->add_order_note(__('User is redicted to ' . SC_GATEWAY_TITLE . ' Payment page.', 'sc'));
			$order->save();

			$notify_url = $this->set_notify_url();
			
			$items                   = $order->get_items();
			$params['numberofitems'] = count($items);

			$params['handling'] = number_format(
				( SC_Versions_Resolver::get_shipping($order) + $this->get_order_data($order, 'order_shipping_tax') ),
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
			$taxes          = WC_Tax::get_rates();
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
			if ('yes' === $this->cashier_in_iframe) {
				if (strpos($return_url, '?') !== false) {
					$return_url .= '&use_iframe=1';
				} else {
					$return_url .= '?use_iframe=1';
				}
			}

			$params['success_url']        = $return_url;
			$params['pending_url']        = $return_url;
			$params['error_url']          = $return_url;
			$params['back_url']           = $payment_page;
			$params['notify_url']         = $notify_url;
			$params['invoice_id']         = $order_id . '_' . $TimeStamp;
			$params['merchant_unique_id'] = $order_id;

			// get and pass billing data
			$params['first_name'] =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_first_name')));
			$params['last_name']  =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_last_name')));
			$params['address1']   =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_address_1')));
			$params['address2']   =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_address_2')));
			$params['zip']        =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_zip')));
			$params['city']       =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_city')));
			$params['state']      =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_state')));
			$params['country']    =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_country')));
			$params['phone1']     =
				urlencode(preg_replace('/[[:punct:]]/', '', SC_Versions_Resolver::get_order_data($order, 'billing_phone')));

			$params['email']         = SC_Versions_Resolver::get_order_data($order, 'billing_email');
			$params['user_token_id'] = is_user_logged_in() ? $params['email'] : '';
			
			// get and pass billing data END

			// get and pass shipping data
			$sh_f_name = urlencode(preg_replace(
				'/[[:punct:]]/',
				'',
				SC_Versions_Resolver::get_order_data($order, 'shipping_first_name')
			));

			if (empty(trim($sh_f_name))) {
				$sh_f_name = $params['first_name'];
			}
			$params['shippingFirstName'] = $sh_f_name;

			$sh_l_name = urlencode(preg_replace(
				'/[[:punct:]]/',
				'',
				SC_Versions_Resolver::get_order_data($order, 'shipping_last_name')
			));

			if (empty(trim($sh_l_name))) {
				$sh_l_name = $params['last_name'];
			}
			$params['shippingLastName'] = $sh_l_name;

			$sh_addr = urlencode(preg_replace(
				'/[[:punct:]]/',
				'',
				SC_Versions_Resolver::get_order_data($order, 'shipping_address_1')
			));
			if (empty(trim($sh_addr))) {
				$sh_addr = $params['address1'];
			}
			$params['shippingAddress'] = $sh_addr;

			$sh_city = urlencode(preg_replace(
				'/[[:punct:]]/',
				'',
				SC_Versions_Resolver::get_order_data($order, 'shipping_city')
			));
			if (empty(trim($sh_city))) {
				$sh_city = $params['city'];
			}
			$params['shippingCity'] = $sh_city;

			$sh_country = urlencode(preg_replace(
				'/[[:punct:]]/',
				'',
				SC_Versions_Resolver::get_order_data($order, 'shipping_country')
			));
			if (empty(trim($sh_country))) {
				$sh_city = $params['country'];
			}
			$params['shippingCountry'] = $sh_country;

			$sh_zip = urlencode(preg_replace(
				'/[[:punct:]]/',
				'',
				SC_Versions_Resolver::get_order_data($order, 'shipping_postcode')
			));
			if (empty(trim($sh_zip))) {
				$sh_zip = $params['zip'];
			}
			$params['shippingZip'] = $sh_zip;
			// get and pass shipping data END

			$params['user_token']     = 'auto';
			$params['total_amount']   = SC_Versions_Resolver::get_order_data($order, 'order_total');
			$params['currency']       = get_woocommerce_currency();
			$params['merchantLocale'] = get_locale();
			$params['webMasterId']    = $this->webMasterId;
		} catch (Exception $ex) {
			SC_HELPER::create_log($ex->getMessage(), 'Exception while preparing order parameters: ');
			
			echo esc_js(
				'<script type="text/javascript">'
					. 'window.location.href = "' . $url . '?Status=error"'
				. '</script>'
				. '<noscript>'
					. '<a href="' . $url . '">' . __('Click to continue!', 'sc') . '</a>'
				. '</noscript>'
			);
			
			exit;
		}
		
		# Cashier payment
		SC_HELPER::create_log('Cashier payment');

		$_SESSION['SC_CASHIER_FORM_RENDED'] = true;

		$i        = 0;
		$test_sum = 0; // use it for the last check of the total

		# Items calculations
		foreach ($items as $item) {
			$i++;

			$params['item_name_' . $i]     = urlencode($item['name']);
			$params['item_number_' . $i]   = $item['product_id'];
			$params['item_quantity_' . $i] = $item['qty'];

			// this is the real price
			$item_qty   = intval($item['qty']);
			$item_price = $item['line_total'] / $item_qty;

			$params['item_amount_' . $i] = number_format($item_price, 2, '.', '');

			$test_sum += ( $item_qty * $params['item_amount_' . $i] );
		}

		// last check for correct calculations
		$test_sum -= $params['discount'];

		$test_diff = $params['total_amount'] - $params['handling'] - $test_sum;
		if (0 !== $test_diff) {
			$params['handling'] += $test_diff;
			SC_HELPER::create_log($test_diff, 'Total diff, added to handling: ');
		}
		# Items calculations END

		// be sure there are no array elements in $params !!!
		$params['checksum'] = hash($this->hash_type, stripslashes($this->secret . implode('', $params)));

		$url = 'yes' === $this->test ? SC_TEST_CASHIER_URL : SC_LIVE_CASHIER_URL;

		SC_HELPER::create_log($url, 'Endpoint URL: ');
		SC_HELPER::create_log($params, 'Order params');
		
		if ('yes' === $this->cashier_in_iframe) {
			echo '<form action="' . esc_url($url) . '" method="post" id="sc_payment_form" target="i_frame">';
		} else {
			echo '<form action="' . esc_url($url) . '" method="post" id="sc_payment_form">';
		}
		
		foreach ($params as $key => $value) {
			if (!is_array($value)) {
				echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
			}
		}
		
		echo
			'<noscript>'
				. '<input type="submit" class="button-alt" id="submit_sc_payment_form" value="' 
					. esc_html__('Pay via ' . SC_GATEWAY_TITLE, 'sc') . '" /><a class="button cancel" href="'
					. esc_url($order->get_cancel_order_url()) . '">'
					. esc_html__('Cancel order &amp; restore cart', 'sc') . '</a>'
			. '</noscript>'
			. '<script type="text/javascript">'
				. 'jQuery(function(){'
					. 'jQuery("#sc_payment_form").submit();'

					. 'if(jQuery("#i_frame").length > 0) {'
						. 'jQuery("#i_frame")[0].scrollIntoView();'
					. '}'
				. '});'
			. '</script>'
		. '</form>';

		if ('yes' === $this->cashier_in_iframe) {
			echo '<iframe id="i_frame" name="i_frame" onLoad=""; style="width: 100%; height: 1000px;"></iframe>';
		}
	}
	
		/**
	 * Function pay_with_d3d_p3d
	 * After we get the DMN form the issuer/bank call this process
	 * to continue the flow.
	 * 
	 * @param bool $use_js_redirect - js redirect or no
	 * @return bool
	 */
	public function pay_with_d3d_p3d() {
		SC_HELPER::create_log('pay_with_d3d_p3d call to the REST API.');
		
		$p3d_resp = false;
		$order    = new WC_Order(@$_SESSION['SC_P3D_Params']['clientUniqueId']);
		
		if (!$order) {
			return false;
		}
		// some corrections
		$_SESSION['SC_P3D_Params']['transactionType'] = $this->transaction_type;
		
		$PaRes = $this->get_param('PaRes');
		
		if ($PaRes) {
			$_SESSION['SC_P3D_Params']['paResponse'] = $PaRes;
		}
		$p3d_resp = SC_HELPER::call_rest_api(
			'yes' == $this->test ? SC_TEST_P3D_URL : SC_LIVE_P3D_URL
			, @$_SESSION['SC_P3D_Params']
		);
		
		if (!$p3d_resp) {
			SC_HELPER::create_log($resp, 'REST API Payment 3D ERROR: ');
			
			if ('pending' === $order_status) {
				$order->set_status('failed');
			}
			$order->add_order_note(__('Payment 3D API response fails.', 'sc'));
			$order->save();
			
			return false;
		}
		
		if ('ERROR' === @$p3d_resp['status'] || 'ERROR' === @$p3d_resp['transactionStatus']) {
			SC_HELPER::create_log('status or transactionStatus ERROR');
			
			$order->set_status('failed');
			$order->save();
			
			return false;
		}
		
		// save the response type of transaction
		if (isset($p3d_resp['transactionType']) && '' !== $p3d_resp['transactionType']) {
			$order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $p3d_resp['transactionType']);
		}
		
		return true;
	}
	
	/**
	  * Function process_payment
	  * Process the payment and return the result. This is the place where site
	  * POST the form and then redirect. Here we will get our custom fields.
	  *
	  * @param int $order_id
	 **/
	public function process_payment( $order_id) {
		if (isset($_SESSION['SC_P3D_PaReq'])) {
			unset($_SESSION['SC_P3D_PaReq']);
		}
		if (isset($_SESSION['SC_P3D_acsUrl'])) {
			unset($_SESSION['SC_P3D_acsUrl']);
		}
		
		$order        = new WC_Order($order_id);
		$order_status = strtolower($order->get_status());
		
		# when use Cashier - redirect to receipt page
		if ('cashier' === $this->payment_api) {
			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array(
						'order-pay' => $order_id,
						'key' => $this->get_order_data($order, 'order_key')
					),
					$order->get_checkout_order_received_url()
				)
			);
		}
		
		# when use REST - call the API
		// when we have Approved from the SDK we complete the order here
		$sc_transaction_id = filter_input(INPUT_POST, 'sc_transaction_id', FILTER_SANITIZE_STRING);
		
		if ($sc_transaction_id) {
			$order->update_meta_data(SC_GW_TRANS_ID_KEY, $sc_transaction_id);
			
			$cache = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'tmp'
				. DIRECTORY_SEPARATOR . $sc_transaction_id . '.txt';
			
			//SC_HELPER::create_log($cache, 'cache file');
			
			if (file_exists($cache)) {
				$params                   = json_decode(file_get_contents($cache), true);
				$params['clientUniqueId'] = $order_id;
				
				if (isset($params['wc-api'])) {
					unset($params['wc-api']);
				}
				
				$url = $this->set_notify_url() . '&' . http_build_query($params);
				
				SC_HELPER::create_log(
					//$url,
					'Internal DMN call'
				);
				
				@unlink($cache);
				
				file_get_contents($url);
			}
			
			return array(
				'result'    => 'success',
				'redirect'  => add_query_arg(array(), $this->get_return_url())
			);
		}
		
		// if we use UPO or APM
		$time           = date('Ymdhis');
		$endpoint_url   = '';
		$is_apm_payment = false;
		
		$params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'userTokenId'       => $this->get_param('billing_email'),
			'clientUniqueId'    => $order_id,
			'clientRequestId'   => $time . '_' . uniqid(),
			'currency'          => $order->get_currency(),
			'amount'            => (string) $order->get_total(),
			'amountDetails'     => array(
				'totalShipping'     => '0.00',
				'totalHandling'     => '0.00',
				'totalDiscount'     => '0.00',
				'totalTax'          => '0.00',
			),
			'shippingAddress'   => array(
				'firstName'         => urlencode(preg_replace('/[[:punct:]]/', '', 
					filter_input(INPUT_POST, 'shipping_first_name', FILTER_SANITIZE_STRING))),
				'lastName'          => urlencode(preg_replace('/[[:punct:]]/', '', 
					filter_input(INPUT_POST, 'shipping_last_name', FILTER_SANITIZE_STRING))),
				'address'           => urlencode(preg_replace('/[[:punct:]]/', '', 
					filter_input(INPUT_POST, 'shipping_address_1', FILTER_SANITIZE_STRING))),
				'cell'              => '',
				'phone'             => '',
				'zip'               => urlencode(preg_replace('/[[:punct:]]/', '', 
					filter_input(INPUT_POST, 'shipping_postcode', FILTER_SANITIZE_STRING))),
				'city'              => urlencode(preg_replace('/[[:punct:]]/', '', 
					filter_input(INPUT_POST, 'shipping_city', FILTER_SANITIZE_STRING))),
				'country'           => urlencode(preg_replace('/[[:punct:]]/', '', 
					filter_input(INPUT_POST, 'shipping_country', FILTER_SANITIZE_STRING))),
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
			'sessionToken'      => get_post('lst'),
		);
		
		// for the session token
		if (empty($params['sessionToken'])) {
			$st_endpoint_url = SC_TEST_SESSION_TOKEN_URL;
			
			if ('yes' !== $this->test) {
				$st_endpoint_url = SC_LIVE_SESSION_TOKEN_URL;
			}

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
			$_SESSION_token_data = SC_HELPER::call_rest_api($st_endpoint_url, $st_params);

			if (
				!$_SESSION_token_data || !is_array($_SESSION_token_data)
				|| !isset($_SESSION_token_data['status'])
				|| 'SUCCESS' !== $_SESSION_token_data['status']
			) {
				SC_HELPER::create_log($_SESSION_token_data, '$_SESSION_token_data problem:');

				wc_add_notice(__('Payment failed, please try again later!', 'sc'), 'error');
				return array(
					'result'    => 'error',
					'redirect'    => add_query_arg(
						array(),
						wc_get_page_permalink('checkout')
					)
				);
			}

			$params['sessionToken'] = $_SESSION_token_data['sessionToken'];
		}
		// for the session token END
		
		$params['userDetails'] = array(
			'firstName'         => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_first_name', FILTER_SANITIZE_STRING))),
			'lastName'          => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_last_name', FILTER_SANITIZE_STRING))),
			'address'           => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_address_1', FILTER_SANITIZE_STRING))),
			'phone'             => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_phone', FILTER_SANITIZE_STRING))),
			'zip'               => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_postcode', FILTER_SANITIZE_STRING))),
			'city'              => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_city', FILTER_SANITIZE_STRING))),
			'country'           => urlencode(preg_replace('/[[:punct:]]/', '', 
				filter_input(INPUT_POST, 'billing_country', FILTER_SANITIZE_STRING))),
			'state'             => '',
			'email'             => filter_input(INPUT_POST, 'billing_email', FILTER_SANITIZE_STRING),
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
		$sc_payment_method      = filter_input(INPUT_POST, 'sc_payment_method', FILTER_SANITIZE_STRING);
		$upo_cvv_field          = filter_input(INPUT_POST, 'upo_cvv_field_' . $sc_payment_method, FILTER_SANITIZE_STRING);
		$post_sc_payment_fields = filter_input(INPUT_POST, $sc_payment_method, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
		
		SC_HELPER::create_log($sc_payment_method, '$sc_payment_method:');
		SC_HELPER::create_log($upo_cvv_field, '$upo_cvv_field:');
		SC_HELPER::create_log($post_sc_payment_fields, '$post_sc_payment_fields:');
		
		if (is_numeric($sc_payment_method) && $upo_cvv_field) {
			$params['userPaymentOption'] = array(
				'userPaymentOptionId'   => $sc_payment_method,
				'CVV'                   => $upo_cvv_field,
			);
			
			$params['isDynamic3D'] = 1;
			$endpoint_url          = 'no' == $this->test ? SC_LIVE_D3D_URL : SC_TEST_D3D_URL;
		} elseif ($sc_payment_method) {
			// in case of APM
			$is_apm_payment          = true;
			$params['paymentMethod'] = $sc_payment_method;
			
			//if (isset($_POST[$_POST['sc_payment_method']])) {
			if ($post_sc_payment_fields) {
				//	$params['userAccountDetails'] = $_POST[$_POST['sc_payment_method']];
				$params['userAccountDetails'] = $post_sc_payment_fields;
			}
			
			$endpoint_url = 'no' === $this->test ? SC_LIVE_PAYMENT_URL : SC_TEST_PAYMENT_URL;
		}
		
		$resp = SC_HELPER::call_rest_api($endpoint_url, $params);
		
		if (!$resp) {
			$order->add_order_note(__('There is no response for the Order.', 'sc'));
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array('Status' => 'error'),
					$this->get_return_url()
				)
			);
		}

		// If we get Transaction ID save it as meta-data
		if (isset($resp['transactionId']) && $resp['transactionId']) {
			$order->update_meta_data(SC_GW_TRANS_ID_KEY, $resp['transactionId'], 0);
		}

		if (isset($resp['transactionStatus']) && 'DECLINED' === $resp['transactionStatus']) {
			$order->add_order_note(__('Order Declined.', 'sc'));
			$order->set_status('cancelled');
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array('Status' => 'error'),
					$this->get_return_url()
				)
			);
		}
		
		if (
			'ERROR' === $this->get_request_status($resp)
			|| ( isset($resp['transactionStatus']) && 'ERROR' === $resp['transactionStatus'] )
		) {
			if ('pending' === $order_status) {
				$order->set_status('failed');
			}

			$error_txt = 'Payment error';

			if (!empty($resp['reason'])) {
				$error_txt .= ': ' . $resp['errCode'] . ' - ' . $resp['reason'] . '.';
			} elseif (!empty($resp['threeDReason'])) {
				$error_txt .= ': ' . $resp['threeDReason'] . '.';
			}
			
			$order->add_order_note(__($error_txt, 'sc'));
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array('Status' => 'error'),
					$this->get_return_url()
				)
			);
		}
		
		if ($this->get_request_status($resp) === 'SUCCESS') {
			# The case with D3D and P3D
			// isDynamic3D is hardcoded to be 1, see SC_REST_API line 509
			// for the three cases see: https://www.safecharge.com/docs/API/?json#dynamic3D,
			// Possible Scenarios for Dynamic 3D (isDynamic3D = 1)

			// prepare the new session data
			if (!$is_apm_payment) {
				$params_p3d = $params;
				
				$params_p3d['orderId']         = $resp['orderId'];
				$params_p3d['transactionType'] = isset($resp['transactionType']) ? $resp['transactionType'] : '';
				$params_p3d['paResponse']      = '';
				
				$_SESSION['SC_P3D_Params'] = $params_p3d;
				
				// case 1
				if (
					!empty($resp['acsUrl'])
					&& ( isset($resp['threeDFlow']) && 1 === $resp['threeDFlow'] )
				) {
					SC_HELPER::create_log('D3D case 1');
					
					$_SESSION['SC_P3D_PaReq']  = !empty($resp['paRequest']) ? $resp['paRequest'] : '';
					$_SESSION['SC_P3D_acsUrl'] = $resp['acsUrl'];
					
					//                  $params_p3d['SC_P3D_PaReq'] = !empty($resp['paRequest']) ? $resp['paRequest'] : '';
					//                  $params_p3d['SC_P3D_acsUrl'] = $resp['acsUrl'];

					// step 1 - go to acsUrl
					return array(
						'result'    => 'success',
						'redirect'    => add_query_arg(
							array(
								'order-pay' => $order_id,
								'key' => $this->get_order_data($order, 'order_key'),
								'redirectUrl' => 1
							),
							$order->get_checkout_order_received_url()
						)
					);

					// step 2 - wait for the DMN
				} elseif (
					// case 2
					isset($resp['threeDFlow'])
					&& 1 === intval($resp['threeDFlow'])
				) {
					SC_HELPER::create_log('D3D case 2.');
					$resp = $this->pay_with_d3d_p3d();
					
					return array(
						'result'    => 'success',
						'redirect'    => add_query_arg(
							!$resp ? array('Status' => 'error') : array(),
							$this->get_return_url()
						)
					);
				}
				// case 3 do nothing
			}
			# The case with D3D and P3D END

			// in case we have redirectURL
			if (isset($resp['redirectURL']) && !empty($resp['redirectURL'])) {
				SC_HELPER::create_log($resp['redirectURL'], 'we have redirectURL: ');

				if (
					( isset($resp['gwErrorCode']) && -1 === $resp['gwErrorCode'] )
					|| isset($resp['gwErrorReason'])
				) {
					$msg = __('Error with the Payment: ' . $resp['gwErrorReason'] . '.', 'sc');
					
					$order->add_order_note($msg);
					$order->save();
					
					return array(
						'result'    => 'success',
						'redirect'    => add_query_arg(
							array('Status' => 'error'),
							$this->get_return_url()
						)
					);
				}
				
				$_SESSION['SC_P3D_acsUrl'] = $resp['redirectURL'];

				return array(
					'result'    => 'success',
					'redirect'    => add_query_arg(
						array(
							'order-pay' => $order_id,
							'key' => $this->get_order_data($order, 'order_key'),
							'redirectURL' => 1,
						),
						$order->get_checkout_order_received_url()
					)
				);
			}
		} // when SUCCESS
		
		if (isset($resp['transactionId']) && '' !== $resp['transactionId']) {
			$order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
		} else {
			$order->add_order_note(__('Payment succsess.', 'sc'));
		}

		// save the response transactionType value
		if (isset($resp['transactionType']) && '' !== $resp['transactionType']) {
			$order->update_meta_data(SC_GW_P3D_RESP_TR_TYPE, $resp['transactionType']);
		}

		$order->save();
		
		return array(
			'result'    => 'success',
			'redirect'    => add_query_arg(
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
	public function process_dmns( $params = array() ) {
		SC_HELPER::create_log($_REQUEST, 'Receive DMN with params: ');
		
		$req_status = $this->get_request_status();
		
		// santitized get variables
		$invoice_id      = $this->get_param('invoice_id');
		$clientUniqueId  = $this->get_param('clientUniqueId');
		$transactionType = $this->get_param('transactionType');
		$Reason          = $this->get_param('Reason');
		$action          = $this->get_param('action');
		$order_id        = $this->get_param('order_id');
		$gwErrorReason   = $this->get_param('gwErrorReason');
		$PaRes           = $this->get_param('PaRes');
		$AuthCode        = $this->get_param('AuthCode');
		$TransactionID   = $this->get_param('TransactionID');
		
		if (empty($TransactionID)) {
			SC_HELPER::create_log('The TransactionID is empty, stops here');
			echo esc_html('DMN error - The TransactionID is empty!');
			exit;
		}
		
		# Sale and Auth
		if (
			in_array($transactionType, array('Sale', 'Auth'), true)
			&& $this->checkAdvancedCheckSum()
		) {
			SC_HELPER::create_log('A sale/auth.');
			
			// Cashier
			if (!empty($invoice_id)) {
				SC_HELPER::create_log('Cashier sale.');
				
				try {
					$arr      = explode('_', $invoice_id);
					$order_id = intval($arr[0]);
					
					$order = new WC_Order($order_id);
				} catch (Exception $ex) {
					SC_HELPER::create_log($ex->getMessage(), 'Cashier DMN Exception when try to get Order ID: ');
					echo esc_html('DMN Exception: ' . $ex->getMessage());
					exit;
				}
				// REST
			} elseif (empty($clientUniqueId) && empty($this->get_param('merchant_unique_id'))) {
				// save cache file
				$cache = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'tmp'
					. DIRECTORY_SEPARATOR . $TransactionID . '.txt';
				
				if (!file_exists($cache)) {
					file_put_contents($cache, json_encode($_REQUEST));

					SC_HELPER::create_log('DMN was saved to a cache file.');
					exit;
				}
			} else {
				$order = new WC_Order($clientUniqueId);
			}
			
			try {
				$order_status = strtolower($order->get_status());
				
				$order->update_meta_data(
					SC_GW_P3D_RESP_TR_TYPE,
					$transactionType
				);
				
				// in case of WebSDK Chalenge
				if (!$order->get_meta(SC_AUTH_CODE_KEY) && '' != $AuthCode) {
					$order->update_meta_data(
						SC_AUTH_CODE_KEY,
						$AuthCode
					);
				}
				
				$this->save_update_order_numbers($order);
				
				if ('completed' !== $order_status) {
					$this->change_order_status(
						$order,
						$arr[0],
						$req_status,
						$transactionType
					);
				}
			} catch (Exception $ex) {
				SC_HELPER::create_log($ex->getMessage(), 'Sale DMN Exception: ');
				echo esc_html('DMN Exception: ' . $ex->getMessage());
				exit;
			}
			
			$order->add_order_note(
				__('DMN for Order #' . $order_id . ', was received.', 'sc')
			);
			$order->save();
			
			echo esc_html('DMN received.');
			exit;
		}
		
		# Void, Settle
		if (
			'' != $clientUniqueId
			&& ( in_array($transactionType, array('Void', 'Settle'), true) )
			&& $this->checkAdvancedCheckSum()
		) {
			SC_HELPER::create_log($transactionType);
			
			try {
				$order_id = $clientUniqueId;
				$order    = new WC_Order($order_id);
				
				if ('Settle' == $transactionType) {
					$this->save_update_order_numbers($order);
				}
				
				$this->change_order_status(
					$order,
					$order_id,
					$req_status,
					$transactionType
				);
				
			} catch (Exception $ex) {
				SC_HELPER::create_log(
					$ex->getMessage(),
					'process_dmns() REST API DMN DMN Exception: probably invalid order number'
				);
			}
			
			$msg = __('DMN for Order #' . $order_id . ', was received.', 'sc');
			
			if (!empty($Reason)) {
				$msg .= ' ' . __($Reason . '.', 'sc');
			}
			
			$order->add_order_note($msg);
			$order->save();
			
			echo esc_html('DMN received.');
			exit;
		}
		
		# Refund
		// see https://www.safecharge.com/docs/API/?json#refundTransaction -> Output Parameters
		// when we refund form CPanel we get transactionType = Credit and Status = 'APPROVED'
		if (
			(
				'refund' == $action
				|| in_array($transactionType, array('Credit', 'Refund'), true)
			)
			&& !empty($req_status)
			&& $this->checkAdvancedCheckSum()
		) {
			SC_HELPER::create_log('Refund DMN.');
			
			$order = new WC_Order($order_id);

			if (!is_a($order, 'WC_Order')) {
				SC_HELPER::create_log('DMN meassage: there is no Order!');
				
				echo 'There is no Order';
				exit;
			}
			
			// change to Refund if request is Approved and the Order status is not Refunded
			if ('APPROVED' === $req_status) {
				$this->change_order_status(
					$order,
					$order->get_id(),
					'APPROVED',
					'Credit',
					array(
						'resp_id'       => $clientUniqueId,
						'totalAmount'   => $this->get_param('totalAmount')
					)
				);
			} elseif (in_array ($this->get_param('transactionStatus'), array('DECLINED', 'ERROR'), true)) {
				$msg = 'DMN message: Your try to Refund #' . $clientUniqueId
					. ' faild with ERROR: "';

				// in case DMN URL was rewrited all spaces were replaces with "_"
				if (1 == $this->get_param('wc_sc_redirected')) {
					$msg .= str_replace('_', ' ', $gwErrorReason);
				} else {
					$msg .= $gwErrorReason;
				}

				$msg .= '".';

				$order -> add_order_note(__($msg, 'sc'));
				$order->save();
			}
			
			echo esc_html('DMN received - Refund.');
			exit;
		} elseif ('p3d' == $action) {
			# D3D and P3D payment
			// the idea here is to get $_REQUEST['paResponse'] and pass it to P3D
			SC_HELPER::create_log('p3d.');
			
			// the DMN from case 1 - issuer/bank
			if ( $PaRes && is_array($this->get_param('SC_P3D_Params')) ) {
				$_SESSION['SC_P3D_Params']['paResponse'] = $PaRes;
				$resp                                    = $this->pay_with_d3d_p3d();
				$url                                     = $this->get_return_url();
				
				if (!$resp) {
					$url .= '?Status=error';
				}
				
				echo
					'<script>'
						. 'window.location.href = "' . esc_url($url) . '";'
					. '</script>';
				
				exit;
			} elseif ( !empty($_REQUEST['merchantId']) && !empty($_REQUEST['merchantSiteId']) ) {
				// the DMN from case 2 - p3d
				// here we must unset $_SESSION['SC_P3D_Params'] as last step
				try {
					$order = new WC_Order($clientUniqueId);

					$this->change_order_status(
						$order,
						$clientUniqueId,
						$this->get_request_status(),
						$transactionType
					);
				} catch (Exception $ex) {
					SC_HELPER::create_log(
						$ex->getMessage(),
						'process_dmns() REST API DMN DMN Exception: '
					);
				}
			}
			
			if (isset($_SESSION['SC_P3D_Params'])) {
				unset($_SESSION['SC_P3D_Params']);
			}
			
			echo 'DMN received.';
			exit;
		}
		
		# other cases
		if (!$action && $this->checkAdvancedCheckSum()) {
			SC_HELPER::create_log('', 'Other cases.');
			
			try {
				$order = new WC_Order($clientUniqueId);

				$this->change_order_status(
					$order,
					$clientUniqueId,
					$this->get_request_status(),
					$transactionType
				);
			} catch (Exception $ex) {
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
		
		if ('' === $req_status) {
			echo 'Error: the DMN Status is empty!';
			exit;
		}
		
		echo 'DMN was not recognized.';
		exit;
	}
	
	public function sc_checkout_process() {
		$_SESSION['sc_subpayment'] = '';
		$sc_payment_method         = $this->get_param('sc_payment_method');
		
		if ('' != $sc_payment_method) {
			$_SESSION['sc_subpayment'] = $sc_payment_method;
		}
		
		return true;
	}

	public function showMessage( $content) {
		return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
	}

	/**
	 * Function checkAdvancedCheckSum
	 * Checks the Advanced Checsum
	 *
	 * @return boolean
	 */
	public function checkAdvancedCheckSum() {
		$str = hash(
			$this->hash_type,
			$this->secret . $this->get_param('totalAmount')
				. $this->get_param('currency') . $this->get_param('responseTimeStamp')
				. $this->get_param('PPP_TransactionID') . $this->get_request_status()
				. $this->get_param('productId')
		);
		
		if (strval($str) == $this->get_param('advanceResponseChecksum')) {
			return true;
		}
		
		return false;
	}
	
	public function set_notify_url() {
		$url_part = get_site_url();
			
		$url = $url_part
			. ( strpos($url_part, '?') !== false ? '&' : '?' ) . 'wc-api=sc_listener';
		
		// some servers needs / before ?
		if (strpos($url, '?') !== false && strpos($url, '/?') === false) {
			$url = str_replace('?', '/?', $url);
		}
		
		// force Notification URL protocol to http
		if (isset($this->use_http) && 'yes' === $this->use_http && false !== strpos($url, 'https://')) {
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
	public function get_order_data( $order, $key = 'completed_date') {
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
	public function process_refund( $order_id, $amount = null, $reason = '') {
		if ('true' == $this->get_param('api_refund')) {
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
	public function create_refund_in_wc( $refund) {
		$order_id = $this->get_param('order_id');
		$post_ID  = $this->get_param('post_ID');
		
		if ('false' == $this->get_param('api_refund') || !$refund) {
			return false;
		}
		
		// get order refunds
		try {
			$refund_data                = $refund->get_data();
			$refund_data['webMasterId'] = $this->webMasterId; // need this param for the API
			
			// the hooks calling this method, fired twice when change status
			// to Refunded, but we do not want to try more than one SC Refunds
			if (isset($_SESSION['sc_last_refund_id'])) {
				if (intval($_SESSION['sc_last_refund_id']) === intval($refund_data['id'])) {
					unset($_SESSION['sc_last_refund_id']);
					return;
				} else {
					$_SESSION['sc_last_refund_id'] = $refund_data['id'];
				}
			} else {
				$_SESSION['sc_last_refund_id'] = $refund_data['id'];
			}
			
			// when we set status to Refunded
			if ('' != $post_ID) {
				$order_id = $post_ID;
			}
			
			$order = new WC_Order($order_id);
			
			$order_meta_data = array(
				'order_tr_id'   => $order->get_meta(SC_GW_TRANS_ID_KEY),
				'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
			);
		} catch (Exception $ex) {
			SC_HELPER::create_log($ex->getMessage(), 'sc_create_refund() Exception: ');
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

		$notify_url  = $this->set_notify_url();
		$notify_url .= '&action=refund&order_id=' . $order_id;
		
		$refund_url = SC_TEST_REFUND_URL;
		$cpanel_url = SC_TEST_CPANEL_URL;

		if ('no' === $this->settings['test']) {
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
		
		$ref_parameters['checksum']    = $checksum;
		$ref_parameters['urlDetails']  = array(
			'notificationUrl' => $notify_url
		);
		$ref_parameters['webMasterId'] = $refund_data['webMasterId'];
		
		$resp = SC_HELPER::call_rest_api($refund_url, $ref_parameters);

		$msg        = '';
		$error_note = 'Please manually delete request Refund #'
			. $refund_data['id'] . ' form the order or login into <i>' . $cpanel_url
			. '</i> and refund Transaction ID ' . $order_meta_data['order_tr_id'];

		if (false === $resp) {
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
		if (isset($json_arr['status']) && 'ERROR' === $json_arr['status']) {
			$msg = 'Request ERROR - "' . $json_arr['reason'] . '" ' . $error_note;

			$order->add_order_note(__($msg, 'sc'));
			$order->save();
			
			return;
		}

		// the status of the request is SUCCESS, check the transaction status
		if (isset($json_arr['transactionStatus']) && 'ERROR' === $json_arr['transactionStatus']) {
			if (isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
				$msg = $json_arr['gwErrorReason'];
			} elseif (isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
				$msg = $json_arr['paymentMethodErrorReason'];
			} else {
				$msg = 'Transaction error';
			}

			$msg .= '. ' . $error_note;

			$order->add_order_note(__($msg, 'sc'));
			$order->save();
			return;
		}

		if (isset($json_arr['transactionStatus']) && 'DECLINED' === $json_arr['transactionStatus']) {
			$msg = 'The refund was declined. ' . $error_note;

			$order->add_order_note(__($msg, 'sc'));
			$order->save();
			
			return;
		}

		if (isset($json_arr['transactionStatus']) && 'APPROVED' === $json_arr['transactionStatus']) {
			return;
		}

		$msg = 'The status of request - Refund #' . $refund_data['id'] . ', is UNKONOWN.';

		$order->add_order_note(__($msg, 'sc'));
		$order->save();
		
		return;
	}
	
	public function sc_return_sc_settle_btn( $args) {
		// revert buttons on Recalculate
		if (!$this->get_param('refund_amount', false) && $this->get_param('items', false)) {
			echo esc_js('<script type="text/javascript">returnSCBtns();</script>');
		}
	}

	/**
	 * Function sc_restock_on_refunded_status
	 * Restock on refund.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function sc_restock_on_refunded_status( $order_id) {
		$order            = new WC_Order($order_id);
		$items            = $order->get_items();
		$is_order_restock = $order->get_meta('_scIsRestock');
		
		// do restock only once
		if (1 !== $is_order_restock) {
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
	public function checkout_open_order() {
		if ('cashier' === $this->payment_api) {
			return;
		}
		
		global $woocommerce;
		
		if (empty($woocommerce->cart->get_totals())) {
			echo
				'<script type="text/javascript">'
					. 'alert("Error with you Cart data. Please try again later!");'
				. '</script>';
			
			return;
		}
		
		$cart_totals = $woocommerce->cart->get_totals();
		
		echo 
			'<script type="text/javascript">'
				. 'scOrderAmount    = "' . esc_html($cart_totals['total']) . '"; '
			. '</script>'
		;
	}
	
	/**
	 * Function create_void
	 * 
	 * @param int $order_id
	 * @param string $action
	 */
	public function create_settle_void( $order_id, $action ) {
		$ord_status = 1;
		$time		= date('YmdHis');
		
		if ('settle' == $action) {
			$url = 'no' == $_SESSION['SC_Variables']['test'] ? SC_LIVE_SETTLE_URL : SC_TEST_SETTLE_URL;
		} else {
			$url = 'no' == $this->test ? SC_LIVE_VOID_URL : SC_TEST_VOID_URL;
		}
		
		try {
			$order = new WC_Order($order_id);
			
			$order_meta_data = array(
				'order_tr_id'   => $order->get_meta(SC_GW_TRANS_ID_KEY),
				'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
			);
			
			$params = array(
				'merchantId'			=> $this->merchantId,
				'merchantSiteId'		=> $this->merchantSiteId,
				'clientRequestId'		=> $time . '_' . uniqid(),
				'clientUniqueId'		=> $order_id,
				'amount'				=> (string) $order->get_total(),
				'currency'				=> get_woocommerce_currency(),
				'relatedTransactionId'	=> $order_meta_data['order_tr_id'],
				'authCode'				=> $order_meta_data['auth_code'],
				'urlDetails'			=> array(
					'notificationUrl'   => $this->set_notify_url(),
				),
				'timeStamp'				=> $time,
				'checksum'				=> '',
				'webMasterId'			=> $this->webMasterId,
			);

			$params['checksum'] = hash(
				$this->hash_type,
				$params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
					. $params['clientUniqueId'] . $params['amount'] . $params['currency']
					. $params['relatedTransactionId'] . $params['authCode']
					. $params['urlDetails']['notificationUrl'] . $params['timeStamp']
					. $this->secret
			);

			$resp = SC_HELPER::call_rest_api($url, $params);
		} catch (Exception $ex) {
			SC_HELPER::create_log($ex->getMessage(), 'Create void exception:');
			
			wp_send_json( array(
				'status' => 0,
				'msg' => __('Unexpexted error during the ' . $action . ':', 'sc'
			)) );
			wp_die();
		}
		
		if (
			!$resp || !is_array($resp)
			|| 'ERROR' == @$resp['status']
			|| 'ERROR' == @$resp['transactionStatus']
			|| 'DECLINED' == @$resp['transactionStatus']
		) {
			$ord_status = 0;
		}

		wp_send_json(array('status' => $ord_status, 'data' => $resp));
		wp_die();
	}
	
	/**
	 * Function prepare_rest_payment
	 * 
	 * @param string $amount
	 * @param string $user_mail
	 * @param string $country
	 */
	public function prepare_rest_payment( $amount, $user_mail, $country) {
		$time = date('YmdHis');
		
		$oo_endpoint_url = 'yes' == $this->test
			? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;

		$oo_params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'clientRequestId'   => $time . '_' . uniqid(),
			'amount'            => $amount,
			'currency'          => get_woocommerce_currency(),
			'timeStamp'         => $time,
			'urlDetails'        => array(
				'successUrl'        => $this->get_return_url(),
				'failureUrl'        => $this->get_return_url(),
				'pendingUrl'        => $this->get_return_url(),
				'notificationUrl'   => $this->set_notify_url(),
			),
			'deviceDetails'     => SC_HELPER::get_device_details(),
			'userTokenId'       => $user_mail,
			'billingAddress'    => array(
				'country' => $country,
			),
		);

		$oo_params['checksum'] = hash(
			$this->hash_type,
			$this->merchantId . $this->merchantSiteId . $oo_params['clientRequestId']
				. $amount . $oo_params['currency'] . $time . $this->secret
		);

		$resp = SC_HELPER::call_rest_api($oo_endpoint_url, $oo_params);

		if (
			empty($resp['status']) || empty($resp['sessionToken'])
			|| 'SUCCESS' != $resp['status']
		) {
			wp_send_json(array(
				'status' => 0,
				'callResp' => $resp
			));
			wp_die();
		}
		# Open Order END

		# get APMs
		$apms_params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'clientRequestId'   => $time . '_' . uniqid(),
			'timeStamp'         => $time,
			'sessionToken'      => $resp['sessionToken'],
			'currencyCode'      => get_woocommerce_currency(),
			'countryCode'       => $country,
			'languageCode'      => $this->formatLocation(get_locale()),
		);
		
		$apms_params['checksum'] = hash(
			$this->hash_type,
			$this->merchantId . $this->merchantSiteId . $apms_params['clientRequestId']
				. $time . $this->secret
		);

		$endpoint_url = 'yes' == $this->test
			? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL;

		$apms_data = SC_HELPER::call_rest_api($endpoint_url, $apms_params);

		if (!is_array($apms_data) || empty($apms_data['paymentMethods'])) {
			wp_send_json(array(
				'status' => 0,
				'apmsData' => $apms_data
			));
			wp_die();
		}

		// set template data with the payment methods
		$payment_methods = $apms_data['paymentMethods'];
		# get APMs END

		# get UPOs
		$upos  = array();
		$icons = array();
		
		/* TODO UPOs are stopped for the moment */
		if (false && is_user_logged_in()) {
			$endpoint_url = 'yes' == $this->test
				? SC_TEST_USER_UPOS_URL : SC_LIVE_USER_UPOS_URL;

			$upos_params = array(
				'merchantId'        => $this->merchantId,
				'merchantSiteId'    => $this->merchantSiteId,
				'userTokenId'       => @wp_get_current_user()->data->user_email,
				'clientRequestId'   => uniqid(),
				'timeStamp'         => $time,
			);
			
			$upos_params['checksum'] =
				hash(
					$this->hash_type,
					$this->merchantId . $this->merchantSiteId . $upos_params['userTokenId']
						. $upos_params['clientRequestId'] . $time . $this->secret
				);

			$upos_data = SC_HELPER::call_rest_api($endpoint_url, $upos_params);

			if (!empty($upos_data['paymentMethods'])) {
				foreach ($upos_data['paymentMethods'] as $upo_key => $upo) {
					if (
						'enabled' != @$upo['upoStatus']
						|| ( isset($upo['upoData']['ccCardNumber'])
							&& empty($upo['upoData']['ccCardNumber']) )
						|| ( isset($upo['expiryDate'])
							&& strtotime($upo['expiryDate']) < strtotime(date('Ymd')) )
					) {
						continue;
					}

					// search in payment methods
					foreach ($payment_methods as $pm) {
						if (
							isset($upo['paymentMethodName'], $pm['paymentMethod'])
							&& $upo['paymentMethodName'] == $pm['paymentMethod']
						) {
							if (
								in_array(@$upo['paymentMethodName'], array('cc_card', 'dc_card'))
								&& @$upo['upoData']['brand'] && @$pm['logoURL']
							) {
								$icons[@$upo['upoData']['brand']] = str_replace(
									'default_cc_card',
									$upo['upoData']['brand'],
									$pm['logoURL']
								);
							} elseif (@$pm['logoURL']) {
								$icons[$pm['paymentMethod']] = $pm['logoURL'];
							}

							$upos[] = $upo;
							break;
						}
					}
				}
			} else {
				SC_HELPER::create_log($upos_data, '$upos_data:');
			}
		}
		# get UPOs END

		wp_send_json(array(
			'status'            => 1,
			'testEnv'           => $this->test,
			'merchantSiteId'    => $this->merchantSiteId,
			'merchantId'        => $this->merchantId,
			'langCode'          => $this->formatLocation(get_locale()),
			'sessionToken'      => $resp['sessionToken'],
			'currency'          => get_woocommerce_currency(),
			'data'              => array(
				'upos'              => $upos,
				'paymentMethods'    => $payment_methods,
				'icons'             => $icons
			)
		));

		wp_die();
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
	private function change_order_status( $order, $order_id, $status, $transactionType = '', $res_args = array()) {
		SC_HELPER::create_log(
			'Order ' . $order_id . ' has Status: ' . $status,
			'WC_SC change_order_status() status-order: '
		);
		
		switch ($status) {
			case 'CANCELED':
				$message = 'Payment status changed to:' . $status
					. '. PPP_TransactionID = ' . $this->get_param('PPP_TransactionID')
					. ', Status = ' . $status . ', GW_TransactionID = '
					. $this->get_param('TransactionID');

				$this->msg['message'] = $message;
				$this->msg['class']   = 'woocommerce_message';
				$order->update_status('failed');
				$order->add_order_note('Failed');
				$order->add_order_note($this->msg['message']);
				break;

			case 'APPROVED':
				if ('Void' === $transactionType) {
					$order->add_order_note(__('DMN message: Your Void request was success, Order #'
						. $this->get_param('clientUniqueId')
						. ' was canceld. Plsese check your stock!', 'sc'));

					$order->update_status('cancelled');
					break;
				}
				
				// Refun Approved
				if ('Credit' === $transactionType) {
					$order->add_order_note(
						__('DMN message: Your Refund #' . $res_args['resp_id']
							. ' was successful. Refund Transaction ID is: ', 'sc')
							. $this->get_param('TransactionID') . '.'
					);
					
					// set flag that order has some refunds
					$order->update_meta_data('_scHasRefund', 1);
					$order->save();
					break;
				}
				
				$message = 'The amount has been authorized and captured by '
					. SC_GATEWAY_TITLE . '. ';
				
				if ('Auth' === $transactionType) {
					$message = 'The amount has been authorized and wait to for Settle. ';
				} elseif ('Settle' === $transactionType) {
					$message = 'The amount has been captured by ' . SC_GATEWAY_TITLE . '. ';
				}
				
				$message .= 'PPP_TransactionID = ' . $this->get_param('PPP_TransactionID')
					. ', Status = ' . $status;

				if ($transactionType) {
					$message .= ', TransactionType = ' . $transactionType;
				}

				$message .= ', GW_TransactionID = ' . $this->get_param('TransactionID');

				$this->msg['message'] = $message;
				$this->msg['class']   = 'woocommerce_message';
				$order->payment_complete($order_id);
				
				if ('Auth' === $transactionType) {
					$order->update_status('pending');
				} else {
					$order->update_status('processing');
					$order->save();
					
					$order->update_status('completed');
				}
				
				if ('Auth' !== $transactionType) {
					$order->add_order_note(SC_GATEWAY_TITLE . ' payment is successful<br/>Unique Id: '
						. $this->get_param('PPP_TransactionID'));
				}

				$order->add_order_note($this->msg['message']);
				break;

			case 'ERROR':
			case 'DECLINED':
			case 'FAIL':
				$reason = ', Reason = ';
				if ('' != $this->get_param('reason')) {
					$reason .= $this->get_param('reason');
				} elseif ('' != $this->get_param('Reason')) {
					$reason .= $this->get_param('Reason');
				}
				
				$message = 'Payment failed. PPP_TransactionID = ' . $this->get_param('PPP_TransactionID')
					. ', Status = ' . $status . ', Error code = ' . $this->get_param('ErrCode')
					. ', Message = ' . $this->get_param('message')
					. $reason;
				
				if ($transactionType) {
					$message .= ', TransactionType = ' . $transactionType;
				}

				$message .= ', GW_TransactionID = ' . $this->get_param('TransactionID');
				
				// do not change status
				if ('Void' === $transactionType) {
					$message = 'DMN message: Your Void request fail with message: "';

					// in case DMN URL was rewrited all spaces were replaces with "_"
					if (1 == $this->get_param('wc_sc_redirected')) {
						$message .= str_replace('_', ' ', $this->get_param('message'));
					} else {
						$message .= $this->get_param('msg');
					}

					$message .= '". Order #' . $this->get_param('clientUniqueId')
						. ' was not canceld!';
					
					$order->add_order_note(__($message, 'sc'));
					$order->save();
					break;
				}
				
				$this->msg['message'] = $message;
				$this->msg['class']   = 'woocommerce_message';
				
				$order->update_status('failed');
				$order->add_order_note(__($message, 'sc'));
				$order->save();
				break;

			case 'PENDING':
				$ord_status = $order->get_status();
				if ('processing' === $ord_status || 'completed' === $ord_status) {
					break;
				}

				$message ='Payment is still pending, PPP_TransactionID '
					. $this->get_param('PPP_TransactionID') . ', Status = ' . $status;

				if ($transactionType) {
					$message .= ', TransactionType = ' . $transactionType;
				}

				$message .= ', GW_TransactionID = ' . $this->get_param('TransactionID');

				$this->msg['message'] = $message;
				$this->msg['class']   = 'woocommerce_message woocommerce_message_info';
				
				$order->add_order_note(
					SC_GATEWAY_TITLE . ' payment status is pending<br/>Unique Id: '
						. $this->get_param('PPP_TransactionID')
				);

				$order->add_order_note($this->msg['message']);
				$order->update_status('on-hold');
				break;
		}
		
		$order->save();
	}
	
	private function formatLocation( $locale) {
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
	private function save_update_order_numbers( $order) {
		// save or update AuthCode and GW Transaction ID
		$auth_code = $this->get_param('AuthCode');
		$saved_ac  = $order->get_meta(SC_AUTH_CODE_KEY);

		if (!$saved_ac || empty($saved_ac) || $saved_ac !== $auth_code) {
			$order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
		}

		$gw_transaction_id = $this->get_param('TransactionID');
		$saved_tr_id       = $order->get_meta(SC_GW_TRANS_ID_KEY);

		if (!$saved_tr_id || empty($saved_tr_id) || $saved_tr_id !== $gw_transaction_id) {
			$order->update_meta_data(SC_GW_TRANS_ID_KEY, $gw_transaction_id);
		}
		
		$order->update_meta_data('_paymentMethod', $this->get_param('payment_method'));

		$order->save();
	}
	
	/**
	 * Function get_request_status
	 * We need this stupid function because as response request variable
	 * we get 'Status' or 'status'...
	 *
	 * @return string
	 */
	private function get_request_status( $params = array()) {
		$Status = $this->get_param('Status');
		$status = $this->get_param('status');
		
		if (empty($params)) {
			if ('' != $Status) {
				return $Status;
			}
			
			if ('' != $status) {
				return $status;
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
	 * Function get_param
	 * Get request parameter by key
	 * 
	 * @param mixed $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function get_param( $key, $default = '') {
		return !empty($_REQUEST[$key]) ? sanitize_text_field($_REQUEST[$key]) : $default;
	}
}

?>
