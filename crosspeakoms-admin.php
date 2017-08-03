<?php

/**
 * Integrates the settings into the WooCommerece Integrations section
 *
 * @link       http://www.crosspeakoms.com/
 * @since      1.0.0
 * @package    CrossPeak_OMS
 * @subpackage CrossPeak_OMS_Admin
 */


// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class CrossPeak_OMS_Admin extends WC_Integration {

	/**
	 * Instance of this class.
	 *
	 * @since	 1.0.0
	 *
	 * @var		object
	 */
	protected static $instance = null;

	/**
	 * Plugin object
	 *
	 * @since	 1.0.0
	 *
	 * @var		object
	 */
	public $plugin;

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
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks.
	 *
	 * @since	1.0.0
	 */
	public function __construct() {
		$this->plugin = CrossPeak_OMS::get_instance();
		$this->id = $this->plugin->plugin_name;
		$this->version = $this->plugin->version;

		$this->method_title          = __( 'CrossPeak OMS', $this->id );
		$this->method_description    = __( 'Integrates your WooCommerce data with CrossPeak OMS.', $this->id );

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();
		$this->plugin->set_options( $this->init_options() );

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options') );

	}

	/**
	 * Loads all of our options for this plugin
	 * @return array An array of options that can be passed to other classes
	 */
	public function init_options() {
		$options = array(
			'url',
			'api_token',
			'validate_addresses'
		);
		$constructor = array();
		foreach ( $options as $option ) {
			$constructor[ $option ] = $this->$option = $this->get_option( $option );
		}
		return $constructor;
	}

	/**
	 * Tells WooCommerce which settings to display under the "integration" tab
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'url' => array(
				'title'       => __( 'CrossPeak OMS URL', $this->id ),
				'description' => __( 'The url of the API you are connecting to ( e.g. https://example.crosspeakoms.com/ )', $this->id ),
				'type'        => 'text',
				'default'     => 'https://example.crosspeakoms.com/'
			),
			'api_token' => array(
				'title' 			=> __( 'API Token', $this->id ),
				'description' 		=> __( 'Your API Token that you retrieved from ...', $this->id ),
				'type' 				=> 'text',
				'default' 			=> ''
			),
			'validate_addresses' => array(
				'title' 			=> __( 'Validate Addresses', $this->id ),
				'description' 		=> __( 'Validate addresses with CrossPeak OMS', $this->id ),
				'type' 				=> 'checkbox',
				'default' 			=> false
			),
		);
	}

}
