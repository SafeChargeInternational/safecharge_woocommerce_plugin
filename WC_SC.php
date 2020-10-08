<?php

/**
 * WC_SC Class
 *
 * Main class for the SafeCharge Plugin
 *
 * 2018
 */

if (!session_id()) {
	session_start();
}

class WC_SC extends WC_Payment_Gateway {


	# payments URL
	private $URL         = '';
	private $webMasterId = 'WooCommerce ';
	private $stop_dmn    = 0; // when 1 - stops the DMN for testing
	private $sc_order;
	
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
		
		// required for the Store
		$this->title		= $this->sc_get_setting('title', '');
		$this->description	= $this->sc_get_setting('description', '');
		
		$this->test			= $this->sc_get_setting('test', '');
		$this->rewrite_dmn	= $this->sc_get_setting('rewrite_dmn', 'no');
		
		// replace usage of this with method sc_get_setting()
//		$this->merchantId = !empty($this->settings['merchantId'])
//			? $this->settings['merchantId'] : '';
		
//		$this->merchantSiteId = !empty($this->settings['merchantSiteId'])
//			? $this->settings['merchantSiteId'] : '';
		
//		$this->secret = !empty($this->settings['secret'])
//			? $this->settings['secret'] : '';
		
//		$this->use_http = isset($this->settings['use_http'])
//			? $this->settings['use_http'] : 'yes';
		
//		$this->save_logs = isset($this->settings['save_logs'])
//			? $this->settings['save_logs'] : 'yes';
		
//		$this->hash_type = isset($this->settings['hash_type'])
//			? $this->settings['hash_type'] : 'sha256';
		
//		$this->payment_action = isset($this->settings['payment_action'])
//			? $this->settings['payment_action'] : 'Auth';
		
//		$this->use_upos = isset($this->settings['use_upos'])
//			? $this->settings['use_upos'] : 1;
		
//		$this->merchant_style = !empty($this->settings['merchant_style'])
//			? $this->settings['merchant_style'] : '';
		// replace usage of this with method sc_get_setting() END
		
		$this->webMasterId .= WOOCOMMERCE_VERSION;
		
		$_SESSION['SC_Variables']['sc_create_logs'] = $this->sc_get_setting('save_logs');
		
		$this->use_wpml_thanks_page =
			!empty($this->settings['use_wpml_thanks_page']) ? $this->settings['use_wpml_thanks_page'] : 'no';
		
		$this->supports[] = 'refunds'; // to enable auto refund support
		
		$this->init_form_fields();
		
		$this->msg['message'] = '';
		$this->msg['class']   = '';
		
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( &$this, 'process_admin_options' )
		);
		
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
			'test' => array(
				'title' => __('Site Mode', 'sc') . ' *',
				'type' => 'select',
				'required' => 'required',
				'options' => array(
					'' => __('Select an option...'),
					'yes' => 'Sandbox',
					'no' => 'Production',
				),
			),
			'merchantId' => array(
				'title' => __('Merchant ID', 'sc') . ' *',
				'type' => 'text',
				'required' => true,
				'description' => __('Merchant ID is provided by ' . SC_GATEWAY_TITLE . '.')
			),
			'merchantSiteId' => array(
				'title' => __('Merchant Site ID', 'sc') . ' *',
				'type' => 'text',
				'required' => true,
				'description' => __('Merchant Site ID is provided by ' . SC_GATEWAY_TITLE . '.')
			),
			'secret' => array(
				'title' => __('Secret key', 'sc') . ' *',
				'type' => 'text',
				'required' => true,
				'description' =>  __('Secret key is provided by ' . SC_GATEWAY_TITLE, 'sc'),
			),
			'hash_type' => array(
				'title' => __('Hash type', 'sc') . ' *',
				'type' => 'select',
				'required' => true,
				'description' => __('Choose Hash type provided by ' . SC_GATEWAY_TITLE, 'sc'),
				'options' => array(
					'' => __('Select an option...'),
					'sha256' => 'sha256',
					'md5' => 'md5',
				)
			),
			'payment_action' => array(
				'title' => __('Payment action', 'sc') . ' *',
				'type' => 'select',
				'required' => true,
				'options' => array(
					'' => __('Select an option...'),
					'Sale' => 'Authorize and Capture',
					'Auth' => 'Authorize',
				)
			),
			'use_upos' => array(
				'title' => __('Allow client to use UPOs', 'sc'),
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes',
				)
			),
			'merchant_style' => array(
				'title' => __('Custom style', 'sc'),
				'type' => 'textarea',
				'default' => '',
				'description' => 'Override the build-in style for the SafeCharge elements.'
			),
			'notify_url' => array(
				'title' => __('Notify URL', 'sc'),
				'type' => 'text',
				'default' => '',
				'description' => $this->set_notify_url(),
				'type' => 'hidden'
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
	  * Function process_payment
	  * Process the payment and return the result. This is the place where site
	  * POST the form and then redirect. Here we will get our custom fields.
	  *
	  * @param int $order_id
	 **/
	public function process_payment( $order_id) {
		SC_CLASS::create_log('Process payment(), Order #' . $order_id);
		
		$order = wc_get_order($order_id);
		
		if (!$order) {
			SC_CLASS::create_log('Order is false for order id ' . $order_id);
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		$return_success_url = add_query_arg(
			array('key' => $order->get_order_key()),
			$this->get_return_url($order)
		);
		
		$return_error_url = add_query_arg(
			array(
				'Status'    => 'error',
				'key'        => $order->get_order_key()
			),
			$this->get_return_url($order)
		);
		
		if ('sc' !== $order->get_payment_method()) {
			SC_CLASS::create_log('Process payment Error - Order payment method is not "sc".');
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
					'key'        => $key
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		// when we have Approved from the SDK we complete the order here
		$sc_transaction_id = $this->get_param('sc_transaction_id', 'int');
		
		# in case of webSDK payment (cc_card)
		if (!empty($sc_transaction_id)) {
			SC_CLASS::create_log('Process webSDK Order, transaction ID #' . $sc_transaction_id);
			
			$order->update_meta_data(SC_TRANS_ID, $sc_transaction_id);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_success_url
			);
		}
		# in case of webSDK payment (cc_card) END
		
		SC_CLASS::create_log('Process Rest APM Order.');
		
		// if we use APM
		$time = gmdate('Ymdhis');
		
		$params = array(
			'merchantId'        => $this->sc_get_setting('merchantId'),
			'merchantSiteId'    => $this->sc_get_setting('merchantSiteId'),
			'userTokenId'       => $order->get_billing_email(),
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
			
			'billingAddress'    => array(
				'firstName'    => $order->get_billing_first_name(),
				'lastName'    => $order->get_billing_last_name(),
				'address'   => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
				'phone'     => $order->get_billing_phone(),
				'zip'       => $order->get_billing_postcode(),
				'city'      => $order->get_billing_city(),
				'country'    => $order->get_billing_country(),
				'email'        => $order->get_billing_email(),
			),
			
			'shippingAddress'    => array(
				'firstName'            => $order->get_shipping_first_name(),
				'lastName'            => $order->get_shipping_last_name(),
				'address'            => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
				'zip'                => $order->get_shipping_postcode(),
				'city'                => $order->get_shipping_city(),
				'country'            => $order->get_shipping_country(),
				'cell'                => '',
				'phone'                => '',
				'state'                => '',
				'shippingCounty'    => '',
			),
			
			'urlDetails'        => array(
				'successUrl'        => $return_success_url,
				'failureUrl'        => $return_error_url,
				'pendingUrl'        => $return_success_url,
				'notificationUrl'   => $this->set_notify_url(),
			),
			
			'timeStamp'         => $time,
			'webMasterId'       => $this->webMasterId,
			'sourceApplication' => SC_SOURCE_APPLICATION,
			'deviceDetails'     => SC_CLASS::get_device_details(),
			'sessionToken'      => $this->get_param('lst'),
		);
		
		$params['userDetails'] = $params['billingAddress'];
		
		$params['items'][0] = array(
			'name'      => $order_id,
			'price'     => $params['amount'],
			'quantity'  => 1,
		);
		
		$params['checksum'] = hash(
			$this->sc_get_setting('hash_type'),
			$params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
				. $params['amount'] . $params['currency'] . $time . $this->sc_get_setting('secret')
		);
		
		$sc_payment_method = $this->get_param('sc_payment_method');
		
		// UPO
		if (is_numeric($sc_payment_method)) {
			$endpoint_method                                = 'payment.do';
			$params['paymentOption']['userPaymentOptionId'] = $sc_payment_method;
		} else {
			// APM
			$endpoint_method         = 'paymentAPM.do';
			$params['paymentMethod'] = $sc_payment_method;
		}
		
		$resp = SC_CLASS::call_rest_api(
			$this->getEndPointBase() . $endpoint_method,
			$params
		);
		
		if (!$resp) {
			$msg = __('There is no response for the Order.', 'sc');
			
			$order->add_order_note($msg);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}

		if (empty($this->get_request_status($resp))) {
			$msg = __('There is no Status for the Order.', 'sc');
			
			$order->add_order_note($msg);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		// If we get Transaction ID save it as meta-data
		if (isset($resp['transactionId']) && $resp['transactionId']) {
			$order->update_meta_data(SC_TRANS_ID, $resp['transactionId'], 0);
		}

		if (
			'DECLINED' === $this->get_request_status($resp)
			|| 'DECLINED' === @$resp['transactionStatus']
		) {
			$order->add_order_note(__('Order Declined.', 'sc'));
			$order->set_status('cancelled');
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		if (
			'ERROR' === $this->get_request_status($resp)
			|| 'ERROR' === @$resp['transactionStatus']
		) {
			$order->set_status('failed');

			$error_txt = 'Payment error';

			if (!empty($resp['reason'])) {
				$error_txt .= ': ' . $resp['errCode'] . ' - ' . $resp['reason'] . '.';
			} elseif (!empty($resp['threeDReason'])) {
				$error_txt .= ': ' . $resp['threeDReason'] . '.';
			} elseif (!empty($resp['message'])) {
				$error_txt .= ': ' . $resp['message'] . '.';
			}
			
			$msg = __($error_txt, 'sc');
			
			$order->add_order_note($msg);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		// catch Error code or reason
		if (
			( isset($resp['gwErrorCode']) && -1 === $resp['gwErrorCode'] )
			|| isset($resp['gwErrorReason'])
		) {
			$msg = __('Error with the Payment: ' . $resp['gwErrorReason'] . '.', 'sc');

			$order->add_order_note($msg);
			$order->save();

			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		// Success status
		if (!empty($resp['redirectURL']) || !empty($resp['paymentOption']['redirectUrl'])) {
			SC_CLASS::create_log('we have redirectURL');
			
			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array(),
					!empty($resp['redirectURL']) ? $resp['redirectURL'] : $resp['paymentOption']['redirectUrl']
				)
			);
		}

		if (isset($resp['transactionId']) && '' !== $resp['transactionId']) {
			$order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
		} else {
			$order->add_order_note(__('Payment succsess.', 'sc'));
		}

		// save the response transactionType value
		if (isset($resp['transactionType']) && '' !== $resp['transactionType']) {
			$order->update_meta_data(SC_RESP_TRANS_TYPE, $resp['transactionType']);
		}

		$order->save();
		
		return array(
			'result'    => 'success',
			'redirect'  => $return_success_url
		);
	}
	
	public function sc_insert_merchant_style($data) {
		ob_start();
		require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'templates/sc_second_step_form.php';
		$html = ob_get_contents();
		ob_clean();
		
		echo $html;
	}
	
	public function sc_get_payment_methods( $content) {
		global $woocommerce;
		
		$resp_data	= array();
		$content	= '';
		
		SC_CLASS::create_log($_POST, '$_POST');
		
		# OpenOrder
		$oo_data = $this->sc_create_open_order($order);
		
		if (!$oo_data) {
			wp_send_json(array(
				'result'	=> 'failure',
				'refresh'	=> false,
				'reload'	=> false,
				'messages'	=> '<ul id="sc_fake_error" class="woocommerce-error" role="alert"><li>'. __('Unexpected error, please try again later!', 'nuvei') .'</li></ul>'
			));

			wp_die();
		}
		
		$oo_params = $oo_data['oo_params'];
		$resp      = $oo_data['resp'];
		$time      = $oo_data['time'];
		
		$resp_data['sessonToken'] = $resp['sessionToken'];
		# OpenOrder END
		
		# get APMs
		$apms_params = array(
			'merchantId'        => $this->sc_get_setting('merchantId'),
			'merchantSiteId'    => $this->sc_get_setting('merchantSiteId'),
			'clientRequestId'   => $time . '_' . uniqid(),
			'timeStamp'         => $time,
			'sessionToken'      => $resp['sessionToken'],
			'currencyCode'      => $oo_params['currency'],
			'countryCode'       => $oo_params['billingAddress']['country'],
			'languageCode'      => $this->formatLocation(get_locale()),
		);
		
		$apms_params['checksum'] = hash(
			$this->sc_get_setting('hash_type'),
			$this->sc_get_setting('merchantId') . $this->sc_get_setting('merchantSiteId') . $apms_params['clientRequestId']
				. $time . $this->sc_get_setting('secret')
		);

		$apms_data = SC_CLASS::call_rest_api(
			$this->getEndPointBase() . 'getMerchantPaymentMethods.do',
			$apms_params
		);
		
		if (!is_array($apms_data) || empty($apms_data['paymentMethods'])) {
			wp_send_json(array(
				'result'	=> 'failure',
				'refresh'	=> false,
				'reload'	=> false,
				'messages'	=> '<ul id="sc_fake_error" class="woocommerce-error" role="alert"><li>'. __('Can not obtain Payment Methods, please try again later!', 'nuvei') .'</li></ul>'
			));

			wp_die();
		}
		
		$resp_data['apms'] = $apms_data['paymentMethods'];
		# get APMs END
		
		# get UPOs
		$icons         = array();
		$upos          = array();
		$user_token_id = $oo_params['userTokenId'];

		// get them only for registred users when there are APMs
		if (
			1 == $this->sc_get_setting('use_upos')
			&& is_user_logged_in()
			&& !empty($apms_data['paymentMethods'])
		) {
			$upo_params = array(
				'merchantId'        => $apms_params['merchantId'],
				'merchantSiteId'    => $apms_params['merchantSiteId'],
				'userTokenId'        => $oo_params['userTokenId'],
				'clientRequestId'    => $apms_params['clientRequestId'],
				'timeStamp'            => $time,
			);

			$upo_params['checksum'] = hash($this->sc_get_setting('hash_type'), implode('', $upo_params) . $this->sc_get_setting('secret'));

			$upo_res = SC_CLASS::call_rest_api(
				$this->getEndPointBase() . 'getUserUPOs.do',
				$upo_params
			);
			
			if (!empty($upo_res['paymentMethods']) && is_array($upo_res['paymentMethods'])) {
				foreach ($upo_res['paymentMethods'] as $data) {
					// chech if it is not expired
					if (!empty($data['expiryDate']) && gmdate('Ymd') > $data['expiryDate']) {
						continue;
					}

					if (empty($data['upoStatus']) || 'enabled' !== $data['upoStatus']) {
						continue;
					}

					// search for same method in APMs, use this UPO only if it is available there
					foreach ($apms_data['paymentMethods'] as $pm_data) {
						// found it
						if ($pm_data['paymentMethod'] === $data['paymentMethodName']) {
							$data['logoURL'] = @$pm_data['logoURL'];
							$data['name']    = @$pm_data['paymentMethodDisplayName'][0]['message'];

							$upos[] = $data;
							break;
						}
					}
				}
			}
		}
		
		$resp_data['upos'] = $upos;
		# get UPOs END
		
		$resp_data['orderAmount']	= WC()->cart->total;
		$resp_data['userTokenId']	= $this->get_param('billing_email', 'mail');
		$resp_data['pluginUrl']		= $this->plugin_url;
		$resp_data['siteUrl']		= get_site_url();
			
		wp_send_json(array(
			'result'	=> 'failure',
			'refresh'	=> false,
			'reload'	=> false,
			'messages'	=> '<script>scPrintApms('. json_encode($resp_data).');</script>'
		));

		wp_die();
	}

	/**
	 * Function process_dmns
	 * Process information from the DMNs.
	 * We call this method form index.php
	 */
	public function process_dmns( $params = array()) {
		SC_CLASS::create_log($_REQUEST, 'Receive DMN with params: ');
		
		if ($this->get_param('stopDMN', 'int') == 1) {
			SC_CLASS::create_log('DMN was stopped for test case, please fire it manually!');
			echo 'DMN was stopped for test case, please fire it manually!';
			exit;
		}
		
		$req_status = $this->get_request_status();
		
		if (empty($req_status)) {
			SC_CLASS::create_log('Error: the DMN Status is empty!');
			echo 'Error: the DMN Status is empty!';
			exit;
		}
		
		// santitized get variables
		$invoice_id      = $this->get_param('invoice_id');
		$clientUniqueId  = $this->get_param('clientUniqueId');
		$transactionType = $this->get_param('transactionType');
		$Reason          = $this->get_param('Reason');
		$action          = $this->get_param('action');
		$order_id        = $this->get_param('order_id', 'int');
		$gwErrorReason   = $this->get_param('gwErrorReason');
		$AuthCode        = $this->get_param('AuthCode', 'int');
		$TransactionID   = $this->get_param('TransactionID', 'int');
		
		if (empty($TransactionID)) {
			SC_CLASS::create_log('The TransactionID is empty, stops here');
			echo esc_html('DMN error - The TransactionID is empty!');
			exit;
		}
		
		if (!$this->checkAdvancedCheckSum()) {
			SC_CLASS::create_log('Error when check AdvancedCheckSum!');
			echo esc_html('Error when check AdvancedCheckSum!');
			exit;
		}
		
		SC_CLASS::create_log($transactionType);
		
		# Sale and Auth
		if (in_array($transactionType, array('Sale', 'Auth'), true)) {
			// WebSDK
			if (
				!is_numeric($clientUniqueId)
				&& $this->get_param('TransactionID', 'int') != 0
			) {
				SC_CLASS::create_log('DMN for WebSDK');
				$order_id = $this->sc_get_order_by_tans_id($TransactionID);
			} else {
				// REST
				SC_CLASS::create_log('DMN for REST call.');
				
				if (empty($order_id) && is_numeric($clientUniqueId)) {
					$order_id = $clientUniqueId;
				}
			}
			
			$this->sc_order = wc_get_order($order_id);
				
			if (!$this->sc_order) {
				SC_CLASS::create_log('Order gets False.');
				echo esc_html('DMN error - Order gets False.');
				exit;
			}
			
			$this->save_update_order_numbers();
			
			$order_status = strtolower($this->sc_order->get_status());
			
			if ('completed' !== $order_status) {
				$this->change_order_status(
					$order_id,
					$req_status,
					$transactionType
				);
			}
			
			echo 'DMN received.';
			exit;
		}
		
		// try to get the Order ID
		$ord_data = $this->sc_get_order_data($this->get_param('relatedTransactionId', 'int'));

		if (!empty($ord_data[0]->post_id)) {
			$order_id = $ord_data[0]->post_id;
		}
			
		# Void, Settle
		if (
			'' != $clientUniqueId
			&& ( in_array($transactionType, array('Void', 'Settle'), true) )
		) {
			$this->sc_order = wc_get_order($order_id);
			
			if (!$this->sc_order) {
				SC_CLASS::create_log('Order gets False.');
				echo esc_html('DMN error - Order gets False.');
				exit;
			}
			
			$msg = __('DMN for Order #' . $order_id . ', was received.', 'sc');
			
			if ('Settle' == $transactionType) {
				$this->save_update_order_numbers();
			}

			$this->change_order_status($order_id, $req_status, $transactionType);
				
			echo esc_html('DMN received.');
			exit;
		}
		
		# Refund
		if (in_array($transactionType, array('Credit', 'Refund'), true)) {
			if(0 == $order_id) {
				$order_id = $this->sc_get_order_by_tans_id($this->get_param('relatedTransactionId', 'int'));
			}
			
			$this->sc_create_refund_record($order_id);
			
			$this->change_order_status(
				$order_id,
				$req_status,
				$transactionType,
				array(
					'resp_id'       => $clientUniqueId,
					'totalAmount'   => $this->get_param('totalAmount', 'float')
				)
			);

			echo esc_html('DMN received.');
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
			$this->sc_get_setting('hash_type'),
			$this->sc_get_setting('secret') . $this->get_param('totalAmount')
				. $this->get_param('currency') . $this->get_param('responseTimeStamp')
				. $this->get_param('PPP_TransactionID') . $this->get_request_status()
				. $this->get_param('productId')
		);
		
//		var_dump($this->sc_get_setting('secret') . $this->get_param('totalAmount')
//			. $this->get_param('currency') . $this->get_param('responseTimeStamp')
//			. $this->get_param('PPP_TransactionID') . $this->get_request_status()
//			. $this->get_param('productId'));
		
//		var_dump($this->get_param('totalAmount', 'float'));
		
		if (strval($str) == $this->get_param('advanceResponseChecksum')) {
			return true;
		}
		
		return false;
	}
	
	public function set_notify_url() {
		$url_part = get_site_url();
			
		$url = $url_part . ( strpos($url_part, '?') !== false ? '&' : '?' )
			. 'wc-api=sc_listener&stopDMN=' . $this->stop_dmn;
		
		// some servers needs / before ?
		if (strpos($url, '?') !== false && strpos($url, '/?') === false) {
			$url = str_replace('?', '/?', $url);
		}
		
		// force Notification URL protocol to http
		if ('yes' === $this->sc_get_setting('use_http') && false !== strpos($url, 'https://')) {
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
	 * 
	 * @deprecated - to be removed in version 3.5+
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
	 */
	public function create_refund_request($order_id, $ref_amount) {
		if($order_id < 1) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Post parameter is less than 1.', 'nuvei'),
				'data' => array($_POST['post'], $order_id)
			));
			wp_die();
		}
		
		$ref_amount = round($ref_amount, 2);
		if($ref_amount < 0) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Invalid Refund amount.', 'nuvei')));
			wp_die();
		}
		
		$order = wc_get_order($order_id);
		
		if(!is_a($order, 'WC_Order')) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('There is no Order with ID ', 'nuvei') . $order_id));
			wp_die();
		}
		
		$tr_id = $order->get_meta(SC_TRANS_ID);
		
		if (empty($tr_id)) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('The Order missing Transaction ID.', 'nuvei')));
			wp_die();
		}
		
		$notify_url	= $this->set_notify_url();
		$time		= gmdate('YmdHis', time());
		
		$ref_parameters = array(
			'merchantId'            => $this->settings['merchantId'],
			'merchantSiteId'        => $this->settings['merchantSiteId'],
			'clientRequestId'       => $time . '_' . $order_meta_data['order_tr_id'],
			'clientUniqueId'        => $time . '_' . uniqid(),
			'amount'                => number_format($ref_amount, 2, '.', ''),
			'currency'              => get_woocommerce_currency(),
			'relatedTransactionId'  => $tr_id, // GW Transaction ID
			'comment'               => '', // optional
			'url'                   => $notify_url,
			'timeStamp'             => $time,
		);
		
		$checksum_str = implode('', $ref_parameters);
		
		$checksum = hash(
			$this->settings['hash_type'],
			$checksum_str . $this->settings['secret']
		);
		
		$ref_parameters['checksum']				= $checksum;
		$ref_parameters['urlDetails']			= array('notificationUrl' => $notify_url);
		$ref_parameters['webMasterId']			= $this->webMasterId;
		$ref_parameters['sourceApplication']	= SC_SOURCE_APPLICATION;
		
		$resp	= SC_CLASS::call_rest_api($this->getEndPointBase() . 'refundTransaction.do', $ref_parameters);
		$msg	= '';

		if (false === $resp) {
			$msg = __('The REST API retun false.', 'nuvei');

			$order->add_order_note($msg);
			$order->save();
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			wp_die();
		}

		$json_arr = $resp;
		if (!is_array($resp)) {
			parse_str($resp, $json_arr);
		}

		if (!is_array($json_arr)) {
			$msg = __('Invalid API response.', 'nuvei');

			$order->add_order_note($msg);
			$order->save();
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			wp_die();
		}

		if (!empty($json_arr['transactionStatus']) && 'APPROVED' == $json_arr['transactionStatus']) {
			$order->update_status('processing');
			
			$this->sc_save_refund_meta_data($order, $json_arr['transactionId'], $ref_amount);
			
			wp_send_json(array('status' => 1));
			wp_die();
		}
		
		// in case we have message but without status
		if (!isset($json_arr['status']) && isset($json_arr['msg'])) {
			$msg = __('Refund request problem: ', 'nuvei') . $json_arr['msg'];

			$order->add_order_note($msg);
			$order->save();
			
			SC_CLASS::create_log($msg);

			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			wp_die();
		}
		
		// the status of the request is ERROR
		if (isset($json_arr['status']) && 'ERROR' === $json_arr['status']) {
			$msg = __('Request ERROR: ', 'nuvei') . $json_arr['reason'];

			$order->add_order_note($msg);
			$order->save();
			
			SC_CLASS::create_log($msg);
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			wp_die();
		}

		// the status of the request is SUCCESS, check the transaction status
		if (isset($json_arr['transactionStatus']) && 'ERROR' === $json_arr['transactionStatus']) {
			if (isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
				$msg = $json_arr['gwErrorReason'];
			} elseif (isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
				$msg = $json_arr['paymentMethodErrorReason'];
			} else {
				$msg = __('Transaction error.', 'nuvei');
			}

			$order->add_order_note($msg);
			$order->save();
			
			SC_CLASS::create_log($msg);
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			wp_die();
		}

		if (isset($json_arr['transactionStatus']) && 'DECLINED' === $json_arr['transactionStatus']) {
			$msg = __('The refund was declined.', 'nuvei');

			$order->add_order_note($msg);
			$order->save();
			
			SC_CLASS::create_log($msg);
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			wp_die();
		}

		$msg = __('The status of Refund request is UNKONOWN.', 'nuvei');

		$order->add_order_note(__($msg, 'sc'));
		$order->save();
		
		SC_CLASS::create_log($msg);
		
		wp_send_json(array(
			'status' => 0,
			'msg' => $msg
		));
		wp_die();
	}
	
	public function sc_return_sc_settle_btn( $args) {
		// revert buttons on Recalculate
		if (!$this->get_param('refund_amount', 'float', false) && !empty($this->get_param('items'))) {
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
		$order            = wc_get_order($order_id);
		$items            = $order->get_items();
		$is_order_restock = $order->get_meta('_scIsRestock');
		
		// do restock only once
		if (1 !== $is_order_restock) {
			wc_restock_refunded_items($order, $items);
			$order->update_meta_data('_scIsRestock', 1);
			$order->save();
			
			SC_CLASS::create_log('Items were restocked.');
		}
		
		return;
	}
	
	/**
	 * Function create_void
	 *
	 * @param int $order_id
	 * @param string $action
	 */
	public function create_settle_void( $order_id, $action) {
		$ord_status = 1;
		$time       = gmdate('YmdHis');
		
		if ('settle' == $action) {
			$method = 'settleTransaction.do';
		} else {
			$method = 'voidTransaction.do';
		}
		
		try {
			$order = wc_get_order($order_id);
			
			$order_meta_data = array(
				'order_tr_id'   => $order->get_meta(SC_TRANS_ID),
				'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
			);
			
			$params = array(
//				'merchantId'            => $this->merchantId,
				'merchantId'            => $this->sc_get_setting('merchantId'),
				'merchantSiteId'        => $this->sc_get_setting('merchantSiteId'),
				'clientRequestId'        => $time . '_' . uniqid(),
				'clientUniqueId'        => $order_id,
				'amount'                => (string) $order->get_total(),
				'currency'                => get_woocommerce_currency(),
				'relatedTransactionId'    => $order_meta_data['order_tr_id'],
				'authCode'                => $order_meta_data['auth_code'],
				'urlDetails'            => array(
					'notificationUrl'   => $this->set_notify_url(),
				),
				'timeStamp'                => $time,
				'checksum'                => '',
				'webMasterId'            => $this->webMasterId,
				'sourceApplication'        => SC_SOURCE_APPLICATION,
			);

			$params['checksum'] = hash(
				$this->sc_get_setting('hash_type'),
				$params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
					. $params['clientUniqueId'] . $params['amount'] . $params['currency']
					. $params['relatedTransactionId'] . $params['authCode']
					. $params['urlDetails']['notificationUrl'] . $params['timeStamp']
					. $this->sc_get_setting('secret')
			);

			$resp = SC_CLASS::call_rest_api($this->getEndPointBase() . $method, $params);
		} catch (Exception $ex) {
			SC_CLASS::create_log($ex->getMessage(), 'Create void exception:');
			
			wp_send_json(array(
				'status' => 0,
				'msg' => __(
					'Unexpexted error during the ' . $action . ':',
					'sc'
			)));
			wp_die();
		}
		
		if (
			!$resp || !is_array($resp)
			|| 'ERROR' == @$resp['status']
			|| 'ERROR' == @$resp['transactionStatus']
			|| 'DECLINED' == @$resp['transactionStatus']
		) {
			$ord_status = 0;
		} else {
			$order->update_status('processing');
		}
		
		wp_send_json(array('status' => $ord_status, 'data' => $resp));
		wp_die();
	}
	
//	public function sc_create_open_order( $order = null) {
	public function sc_create_open_order( $is_ajax = false ) {
//		$is_ajax = false;
//		
//		if (is_null($order)) {
//			$is_ajax  = true;
//			$order_id = $this->get_param('order_id', 'int', false);
//			
//			if (!$order_id) {
//				SC_CLASS::create_log('sc_create_open_order Error with the order id ');
//				
//				wp_send_json(array(
//					'status'    => 0,
//					'msg'        => 'sc_create_open_order Error with the order id '
//				));
//				wp_die();
//			}
//			
//			$order = wc_get_order($order_id);
//		
//			if (!$order) {
//				SC_CLASS::create_log('sc_create_open_order Error - Order is false for order id ' . $order_id);
//				
//				wp_send_json(array(
//					'status'    => 0,
//					'msg'        => 'sc_create_open_order Error - Order is false for order id ' . $order_id
//				));
//				wp_die();
//			}
//		}
		
		global $woocommerce;
		
		$time			= gmdate('YmdHis');
		$ajax_params	= array();
		
		if(!empty($this->get_param('scFormData'))) {
			parse_str($this->get_param('scFormData'), $ajax_params); 
		}
		
		$oo_params = array(
			'merchantId'        => $this->sc_get_setting('merchantId'),
			'merchantSiteId'    => $this->sc_get_setting('merchantSiteId'),
			'clientRequestId'	=> $time . '_' . uniqid(),
			'clientUniqueId'    => $time . '_' . uniqid() . '_wc_cart',
			'amount'            => WC()->cart->total,
			'currency'          => get_woocommerce_currency(),
			'timeStamp'         => $time,
			
			'urlDetails'        => array(
				'notificationUrl'   => $this->set_notify_url(),
			),
			
			'deviceDetails'     => SC_CLASS::get_device_details(),
			'userTokenId'       => $this->get_param('billing_email', 'mail', '', $ajax_params),
			
			'billingAddress'	=> array(
				'firstName'	=> $this->get_param('billing_first_name', 'string', '', $ajax_params),
				'lastName'	=> $this->get_param('billing_last_name', 'string', '', $ajax_params),
				'address'	=> $this->get_param('billing_address_1', 'string', '', $ajax_params)
								. ' ' . $this->get_param('billing_address_1', 'string', '', $ajax_params),
				'phone'		=> $this->get_param('billing_phone', 'string', '', $ajax_params),
				'zip'		=> $this->get_param('billing_postcode', 'int', 0, $ajax_params),
				'city'		=> $this->get_param('billing_city', 'string', '', $ajax_params),
				'country'	=> $this->get_param('billing_country', 'string', '', $ajax_params),
				'email'		=> $this->get_param('billing_email', 'mail', '', $ajax_params),
//				'firstName'    => $order->get_billing_first_name(),
//				'lastName'    => $order->get_billing_last_name(),
//				'address'   => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
//				'phone'     => $order->get_billing_phone(),
//				'zip'       => $order->get_billing_postcode(),
//				'city'      => $order->get_billing_city(),
//				'country'    => $order->get_billing_country(),
//				'email'        => $order->get_billing_email(),
			),
			
			'shippingAddress'	=> array(
				'firstName'	=> $this->get_param('shipping_first_name', 'string', '', $ajax_params),
				'lastName'  => $this->get_param('shipping_last_name', 'string', '', $ajax_params),
				'address'   => $this->get_param('shipping_address_1', 'string', '', $ajax_params)
								. ' ' . $this->get_param('shipping_address_2', 'string', '', $ajax_params),
				'zip'       => $this->get_param('shipping_postcode', 'string', '', $ajax_params),
				'city'      => $this->get_param('shipping_city', 'string', '', $ajax_params),
				'country'   => $this->get_param('shipping_country', 'string', '', $ajax_params),
//				'firstName'    => $order->get_shipping_first_name(),
//				'lastName'    => $order->get_shipping_last_name(),
//				'address'   => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
//				'zip'       => $order->get_shipping_postcode(),
//				'city'      => $order->get_shipping_city(),
//				'country'    => $order->get_shipping_country(),
			),
			
			'webMasterId'       => $this->webMasterId,
			'paymentOption'        => array('card' => array('threeD' => array('isDynamic3D' => 1))),
			'transactionType'    => $this->sc_get_setting('payment_action'),
		);
		
		$oo_params['userDetails'] = $oo_params['billingAddress'];
		
		$oo_params['checksum'] = hash(
			$this->sc_get_setting('hash_type'),
//			$this->merchantId . $this->merchantSiteId . $oo_params['clientRequestId']
			$this->sc_get_setting('merchantId') . $this->sc_get_setting('merchantSiteId') . $oo_params['clientRequestId']
				. $oo_params['amount'] . $oo_params['currency'] . $time . $this->sc_get_setting('secret')
		);
		
		SC_CLASS::create_log($this->getEndPointBase(), 'getEndPointBase');
		SC_CLASS::create_log($this->sc_get_setting('test'), '$this->test');
		
		$resp = SC_CLASS::call_rest_api(
			$this->getEndPointBase() . 'openOrder.do',
			$oo_params
		);
		
		if (
			empty($resp['status'])
			|| empty($resp['sessionToken'])
			|| 'SUCCESS' != $resp['status']
		) {
			if ($is_ajax) {
				wp_send_json(array(
					'status'	=> 0,
					'msg'		=> $resp
				));
				wp_die();
			} else {
				return false;
			}
		}
		
		if ($is_ajax) {
			wp_send_json(array(
				'status'        => 1,
				'sessionToken'    => $resp['sessionToken']
			));
			wp_die();
		}
		
		return array(
			'oo_params' => $oo_params,
			'resp' => $resp,
			'time' => $time
		);
	}
	
	/**
	 * Function prepare_rest_payment
	 *
	 * @param string $amount
	 * @param string $user_mail
	 * @param string $country
	 *
	 * @deprecated
	 */
	//	public function prepare_rest_payment( $amount, $user_mail, $country) {
	public function prepare_rest_payment() {
		SC_CLASS::create_log($_SESSION['sc_order_details'], 'prepare_rest_payment(), sc_order_details');
		
		if (empty($_SESSION['sc_order_details']) || !is_array($_SESSION['sc_order_details'])) {
			wp_send_json(array(
				'status'    => 0,
				'message'    => 'There are no Order details.'
			));
			wp_die();
		}
		
		global $woocommerce;
		
		if (empty($woocommerce->cart->get_totals()['total'])) {
			SC_CLASS::create_log($woocommerce->cart->get_totals(), 'prepare_rest_payment(), cart totals');
			
			wp_send_json(array(
				'status'    => 0,
				'message'    => 'Can not get Order Total amount.'
			));
			wp_die();
		}
		
		$time = gmdate('YmdHis');

		$oo_params = array(
//			'merchantId'        => $this->merchantId,
			'merchantId'        => $this->sc_get_setting('merchantId'),
			'merchantSiteId'    => $this->sc_get_setting('merchantSiteId'),
			'clientRequestId'   => $time . '_' . uniqid(),
			'clientUniqueId'    => $time . '_' . uniqid(),
			'amount'            => $woocommerce->cart->get_totals()['total'],
			'currency'          => get_woocommerce_currency(),
			'timeStamp'         => $time,
			
			'urlDetails'        => array(
				'notificationUrl'   => $this->set_notify_url(),
			),
			
			'deviceDetails'     => SC_CLASS::get_device_details(),
			'userTokenId'       => $this->get_param('billing_email', 'mail', '', $_SESSION['sc_order_details']),
			
			'billingAddress'    => array(
				'firstName'    => $this->get_param('billing_first_name', 'string', '', $_SESSION['sc_order_details']),
				'lastName'    => $this->get_param('billing_last_name', 'string', '', $_SESSION['sc_order_details']),
				'address'   => $this->get_param('billing_address_1', 'string', '', $_SESSION['sc_order_details'])
								. ' ' . $this->get_param('billing_address_2', 'string', '', $_SESSION['sc_order_details']),
				'phone'     => $this->get_param('billing_phone', 'string', '', $_SESSION['sc_order_details']),
				'zip'       => $this->get_param('billing_postcode', 'int', '', $_SESSION['sc_order_details']),
				'city'      => $this->get_param('billing_city', 'string', '', $_SESSION['sc_order_details']),
				'country'    => $this->get_param('billing_country', 'string', '', $_SESSION['sc_order_details']),
				'email'        => $this->get_param('billing_email', 'mail', '', $_SESSION['sc_order_details']),
			),
			
			'shippingAddress'    => array(
				'firstName'    => $this->get_param('shipping_first_name', 'string', '', $_SESSION['sc_order_details']),
				'lastName'    => $this->get_param('shipping_last_name', 'string', '', $_SESSION['sc_order_details']),
				'address'   => $this->get_param('shipping_address_1', 'string', '', $_SESSION['sc_order_details'])
								. ' ' . $this->get_param('shipping_address_2', 'string', '', $_SESSION['sc_order_details']),
				'zip'       => $this->get_param('shipping_postcode', 'int', '', $_SESSION['sc_order_details']),
				'city'      => $this->get_param('shipping_city', 'string', '', $_SESSION['sc_order_details']),
				'country'    => $this->get_param('shipping_country', 'string', '', $_SESSION['sc_order_details']),
			),
			
			'webMasterId'       => $this->webMasterId,
			'paymentOption'        => array('card' => array('threeD' => array('isDynamic3D' => 1))),
			'transactionType'    => $this->sc_get_setting('payment_action'),
		);
		
		$oo_params['userDetails'] = $oo_params['billingAddress'];

		$oo_params['checksum'] = hash(
			$this->sc_get_setting('hash_type'),
//			$this->merchantId . $this->merchantSiteId . $oo_params['clientRequestId']
			$this->sc_get_setting('merchantId') . $this->sc_get_setting('merchantSiteId') . $oo_params['clientRequestId']
				. $oo_params['amount'] . $oo_params['currency'] . $time . $this->sc_get_setting('secret')
		);

		$resp = SC_CLASS::call_rest_api($this->getEndPointBase() . 'openOrder.do', $oo_params);

		if (
			empty($resp['status']) || empty($resp['sessionToken'])
			|| 'SUCCESS' != $resp['status']
		) {
			if (!empty($resp['message'])) {
				wp_send_json(array(
					'status' => 0,
					'message' => $resp['message']
				));
			} else {
				wp_send_json(array(
					'status' => 0,
					'callResp' => $resp
				));
			}
			
			wp_die();
		}
		# Open Order END

		# get APMs
		$apms_params = array(
//			'merchantId'        => $this->merchantId,
			'merchantId'        => $this->sc_get_setting('merchantId'),
			'merchantSiteId'    => $this->sc_get_setting('merchantSiteId'),
			'clientRequestId'   => $time . '_' . uniqid(),
			'timeStamp'         => $time,
			'sessionToken'      => $resp['sessionToken'],
			'currencyCode'      => get_woocommerce_currency(),
			'countryCode'       => $oo_params['billingAddress']['country'],
			'languageCode'      => $this->formatLocation(get_locale()),
		);
		
		$apms_params['checksum'] = hash(
			$this->sc_get_setting('hash_type'),
			$this->sc_get_setting('merchantId') . $this->sc_get_setting('merchantSiteId') . $apms_params['clientRequestId']
//			$this->merchantId . $this->merchantSiteId . $apms_params['clientRequestId']
				. $time . $this->sc_get_setting('secret')
		);

		$apms_data = SC_CLASS::call_rest_api($this->getEndPointBase() . 'getMerchantPaymentMethods.do', $apms_params);
		
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
		$icons         = array();
		$upos          = array();
		$user_token_id = $oo_params['userTokenId'];

		// get them only for registred users when there are APMs
		if (
			1 == $this->sc_get_setting('use_upos')
			&& is_user_logged_in()
			&& !empty($payment_methods)
		) {
			$upo_params = array(
				'merchantId'        => $apms_params['merchantId'],
				'merchantSiteId'    => $apms_params['merchantSiteId'],
				'userTokenId'        => $oo_params['userTokenId'],
				'clientRequestId'    => $apms_params['clientRequestId'],
				'timeStamp'            => $time,
			);

			$upo_params['checksum'] = hash($this->sc_get_setting('hash_type'), implode('', $upo_params) . $this->sc_get_setting('secret'));

			$upo_res = SC_CLASS::call_rest_api($this->getEndPointBase() . 'getUserUPOs.do', $upo_params);
			
			if (!empty($upo_res['paymentMethods']) && is_array($upo_res['paymentMethods'])) {
				foreach ($upo_res['paymentMethods'] as $data) {
					// chech if it is not expired
					if (!empty($data['expiryDate']) && gmdate('Ymd') > $data['expiryDate']) {
						continue;
					}

					if (empty($data['upoStatus']) || 'enabled' !== $data['upoStatus']) {
						continue;
					}

					// search for same method in APMs, use this UPO only if it is available there
					foreach ($payment_methods as $pm_data) {
						// found it
						if ($pm_data['paymentMethod'] === $data['paymentMethodName']) {
							$data['logoURL'] = @$pm_data['logoURL'];
							$data['name']    = @$pm_data['paymentMethodDisplayName'][0]['message'];

							$upos[] = $data;
							break;
						}
					}
				}
			}
		}
		# get UPOs END
		
		wp_send_json(array(
			'status'            => 1,
			'testEnv'           => $this->sc_get_setting('test'),
			'merchantSiteId'    => $this->sc_get_setting('merchantSiteId'),
//			'merchantId'        => $this->merchantId,
			'merchantId'        => $this->sc_get_setting('merchantId'),
			'langCode'          => $this->formatLocation(get_locale()),
			'sessionToken'      => $resp['sessionToken'],
			'currency'          => get_woocommerce_currency(),
			'amount'            => $oo_params['amount'],
			'data'              => array(
				'upos'              => $upos,
				'paymentMethods'    => $payment_methods,
				'icons'             => $icons
			)
		));

		wp_die();
	}
	
	public function delete_user_upo() {
		$upo_id = $this->get_param('scUpoId', 'int', false);
		
		if (!$upo_id) {
			wp_send_json(
				array(
				'status' => 'error',
				'msg' => __('Invalid UPO ID parameter.', 'sc')
				)
			);

			wp_die();
		}
		
		if (!is_user_logged_in()) {
			wp_send_json(
				array(
				'status' => 'error',
				'msg' => __('The user in not logged in.', 'sc')
				)
			);

			wp_die();
		}
		
		$curr_user = wp_get_current_user();
		
		if (empty($curr_user->user_email) || !filter_var($curr_user->user_email, FILTER_VALIDATE_EMAIL)) {
			wp_send_json(
				array(
				'status' => 'error',
				'msg' => __('The user email is not valid.', 'sc')
				)
			);

			wp_die();
		}
		
		$timeStamp = gmdate('YmdHis', time());
			
		$params = array(
//			'merchantId'            => $this->merchantId,
			'merchantId'            => $this->sc_get_setting('merchantId'),
			'merchantSiteId'        => $this->sc_get_setting('merchantSiteId'),
			'userTokenId'            => $curr_user->user_email,
			'clientRequestId'        => $timeStamp . '_' . uniqid(),
			'userPaymentOptionId'    => $upo_id,
			'timeStamp'                => $timeStamp,
		);
		
		$params['checksum'] = hash(
			$this->sc_get_setting('hash_type'),
			implode('', $params) . $this->sc_get_setting('secret')
		);
		
		$resp = SC_CLASS::call_rest_api($this->getEndPointBase() . 'deleteUPO.do', $params);
		
		if (empty($resp['status']) || 'SUCCESS' != $resp['status']) {
			$msg = !empty($resp['reason']) ? $resp['reason'] : '';
			
			wp_send_json(
			
				array(
				'status' => 'error',
				'msg' => $msg
				)
			);

			wp_die();
		}
		
		wp_send_json(array('status' => 'success'));
		wp_die();
	}
	
	/**
	 * Function get_request_status
	 * We need this stupid function because as response request variable
	 * we get 'Status' or 'status'...
	 *
	 * @return string
	 */
	public function get_request_status( $params = array()) {
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
	 * @param string $key - request key
	 * @param string $type - possible vaues: string, float, int, array, mail, other
	 * @param mixed $default - returnd value if fail
	 * @param array $parent - optional list with parameters
	 *
	 * @return mixed
	 */
	public function get_param( $key, $type = 'string', $default = '', $parent = array()) {
		$arr = $_REQUEST;
		
		if (!empty($parent) && is_array($parent)) {
			$arr = $parent;
		}
		
		switch ($type) {
			case 'mail':
				return !empty($arr[$key])
					? filter_var($arr[$key], FILTER_VALIDATE_EMAIL) : $default;
				
			case 'float':
				if (empty($default)) {
					$default = (float) 0;
				}
				
				return ( !empty($arr[$key]) && is_numeric($arr[$key]) )
					? floatval(filter_var($arr[$key], FILTER_DEFAULT)) : $default;
				
			case 'int':
				if (empty($default)) {
					$default = (float) 0;
				}
				
				return ( !empty($arr[$key]) && is_numeric($arr[$key]) )
					? intval(filter_var($arr[$key], FILTER_DEFAULT)) : $default;
				
			case 'array':
				if (empty($default)) {
					$default = array();
				}
				
				return !empty($arr[$key])
					? filter_var($arr[$key], FILTER_REQUIRE_ARRAY) : $default;
				
			case 'string':
				return !empty($arr[$key])
			//                  ? urlencode(preg_replace('/[[:punct:]]/', '', filter_var($arr[$key], FILTER_SANITIZE_STRING))) : $default;
			//                  ? urlencode(filter_var($arr[$key], FILTER_SANITIZE_STRING)) : $default;
					? filter_var($arr[$key], FILTER_SANITIZE_STRING) : $default;
				
			default:
				return !empty($arr[$key])
					? filter_var($arr[$key], FILTER_DEFAULT) : $default;
		}
	}
	
	public function sc_reorder() {
		global $woocommerce;
		
		$products_ids = json_decode($this->get_param('product_ids'), true);
		
		if (empty($products_ids) || !is_array($products_ids)) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Problem with the Products IDs.', 'nuvei')
			));
			wp_die();
		}
		
		$prod_factory  = new WC_Product_Factory();
		$msg           = '';
		$is_prod_added = false;
		
		foreach ($products_ids as $id) {
			$product = $prod_factory->get_product($id);
		
			if ('in-stock' != $product->get_availability()['class'] ) {
				$msg = __('Some of the Products are not availavle, and are not added in the new Order.', 'nuvei');
				continue;
			}

			$is_prod_added = true;
			$woocommerce->cart->add_to_cart($id);
		}
		
		if (!$is_prod_added) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('There are no added Products to the Cart.', 'nuvei'),
			));
			wp_die();
		}
		
		$cart_url = wc_get_cart_url();
		
		if (!empty($msg)) {
			$cart_url .= strpos($cart_url, '?') !== false ? '&sc_msg=' : '?sc_msg=';
			$cart_url .= urlencode($msg);
		}
		
		wp_send_json(array(
			'status'		=> 1,
			'msg'			=> $msg,
			'redirect_url'	=> wc_get_cart_url(),
		));
		wp_die();
	}
	
	private function sc_get_order_data( $TransactionID) {
		global $wpdb;
		
		$res = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s ;",
				SC_TRANS_ID,
				$TransactionID
			)
		);
				
		return $res;
	}
	
	/**
	 * Function change_order_status
	 * Change the status of the order.
	 *
	 * @param int $order_id
	 * @param string $status
	 * @param string $transactionType
	 * @param array $res_args - we must use $res_args instead $_REQUEST, if not empty
	 */
	private function change_order_status( $order_id, $req_status, $transactionType, $res_args = array()) {
		SC_CLASS::create_log(
			'Order ' . $order_id . ' was ' . $req_status,
			'WC_SC change_order_status()'
		);
		
		$gw_data = '<br/>' . __('Status: ', 'sc') . $req_status
			. '<br/>' . __('PPP Transaction ID: ', 'sc') . $this->get_param('PPP_TransactionID', 'int')
			. ',<br/>' . __('Transaction Type: ', 'sc') . $transactionType
			. ',<br/>' . __('Transaction ID: ', 'sc') . $this->get_param('TransactionID', 'int')
			. ',<br/>' . __('Payment Method: ', 'sc') . $this->get_param('payment_method');
		
		$message = '';
		$status  = $this->sc_order->get_status();
		
		switch ($req_status) {
			case 'CANCELED':
				$message            = __('Your action was Canceld.', 'sc') . $gw_data;
				$this->msg['class'] = 'woocommerce_message';
				
				if (in_array($transactionType, array('Auth', 'Settle', 'Sale'))) {
					$status = 'failed';
				}
				break;

			case 'APPROVED':
				if ('Void' === $transactionType) {
					$message = __('DMN Void message', 'sc')
						. $gw_data . '<br/>' . __('Plsese check your stock!', 'sc');
					
					$status = 'cancelled';
				} elseif (in_array($transactionType, array('Credit', 'Refund'), true)) {
					$message = __('DMN Refund message', 'sc') . $gw_data;
					$status  = 'completed';
					
					// get current refund amount
					$refunds = json_decode($this->sc_order->get_meta('_sc_refunds'), true);
					$currency_code = $this->sc_order->get_currency();
					$currency_symbol = get_woocommerce_currency_symbol( $currency_code );
					
					$message .= '<br/>' . __('Refund Amount') . ': ' . $currency_symbol
						. number_format($refunds[$this->get_param('TransactionID', 'int')]['refund_amount'], 2, '.', '')
						. '<br/>' . __('Refund') . ' #' . $refunds[$this->get_param('TransactionID', 'int')]['wc_id'];
					
					if(round($this->sc_order->get_total(), 2) <= $this->sc_sum_order_refunds()) {
						$status  = 'refunded';
					}
					
					/**
					 * set flag that order has some refunds
					 * @deprecated since version 3.5
					 */
//					$this->sc_order->update_meta_data(SC_ORDER_HAS_REFUND, 1);
				} elseif ('Auth' === $transactionType) {
					$message = __('The amount has been authorized and wait for Settle.', 'sc') . $gw_data;
					$status  = 'pending';
				} elseif (in_array($transactionType, array('Settle', 'Sale'), true)) {
					$message = __('The amount has been authorized and captured by ', 'sc') . SC_GATEWAY_TITLE . '.' . $gw_data;
					$status  = 'completed';
					
					$this->sc_order->payment_complete($order_id);
				}
				
				$this->msg['class'] = 'woocommerce_message';
				break;

			case 'ERROR':
			case 'DECLINED':
			case 'FAIL':
				$reason = ',<br/>' . __('Reason: ', 'sc');
				if ('' != $this->get_param('reason')) {
					$reason .= $this->get_param('reason');
				} elseif ('' != $this->get_param('Reason')) {
					$reason .= $this->get_param('Reason');
				}
				
				$message = __('Transaction failed.', 'sc')
					. '<br/>' . __('Error code: ', 'sc') . $this->get_param('ErrCode')
					. '<br/>' . __('Message: ', 'sc') . $this->get_param('message') . $reason . $gw_data;
				
				// do not change status
				if ('Void' === $transactionType) {
					$message = 'DMN message: Your Void request fail.';
				}
				if (in_array($transactionType, array('Auth', 'Settle', 'Sale'))) {
					$status = 'failed';
				}
				
				$this->msg['class'] = 'woocommerce_message';
				break;

			case 'PENDING':
				if ('processing' === $status || 'completed' === $status) {
					break;
				}

				$message            = __('Payment is still pending.', 'sc') . $gw_data;
				$this->msg['class'] = 'woocommerce_message woocommerce_message_info';
				$status             = 'on-hold';
				break;
		}
		
		if (!empty($message)) {
			$this->msg['message'] = $message;
			$this->sc_order->add_order_note($this->msg['message']);
		}

		$this->sc_order->update_status($status);
		$this->sc_order->save();
		
		SC_CLASS::create_log($message, 'Message');
		SC_CLASS::create_log($status, 'Status');
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
	 */
	private function save_update_order_numbers() {
		// save or update AuthCode and GW Transaction ID
		$auth_code = $this->get_param('AuthCode', 'int');
		if (!empty($auth_code)) {
			$this->sc_order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
		}

		$transaction_id = $this->get_param('TransactionID', 'int');
		if (!empty($transaction_id)) {
			$this->sc_order->update_meta_data(SC_TRANS_ID, $transaction_id);
		}
		
		$pm = $this->get_param('payment_method');
		if (!empty($pm)) {
			$this->sc_order->update_meta_data(SC_PAYMENT_METHOD, $pm);
		}

		$tr_type = $this->get_param('transactionType');
		if (!empty($tr_type)) {
			$this->sc_order->update_meta_data(SC_RESP_TRANS_TYPE, $tr_type);
		}
		
		SC_CLASS::create_log(array(
			'order id' => $this->sc_order->get_id(),
			'AuthCode' => $auth_code,
			'TransactionID' => $transaction_id,
			'payment_method' => $pm,
			'transactionType' => $tr_type,
		), 'Order to update fields');
		
		$this->sc_order->save();
	}
	
	private function getEndPointBase() {
		if ('yes' == $this->sc_get_setting('test')) {
			return 'https://ppp-test.safecharge.com/ppp/api/v1/';
		}
		
		return 'https://secure.safecharge.com/ppp/api/v1/';
	}
	
	/**
	 * Function sc_get_setting
	 * @param string $key - the key we are search for
	 * @param mixed $default - the default value if no setting found
	 */
	private function sc_get_setting($key, $default = 0) {
		if(!empty($this->settings[$key])) {
			return $this->settings[$key];
		}
		
		return $default;
	}
	
	private function sc_get_order_by_tans_id($trans_id) {
		// try to get Order ID by its meta key
		$tries = 0;

		do {
			$tries++;

			$res = $this->sc_get_order_data($trans_id);

			if (empty($res[0]->post_id)) {
				sleep(3);
			}
		} while ($tries <= 10 && empty($res[0]->post_id));

		if (empty($res[0]->post_id)) {
			SC_CLASS::create_log('The DMN didn\'t wait for the Order creation. Exit.');
			echo 'The DMN didn\'t wait for the Order creation. Exit.';
			exit;
		}

		return $res[0]->post_id;
	}
	
	private function sc_create_refund_record($order_id, $reason = '') {
		$this->sc_order = wc_get_order( $order_id );
		
		if( ! is_a( $this->sc_order, 'WC_Order') ) {
			SC_CLASS::create_log('DMN Error for Cpanel Refund - Provided Order ID is not a WC Order');
			echo 'DMN Error for Cpanel Refund - Provided { ID is not a WC Order';
			exit;
		}
		
		if( !in_array($this->sc_order->get_status(), array('completed', 'processing')) ) {
			SC_CLASS::create_log(
				$this->sc_order->get_status(),
				'DMN Error for Cpanel Refund - the Order status does not allow refunds. The status is:'
			);
			echo 'DMN Error for Cpanel Refund - the Order status does not allow refunds.';
			exit;
		}
		
		$ref_amount = 0;
		$tries		= 0;
		
		do {
			$refunds = json_decode($this->sc_order->get_meta('_sc_refunds'), true);
			$tries++;
			
			SC_CLASS::create_log($tries, 'Wait for Refund meta data');
			sleep(2);
		}
		while(empty($refunds[$this->get_param('TransactionID', 'int')]) && $tries < 5);
		
		SC_CLASS::create_log($refunds, 'saved refund');
		
		if(
			!empty($refunds[$this->get_param('TransactionID', 'int')])
			&& 'pending' == $refunds[$this->get_param('TransactionID', 'int')]['status']
			&& !empty($refunds[$this->get_param('TransactionID', 'int')]['refund_amount'])
		) {
			$ref_amount = $refunds[$this->get_param('TransactionID', 'int')]['refund_amount'];
		}
		
		// in case of CPanel refund - add Refund meta data here
		if(0 == $ref_amount && strpos($this->get_param('clientRequestId'), 'gwp_') !== false) {
			$ref_amount = $this->get_param('totalAmount', 'float');
			
			$this->sc_save_refund_meta_data(
				$this->sc_order,
				$this->get_param('TransactionID'),
				$ref_amount
			);
			
			$refunds = json_decode($this->sc_order->get_meta('_sc_refunds'), true);
		}
		
		if(0 == $ref_amount) {
			return;
		}
		
		$refund = wc_create_refund(array(
			'amount'         => round(floatval($ref_amount), 2),
			'order_id'       => $order_id,
		));
		
		if (is_a($refund, 'WP_Error')) {
			SC_CLASS::create_log($refund, 'DMN Error for Cpanel Refund - the Refund process in WC returns error: ');
			echo 'DMN Error for Cpanel Refund - the Refund process in WC returns error.';
			exit;
		}
		
		$refunds[$this->get_param('TransactionID', 'int')]['status'] = 'approved';
		$refunds[$this->get_param('TransactionID', 'int')]['wc_id'] = $refund->get_id();
		
		$this->sc_order->update_meta_data('_sc_refunds', json_encode($refunds));
		$this->sc_order->save();

		return true;
	}
	
	private function sc_sum_order_refunds() {
		$refunds = json_decode($this->sc_order->get_meta('_sc_refunds'), true);
		$sum = 0;
		
		if(!empty($refunds[$this->get_param('TransactionID', 'int')])) {
			SC_CLASS::create_log($refunds, 'Order Refunds');
			
			foreach($refunds as $data) {
				if('approved' == $data['status']) {
					$sum += $data['refund_amount'];
				}
			}
		}
		
		SC_CLASS::create_log($sum, 'Sum of refunds for an Order.');
		return round($sum, 2);
	}
	
	private function sc_save_refund_meta_data($order, $trans_id, $ref_amount) {
		$refunds = json_decode($order->get_meta('_sc_refunds'), true);
		
		if(empty($refunds)) {
			$refunds = array();
		}
		
		SC_CLASS::create_log($refunds, 'Saved Refunds meta data before add the current one.');
		SC_CLASS::create_log($ref_amount, '$ref_amount');
		
		// add the new refund
		$refunds[$trans_id] = array(
			'refund_amount'	=> round(floatval($ref_amount), 2),
			'status'		=> 'pending'
		);

		$order->update_meta_data('_sc_refunds', json_encode($refunds));
		$order->save();
		
		SC_CLASS::create_log(json_decode($order->get_meta('_sc_refunds'), true), 'Saved Refunds after the request.');
	}
	
}

?>
