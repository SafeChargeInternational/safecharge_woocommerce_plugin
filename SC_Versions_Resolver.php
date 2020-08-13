<?php
/*
 * A try to get what we need when work with different versions of WooCommers.
 * For the moment the plugin lowest support version is 3.0.0
 *
 * SafeCharge
 * 2018-08
 */
class SC_Versions_Resolver {


	/**
	 * Function process_admin_options
	 * Add hook to save admin options
	 *
	 * @param WC_SC $_this - object of type WC_SC
	 */
	public static function process_admin_options( $_this) {
		add_action(
			'woocommerce_update_options_payment_gateways_' . $_this->id,
			array( &$_this, 'process_admin_options' )
		);
	}
	
	/**
	 * Function get_order_data
	 * Extract the data from the order
	 *
	 * @param WC_Order $order
	 * @param string $key - a key name to extract
	 */
	public static function get_order_data( $order, $key = 'completed_date') {
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
	 * Function get_client_country
	 *
	 * @param WC_Customer $client
	 * @return string
	 */
	public static function get_client_country( $client) {
		if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>')) {
			return $client->get_billing_country();
		} else {
			return $client->get_country();
		}
	}
	
	public static function get_shipping( $order) {
		if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>')) {
			return $order->get_shipping_total();
		} else {
			return $order->get_total_shipping();
		}
	}
}
