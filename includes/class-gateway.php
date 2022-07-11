<?php
/**
 * Copyright (c) 2019-2022 Mastercard
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

define( 'MPGS_MODULE_VERSION', '1.3.0' );

require_once dirname( __FILE__ ) . '/class-checkout-builder.php';
require_once dirname( __FILE__ ) . '/class-gateway-service.php';
require_once dirname( __FILE__ ) . '/class-payment-gateway-cc.php';

class Mastercard_Gateway extends WC_Payment_Gateway {

	const ID = 'mpgs_gateway';

	const MPGS_API_VERSION = 'version/63';
	const MPGS_API_VERSION_NUM = '63';

	const MPGS_LEGACY_API_VERSION = 'version/61';
	const MPGS_LEGACY_API_VERSION_NUM = '61';

	const HOSTED_SESSION = 'hostedsession';
	const HOSTED_CHECKOUT = 'newhostedcheckout';
	const LEGACY_HOSTED_CHECKOUT = 'hostedcheckout';

	const HC_TYPE_REDIRECT = 'redirect';
	const HC_TYPE_MODAL = 'modal';
	const HC_TYPE_EMBEDDED = 'embedded';

	const API_EU = 'eu-gateway.mastercard.com';
	const API_AS = 'ap-gateway.mastercard.com';
	const API_NA = 'na-gateway.mastercard.com';
	const API_CUSTOM = 'custom';

	const TXN_MODE_PURCHASE = 'capture';
	const TXN_MODE_AUTH_CAPTURE = 'authorize';

	const THREED_DISABLED = 'no';
	const THREED_V1 = 'yes'; // Backward compatibility with checkbox value
	const THREED_V2 = '2';

	/**
	 * @var string
	 */
	protected $order_prefix;

	/**
	 * @var bool
	 */
	protected $sandbox;

	/**
	 * @var string
	 */
	protected $username;

	/**
	 * @var string
	 */
	protected $password;

	/**
	 * @var string
	 */
	protected $gateway_url;

	/**
	 * @var Mastercard_GatewayService
	 */
	protected $service;

	/**
	 * @var string
	 */
	protected $hc_interaction;

	/**
	 * @var string
	 *
	 * @todo Remove after removal of Legacy Hosted Checkout
	 */
	protected $hc_type;

	/**
	 * @var bool
	 */
	protected $capture;

	/**
	 * @var string
	 */
	protected $method;

	/**
	 * @var bool
	 */
	protected $threedsecure_v1;

	/**
	 * @var bool
	 */
	protected $threedsecure_v2;

	/**
	 * @var bool
	 */
	protected $saved_cards;

	/**
	 * Mastercard_Gateway constructor.
	 * @throws Exception
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->title              = __( 'Mastercard Payment Gateway Services', 'mastercard' );
		$this->method_title       = __( 'Mastercard Payment Gateway Services', 'mastercard' );
		$this->has_fields         = true;
		$this->method_description = __( 'Accept payments on your WooCommerce store using Mastercard Payment Gateway Services.',
			'mastercard' );

		$this->init_form_fields();
		$this->init_settings();

		$this->order_prefix    = $this->get_option( 'order_prefix' );
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled', false );
		$this->hc_type         = $this->get_option( 'hc_type', self::HC_TYPE_MODAL );
		$this->hc_interaction  = $this->get_option( 'hc_interaction', self::HC_TYPE_EMBEDDED );
		$this->capture         = $this->get_option( 'txn_mode', self::TXN_MODE_PURCHASE ) === self::TXN_MODE_PURCHASE;
		$this->threedsecure_v1 = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V1;
		$this->threedsecure_v2 = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V2;
		$this->method          = $this->get_option( 'method', self::HOSTED_CHECKOUT );
		$this->saved_cards     = $this->get_option( 'saved_cards', 'yes' ) == 'yes';
		$this->supports        = array(
			'products',
			'refunds',
			'tokenization',
		);

		$this->service = $this->init_service();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_order_action_mpgs_capture_order', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_mastercard_gateway', array( $this, 'return_handler' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * @return Mastercard_GatewayService
	 * @throws Exception
	 */
	protected function init_service() {
		$this->sandbox  = $this->get_option( 'sandbox', false );
		$this->username = $this->sandbox == 'no' ? $this->get_option( 'username' ) : $this->get_option( 'sandbox_username' );
		$this->password = $this->sandbox == 'no' ? $this->get_option( 'password' ) : $this->get_option( 'sandbox_password' );

		$loggingLevel = $this->get_debug_logging_enabled()
			? \Monolog\Logger::DEBUG
			: \Monolog\Logger::ERROR;

		return new Mastercard_GatewayService(
			$this->get_gateway_url(),
			$this->get_api_version(),
			$this->username,
			$this->password,
			$this->get_webhook_url(),
			$loggingLevel
		);
	}

	/**
	 * @return bool
	 */
	protected function is_legacy_hosted_checkout() {
		$method = $this->get_option( 'method', self::HOSTED_CHECKOUT );

		return $method === 'hostedcheckout';
	}

	/**
	 * @return bool
	 */
	protected function get_debug_logging_enabled() {
		if ( $this->sandbox === 'yes' ) {
			return $this->get_option( 'debug', false ) === 'yes';
		}

		return false;
	}

	/**
	 * @return string
	 */
	protected function get_gateway_url() {
		$gateway_url = $this->get_option( 'gateway_url', self::API_EU );
		if ( $gateway_url === self::API_CUSTOM ) {
			$gateway_url = $this->get_option( 'custom_gateway_url' );
		}

		return $gateway_url;
	}

	/**
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		try {
			$service = $this->init_service();
			$service->paymentOptionsInquiry();
		} catch ( Exception $e ) {
			$this->add_error(
				sprintf( __( 'Error communicating with payment gateway API: "%s"', 'mastercard' ), $e->getMessage() )
			);
		}

		return $saved;
	}

	/**
	 * @return void
	 */
	public function admin_scripts() {
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}
		wp_enqueue_script( 'woocommerce_mastercard_admin', plugins_url( 'assets/js/mastercard-admin.js', __FILE__ ),
			array(), MPGS_MODULE_VERSION, true );
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	public function process_capture() {
		$order = new WC_Order( $_REQUEST['post_ID'] );
		if ( $order->get_payment_method() != $this->id ) {
			throw new Exception( 'Wrong payment method' );
		}
		if ( $order->get_status() != 'processing' ) {
			throw new Exception( 'Wrong order status, must be \'processing\'' );
		}
		if ( $order->get_meta( '_mpgs_order_captured' ) ) {
			throw new Exception( 'Order already captured' );
		}

		$result = $this->service->captureTxn(
			$this->add_order_prefix( $order->get_id() ),
			time(),
			(float) $order->get_total(),
			$order->get_currency()
		);

		$txn = $result['transaction'];
		$order->add_order_note( sprintf( __( 'Mastercard payment CAPTURED (ID: %s, Auth Code: %s)', 'mastercard' ),
			$txn['id'], $txn['authorizationCode'] ) );

		$order->update_meta_data( '_mpgs_order_captured', true );
		$order->save_meta_data();

		wp_redirect( wp_get_referer() );
	}

	/**
	 * admin_notices
	 */
	public function admin_notices() {
		if ( ! $this->enabled ) {
			return;
		}

		if ( ! $this->username || ! $this->password ) {
			echo '<div class="error"><p>' . __( 'API credentials are not valid. To activate the payment methods please your details to the forms below.' ) . '</p></div>';
		}

		$this->display_errors();
	}

	/**
	 * @param int $order_id
	 * @param float|null $amount
	 * @param string $reason
	 *
	 * @return bool
	 * @throws \Http\Client\Exception
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = new WC_Order( $order_id );
		$result = $this->service->refund(
			$this->add_order_prefix( $order_id ),
			(string) time(),
			$amount,
			$order->get_currency()
		);
		$order->add_order_note( sprintf(
			__( 'Mastercard registered refund %s %s (ID: %s)', 'mastercard' ),
			$result['transaction']['amount'],
			$result['transaction']['currency'],
			$result['transaction']['id']
		) );

		return true;
	}

	/**
	 * @return array|void
	 * @throws \Http\Client\Exception
	 */
	public function return_handler() {
		@ob_clean();
		header( 'HTTP/1.1 200 OK' );

		$three_ds_txn_id = null;
		if ( isset( $_REQUEST['response_gatewayRecommendation'] ) ) {
			if ( $_REQUEST['response_gatewayRecommendation'] === 'PROCEED' ) {
				$three_ds_txn_id = $_REQUEST['transaction_id'];
			} else {
				$order = new WC_Order( $this->remove_order_prefix( $_REQUEST['order_id'] ) );
				$order->update_status( 'failed',
					__( '3DS authorization was not provided. Payment declined.', 'mastercard' ) );
				wc_add_notice( __( '3DS authorization was not provided. Payment declined.', 'mastercard' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}
		}

		if ( $this->method === self::HOSTED_SESSION ) {
			$this->process_hosted_session_payment( $three_ds_txn_id );
		}

		/**
		 * @todo Remove branching after Legacy Hosted Checkout removal
		 */
		if ( in_array( $this->method, array( self::HOSTED_CHECKOUT, self::LEGACY_HOSTED_CHECKOUT ), true ) ) {
			$this->process_hosted_checkout_payment();
		}
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_checkout_payment() {
		$order_id          = $this->remove_order_prefix( $_REQUEST['order_id'] );
		$result_indicator  = $_REQUEST['resultIndicator'];
		$order             = new WC_Order( $order_id );
		$success_indicator = $order->get_meta( '_mpgs_success_indicator' );

		try {
			if ( $success_indicator !== $result_indicator ) {
				throw new Exception( 'Result indicator mismatch' );
			}

			$mpgs_order = $this->service->retrieveOrder( $this->add_order_prefix( $order_id ) );
			if ( $mpgs_order['result'] !== 'SUCCESS' ) {
				throw new Exception( 'Payment was declined.' );
			}

			$txn = $mpgs_order['transaction'][0];
			$this->process_wc_order( $order, $mpgs_order, $txn );

			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	/**
	 * @return array
	 */
	protected function get_token_from_request() {
		$token_key = $this->get_token_key();
		$tokenId   = null;
		if ( isset( $_REQUEST[ $token_key ] ) ) {
			$token_id = $_REQUEST[ $token_key ];
		}
		$tokens = $this->get_tokens();
		if ( $token_id && isset( $tokens[ $token_id ] ) ) {
			return array(
				'token' => $tokens[ $token_id ]->get_token()
			);
		}

		return array();
	}

	/**
	 * @return string
	 */
	protected function get_token_key() {
		return 'wc-' . $this->id . '-payment-token';
	}

	/**
	 * @param string|null $three_ds_txn_id
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_session_payment( $three_ds_txn_id = null ) {
		$order_id        = $this->remove_order_prefix( $_REQUEST['order_id'] );
		$session_id      = $_REQUEST['session_id'];
		$session_version = isset( $_REQUEST['session_version'] ) ? $_REQUEST['session_version'] : null;

		$session = array(
			'id' => $session_id
		);

		if ( $session_version === null ) {
			$session['version'] = $session_version;
		}

		$order              = new WC_Order( $order_id );
		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) ? $_REQUEST['check_3ds_enrollment'] == '1' : false;
		$process_acl_result = isset( $_REQUEST['process_acs_result'] ) ? $_REQUEST['process_acs_result'] == '1' : false;
		$tds_id             = null;

		if ( $check_3ds ) {
			$data      = array(
				'authenticationRedirect' => array(
					'pageGenerationMode' => 'CUSTOMIZED',
					'responseUrl'        => $this->get_payment_return_url( $order_id, array(
						'status' => '3ds_done'
					) )
				)
			);
			$session   = array(
				'id' => $session_id
			);
			$orderData = array(
				'amount'   => (float) $order->get_total(),
				'currency' => $order->get_currency()
			);

			$source_of_funds = $this->get_token_from_request();

			$response = $this->service->check3dsEnrollment( $data, $orderData, $session, $source_of_funds );

			if ( $response['response']['gatewayRecommendation'] !== 'PROCEED' ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard' ) );
				wc_add_notice( __( 'Payment was declined.', 'mastercard' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$tds_auth  = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();

				set_query_var( 'authenticationRedirect', $tds_auth );
				set_query_var( 'returnUrl', $this->get_payment_return_url( $order_id, array(
					'3DSecureId'         => $response['3DSecureId'],
					'process_acs_result' => '1',
					'session_id'         => $session_id,
					'session_version'    => $session_version,
					$token_key           => isset( $_REQUEST[ $token_key ] ) ? $_REQUEST[ $token_key ] : null
				) ) );

				set_query_var( 'order', $order );
				set_query_var( 'gateway', $this );

				load_template( dirname( __FILE__ ) . '/../templates/3dsecure/form.php' );
				exit();
			}

			$this->pay( $session, $order );
		}

		if ( $process_acl_result ) {
			$pa_res = $_POST['PaRes'];
			$tds_id = $_REQUEST['3DSecureId'];

			$response = $this->service->process3dsResult( $tds_id, $pa_res );

			if ( $response['response']['gatewayRecommendation'] !== 'PROCEED' ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard' ) );
				wc_add_notice( __( 'Payment was declined.', 'mastercard' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $tds_id );
		}

		if ( $three_ds_txn_id !== null ) {
			$this->pay( $session, $order, $three_ds_txn_id );
		}

		if ( ! $check_3ds && ! $process_acl_result && ! $this->threedsecure_v1 ) {
			$this->pay( $session, $order );
		}

		$order->update_status( 'failed', __( 'Unexpected payment condition error.', 'mastercard' ) );
		wc_add_notice( __( 'Unexpected payment condition error.', 'mastercard' ), 'error' );
		wp_redirect( wc_get_checkout_url() );
		exit();
	}

	/**
	 * @param array $session
	 * @param WC_Order $order
	 * @param string|null $tds_id
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function pay( $session, $order, $tds_id = null ) {
		if ( $this->is_order_paid( $order ) ) {
			wp_redirect( $this->get_return_url( $order ) );
			exit();
		}

		try {
			$txn_id = $this->generate_txn_id_for_order( $order );

			$auth = null;
			if ( $this->threedsecure_v2 ) {
				$auth   = [
					'transactionId' => $tds_id
				];
				$tds_id = null;
			}

			$order_builder = new Mastercard_CheckoutBuilder( $order );
			if ( $this->capture ) {
				$mpgs_txn = $this->service->pay(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$auth,
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			} else {
				$mpgs_txn = $this->service->authorize(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$auth,
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			}

			if ( $mpgs_txn['result'] !== 'SUCCESS' ) {
				throw new Exception( __( 'Payment was declined.', 'mastercard' ) );
			}

			$this->process_wc_order( $order, $mpgs_txn['order'], $mpgs_txn );

			if ( $this->saved_cards && $order->get_meta( '_save_card' ) ) {
				$this->process_saved_cards( $session, $order->get_user_id( 'system' ) );
			}

			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	/**
	 * @param array $session
	 * @param mixed $user_id
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function process_saved_cards( $session, $user_id ) {
		$response = $this->service->createCardToken( $session['id'] );

		if ( ! isset( $response['token'] ) || empty( $response['token'] ) ) {
			throw new Exception( 'Token not present in reponse' );
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( $response['token'] );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( $response['sourceOfFunds']['provided']['card']['brand'] );

		$last4 = substr(
			$response['sourceOfFunds']['provided']['card']['number'],
			- 4
		);
		$token->set_last4( $last4 );

		$m = [];
		preg_match( '/^(\d{2})(\d{2})$/', $response['sourceOfFunds']['provided']['card']['expiry'], $m );

		$token->set_expiry_month( $m[1] );
		$token->set_expiry_year( '20' . $m[2] );
		$token->set_user_id( $user_id );

		$token->save();
	}

	/**
	 * @param WC_Order $order
	 * @param array $order_data
	 * @param array $txn_data
	 *
	 * @throws Exception
	 */
	protected function process_wc_order( $order, $order_data, $txn_data ) {
		$this->validate_order( $order, $order_data );

		$captured = $order_data['status'] === 'CAPTURED';
		$order->add_meta_data( '_mpgs_order_captured', $captured );
		$order->add_meta_data( '_mpgs_order_paid', 1 );

		$order->payment_complete( $txn_data['transaction']['id'] );

		if ( $captured ) {
			$order->add_order_note(
				sprintf(
					__( 'Mastercard payment CAPTURED (ID: %s, Auth Code: %s)', 'mastercard' ),
					$txn_data['transaction']['id'],
					$txn_data['transaction']['authorizationCode']
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					__( 'Mastercard payment AUTHORIZED (ID: %s, Auth Code: %s)', 'mastercard' ),
					$txn_data['transaction']['id'],
					$txn_data['transaction']['authorizationCode']
				)
			);
		}
	}

	/**
	 * @param WC_Order $order
	 * @param array $mpgs_order
	 *
	 * @return bool
	 * @throws Exception
	 */
	protected function validate_order( $order, $mpgs_order ) {
		if ( $order->get_currency() !== $mpgs_order['currency'] ) {
			throw new Exception( 'Currency mismatch' );
		}

		if ( (float) $order->get_total() !== (float) $mpgs_order['amount'] ) {
			throw new Exception( 'Amount mismatch' );
		}

		return true;
	}

	/**
	 * @param int $order_id
	 * @param array $params
	 *
	 * @return string
	 */
	public function get_payment_return_url( $order_id, $params = array() ) {
		$params = array_merge( array(
			'order_id' => $order_id
		), $params );

		return add_query_arg( 'wc-api', self::class, home_url( '/' ) ) . '&' . http_build_query( $params );
	}

	/**
	 * @return bool
	 */
	public function use_embedded() {
		return $this->hc_interaction === self::HC_TYPE_EMBEDDED;
	}

	/**
	 * @return bool
	 *
	 * @todo remove after removal of Legacy Hosted Checkout
	 */
	public function use_modal() {
		return $this->hc_type === self::HC_TYPE_MODAL;
	}

	/**
	 * @return bool
	 */
	public function use_3dsecure_v1() {
		return $this->threedsecure_v1;
	}

	/**
	 * @return bool
	 */
	public function use_3dsecure_v2() {
		return $this->threedsecure_v2;
	}

	/**
	 * @return string
	 */
	public function get_merchant_id() {
		return $this->username;
	}

	/**
	 * @return int
	 *
	 * @todo remove branching with Legacy Hosted Checkout
	 */
	public function get_api_version_num() {
		if ( $this->is_legacy_hosted_checkout() ) {
			return (int) self::MPGS_LEGACY_API_VERSION_NUM;
		} else {
			return (int) self::MPGS_API_VERSION_NUM;
		}
	}

	/**
	 * @return string
	 *
	 * @todo remove branching with Legacy Hosted Checkout
	 */
	public function get_api_version() {
		if ( $this->is_legacy_hosted_checkout() ) {
			return self::MPGS_LEGACY_API_VERSION;
		} else {
			return self::MPGS_API_VERSION;
		}
	}

	/**
	 * @param int $forOrderId
	 *
	 * @return string
	 */
	public function get_create_checkout_session_url( $forOrderId ) {
		return rest_url( "mastercard/v1/checkoutSession/{$forOrderId}/" );
	}

	/**
	 * @param int $forOrderId
	 *
	 * @return string
	 */
	public function get_create_session_url( $forOrderId ) {
		return rest_url( "mastercard/v1/session/{$forOrderId}/" );
	}

	/**
	 * @param int $forOrderId
	 *
	 * @return string
	 */
	public function get_save_payment_url( $forOrderId ) {
		return rest_url( "mastercard/v1/savePayment/{$forOrderId}/" );
	}

	/**
	 * @return string
	 */
	public function get_webhook_url() {
		return rest_url( "mastercard/v1/webhook/" );
	}

	/**
	 * @param $route
	 * @param WP_REST_Request $request
	 *
	 * @return array|null
	 * @throws Mastercard_GatewayResponseException
	 * @throws \Http\Client\Exception
	 */
	public function rest_route_processor( $route, $request ) {
		$result = null;
		switch ( $route ) {
			case ( (bool) preg_match( '~/mastercard/v1/checkoutSession/\d+~', $route ) ):
				$order     = new WC_Order( $request->get_param( 'id' ) );
				$returnUrl = $this->get_payment_return_url( $order->get_id() );

				$order_builder = new Mastercard_CheckoutBuilder( $order );

				if ( $this->is_legacy_hosted_checkout() ) {
					$result = $this->service->createCheckoutSession(
						$order_builder->getHostedCheckoutOrder(),
						$order_builder->getLegacyInteraction( $this->capture, $returnUrl ),
						$order_builder->getCustomer(),
						$order_builder->getBilling(),
						$order_builder->getShipping()
					);
				} else {
					$result = $this->service->initiateCheckout(
						$order_builder->getHostedCheckoutOrder(),
						$order_builder->getInteraction( $this->capture, $returnUrl ),
						$order_builder->getCustomer(),
						$order_builder->getBilling(),
						$order_builder->getShipping()
					);
				}

				if ( $order->meta_exists( '_mpgs_success_indicator' ) ) {
					$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );
				} else {
					$order->add_meta_data( '_mpgs_success_indicator', $result['successIndicator'], true );
				}
				$order->save_meta_data();
				break;

			case ( (bool) preg_match( '~/mastercard/v1/savePayment/\d+~', $route ) ):
				$order = new WC_Order( $request->get_param( 'id' ) );

				$save_new_card = $request->get_param( 'save_new_card' ) === 'true';
				if ( $save_new_card ) {
					$order->update_meta_data( '_save_card', true );
					$order->save_meta_data();
				}

				$auth = array();
				if ( $this->threedsecure_v1 ) {
					$auth = array(
						'acceptVersions' => '3DS1'
					);
				}

				if ( $this->threedsecure_v2 ) {
					$auth = array(
						'channel' => 'PAYER_BROWSER',
						'purpose' => 'PAYMENT_TRANSACTION',
					);
				}

				$session_id = $order->get_meta( '_mpgs_session_id' );

				$order_builder = new Mastercard_CheckoutBuilder( $order );
				$result        = $this->service->update_session(
					$session_id,
					$order_builder->getHostedCheckoutOrder(),
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping(),
					$auth,
					$this->get_token_from_request()
				);

				if ( $order->meta_exists( '_mpgs_success_indicator' ) ) {
					$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );
				} else {
					$order->add_meta_data( '_mpgs_success_indicator', $result['successIndicator'], true );
				}
				$order->save_meta_data();
				break;

			case ( (bool) preg_match( '~/mastercard/v1/session/\d+~', $route ) ):
				$order  = new WC_Order( $request->get_param( 'id' ) );
				$result = $this->service->create_session();

				if ( $order->meta_exists( '_mpgs_session_id' ) ) {
					$order->update_meta_data( '_mpgs_session_id', $result['session']['id'] );
				} else {
					$order->add_meta_data( '_mpgs_session_id', $result['session']['id'], true );
				}
				$order->save_meta_data();
				break;

			case '/mastercard/v1/webhook':
				break;
		}

		return $result;
	}

	/**
	 * @return string
	 */
	public function get_hosted_checkout_js() {
		if ( $this->is_legacy_hosted_checkout() ) {
			return sprintf(
				'https://%s/checkout/%s/checkout.js',
				$this->get_gateway_url(),
				$this->get_api_version()
			);
		} else {
			return sprintf(
				'https://%s/static/checkout/checkout.min.js',
				$this->get_gateway_url()
			);
		}
	}

	/**
	 * @return string
	 */
	public function get_hosted_session_js() {
		return sprintf( 'https://%s/form/%s/merchant/%s/session.js', $this->get_gateway_url(), self::MPGS_API_VERSION,
			$this->get_merchant_id() );
	}

	/**
	 * @return string
	 */
	public function get_threeds_js() {
		return sprintf( 'https://%s/static/threeDS/1.3.0/three-ds.min.js', $this->get_gateway_url() );
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );

		$order->update_status( 'pending', __( 'Pending payment', 'mastercard' ) );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		);
	}

	/**
	 * @param int $order_id
	 */
	public function receipt_page( $order_id ) {
		$order = new WC_Order( $order_id );

		set_query_var( 'order', $order );
		set_query_var( 'gateway', $this );

		if ( $this->method === self::HOSTED_SESSION ) {
			$display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && $this->saved_cards;
			set_query_var( 'display_tokenization', $display_tokenization );

			$cc_form     = new Mastercard_Payment_Gateway_CC();
			$cc_form->id = $this->id;

			$support = $this->supports;
			if ( $this->saved_cards == false ) {
				foreach ( array_keys( $support, 'tokenization', true ) as $key ) {
					unset( $support[ $key ] );
				}
			}
			$cc_form->supports = $support;

			set_query_var( 'cc_form', $cc_form );

			load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedsession.php' );
		} else if ( $this->is_legacy_hosted_checkout() ) {
			load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedcheckout.php' );
		} else {
			load_template( dirname( __FILE__ ) . '/../templates/checkout/newhostedcheckout.php' );
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'heading'            => array(
				'title'       => null,
				'type'        => 'title',
				'description' => sprintf(
					__( 'Plugin version: %s<br />API version: %s<br />Legacy Hosted Checkout API version: %s', 'mastercard' ),
					MPGS_MODULE_VERSION,
					self::MPGS_API_VERSION_NUM,
					self::MPGS_LEGACY_API_VERSION_NUM
				),
			),
			'enabled'            => array(
				'title'       => __( 'Enable/Disable', 'mastercard' ),
				'label'       => __( 'Enable', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'title'              => array(
				'title'       => __( 'Title', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'mastercard' ),
				'default'     => __( 'Mastercard Payment Gateway Services', 'mastercard' ),
			),
			'description'        => array(
				'title'       => __( 'Description', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'The description displayed when this payment method is selected.',
					'mastercard' ),
				'default'     => 'Pay with your card via Mastercard.',
			),
			'gateway_url'        => array(
				'title'   => __( 'Gateway', 'mastercard' ),
				'type'    => 'select',
				'options' => array(
					self::API_AS     => __( 'Asia Pacific', 'mastercard' ),
					self::API_EU     => __( 'Europe', 'mastercard' ),
					self::API_NA     => __( 'North America', 'mastercard' ),
					//self::API_UAT    => __( 'UAT', 'mastercard' ),
					self::API_CUSTOM => __( 'Custom URL', 'mastercard' ),
				),
				'default' => self::API_EU,
			),
			'custom_gateway_url' => array(
				'title'       => __( 'Custom Gateway Host', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'Enter only the hostname without https prefix. For example na.gateway.mastercard.com.',
					'mastercard' )
			),
			'txn_mode'           => array(
				'title'       => __( 'Transaction Mode', 'mastercard' ),
				'type'        => 'select',
				'options'     => array(
					self::TXN_MODE_PURCHASE     => __( 'Purchase', 'mastercard' ),
					self::TXN_MODE_AUTH_CAPTURE => __( 'Authorize', 'mastercard' )
				),
				'default'     => self::TXN_MODE_PURCHASE,
				'description' => __( 'In “Purchase” mode, the customer is charged immediately. In Authorize mode, the transaction is only authorized and the capturing of funds is a manual process that you do using the Woocommerce admin panel.',
					'mastercard' ),
			),
			'method'             => array(
				'title'   => __( 'Integration Model', 'mastercard' ),
				'type'    => 'select',
				'options' => array(
					self::HOSTED_CHECKOUT        => __( 'Hosted Checkout', 'mastercard' ),
					self::LEGACY_HOSTED_CHECKOUT => __( 'Legacy Hosted Checkout', 'mastercard' ),
					self::HOSTED_SESSION         => __( 'Hosted Session', 'mastercard' ),
				),
				'default' => self::LEGACY_HOSTED_CHECKOUT,
			),
			'threedsecure'       => array(
				'title'       => __( '3D-Secure', 'mastercard' ),
				'label'       => __( 'Use 3D-Secure', 'mastercard' ),
				'type'        => 'select',
				'options'     => array(
					self::THREED_DISABLED => __( 'Disabled' ),
					self::THREED_V1       => __( '3DS1' ),
					self::THREED_V2       => __( '3DS2 (with fallback to 3DS1)' ),
				),
				'default'     => self::THREED_DISABLED,
				'description' => __( 'For more information please contact your payment service provider.',
					'mastercard' ),
			),
			'hc_interaction'            => array(
				'title'   => __( 'Checkout Interaction', 'mastercard' ),
				'type'    => 'select',
				'options' => array(
					self::HC_TYPE_REDIRECT => __( 'Redirect to Payment Page', 'mastercard' ),
					self::HC_TYPE_EMBEDDED    => __( 'Embedded', 'mastercard' )
				),
				'default' => self::HC_TYPE_EMBEDDED,
			),
			'hc_type'            => array(
				'title'   => __( 'Checkout Interaction', 'mastercard' ),
				'type'    => 'select',
				'options' => array(
					self::HC_TYPE_REDIRECT => __( 'Redirect to Payment Page', 'mastercard' ),
					self::HC_TYPE_MODAL    => __( 'Lightbox', 'mastercard' )
				),
				'default' => self::HC_TYPE_MODAL,
			),
			'saved_cards'        => array(
				'title'       => __( 'Saved Cards', 'mastercard' ),
				'label'       => __( 'Enable payment via saved tokenized cards', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, users will be able to pay with a saved card during checkout. Card details are saved in the payment gateway, not on your store.',
					'mastercard' ),
				'default'     => 'yes',
			),
			'debug'              => array(
				'title'       => __( 'Debug Logging', 'mastercard' ),
				'label'       => __( 'Enable Debug Logging', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => __( 'Logs all communication with Mastercard gateway to file ./wp-content/mastercard.log. Debug logging only works in Sandbox mode.',
					'mastercard' ),
				'default'     => 'no'
			),
			'api_details'        => array(
				'title'       => __( 'API credentials', 'mastercard' ),
				'type'        => 'title',
				'description' => sprintf( __( 'Enter your API credentials to process payments via this payment gateway. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.',
					'mastercard' ),
					'https://test-gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/pickSecurityModel/secureYourIntegration.html?locale=en_US' ),
			),
			'sandbox'            => array(
				'title'       => __( 'Test Sandbox', 'mastercard' ),
				'label'       => __( 'Enable test sandbox mode', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in test mode using test API credentials (real payments will not be taken).',
					'mastercard' ),
				'default'     => 'yes'
			),
			'sandbox_username'   => array(
				'title'       => __( 'Test Merchant ID', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'This is your test merchant profile ID prefixed with TEST', 'mastercard' ),
				'default'     => '',
			),
			'sandbox_password'   => array(
				'title'   => __( 'Test API Password', 'mastercard' ),
				'type'    => 'password',
				'default' => '',
			),
			'username'           => array(
				'title'   => __( 'Merchant ID', 'mastercard' ),
				'type'    => 'text',
				'default' => '',
			),
			'password'           => array(
				'title'   => __( 'API Password', 'mastercard' ),
				'type'    => 'password',
				'default' => '',
			),
			'order_prefix'       => array(
				'title'       => __( 'Order ID prefix', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'Should be specified in case multiple integrations use the same Merchant ID',
					'mastercard' ),
				'default'     => ''
			)
		);
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		$is_available = parent::is_available();

		if ( ! $this->username || ! $this->password ) {
			return false;
		}

		return $is_available;
	}

	/**
	 * @param string $order_id
	 *
	 * @return string
	 */
	public function remove_order_prefix( $order_id ) {
		if ( $this->order_prefix && strpos( $order_id, $this->order_prefix ) === 0 ) {
			$order_id = substr( $order_id, strlen( $this->order_prefix ) );
		}

		return $order_id;
	}

	/**
	 * @param string $order_id
	 *
	 * @return string
	 */
	public function add_order_prefix( $order_id ) {
		if ( $this->order_prefix ) {
			$order_id = $this->order_prefix . $order_id;
		}

		return $order_id;
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return float
	 */
	protected function get_payment_amount( $order ) {
		return round(
			$order->get_total(),
			wc_get_price_decimals()
		);
	}

	/**
	 * @param WC_Order $order
	 */
	protected function generate_txn_id_for_order( $order ) {

		if ( ! $order->meta_exists( '_txn_id' ) ) {
			$txn_id = $this->compose_new_transaction_id( 1, $order );
			$order->add_meta_data( '_txn_id', $txn_id );
		} else {
			$old_txn_id     = $order->get_meta( '_txn_id' );
			$txn_id_pattern = '/(?<order_id>.*\-)?(?<txn_id>\d+)$/';
			preg_match( $txn_id_pattern, $old_txn_id, $matches );

			$txn_id_num = (int) $matches['txn_id'] ?? 1;
			$txn_id     = $this->compose_new_transaction_id( $txn_id_num + 1, $order );
			$order->update_meta_data( '_txn_id', $txn_id );
		}

		$order->save_meta_data();

		return $txn_id;
	}

	/**
	 * @param int $txn_id
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	protected function compose_new_transaction_id( $txn_id, $order ) {
		if ( $this->order_prefix ) {
			$order_id = $this->order_prefix;
		}
		$order_id .= $order->get_id();

		return sprintf( '%s-%s', $order_id, $txn_id );
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	protected function is_order_paid( WC_Order $order ) {
		return (bool) $order->get_meta( '_mpgs_order_paid', 0 );
	}
}
