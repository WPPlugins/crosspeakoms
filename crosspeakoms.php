<?php

/**
 *
 * @link              http://www.crosspeakoms.com/
 * @since             1.0.0
 * @package           WC_CrossPeak_OMS
 *
 * @wordpress-plugin
 * Plugin Name:       CrossPeak OMS for WooCommerce
 * Plugin URI:        http://www.crosspeakoms.com/
 * Description:       Integrates WooCommerce with CrossPeak OMS
 * Version:           1.2.0
 * Author:            CrossPeak OMS
 * Author URI:        http://www.crosspeakoms.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       crosspeakoms
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


class CrossPeak_OMS {
	/**
	 * Instance of this class.
	 *
	 * @since	 1.0.0
	 *
	 * @var		object
	 */
	protected static $instance = null;

	/**
	 * Plugin Name
	 *
	 * @since	 1.0.0
	 *
	 * @var		string
	 */
	public $plugin_name;

	/**
	 * Version
	 *
	 * @since	 1.0.0
	 *
	 * @var		string
	 */
	public $version;

	/**
	 * Options
	 *
	 * @since	 1.0.0
	 *
	 * @var		array
	 */
	private $options;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks.
	 *
	 * @since	1.0.0
	 */
	public function __construct() {

		$this->plugin_name = 'crosspeakoms';
		$this->version = '1.2.0';

		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) && defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ) {
			require_once ( dirname( __FILE__ ) . '/crosspeakoms-admin.php' );
			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
		}

		// order.created
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'send_order' ) );
		add_action( 'woocommerce_api_create_order', array( $this, 'send_order' ) );
		// order.updated
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'send_order' ) );
		add_action( 'woocommerce_api_edit_order', array( $this, 'send_order' ) );
		add_action( 'woocommerce_order_edit_status', array( $this, 'send_order' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'send_order' ) );
		// order.deleted
		add_action( 'wp_trash_post', array( $this, 'send_order' ) );

		// Post save
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );

		// Product stock
		add_action( 'woocommerce_product_set_stock', array( $this, 'send_product_stock' ) );

		// Add fields to API response
		add_filter( 'woocommerce_api_order_response', array( $this, 'order_api' ), 10, 4 );
		add_filter( 'woocommerce_api_product_response', array( $this, 'product_api' ), 10, 4 );

	}

	/**
	 * Return an instance of this class.
	 *
	 * @since	  1.0.0
	 *
	 * @return	 object	 A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Sets the options from an array
	 *
	 * @since	  1.0.0
	 *
	 * @param $options   array
	 */
	public function set_options( $options ) {
		$this->options = $options;
	}

	/**
	 * Send the order to CrossPeak OMS
	 *
	 * @since     1.0.0
	 *
	 * @param $order_id   int
	 */
	public function send_order( $order_id ) {

		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) ) {
			return;
		}

		$this->load_woo_api();

		$data = $this->send_api_data( 'api/v1/order/create', apply_filters( 'crosspeak_order_update', $this->get_order( $order_id ) ) );

		if ( false !== $data ) {

			update_post_meta( $order_id, 'crosspeak_order_id', $data['order_id'] );
			do_action( 'crosspeak_order_sent', $order_id, $data );

		}
	}

	/**
	 * Send the product to CrossPeak OMS on transition_post_status
	 *
	 * @since     1.0.0
	 *
	 * @param $order_id   int
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {

		// Sanity check for settings
		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) ) {
			return;
		}

		// Only send products that are published of were published
		if ( ( 'publish' === $new_status || 'publish' === $old_status ) && in_array( $post->post_type, array( 'product', 'product_variation' ) ) ) {

			$this->load_woo_api();

			$data = $this->send_api_data( 'api/v1/product/create', apply_filters( 'crosspeak_product_update', WC()->api->WC_API_Products->get_product( $post->ID ) ) );

			if ( false !== $data ) {

				update_post_meta( $post->ID, 'crosspeak_product_id', $data['product_id'] );
				do_action( 'crosspeak_product_sent', $post->ID, $data );

			}
		}

		// Only send coupons that are published of were published
		if ( ( 'publish' === $new_status || 'publish' === $old_status ) && $post->post_type == 'shop_coupon' ) {

			$this->load_woo_api();

			$data = $this->send_api_data( 'api/v1/coupon/create', apply_filters( 'crosspeak_coupon_update', WC()->api->WC_API_Coupons->get_coupon( $post->ID ) ) );

			if ( false !== $data ) {

				update_post_meta( $post->ID, 'crosspeak_coupon_id', $data['coupon_id'] );
				do_action( 'crosspeak_coupon_sent', $post->ID, $data );

			}
		}
	}

	/**
	 * Send the product stock to CrossPeak OMS
	 *
	 * @since     1.0.0
	 *
	 * @param $order_id   int
	 */
	public function send_product_stock( $product ) {

		// Sanity check for settings
		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) ) {
			return;
		}

		$data = $this->send_api_data( 'api/v1/product/stock', apply_filters( 'crosspeak_stock_update', array(
			'product_id' => $product->id,
			'stock' => $product->stock
		) ) );

		if ( false !== $data ) {

			do_action( 'crosspeak_product_stock_sent', $product->id, $data );

		}
	}

	/**
	 * Load Woo API if it hasn't been loaded already.
	 *
	 * @since     1.0.0
	 *
	 */
	public function load_woo_api() {
		if ( ! class_exists( 'WC_API_Exception' ) ) {
			WC()->api->includes();
		}

		if ( ! is_object( WC()->api->WC_API_Orders ) ) {
			WC()->api->register_resources( new WC_API_Server( $GLOBALS['wp']->query_vars['wc-api-route'] ) );
		}
	}

	/**
	 * Add a new integration to WooCommerce.
	 *
	 * @since     1.0.0
	 *
	 * @param  array $integrations WooCommerce integrations.
	 *
	 * @return array
	 */
	public function add_integration( $integrations ) {
		$integrations[] = 'CrossPeak_OMS_Admin';
		return $integrations;
	}


	/**
	 * Saturday Shipping
	 *
	 * @since     1.1.0
	 *
	 * @param  string $postal_code Postal Code
	 *
	 * @return boolean If Saturday shipping is available for the address.
	 */
	public function saturday_shipping( $postal_code ) {

		// Sanity check for settings
		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) ) {
			return true;
		}

		$data = array(
			'context' => 'saturday_shipping',
			'postal_code' => $postal_code,
		);

		$result = $this->send_api_data( 'api/v1/shipping/saturday', apply_filters( 'crosspeak_shipping_rates', $data ) );

		if( empty( $result ) ) {
			return false;
		}
		elseif( $result['success'] === false ) {
			return false;
		}
		else {
			return $result['saturday'];
		}


	}

	/**
	 * Shipping Rates
	 *
	 * @since     1.1.0
	 *
	 * @param  string $method Shipping Method
	 * @param  string $postal_code Postal Code
	 * @param  string $delivery_date Delivery Date
	 *
	 * @return mixed Data returned from endpoint
	 */
	public function shipping_rates( $method, $postal_code, $delivery_date = '' ) {

		// Sanity check for settings
		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) ) {
			return;
		}

		$data = array(
			'context' => 'shipping_rates',
			'method' => $method,
			'postal_code' => $postal_code,
			'delivery_date' => $delivery_date
		);

		return $this->send_api_data( 'api/v1/shipping/rates', apply_filters( 'crosspeak_shipping_rates', $data ) );
	}

	/**
	 * Validate Address with CrossPeak OMS
	 *
	 * @since     1.1.0
	 *
	 * @param  array $data Data to send, values should be
	 *                     company_name
	 *                     address_1
	 *                     address_2
	 *                     city
	 *                     state
	 *                     postcode
	 *
	 * @return mixed Data returned from endpoint
	 */
	public function validate_address( $data ) {

		// Sanity check for settings
		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) || empty( $this->options['validate_addresses'] ) ) {
			return array( 'status' => true ); // Return true because if we arn't validating it should always validate success
		}

		return $this->send_api_data( 'api/v1/address/validate', apply_filters( 'crosspeak_verify_address', $data ) );
	}


	/**
	 * Send API call to CrossPeak OMS
	 *
	 * @since     1.0.0
	 *
	 * @param  string $url API endpoint.
	 * @param  array $data Data to send
	 *
	 * @return mixed Data returned from endpoint
	 */
	private function send_api_data( $url, $data ) {

		// Sanity check for settings
		if ( empty( $this->options['url'] ) || empty( $this->options['api_token'] ) ) {
			return;
		}

		$response = wp_remote_post(
			$this->options['url'] . $url,
			array(
				'method' => 'POST',
				'timeout' => 45, // Time in seconds until a request times out.
				'httpversion' => '1.0',
				'body' => array(
					'api_token' => $this->options['api_token'],
					'source' => get_site_url(),
					'data' => $data
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			return "Something went wrong: $error_message";
		}
		else {
			return json_decode( $response['body'], true );
		}
	}

	/**
	 * Hook into order API to add fields
	 *
	 * @since     1.1.0
	 *
	 * @param $order_data   array
	 * @param $order   WC_Order
	 * @param $fields   array
	 * @param $server   array
	 *
	 * @return $order_data   array
	 */
	public function order_api( $order_data, $order, $fields, $server ) {

		$paymethod = get_post_meta( $order->id, '_payment_method', true );

		if( $paymethod === 'authorize_net_aim' ){
			// Authorize.Net AIM
			$order_data['payment_trans_id'] = get_post_meta( $order->id, '_wc_authorize_net_aim_trans_id', true );
			$order_data['payment_trans_date'] = get_post_meta( $order->id, '_wc_authorize_net_aim_trans_date', true );
			$order_data['payment_charge_captured'] = get_post_meta( $order->id, '_wc_authorize_net_aim_charge_captured', true );
			$order_data['payment_card_type'] = get_post_meta( $order->id, '_wc_authorize_net_aim_card_type', true );
			$order_data['payment_last_four'] = get_post_meta( $order->id, '_wc_authorize_net_aim_account_four', true );

		} else if( $paymethod === 'authorize_net_cim_credit_card' ) {
			// Authorize.Net CIM
			$order_data['payment_trans_id'] = get_post_meta( $order->id, '_wc_authorize_net_cim_credit_card_trans_id', true );
			$order_data['payment_trans_date'] = get_post_meta( $order->id, '_wc_authorize_net_cim_credit_card_trans_date', true );
			$order_data['payment_charge_captured'] = get_post_meta( $order->id, '_wc_authorize_net_cim_credit_card_charge_captured', true );
			$order_data['payment_gateway_account'] = get_post_meta( $order->id, '_wc_authorize_net_cim_credit_card_customer_id', true );
			$order_data['payment_card_type'] = get_post_meta( $order->id, '_wc_authorize_net_cim_credit_card_card_type', true );
			$order_data['payment_last_four'] = get_post_meta( $order->id, '_wc_authorize_net_cim_credit_card_account_four', true );


		} else {
			// Generic Woo Defaults
			$order_data['payment_trans_id'] = get_post_meta( $order->id, '_transaction_id', true );
			$order_data['payment_trans_date'] = get_post_meta( $order->id, '_paid_date', true );
		}

		return $order_data;
	}


	/**
	 * Hook into product API to add fields
	 *
	 * @since     1.0.0
	 *
	 * @param $product_data   array
	 * @param $product   WC_Product
	 * @param $fields   array
	 * @param $server   array
	 *
	 * @return $order_data   array
	 */
	public function product_api( $product_data, $product, $fields, $server ) {

		if ( $product_data['type'] == 'variable' ) {
			foreach ( $product_data['variations'] as $var_key => $product_variation ) {
				$product_data['variations'][ $var_key ]['variation_description'] = get_post_meta( $product_variation['id'], '_variation_description', true );

			}
		}

		return $product_data;
	}


	/**
	 * Get the order for the given ID.
	 *
	 * @since 1.1.0
	 *
	 * @param int $id The order ID.
	 * @param array $fields Request fields.
	 * @param array $filter Request filters.
	 *
	 * @return array
	 */
	public function get_order( $id, $fields = null, $filter = array() ) {

		// Ensure order ID is valid & user has permission to read.
		$id = $this->validate_request( $id, 'shop_order', 'read' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Get the decimal precession.
		$dp         = ( isset( $filter['dp'] ) ? intval( $filter['dp'] ) : 2 );
		$order      = wc_get_order( $id );
		$order_post = get_post( $id );
		$expand     = array();

		if ( ! empty( $filter['expand'] ) ) {
			$expand = explode( ',', $filter['expand'] );
		}

		$order_data = array(
			'id'                        => $order->id,
			'order_number'              => $order->get_order_number(),
			'order_key'                 => $order->order_key,
			'created_at'                => $this->format_datetime( $order_post->post_date_gmt ),
			'updated_at'                => $this->format_datetime( $order_post->post_modified_gmt ),
			'completed_at'              => $this->format_datetime( $order->completed_date, true ),
			'status'                    => $order->get_status(),
			'currency'                  => $order->get_order_currency(),
			'total'                     => wc_format_decimal( $order->get_total(), $dp ),
			'subtotal'                  => wc_format_decimal( $order->get_subtotal(), $dp ),
			'total_line_items_quantity' => $order->get_item_count(),
			'total_tax'                 => wc_format_decimal( $order->get_total_tax(), $dp ),
			'total_shipping'            => wc_format_decimal( $order->get_total_shipping(), $dp ),
			'cart_tax'                  => wc_format_decimal( $order->get_cart_tax(), $dp ),
			'shipping_tax'              => wc_format_decimal( $order->get_shipping_tax(), $dp ),
			'total_discount'            => wc_format_decimal( $order->get_total_discount(), $dp ),
			'shipping_methods'          => $order->get_shipping_method(),
			'payment_details' => array(
				'method_id'    => $order->payment_method,
				'method_title' => $order->payment_method_title,
				'paid'         => isset( $order->paid_date ),
			),
			'billing_address' => array(
				'first_name' => $order->billing_first_name,
				'last_name'  => $order->billing_last_name,
				'company'    => $order->billing_company,
				'address_1'  => $order->billing_address_1,
				'address_2'  => $order->billing_address_2,
				'city'       => $order->billing_city,
				'state'      => $order->billing_state,
				'postcode'   => $order->billing_postcode,
				'country'    => $order->billing_country,
				'email'      => $order->billing_email,
				'phone'      => $order->billing_phone,
			),
			'shipping_address' => array(
				'first_name' => $order->shipping_first_name,
				'last_name'  => $order->shipping_last_name,
				'company'    => $order->shipping_company,
				'address_1'  => $order->shipping_address_1,
				'address_2'  => $order->shipping_address_2,
				'city'       => $order->shipping_city,
				'state'      => $order->shipping_state,
				'postcode'   => $order->shipping_postcode,
				'country'    => $order->shipping_country,
			),
			'note'                      => $order->customer_note,
			'customer_ip'               => $order->customer_ip_address,
			'customer_user_agent'       => $order->customer_user_agent,
			'customer_id'               => $order->get_user_id(),
			'view_order_url'            => $order->get_view_order_url(),
			'line_items'                => array(),
			'shipping_lines'            => array(),
			'tax_lines'                 => array(),
			'fee_lines'                 => array(),
			'coupon_lines'              => array(),
		);

		// Add line items.
		foreach ( $order->get_items() as $item_id => $item ) {
			$product     = $order->get_product_from_item( $item );
			$product_id  = null;
			$product_sku = null;

			// Check if the product exists.
			if ( is_object( $product ) ) {
				$product_id  = ( isset( $product->variation_id ) ) ? $product->variation_id : $product->id;
				$product_sku = $product->get_sku();
			}

			$meta = new WC_Order_Item_Meta( $item, $product );

			$item_meta = array();

			$hideprefix = ( isset( $filter['all_item_meta'] ) && 'true' === $filter['all_item_meta'] ) ? null : '_';

			foreach ( $meta->get_formatted( $hideprefix ) as $meta_key => $formatted_meta ) {
				$item_meta[] = array(
					'key'   => $formatted_meta['key'],
					'label' => $formatted_meta['label'],
					'value' => $formatted_meta['value'],
				);
			}

			$line_item = array(
				'id'           => $item_id,
				'subtotal'     => wc_format_decimal( $order->get_line_subtotal( $item, false, false ), $dp ),
				'subtotal_tax' => wc_format_decimal( $item['line_subtotal_tax'], $dp ),
				'total'        => wc_format_decimal( $order->get_line_total( $item, false, false ), $dp ),
				'total_tax'    => wc_format_decimal( $item['line_tax'], $dp ),
				'price'        => wc_format_decimal( $order->get_item_total( $item, false, false ), $dp ),
				'quantity'     => wc_stock_amount( $item['qty'] ),
				'tax_class'    => ( ! empty( $item['tax_class'] ) ) ? $item['tax_class'] : null,
				'name'         => $item['name'],
				'product_id'   => $product_id,
				'sku'          => $product_sku,
				'meta'         => $item_meta,
			);

			if ( in_array( 'products', $expand ) ) {
				$_product_data = WC()->api->WC_API_Products->get_product( $product_id );

				if ( isset( $_product_data['product'] ) ) {
					$line_item['product_data'] = $_product_data['product'];
				}
			}

			$order_data['line_items'][] = $line_item;
		}

		// Add shipping.
		foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
			$order_data['shipping_lines'][] = array(
				'id'           => $shipping_item_id,
				'method_id'    => $shipping_item['method_id'],
				'method_title' => $shipping_item['name'],
				'total'        => wc_format_decimal( $shipping_item['cost'], $dp ),
			);
		}

		// Add taxes.
		foreach ( $order->get_tax_totals() as $tax_code => $tax ) {
			$tax_line = array(
				'id'       => $tax->id,
				'rate_id'  => $tax->rate_id,
				'code'     => $tax_code,
				'title'    => $tax->label,
				'total'    => wc_format_decimal( $tax->amount, $dp ),
				'compound' => (bool) $tax->is_compound,
			);

			if ( in_array( 'taxes', $expand ) ) {
				$_rate_data = WC()->api->WC_API_Taxes->get_tax( $tax->rate_id );

				if ( isset( $_rate_data['tax'] ) ) {
					$tax_line['rate_data'] = $_rate_data['tax'];
				}
			}

			$order_data['tax_lines'][] = $tax_line;
		}

		// Add fees.
		foreach ( $order->get_fees() as $fee_item_id => $fee_item ) {
			$order_data['fee_lines'][] = array(
				'id'        => $fee_item_id,
				'title'     => $fee_item['name'],
				'tax_class' => ( ! empty( $fee_item['tax_class'] ) ) ? $fee_item['tax_class'] : null,
				'total'     => wc_format_decimal( $order->get_line_total( $fee_item ), $dp ),
				'total_tax' => wc_format_decimal( $order->get_line_tax( $fee_item ), $dp ),
			);
		}

		// Add coupons.
		foreach ( $order->get_items( 'coupon' ) as $coupon_item_id => $coupon_item ) {
			$coupon_line = array(
				'id'     => $coupon_item_id,
				'code'   => $coupon_item['name'],
				'amount' => wc_format_decimal( $coupon_item['discount_amount'], $dp ),
			);

			if ( in_array( 'coupons', $expand ) ) {
				$_coupon_data = WC()->api->WC_API_Coupons->get_coupon_by_code( $coupon_item['name'] );

				if ( isset( $_coupon_data['coupon'] ) ) {
					$coupon_line['coupon_data'] = $_coupon_data['coupon'];
				}
			}

			$order_data['coupon_lines'][] = $coupon_line;
		}



		return array( 'order' => apply_filters( 'woocommerce_api_order_response', $order_data, $order, $fields, $this->server ) );
	}


	/**
	 * Validate the request by checking:
	 *
	 * 1) the ID is a valid integer
	 * 2) the ID returns a valid post object and matches the provided post type
	 * 3) the current user has the proper permissions to read/edit/delete the post
	 *
	 * @since 2.1
	 * @param string|int $id the post ID
	 * @param string $type the post type, either `shop_order`, `shop_coupon`, or `product`
	 * @param string $context the context of the request, either `read`, `edit` or `delete`
	 * @return int|WP_Error valid post ID or WP_Error if any of the checks fails
	 */
	protected function validate_request( $id, $type, $context ) {

		if ( 'shop_order' === $type || 'shop_coupon' === $type || 'shop_webhook' === $type ) {
			$resource_name = str_replace( 'shop_', '', $type );
		} else {
			$resource_name = $type;
		}

		$id = absint( $id );

		// Validate ID
		if ( empty( $id ) ) {
			return new WP_Error( "woocommerce_api_invalid_{$resource_name}_id", sprintf( __( 'Invalid %s ID', 'woocommerce' ), $type ), array( 'status' => 404 ) );
		}

		// Only custom post types have per-post type/permission checks
		if ( 'customer' !== $type ) {

			$post = get_post( $id );

			if ( null === $post ) {
				return new WP_Error( "woocommerce_api_no_{$resource_name}_found", sprintf( __( 'No %s found with the ID equal to %s', 'woocommerce' ), $resource_name, $id ), array( 'status' => 404 ) );
			}

			// For checking permissions, product variations are the same as the product post type
			$post_type = ( 'product_variation' === $post->post_type ) ? 'product' : $post->post_type;

			// Validate post type
			if ( $type !== $post_type ) {
				return new WP_Error( "woocommerce_api_invalid_{$resource_name}", sprintf( __( 'Invalid %s', 'woocommerce' ), $resource_name ), array( 'status' => 404 ) );
			}
		}

		return $id;
	}


	/**
	 * Format a unix timestamp or MySQL datetime into an RFC3339 datetime
	 *
	 * @since 2.1
	 * @param int|string $timestamp unix timestamp or MySQL datetime
	 * @param bool $convert_to_utc
	 * @return string RFC3339 datetime
	 */
	public function format_datetime( $timestamp, $convert_to_utc = false ) {

		if ( $convert_to_utc ) {
			$timezone = new DateTimeZone( wc_timezone_string() );
		} else {
			$timezone = new DateTimeZone( 'UTC' );
		}

		try {

			if ( is_numeric( $timestamp ) ) {
				$date = new DateTime( "@{$timestamp}" );
			} else {
				$date = new DateTime( $timestamp, $timezone );
			}

			// convert to UTC by adjusting the time based on the offset of the site's timezone
			if ( $convert_to_utc ) {
				$date->modify( -1 * $date->getOffset() . ' seconds' );
			}

		} catch ( Exception $e ) {

			$date = new DateTime( '@0' );
		}

		return $date->format( 'Y-m-d\TH:i:s\Z' );
	}

}

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
add_action('plugins_loaded', array( 'CrossPeak_OMS', 'get_instance' ) );
