<?php
/**
 * Copyright (c) 2019 Mastercard
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
 */

define( 'MPGS_MODULE_VERSION', '1.0.0' );

require_once dirname( __FILE__ ) . '/class-checkout-builder.php';
require_once dirname( __FILE__ ) . '/class-gateway-service.php';

class Mastercard_Gateway extends WC_Payment_Gateway {

	const ID = 'mpgs_gateway';

	const MPGS_API_VERSION = 'version/52';

	const HOSTED_SESSION = 'hostedsession';
	const HOSTED_CHECKOUT = 'hostedcheckout';

	const HC_TYPE_REDIRECT = 'redirect';
	const HC_TYPE_MODAL = 'modal';

	const API_EU = 'eu-gateway.mastercard.com';
	const API_AS = 'ap-gateway.mastercard.com';
	const API_NA = 'na-gateway.mastercard.com';
	const API_UAT = 'secure.uat.tnspayments.com';
	const API_CUSTOM = null;

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
	protected $threedsecure;

	/**
	 * Mastercard_Gateway constructor.
	 * @throws Exception
	 */
	public function __construct() {
		$this->id         = self::ID;
		$this->title      = __( 'Mastercard Payment Gateway Services', 'mastercard' );
		$this->has_fields = true;

		// @todo: change
		$this->method_description = __( 'Mastercard Payment Gateway Services Description', 'mastercard' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled', false );
		$this->sandbox      = $this->get_option( 'sandbox', false );
		$this->username     = $this->sandbox == 'no' ? $this->get_option( 'username' ) : $this->get_option( 'sandbox_username' );
		$this->password     = $this->sandbox == 'no' ? $this->get_option( 'password' ) : $this->get_option( 'sandbox_password' );
		$this->gateway_url  = $this->get_option( 'gateway_url', self::API_EU );
		$this->hc_type      = $this->get_option( 'hc_type', self::HC_TYPE_MODAL );
		$this->capture      = $this->get_option( 'capture', 'yes' ) == 'yes' ? true : false;
		$this->threedsecure = $this->get_option( 'threedsecure', 'yes' ) == 'yes' ? true : false;
		$this->method       = $this->get_option( 'method', self::HOSTED_CHECKOUT );
		$this->supports     = array(
			'products',
			'refunds',
		);

		$this->service = new Mastercard_GatewayService(
			$this->gateway_url,
			self::MPGS_API_VERSION,
			$this->username,
			$this->password,
			$this->get_webhook_url()
		);

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
	 * @return void
	 */
	public function admin_scripts() {
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}
		wp_enqueue_script( 'woocommerce_mastercard_admin', plugins_url( 'assets/js/mastercard-admin.js', __FILE__ ), array(), MPGS_MODULE_VERSION, true );
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

		$result = $this->service->captureTxn( $order->get_id(), time(), $order->get_total(), $order->get_currency() );

		$txn = $result['transaction'];
		$order->add_order_note( sprintf( __( 'Mastercard payment CAPTURED (ID: %s, Auth Code: %s)', 'woocommerce' ), $txn['id'], $txn['authorizationCode'] ) );

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
		$result = $this->service->refund( $order_id, (string) time(), $amount, $order->get_currency() );
		$order->add_order_note( sprintf(
			__( 'Mastercard registered refund %s %s (ID: %s)', 'woocommerce' ),
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

		// todo: better status/messages handling
		$status = isset( $_REQUEST['status'] ) ? $_REQUEST['status'] : null;
		if ( $status == 'declined' ) {
			echo "Payment was declined";
			exit();
		}
		if ( $status == 'error' ) {
		    echo $_REQUEST['status'] . ': ' . $_REQUEST['reason'];
		    exit();
        }

		if ( $this->method === self::HOSTED_SESSION ) {
			WC()->cart->empty_cart();
			$this->process_hosted_session_payment();
		}

		if ( $this->method === self::HOSTED_CHECKOUT ) {
			WC()->cart->empty_cart();
			$this->process_hosted_checkout_payment();
		}
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_checkout_payment() {
		$order_id          = $_REQUEST['order_id'];
		$result_indicator  = $_REQUEST['resultIndicator'];
		$order             = new WC_Order( $order_id );
		$success_indicator = $order->get_meta( '_mpgs_success_indicator' );

		try {
			if ( $success_indicator !== $result_indicator ) {
				throw new Exception( 'Result indicator mismatch' );
			}

			$mpgs_order = $this->service->retrieveOrder( $order_id );
			if ($mpgs_order['result'] !== 'SUCCESS') {
				throw new Exception('Payment not successful');
			}

			$txn = $mpgs_order['transaction'][0];
			$this->process_wc_order($order, $mpgs_order, $txn);

			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			wp_redirect( $this->get_payment_return_url( $order_id, array(
				'status' => 'error',
				'reason' => $e->getMessage()
			) ) );
			exit();
		}
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_session_payment() {
		$order_id           = $_REQUEST['order_id'];
		$session_id         = $_REQUEST['session_id'];
		$session_version    = $_REQUEST['session_version'];
		$session            = array(
			'id'      => $session_id,
			'version' => $session_version
		);
		$order              = new WC_Order( $order_id );
		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) ? $_REQUEST['check_3ds_enrollment'] == '1' : false;
		$process_acl_result = isset( $_REQUEST['process_acs_result'] ) ? $_REQUEST['process_acs_result'] == '1' : false;
		$threeDSecureData   = null;

		if ( $check_3ds ) {
			$data    = array(
				'authenticationRedirect' => array(
					'pageGenerationMode' => 'CUSTOMIZED',
					'responseUrl'        => $this->get_payment_return_url( $order_id, array(
						'status' => '3ds_done'
					) )
				)
			);
			$session = array(
				'id' => $session_id
			);
			$order   = array(
				'amount'   => $order->get_total(),
				'currency' => $order->get_currency()
			);

			$response = $this->service->check3dsEnrollment( $data, $order, $session );

			if ( $response['response']['gatewayRecommendation'] !== 'PROCEED' ) {
				wp_redirect( $this->get_payment_return_url( $order_id, array(
					'status' => 'declined',
					'reason' => 'gatewayRecommendation not proceed'
				) ) );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$tds_auth = $response['3DSecure']['authenticationRedirect']['customized'];

				set_query_var( 'authenticationRedirect', $tds_auth );
				set_query_var( 'returnUrl', $this->get_payment_return_url( $order_id, array(
					'3DSecureId'         => $response['3DSecureId'],
					'process_acs_result' => '1',
					'session_id'         => $session_id,
					'session_version'    => $session_version,
				) ) );

				set_query_var( 'order', $order );
				set_query_var( 'gateway', $this );

				load_template( dirname( __FILE__ ) . '/../templates/3dsecure/form.php' );
				exit();
			} else {
				$this->pay( $session, $order );
			}
		} else {
			$this->pay( $session, $order );
        }

		if ( $process_acl_result ) {
			$pa_res = $_POST['PaRes'];
			$id     = $_REQUEST['3DSecureId'];

			$response = $this->service->process3dsResult( $id, $pa_res );

			if ( $response['response']['gatewayRecommendation'] !== 'PROCEED' ) {
				wp_redirect( $this->get_payment_return_url( $order_id, array(
					'status' => 'declined',
					'reason' => 'gatewayRecommendation not proceed'
				) ) );
				exit();
			}

			$threeDSecureData = array(
				'acsEci'              => $response['3DSecure']['acsEci'],
				'authenticationToken' => $response['3DSecure']['authenticationToken'],
				'paResStatus'         => $response['3DSecure']['paResStatus'],
				'veResEnrolled'       => $response['3DSecure']['veResEnrolled'],
				'xid'                 => $response['3DSecure']['xid'],
			);

			$this->pay( $session, $order, $threeDSecureData );
		}

		wp_redirect( $this->get_payment_return_url( $order->get_id(), array(
			'status' => 'error',
			'reason' => 'unexpected condition'
		) ) );
		exit();
	}

	/**
	 * @param array $session
	 * @param WC_Order $order
	 * @param array|null $threeDSecure
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function pay( $session, $order, $threeDSecure = null ) {
		$order_builder = new Mastercard_CheckoutBuilder( $order );
		if ( $this->capture ) {
			$mpgs_txn = $this->service->pay(
				$order->get_id(),
				$order_builder->getOrder(),
				$threeDSecure,
				$session,
				$order_builder->getCustomer(),
				$order_builder->getBilling()
			);
		} else {
			$mpgs_txn = $this->service->authorize(
				$order->get_id(),
				$order_builder->getOrder(),
				$threeDSecure,
				$session,
				$order_builder->getCustomer(),
				$order_builder->getBilling()
			);
		}

		try {
		    if ($mpgs_txn['result'] !== 'SUCCESS') {
		        throw new Exception('Payment not successful');
            }

		    $this->process_wc_order($order, $mpgs_txn['order'], $mpgs_txn);
			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			wp_redirect( $this->get_payment_return_url( $order->get_id(), array(
				'status' => 'error',
				'reason' => $e->getMessage()
			) ) );
			exit();
		}
	}

	/**
	 * @param WC_Order $order
	 * @param array $order_data
	 * @param array $txn_data
	 *
	 * @throws Exception
	 */
	protected function process_wc_order($order, $order_data, $txn_data) {
		$this->validate_order( $order, $order_data );

		$captured = $order_data['status'] === 'CAPTURED';
		$order->add_meta_data( '_mpgs_order_captured', $captured );

		$order->payment_complete( $txn_data['transaction']['id'] );

		if ( $captured ) {
			$order->add_order_note( sprintf( __( 'Mastercard payment CAPTURED (ID: %s, Auth Code: %s)', 'woocommerce' ), $txn_data['transaction']['id'], $txn_data['transaction']['authorizationCode'] ) );
		} else {
			$order->add_order_note( sprintf( __( 'Mastercard payment AUTHORIZED (ID: %s, Auth Code: %s)', 'woocommerce' ), $txn_data['transaction']['id'], $txn_data['transaction']['authorizationCode'] ) );
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
		if ( (float) $order->get_total() !== $mpgs_order['amount'] ) {
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
	public function use_modal() {
		return $this->hc_type === self::HC_TYPE_MODAL;
	}

	/**
	 * @return bool
	 */
	public function use_3dsecure() {
		return $this->threedsecure;
	}

	/**
	 * @return string
	 */
	public function get_merchant_id() {
		return $this->username;
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
				$result        = $this->service->createCheckoutSession(
					$order_builder->getHostedCheckoutOrder(),
					$order_builder->getInteraction( $this->capture, $returnUrl ),
					$order_builder->getCustomer(),
					$order_builder->getBilling()
				);

				if ( $order->meta_exists( '_mpgs_success_indicator' ) ) {
					$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );
				} else {
					$order->add_meta_data( '_mpgs_success_indicator', $result['successIndicator'], true );
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
		return sprintf( 'https://%s/checkout/%s/checkout.js', $this->gateway_url, self::MPGS_API_VERSION );
	}

	/**
	 * @return string
	 */
	public function get_hosted_session_js() {
		return sprintf( 'https://%s/form/%s/merchant/%s/session.js', $this->gateway_url, self::MPGS_API_VERSION, $this->get_merchant_id() );
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );

		$order->update_status( 'pending', __( 'Pending payment', 'woocommerce' ) );

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
			load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedsession.php' );
		} else {
			load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedcheckout.php' );
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		// @todo: Labels, descriptions
		$this->form_fields = array(
			'enabled'            => array(
				'title'       => __( 'Enable/Disable', 'woocommerce' ),
				'label'       => __( 'Enable', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'title'              => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Mastercard Payment Gateway Services', 'woocommerce' ),
				'desc_tip'    => true
			),
			'description'        => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => 'Pay with your card via Mastercard.',
				'desc_tip'    => true
			),
			'gateway_url'        => array(
				'title'   => __( 'Gateway', 'woocommerce' ),
				'type'    => 'select',
				'options' => array(
					self::API_AS     => __( 'Asia Pacific', 'woocommerce' ),
					self::API_EU     => __( 'Europe', 'woocommerce' ),
					self::API_NA     => __( 'North America', 'woocommerce' ),
					self::API_UAT    => __( 'UAT', 'woocommerce' ),
					self::API_CUSTOM => __( 'Custom URL', 'woocommerce' ),
				),
				'default' => self::API_EU,
			),
			'custom_gateway_url' => array(
				'title' => __( 'Gateway URL', 'woocommerce' ),
				'type'  => 'text'
			),
			'method'             => array(
				'title'       => __( 'Payment Model', 'woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					self::HOSTED_CHECKOUT => __( 'Hosted Checkout', 'woocommerce' ),
					self::HOSTED_SESSION  => __( 'Hosted Session', 'woocommerce' ),
				),
				'default'     => self::HOSTED_CHECKOUT,
				'desc_tip'    => true,
			),
			'threedsecure'       => array(
				'title'       => __( '3D-Secure', 'woocommerce' ),
				'label'       => __( 'Use 3D-Secure', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Be sure to Enable 3D-Secure in your MasterCard account.', 'woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'capture'            => array(
				'title'       => __( 'Capture', 'woocommerce' ),
				'label'       => __( 'Capture charge immediately', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later.', 'woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'hc_type'            => array(
				'title'       => __( 'Payment Behaviour', 'woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					self::HC_TYPE_REDIRECT => __( 'Redirect', 'woocommerce' ),
					self::HC_TYPE_MODAL    => __( 'Modal', 'woocommerce' )
				),
				'default'     => self::HC_TYPE_MODAL,
				'desc_tip'    => true,
			),
			'api_details'           => array(
				'title'       => __( 'API credentials', 'woocommerce' ),
				'type'        => 'title',
				'description' => sprintf( __( 'Enter your Mastercard API credentials to process payments via Mastercard. Learn how to access your <a href="%s" target="_blank">Mastercard API Credentials</a>.', 'woocommerce' ), 'https://developer.mastercard.com/' ),
			),
			'sandbox'            => array(
				'title'       => __( 'Sandbox', 'woocommerce' ),
				'label'       => __( 'Enable Sandbox Mode', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'woocommerce' ),
				'default'     => 'yes'
			),
			'sandbox_username'   => array(
				'title'       => __( 'Sandbox Merchant ID', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Mastercard account: Settings > API Keys.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true
			),
			'sandbox_password'   => array(
				'title'       => __( 'Sandbox Password', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Mastercard account: Settings > API Keys.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true
			),
			'username'           => array(
				'title'       => __( 'Merchant ID', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Mastercard account: Settings > API Keys.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true
			),
			'password'           => array(
				'title'       => __( 'Password', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Get your API keys from your Mastercard account: Settings > API Keys.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true
			),
		);
	}

	/**
	 * Render admin fields
	 */
	public function admin_options() {
		?>
        <h2><?php _e( 'Mastercard Payment Gateway Services', 'woocommerce' ); ?></h2>
        <table class="form-table">
			<?php $this->generate_settings_html(); ?>
        </table> <?php
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
}
