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
		
		$this->title = !empty($this->settings['title'])
			? $this->settings['title'] : '';
		
		$this->description = !empty($this->settings['description'])
			? $this->settings['description'] : '';
		
		$this->merchantId = !empty($this->settings['merchantId'])
			? $this->settings['merchantId'] : '';
		
		$this->merchantSiteId = !empty($this->settings['merchantSiteId'])
			? $this->settings['merchantSiteId'] : '';
		
		$this->secret = !empty($this->settings['secret'])
			? $this->settings['secret'] : '';
		
		$this->test = isset($this->settings['test'])
			? $this->settings['test'] : 'yes';
		
		$this->use_http = isset($this->settings['use_http'])
			? $this->settings['use_http'] : 'yes';
		
		$this->save_logs = isset($this->settings['save_logs'])
			? $this->settings['save_logs'] : 'yes';
		
		$this->hash_type = isset($this->settings['hash_type'])
			? $this->settings['hash_type'] : 'sha256';
		
		$this->payment_action = isset($this->settings['payment_action'])
			? $this->settings['payment_action'] : 'Auth';
		
		$this->use_upos = isset($this->settings['use_upos'])
			? $this->settings['use_upos'] : 1;
		
		$this->rewrite_dmn = !empty($this->settings['rewrite_dmn'])
			? $this->settings['rewrite_dmn'] : 'no';
		
		$this->merchant_style = !empty($this->settings['merchant_style'])
			? $this->settings['merchant_style'] : '';
		
		$this->webMasterId .= WOOCOMMERCE_VERSION;
		
		$_SESSION['SC_Variables']['sc_create_logs'] = $this->save_logs;
		
		$this->use_wpml_thanks_page =
			@$this->settings['use_wpml_thanks_page'] ? $this->settings['use_wpml_thanks_page'] : 'no';
		
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
			'payment_action' => array(
				'title' => __('Payment action', 'sc'),
				'type' => 'select',
				'options' => array(
					'Sale' => 'Authorize and Capture',
					'Auth' => 'Authorize',
				)
			),
			'use_upos' => array(
				'title' => __('Allow client to use UPOs', 'sc'),
				'type' => 'select',
				'options' => array(
					1 => 'Yes',
					0 => 'No',
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
		
		# Redirect to second step
		if (
			$this->get_param('payment_method') == 'sc'
			&& empty($this->get_param('sc_payment_method'))
		) {
			return array(
				'result'    => 'success',
				'redirect'  => add_query_arg(
					array(
						'order_id'    => $order_id,
						'key'        => $order->get_order_key()
					),
					wc_get_checkout_url()
				)
			);
		}
		
		# Process order
		//		$order_key = $order->get_order_key();
		
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
			
			wp_redirect($return_error_url);
			exit;
			
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
		
		// reset it
		//		$_SESSION['sc_order_details'] = array();
		
		# in case of webSDK payment (cc_card)
		if (!empty($sc_transaction_id)) {
			SC_CLASS::create_log('Process webSDK Order, transaction ID #' . $sc_transaction_id);
			
			$order->update_meta_data(SC_TRANS_ID, $sc_transaction_id);
			$order->save();
			
			wp_redirect($return_success_url);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'  => $return_success_url
			//          );
		}
		# in case of webSDK payment (cc_card) END
		
		SC_CLASS::create_log('Process Rest APM Order.');
		
		// if we use APM
		$time = gmdate('Ymdhis');
		
		$params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
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
			$this->hash_type,
			$params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
				. $params['amount'] . $params['currency'] . $time . $this->secret
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
			
			wp_redirect($return_error_url);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'  => $return_error_url
			//          );
		}

		//		if(empty($resp['transactionStatus'])) {
		if (empty($this->get_request_status($resp))) {
			//			$msg = __('There is no Transaction Status for the Order.', 'sc');
			$msg = __('There is no Status for the Order.', 'sc');
			
			$order->add_order_note($msg);
			$order->save();
			
			wp_redirect($return_error_url);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'  => $return_error_url
			//          );
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
			
			wp_redirect($return_error_url);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'  => $return_error_url
			//          );
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
			
			wp_redirect($return_error_url);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'  => $return_error_url
			//          );
		}
		
		// catch Error code or reason
		if (
			( isset($resp['gwErrorCode']) && -1 === $resp['gwErrorCode'] )
			|| isset($resp['gwErrorReason'])
		) {
			$msg = __('Error with the Payment: ' . $resp['gwErrorReason'] . '.', 'sc');

			$order->add_order_note($msg);
			$order->save();

			wp_redirect($return_error_url);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'  => $return_error_url
			//          );
		}
		
		// Success status
		if (!empty($resp['redirectURL']) || !empty($resp['paymentOption']['redirectUrl'])) {
			SC_CLASS::create_log('we have redirectURL');
			
			wp_redirect(!empty($resp['redirectURL']) ? $resp['redirectURL'] : $resp['paymentOption']['redirectUrl']);
			exit;
			
			//			return array(
			//              'result'    => 'success',
			//              'redirect'    => add_query_arg(
			//                  array(),
			//                  !empty($resp['redirectURL']) ? $resp['redirectURL'] : $resp['paymentOption']['redirectUrl']
			//              )
			//          );
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
		
		wp_redirect($return_success_url);
		exit;
		
		//		return array(
		//          'result'    => 'success',
		//          'redirect'  => $return_success_url
		//      );
	}
	
	public function checkoutSecondStep( $content) {
		if (!is_checkout()) {
			return $content;
		}
		
		$order_id = $this->get_param('order_id', 'int', 0);
		$key      = $this->get_param('key');
		
		if (0 == $order_id) {
			SC_CLASS::create_log('checkoutSecondStep Error - order id is 0');
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
					'key'        => $order->get_order_key()
				),
				wc_get_checkout_url() . 'order-received/'
			);
		}
		
		if (empty($key)) {
			SC_CLASS::create_log('checkoutSecondStep Error - order key is empty.');
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		$order = wc_get_order($order_id);
		
		if (!$order) {
			SC_CLASS::create_log('checkoutSecondStep Error - Order is false for order id ' . $order_id);
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
					'key'        => $key
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		if ('sc' !== $order->get_payment_method()) {
			SC_CLASS::create_log('checkoutSecondStep Error - Order payment method is not "sc".');
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
					'key'        => $key
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		$order_key = $order->get_order_key();
		
		if ($order_key !== $key) {
			SC_CLASS::create_log(
				array(
					'order key'        => $order_key,
					'request key'    => $key
				),
				'checkoutSecondStep Error - Order key is different than request key'
			);
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
					'key'        => $key
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
		
		$content = '';
		
		if (!empty(trim($this->merchant_style))) {
			$content .= '<style>' . $this->merchant_style . '</style>';
		}
		
		$content .=
			'<form id="sc_second_step_form" method="post" action="' . get_site_url() . '/?wc-api=process-order&order_id=' . $order_id . '" enctype="multipart/form-data" novalidate="novalidate">'
				. '<div id="sc_loader_background">'
					. '<img src="' . $this->plugin_url . 'icons/loader.gif" alt="loading..." />'
				. '</div>'
				. '<div id="sc_checkout_messages"></div>';
		
		# OpenOrder
		$oo_data = $this->sc_open_order($order);
		
		if (!$oo_data) {
			return array(
				'result'    => 'success',
				'redirect'    => $return_error_url
			);
		}
		
		$oo_params = $oo_data['oo_params'];
		$resp      = $oo_data['resp'];
		$time      = $oo_data['time'];
		# OpenOrder END
		
		# get APMs
		$apms_params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'clientRequestId'   => $time . '_' . uniqid(),
			'timeStamp'         => $time,
			'sessionToken'      => $resp['sessionToken'],
			'currencyCode'      => $oo_params['currency'],
			'countryCode'       => $oo_params['billingAddress']['country'],
			'languageCode'      => $this->formatLocation(get_locale()),
		);
		
		$apms_params['checksum'] = hash(
			$this->hash_type,
			$this->merchantId . $this->merchantSiteId . $apms_params['clientRequestId']
				. $time . $this->secret
		);

		$apms_data = SC_CLASS::call_rest_api(
			$this->getEndPointBase() . 'getMerchantPaymentMethods.do',
			$apms_params
		);
		
		if (!is_array($apms_data) || empty($apms_data['paymentMethods'])) {
			return array(
				'result'    => 'success',
				'redirect'    => $return_error_url
			);
		}
		# get APMs END
		
		# get UPOs
		$icons         = array();
		$upos          = array();
		$user_token_id = $oo_params['userTokenId'];

		// get them only for registred users when there are APMs
		if (
			1 == $this->use_upos
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

			$upo_params['checksum'] = hash($this->hash_type, implode('', $upo_params) . $this->secret);

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
		# get UPOs END
		
		# prepare the content
		// add UPOs
		if (!empty($upos)) {
			$content .=
				'<h3>' . __('Choose from you preferred payment methods', 'nuvei') . ':</h3>'
				. '<ul id="sc_upos_list">';
			
			foreach ($upos as $upo) {
				if ('cc_card' == $upo['paymentMethodName']) {
					$img = '<img src="' . $this->plugin_url . 'icons/visa_mc_maestro.svg" alt="'
						. $upo['name'] . '" style="height: 36px;" />';
				} else {
					$img = '<img src="' . str_replace('/svg/', '/svg/solid-white/', $upo['logoURL'])
						. '" alt="' . $upo['name'] . '" />';
				}
				
				$content .=
					'<li class="upo_container" id="upo_cont_' . $upo['userPaymentOptionId'] . '">'
						. '<label class="apm_title">'
							. '<input id="sc_payment_method_' . $upo['userPaymentOptionId'] . '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' . $upo['userPaymentOptionId'] . '" data-upo-name="' . $upo['paymentMethodName'] . '" />&nbsp;'
							. $img . '&nbsp;&nbsp;'
							. '<span>';
				
				// add upo identificator
				if ('cc_card' == $upo['paymentMethodName']) {
					$content .= $upo['upoData']['ccCardNumber'];
				} elseif (!empty($upo['upoName'])) {
					$content .= $upo['upoName'];
				}
				
				$content .=
							'</span>&nbsp;&nbsp;';
				
				// add remove icon
				$content .=
							'<span id="#sc_remove_upo_' . $upo['userPaymentOptionId'] . '" class="dashicons dashicons-trash" data-upo-id="' . $upo['userPaymentOptionId'] . '"></span>'
						. '</label>';
				
				if ('cc_card' === $upo['paymentMethodName']) {
					$content .=
						'<div class="apm_fields" id="sc_' . $upo['userPaymentOptionId'] . '">'
							. '<div id="sc_upo_' . $upo['userPaymentOptionId'] . '_cvc"></div>'
						. '</div>';
				}
				
				$content .=
					'</li>';
			}
			
			$content .=
				'</ul>';
		}
		// add UPOs END
		
		// add APMs
		$content .=
				'<h3>' . __('Choose from the payment options', 'nuvei') . ':</h3>'
				. '<ul id="sc_apms_list">';
		
		foreach ($apms_data['paymentMethods'] as $apm) {
			$pmMsg = '';
			
			if (!empty($apm['paymentMethodDisplayName'][0]['message'])) {
				$pmMsg = $apm['paymentMethodDisplayName'][0]['message'];
			} elseif (!empty($apm['paymentMethod'])) {
				// fix when there is no display name
				$pmMsg = str_replace('apmgw_', '', $apm['paymentMethod']);
				$pmMsg = str_replace('_', ' ', $pmMsg);
			}
			
			$newImg = $pmMsg;
			
			if ('cc_card' == $apm['paymentMethod']) {
				$newImg = '<img src="' . $this->plugin_url . 'icons/visa_mc_maestro.svg" alt="'
					. $pmMsg . '" style="height: 36px;" />';
			} elseif (!empty($apm['logoURL'])) {
				$newImg = '<img src="' . str_replace('/svg/', '/svg/solid-white/', $apm['logoURL'])
					. '" alt="' . $pmMsg . '" />';
			} else {
				$newImg = '<img src="#" alt="' . $pmMsg . '" />';
			}
			
			$content .=
					'<li class="apm_container">'
						. '<label class="apm_title">'
							. '<input id="sc_payment_method_' . $apm['paymentMethod'] . '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' . $apm['paymentMethod'] . '" data-nuvei-is-direct="' . ( isset($apm['isDirect']) ? $apm['isDirect'] : 'false' ) . '" />&nbsp;'
							. $newImg
						. '</label>';
			
			if ('cc_card' == $apm['paymentMethod']) {
				$content .=
						'<div class="apm_fields" id="sc_' . $apm['paymentMethod'] . '">'
							. '<input type="text" id="sc_card_holder_name" name="' . $apm['paymentMethod'] . '[cardHolderName]" placeholder="Card holder name" />'

							. '<div id="sc_card_number"></div>'
							. '<div id="sc_card_expiry"></div>'
							. '<div id="sc_card_cvc"></div>';
			} elseif (count($apm['fields']) > 0) {
				$content .=
						'<div class="apm_fields">';

				foreach ($apm['fields'] as $field) {
					$pattern = '';
					if (!empty($field['regex'])) {
						$pattern = $field['regex'];
					}

					$placeholder = '';
					if (!empty($field['caption'][0]['message'])) {
						$placeholder = $field['caption'][0]['message'];
					} else {
						$placeholder = str_replace('_', ' ', $field['name']);
					}
					
					$content .=
							'<input id="' . $apm['paymentMethod'] . '_' . $field['name']
								. '" name="' . $field['name']
								. '" type="' . $field['type']
								. '" pattern="' . $pattern
								. '" placeholder="' . $placeholder
								. '" autocomplete="new-password" />';
				}
				
				$content .=
						'</div>';
			}
			
			$content .=
					'</li>';
		}
		
		$content .=
				'</ul>'
				. '<input type="hidden" name="sc_transaction_id" id="sc_transaction_id" value="" />'
				. '<input type="hidden" name="lst" id="lst" value="' . $resp['sessionToken'] . '" />'
				. '<a class="button" href="' . wc_get_checkout_url() . '">' . __('Back') . '</a>&nbsp;'
				. '<button type="button" onclick="scValidateAPMFields()" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="' . __('Pay') . '" data-value="Continue" data-default-text="Place order">' . __('Pay') . '</button>';
		// add APMs END
		
		$content .=
			'</form>'
				. '<script>'
					. 'jQuery("#site-header-cart").remove();'
					. 'var orderId		= ' . $order_id . ';'
					. 'var locale		= "' . $apms_params['languageCode'] . '";'
					. 'scOpenOrderToken	= "' . $resp['sessionToken'] . '";'
					. 'scOrderCurr		= "' . $oo_params['currency'] . '";'
					. 'scMerchantId		= scData.merchantId = "' . $this->merchantId . '";'
					. 'scMerchantSiteId	= scData.merchantSiteId = "' . $this->merchantSiteId . '";'
					. 'scOrderAmount	= "' . $oo_params['amount'] . '";'
					. 'scUserTokenId	= "' . $oo_params['userTokenId'] . '";'

					. 'scData.sessionToken		= "' . $resp['sessionToken'] . '";'
					. 'scData.sourceApplication	= scTrans.webMasterId;';

		if ('yes' == $this->test) {
			$content .=
					'scData.env = "test";';
		}
		
		$content .=
					'sfc = SafeCharge(scData);'
					. 'scFields = sfc.fields({ locale: "' . $apms_params['languageCode'] . '" });'
				. '</script>';
		# prepare the content
		
		return $content;
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
				// try to get Order ID by its meta key
				$tries = 0;
				
				do {
					$tries++;

					$res = $this->sc_get_order_data($TransactionID);

					if (empty($res[0]->post_id)) {
						sleep(3);
					}
				} while ($tries <= 10 && empty($res[0]->post_id));
				
				if (empty($res[0]->post_id)) {
					SC_CLASS::create_log('The DMN didn\'t wait for the Order creation. Exit.');
					echo 'The DMN didn\'t wait for the Order creation. Exit.';
					exit;
				}
				
				$order_id = $res[0]->post_id;
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
		// see https://www.safecharge.com/docs/API/?json#refundTransaction -> Output Parameters
		// when we refund form CPanel we get transactionType = Credit and Status = 'APPROVED'
		if (
			'refund' == $action
			|| in_array($transactionType, array('Credit', 'Refund'), true)
		) {
			$this->sc_order = wc_get_order($order_id);
			
			if (!$this->sc_order) {
				SC_CLASS::create_log('Order gets False.');
				echo esc_html('DMN error - Order gets False.');
				exit;
			}
			
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
			$this->hash_type,
			$this->secret . $this->get_param('totalAmount', 'float')
				. $this->get_param('currency') . $this->get_param('responseTimeStamp')
				. $this->get_param('PPP_TransactionID', 'int') . $this->get_request_status()
				. $this->get_param('productId')
		);
		
		//      var_dump($this->secret . $this->get_param('totalAmount', 'float')
		//              . $this->get_param('currency') . $this->get_param('responseTimeStamp')
		//              . $this->get_param('PPP_TransactionID', 'int') . $this->get_request_status()
		//              . $this->get_param('productId'));
		
		//      var_dump($this->get_param('totalAmount', 'float'));
		
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
		$post_ID  = $this->get_param('post_ID', 'int');
		
		if (empty($this->get_param('api_refund')) || !$refund) {
			return false;
		}
		
		// get order refunds
		try {
			$refund_data = $refund->get_data();
			
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
			
			$order = wc_get_order($order_id);
			
			$order_meta_data = array(
				'order_tr_id'   => $order->get_meta(SC_TRANS_ID),
				'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
			);
		} catch (Exception $ex) {
			SC_CLASS::create_log($ex->getMessage(), 'sc_create_refund() Exception: ');
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
		
		$time = gmdate('YmdHis', time());
		
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
		
		$ref_parameters['checksum']          = $checksum;
		$ref_parameters['urlDetails']        = array('notificationUrl' => $notify_url);
		$ref_parameters['webMasterId']       = $this->webMasterId;
		$ref_parameters['sourceApplication'] = SC_SOURCE_APPLICATION;
		
		$resp = SC_CLASS::call_rest_api($refund_url, $ref_parameters);

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
			$order->update_status('processing');
			return;
		}

		$msg = 'The status of request - Refund #' . $refund_data['id'] . ', is UNKONOWN.';

		$order->add_order_note(__($msg, 'sc'));
		$order->save();
		
		return;
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
			$url = 'no' == $_SESSION['SC_Variables']['test'] ? SC_LIVE_SETTLE_URL : SC_TEST_SETTLE_URL;
		} else {
			$url = 'no' == $this->test ? SC_LIVE_VOID_URL : SC_TEST_VOID_URL;
		}
		
		try {
			$order = wc_get_order($order_id);
			
			$order_meta_data = array(
				'order_tr_id'   => $order->get_meta(SC_TRANS_ID),
				'auth_code'     => $order->get_meta(SC_AUTH_CODE_KEY),
			);
			
			$params = array(
				'merchantId'            => $this->merchantId,
				'merchantSiteId'        => $this->merchantSiteId,
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
				$this->hash_type,
				$params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
					. $params['clientUniqueId'] . $params['amount'] . $params['currency']
					. $params['relatedTransactionId'] . $params['authCode']
					. $params['urlDetails']['notificationUrl'] . $params['timeStamp']
					. $this->secret
			);

			$resp = SC_CLASS::call_rest_api($url, $params);
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
	
	public function sc_open_order( $order = null) {
		$is_ajax = false;
		
		if (is_null($order)) {
			$is_ajax  = true;
			$order_id = $this->get_param('order_id', 'int', false);
			
			if (!$order_id) {
				SC_CLASS::create_log('sc_open_order Error with the order id ');
				
				wp_send_json(array(
					'status'    => 0,
					'msg'        => 'sc_open_order Error with the order id '
				));
				wp_die();
			}
			
			$order = wc_get_order($order_id);
		
			if (!$order) {
				SC_CLASS::create_log('sc_open_order Error - Order is false for order id ' . $order_id);
				
				wp_send_json(array(
					'status'    => 0,
					'msg'        => 'sc_open_order Error - Order is false for order id ' . $order_id
				));
				wp_die();
			}
		}
		
		$time = gmdate('YmdHis');
		
		$oo_params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'clientRequestId'   => $time . '_' . uniqid(),
			'clientUniqueId'    => $time . '_' . uniqid(),
			'amount'            => $order->get_total(),
			'currency'          => $order->get_currency(),
			'timeStamp'         => $time,
			
			'urlDetails'        => array(
				'notificationUrl'   => $this->set_notify_url(),
			),
			
			'deviceDetails'     => SC_CLASS::get_device_details(),
			'userTokenId'       => $order->get_billing_email(),
			
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
				'firstName'    => $order->get_shipping_first_name(),
				'lastName'    => $order->get_shipping_last_name(),
				'address'   => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
				'zip'       => $order->get_shipping_postcode(),
				'city'      => $order->get_shipping_city(),
				'country'    => $order->get_shipping_country(),
			),
			
			'webMasterId'       => $this->webMasterId,
			'paymentOption'        => array('card' => array('threeD' => array('isDynamic3D' => 1))),
			'transactionType'    => $this->payment_action,
		);
		
		$oo_params['userDetails'] = $oo_params['billingAddress'];
		
		$oo_params['checksum'] = hash(
			$this->hash_type,
			$this->merchantId . $this->merchantSiteId . $oo_params['clientRequestId']
				. $oo_params['amount'] . $oo_params['currency'] . $time . $this->secret
		);
		
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
		
		$time            = gmdate('YmdHis');
		$oo_endpoint_url = 'yes' == $this->test ? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;

		$oo_params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
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
			'transactionType'    => $this->payment_action,
		);
		
		$oo_params['userDetails'] = $oo_params['billingAddress'];

		$oo_params['checksum'] = hash(
			$this->hash_type,
			$this->merchantId . $this->merchantSiteId . $oo_params['clientRequestId']
				. $oo_params['amount'] . $oo_params['currency'] . $time . $this->secret
		);

		$resp = SC_CLASS::call_rest_api($oo_endpoint_url, $oo_params);

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
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'clientRequestId'   => $time . '_' . uniqid(),
			'timeStamp'         => $time,
			'sessionToken'      => $resp['sessionToken'],
			'currencyCode'      => get_woocommerce_currency(),
			'countryCode'       => $oo_params['billingAddress']['country'],
			'languageCode'      => $this->formatLocation(get_locale()),
		);
		
		$apms_params['checksum'] = hash(
			$this->hash_type,
			$this->merchantId . $this->merchantSiteId . $apms_params['clientRequestId']
				. $time . $this->secret
		);

		$endpoint_url = 'yes' == $this->test
			? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL;

		$apms_data = SC_CLASS::call_rest_api($endpoint_url, $apms_params);
		
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
			1 == $this->use_upos
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

			$upo_params['checksum'] = hash($this->hash_type, implode('', $upo_params) . $this->secret);

			$upo_res = SC_CLASS::call_rest_api(
				'yes' == $this->test ? SC_TEST_USER_UPOS_URL : SC_LIVE_USER_UPOS_URL,
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
			'testEnv'           => $this->test,
			'merchantSiteId'    => $this->merchantSiteId,
			'merchantId'        => $this->merchantId,
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
			'merchantId'            => $this->merchantId,
			'merchantSiteId'        => $this->merchantSiteId,
			'userTokenId'            => $curr_user->user_email,
			'clientRequestId'        => $timeStamp . '_' . uniqid(),
			'userPaymentOptionId'    => $upo_id,
			'timeStamp'                => $timeStamp,
		);
		
		$params['checksum'] = hash(
			$this->hash_type,
			implode('', $params) . $this->secret
		);
		
		$resp = SC_CLASS::call_rest_api(
			'yes' == $this->test ? SC_TEST_DELETE_UPO_URL: SC_LIVE_DELETE_UPO_URL,
			$params
		);
		
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
	 * @param mixed $default - returnd value if fail
	 * @param string $type - possible vaues: string, float, int, array, mail, other
	 * @param string $array - array to search into
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
			case 'int':
				if (empty($default)) {
					$default = (float) 0.00;
				}
				
				return ( !empty($arr[$key]) && is_numeric($arr[$key]) )
					? filter_var($arr[$key], FILTER_DEFAULT) : $default;
				
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
		
		$gw_data = ',<br/>' . __('Status = ', 'sc') . $req_status
			. '<br/>' . __('PPP Transaction ID = ', 'sc') . $this->get_param('PPP_TransactionID', 'int')
			. ',<br/>' . __('Transaction Type = ', 'sc') . $transactionType
			. ',<br/>' . __('Transaction ID = ', 'sc') . $this->get_param('TransactionID', 'int')
			. ',<br/>' . __('Payment Method - ', 'sc') . $this->get_param('payment_method');
		
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
					$message = __('DMN message: Your Void was successful.', 'sc')
						. $gw_data . '<br/>' . __('Plsese check your stock!', 'sc');
					
					$status = 'cancelled';
				} elseif (in_array($transactionType, array('Credit', 'Refund'), true)) {
					$message = __('DMN message: Your Refund was successful.', 'sc') . $gw_data;
					$status  = 'completed';
					
					// set flag that order has some refunds
					$this->sc_order->update_meta_data(SC_ORDER_HAS_REFUND, 1);
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
				$reason = ',<br/>' . __('Reason = ', 'sc');
				if ('' != $this->get_param('reason')) {
					$reason .= $this->get_param('reason');
				} elseif ('' != $this->get_param('Reason')) {
					$reason .= $this->get_param('Reason');
				}
				
				$message = __('Transaction failed.', 'sc')
					. '<br/>' . __('Error code = ', 'sc') . $this->get_param('ErrCode')
					. '<br/>' . __('Message = ', 'sc') . $this->get_param('message') . $reason . $gw_data;
				
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
		if ('yes' == $this->test) {
			return 'https://ppp-test.safecharge.com/ppp/api/v1/';
		}
		
		return 'https://secure.safecharge.com/ppp/api/v1/';
	}
}

?>
