<?php
/**
 * Plugin Name: Mastercard Payment Gateway Services
 * Description: Accept payments on your WooCommerce store using Mastercard Payment Gateway Services.
 * Plugin URI: https://github.com/Mastercard-Gateway/gateway-woocommerce-module/
 * Author: OnTap Networks Ltd.
 * Author URI: https://www.ontapgroup.com/
 * Version: 1.3.0
 */

/**
 * Copyright (c) 2019-2021 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

class WC_Mastercard {
	/**
	 * @var WC_Mastercard
	 */
	private static $instance;

	/**
	 * WC_Mastercard constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		define( 'MPGS_ISO3_COUNTRIES', include plugin_basename( '/iso3.php' ) );
		require_once plugin_basename( '/vendor/autoload.php' );
		require_once plugin_basename( '/includes/class-gateway.php' );

		load_plugin_textdomain( 'mastercard', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) . 'i18n/' );

		add_filter( 'woocommerce_order_actions', function ( $actions ) {
			$order = new WC_Order( $_REQUEST['post'] );
			if ( $order->get_payment_method() == Mastercard_Gateway::ID ) {
				if ( ! $order->get_meta( '_mpgs_order_captured' ) ) {
					if ( $order->get_status() == 'processing' ) {
						$actions['mpgs_capture_order'] = __( 'Capture payment', 'mastercard' );
					}
				}
			}

			return $actions;
		} );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'mastercard/v1', '/checkoutSession/(?P<id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_route_forward' ],
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						}
					)
				)
			) );
			register_rest_route( 'mastercard/v1', '/session/(?P<id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_route_forward' ],
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						}
					)
				)
			) );
			register_rest_route( 'mastercard/v1', '/savePayment/(?P<id>\d+)', array(
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_route_forward' ],
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						}
					)
				)
			) );
			register_rest_route( 'mastercard/v1', '/webhook', array(
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_route_forward' ],
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			) );
		} );
	}

	/**
	 * @param $request
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return true;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 * @throws Mastercard_GatewayResponseException
	 * @throws \Http\Client\Exception
	 */
	public function rest_route_forward( $request ) {
		$gateway = new Mastercard_Gateway();

		return $gateway->rest_route_processor( $request->get_route(), $request );
	}

	/**
	 * @param array $methods
	 *
	 * @return array
	 */
	public function add_gateways( $methods ) {
		$methods[] = 'Mastercard_Gateway';

		return $methods;
	}

	/**
	 * @return WC_Mastercard
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @return void
	 */
	public static function activation_hook() {
		$environment_warning = self::get_env_warning();
		if ( $environment_warning ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( $environment_warning );
		}
	}

	/**
	 * @return bool
	 */
	public static function get_env_warning() {
		// @todo: Add some php version and php library checks here
		return false;
	}

	/**
	 * @param array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="https://www.ontapgroup.com/uk/helpdesk/ticket/">' . __( 'Support', 'mastercard' ) . '</a>' );
		array_unshift( $links, '<a href="http://ontap.wiki/woocommerce-mastercard-payment-gateway-services">' . __( 'Docs', 'mastercard' ) . '</a>' );
		array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mpgs_gateway' ) . '">' . __( 'Settings', 'mastercard' ) . '</a>' );

		return $links;
	}
}

WC_Mastercard::get_instance();
register_activation_hook( __FILE__, array( 'WC_Mastercard', 'activation_hook' ) );
