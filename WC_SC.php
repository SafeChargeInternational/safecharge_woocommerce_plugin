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
		
		$this->title          = @$this->settings['title'] ? $this->settings['title'] : '';
		$this->description    = @$this->settings['description'] ? $this->settings['description'] : '';
		$this->merchantId     = @$this->settings['merchantId'] ? $this->settings['merchantId'] : '';
		$this->merchantSiteId = @$this->settings['merchantSiteId'] ? $this->settings['merchantSiteId'] : '';
		$this->secret         = @$this->settings['secret'] ? $this->settings['secret'] : '';
		$this->test           = @$this->settings['test'] ? $this->settings['test'] : 'yes';
		$this->use_http       = @$this->settings['use_http'] ? $this->settings['use_http'] : 'yes';
		$this->save_logs      = @$this->settings['save_logs'] ? $this->settings['save_logs'] : 'yes';
		$this->hash_type      = @$this->settings['hash_type'] ? $this->settings['hash_type'] : 'sha256';
		$this->payment_action = @$this->settings['payment_action'] ? $this->settings['payment_action'] : 'Auth';
		$this->rewrite_dmn    = @$this->settings['rewrite_dmn'] ? $this->settings['rewrite_dmn'] : 'no';
		$this->webMasterId   .= WOOCOMMERCE_VERSION;
		
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
		//              'description' => __('Choose Hash type provided by ' . SC_GATEWAY_TITLE, 'sc'),
				'options' => array(
					'Sale' => 'Authorize and Capture',
					'Auth' => 'Authorize',
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
		SC_HELPER::create_log('Process payment(), Order #' . $order_id);
		
		$order = wc_get_order($order_id);
		
		if (!$order) {
			SC_HELPER::create_log('Order is false for order id ' . $order_id);
			return array('result' => 'error');
		}
		
		$order_status = strtolower($order->get_status());
		
		// when we have Approved from the SDK we complete the order here
		$sc_transaction_id	= filter_input(INPUT_POST, 'sc_transaction_id', FILTER_SANITIZE_STRING);
		$return_success_url	= add_query_arg(
			array('key' => $order->get_order_key()),
			$this->get_return_url($order)
		);
		$return_error_url	= add_query_arg(
			array(
				'Status'	=> 'error',
				'key'		=> $order->get_order_key()
			),
			$this->get_return_url($order)
		);
		
		// in case of webSDK payment (cc_card)
		if ($sc_transaction_id) {
			SC_HELPER::create_log('Process webSDK Order, transaction ID #' . $sc_transaction_id);
			
			$order->update_meta_data(SC_TRANS_ID, $sc_transaction_id);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_success_url
			);
		}
		
		SC_HELPER::create_log('Process Rest APM Order.');
		
		// if we use APM
		$time         = gmdate('Ymdhis');
		$endpoint_url = '';
		
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
				'successUrl'        => $return_success_url,
				'failureUrl'        => $return_success_url,
				'pendingUrl'        => $return_success_url,
				'notificationUrl'   => $this->set_notify_url(),
			),
			'timeStamp'         => $time,
			'webMasterId'       => $this->webMasterId,
			'sourceApplication' => SC_SOURCE_APPLICATION,
			'deviceDetails'     => SC_HELPER::get_device_details(),
			'sessionToken'      => filter_input(INPUT_POST, 'lst', FILTER_SANITIZE_STRING),
		);
		
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
		
		$params['paymentMethod'] = filter_input(INPUT_POST, 'sc_payment_method', FILTER_SANITIZE_STRING);
		$post_sc_payment_fields  = filter_input(INPUT_POST, $params['paymentMethod'], FILTER_DEFAULT , FILTER_REQUIRE_ARRAY);
		
		if ($post_sc_payment_fields) {
			$params['userAccountDetails'] = $post_sc_payment_fields;
		}

		$endpoint_url = 'no' === $this->test ? SC_LIVE_PAYMENT_URL : SC_TEST_PAYMENT_URL;
		
		$resp = SC_HELPER::call_rest_api($endpoint_url, $params);
		
		if (!$resp) {
			$order->add_order_note(__('There is no response for the Order.', 'sc'));
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'	=> $return_error_url
			);
		}

		// If we get Transaction ID save it as meta-data
		if (isset($resp['transactionId']) && $resp['transactionId']) {
			$order->update_meta_data(SC_TRANS_ID, $resp['transactionId'], 0);
		}

		if (isset($resp['transactionStatus']) && 'DECLINED' === $resp['transactionStatus']) {
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
				'redirect'  => $return_error_url
			);
		}
		
		if ($this->get_request_status($resp) === 'SUCCESS' && !empty($resp['redirectURL'])) {
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
					'redirect'  => $return_error_url
				);
			}

			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array(),
					$resp['redirectURL']
				)
			);
		} // when SUCCESS
		
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
	
	/**
	 * Function process_dmns
	 * Process information from the DMNs.
	 * We call this method form index.php
	 */
	public function process_dmns( $params = array() ) {
		SC_HELPER::create_log($_REQUEST, 'Receive DMN with params: ');
		
		$req_status = $this->get_request_status();
		
		if (empty($req_status)) {
			SC_HELPER::create_log('Error: the DMN Status is empty!');
			echo 'Error: the DMN Status is empty!';
			exit;
		}
		
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
		
		if (!$this->checkAdvancedCheckSum()) {
			SC_HELPER::create_log('Error when check AdvancedCheckSum!');
			echo esc_html('Error when check AdvancedCheckSum!');
			exit;
		}
		
		SC_HELPER::create_log($transactionType);
		
		# Sale and Auth
		if (in_array($transactionType, array('Sale', 'Auth'), true)) {
			// WebSDK
			if (
				empty($clientUniqueId)
				&& empty($this->get_param('merchant_unique_id'))
				&& !empty($this->get_param('TransactionID'))
			) {
				SC_HELPER::create_log('WebSDK');
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
					SC_HELPER::create_log('The DMN didn\'t wait for the Order creation. Exit.');
					echo 'The DMN didn\'t wait for the Order creation. Exit.';
					exit;
				}
				
				$order_id = $res[0]->post_id;
			} else {
				// REST
				SC_HELPER::create_log('REST Sale.');
				
				if (empty($order_id) && is_numeric($clientUniqueId)) {
					$order_id = $clientUniqueId;
				}
			}
			
			$order = wc_get_order($order_id);
				
			if (!$order) {
				SC_HELPER::create_log('Order gets False.');
				echo esc_html('DMN error - Order gets False.');
				exit;
			}
			
			$this->save_update_order_numbers($order);
			
			$order_status = strtolower($order->get_status());
			
			if ('completed' !== $order_status) {
				$this->change_order_status(
					$order,
					$order_id,
					$req_status,
					$transactionType
				);
			}
			
			$msg = __('DMN for Order #' . $order_id . ', was received.', 'sc')
				. '<br/>' . __('Payment method:', 'sc') . ' <b>' . $this->get_param('payment_method') . '</b>.';
			
			$order->add_order_note($msg);
			$order->save();
			
			echo 'DMN received.';
			exit;
		}
		
		// try to get the Order ID
		$order_id = $clientUniqueId;
		
		if (!is_numeric($order_id)) {
			$ord_data = $this->sc_get_order_data($this->get_param('relatedTransactionId'));

			if (!empty($ord_data[0]->post_id)) {
				$order_id = $ord_data[0]->post_id;
			}
		}
		
		# Void, Settle
		if (
			'' != $clientUniqueId
			&& ( in_array($transactionType, array('Void', 'Settle'), true) )
		) {
			$order = wc_get_order($order_id);
			
			if (!$order) {
				SC_HELPER::create_log('Order gets False.');
				echo esc_html('DMN error - Order gets False.');
				exit;
			}
			
			$msg = __('DMN for Order #' . $order_id . ', was received.', 'sc');
			
			if ('Settle' == $transactionType) {
				$this->save_update_order_numbers($order);

				$msg .= '<br/>' . __('Payment method:', 'sc')
					. ' <b>' . $this->get_param('payment_method') . '</b>.';
			}

			$this->change_order_status($order, $order_id, $req_status, $transactionType);
				
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
			'refund' == $action
			|| in_array($transactionType, array('Credit', 'Refund'), true)
		) {
			$order = wc_get_order($order_id);
			
			if (!$order) {
				SC_HELPER::create_log('Order gets False.');
				echo esc_html('DMN error - Order gets False.');
				exit;
			}
			
			$this->change_order_status($order, $order_id, $req_status, $transactionType,
				array(
					'resp_id'       => $clientUniqueId,
					'totalAmount'   => $this->get_param('totalAmount')
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
		$order            = wc_get_order($order_id);
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
				. 'scOrderAmount = ' . esc_js(round(floatval($cart_totals['total']), 2)) . '; '
			. '</script>';
	}
	
	/**
	 * Function create_void
	 * 
	 * @param int $order_id
	 * @param string $action
	 */
	public function create_settle_void( $order_id, $action ) {
		$ord_status = 1;
		$time		= gmdate('YmdHis');
		
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
				'sourceApplication'		=> SC_SOURCE_APPLICATION,
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
		$time = gmdate('YmdHis');
		
		$oo_endpoint_url = 'yes' == $this->test ? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;

		$oo_params = array(
			'merchantId'        => $this->merchantId,
			'merchantSiteId'    => $this->merchantSiteId,
			'clientRequestId'   => $time . '_' . uniqid(),
			'amount'            => $amount,
			'currency'          => get_woocommerce_currency(),
			'timeStamp'         => $time,
			'urlDetails'        => array(
		//              'successUrl'        => $this->get_return_url(),
		//              'failureUrl'        => $this->get_return_url(),
		//              'pendingUrl'        => $this->get_return_url(),
				'notificationUrl'   => $this->set_notify_url(),
			),
			'deviceDetails'     => SC_HELPER::get_device_details(),
			'userTokenId'       => $user_mail,
			'billingAddress'    => array(
				'country'	=> $country,
				'email'		=> $user_mail,
			),
			'webMasterId'       => $this->webMasterId,
			'paymentOption'		=> array('card' => array('threeD' => array('isDynamic3D' => 1))),
			'transactionType'	=> $this->payment_action,
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
	
	private function sc_get_order_data( $TransactionID) {
		global $wpdb;
		
		$res = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s ;",
				SC_TRANS_ID,
				$TransactionID
			)
		);
				
		SC_HELPER::create_log($wpdb->last_query, 'Last query:');
		SC_HELPER::create_log($wpdb->last_result, 'Last result:');
				
		return $res;
	}
	
	/**
	 * Function change_order_status
	 * Change the status of the order.
	 *
	 * @param object $order
	 * @param int $order_id
	 * @param string $status
	 * @param string $transactionType
	 * @param array $res_args - we must use $res_args instead $_REQUEST, if not empty
	 */
	private function change_order_status( $order, $order_id, $status, $transactionType, $res_args = array()) {
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
				
				if (in_array($transactionType, array('Credit', 'Refund'), true)) {
					$order->add_order_note(
						__('DMN message: Your Refund #' . $res_args['resp_id']
							. ' was successful.<br/>Refund Transaction ID is: ', 'sc')
							. $this->get_param('TransactionID') . '.'
					);
					
					// set flag that order has some refunds
					$order->update_meta_data(SC_ORDER_HAS_REFUND, 1);
					$order->save();
					break;
				}
				
				$message = 'The amount has been authorized and captured by ' . SC_GATEWAY_TITLE . '. ';
				
				if ('Auth' === $transactionType) {
					$message = 'The amount has been authorized and wait to for Settle. ';
				} elseif ('Settle' === $transactionType) {
					$message = 'The amount has been captured by ' . SC_GATEWAY_TITLE . '. ';
				}
				
				$message .= '<br/>PPP_TransactionID = ' . $this->get_param('PPP_TransactionID')
					. ',<br/>Status = ' . $status . ',<br/>TransactionType = ' . $transactionType
					. ',<br/>TransactionID = ' . $this->get_param('TransactionID');

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
				$reason = ',<br/>Reason = ';
				if ('' != $this->get_param('reason')) {
					$reason .= $this->get_param('reason');
				} elseif ('' != $this->get_param('Reason')) {
					$reason .= $this->get_param('Reason');
				}
				
				$message = 'Payment failed.<br/>PPP_TransactionID = ' . $this->get_param('PPP_TransactionID')
					. ',<br/>Status = ' . $status . ',<br/>Error code = ' . $this->get_param('ErrCode')
					. ',<br/>Message = ' . $this->get_param('message') . $reason
					. ',<br/>TransactionType = ' . $transactionType
					. ',<br/>TransactionID = ' . $this->get_param('TransactionID');
				
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
		if (!empty($auth_code)) {
			$order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
		}

		$transaction_id = $this->get_param('TransactionID');
		if (!empty($transaction_id)) {
			$order->update_meta_data(SC_TRANS_ID, $transaction_id);
		}
		
		$pm = $this->get_param('payment_method');
		if (!empty($pm)) {
			$order->update_meta_data(SC_PAYMENT_METHOD, $pm);
		}

		$tr_type = $this->get_param('transactionType');
		if (!empty($tr_type)) {
			$order->update_meta_data(SC_RESP_TRANS_TYPE, $tr_type);
		}
		
		SC_HELPER::create_log(array(
			'ord id' => $order->get_id(),
			'AuthCode' => $auth_code,
			'TransactionID' => $transaction_id,
			'payment_method' => $pm,
			'transactionType' => $tr_type,
		), 'Order to update fields');
		
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
