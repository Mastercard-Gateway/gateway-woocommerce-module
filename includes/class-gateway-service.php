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

use Http\Client\Common\Exception\ClientErrorException;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Common\HttpClientRouter;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception;
use Http\Discovery\HttpClientDiscovery;
use Http\Message\Authentication\BasicAuth;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Message\RequestMatcher\RequestMatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class Mastercard_ApiErrorPlugin implements Plugin {
	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var Formatter
	 */
	private $formatter;

	/**
	 * @inheritdoc
	 */
	public function __construct( LoggerInterface $logger, Formatter $formatter = null ) {
		$this->logger    = $logger;
		$this->formatter = $formatter ?: new SimpleFormatter();
	}

	/**
	 * @inheritdoc
	 */
	public function handleRequest( \Psr\Http\Message\RequestInterface $request, callable $next, callable $first ) {
		$promise = $next( $request );

		return $promise->then( function ( ResponseInterface $response ) use ( $request ) {
			return $this->transformResponseToException( $request, $response );
		} );
	}

	/**
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 *
	 * @return ResponseInterface
	 */
	protected function transformResponseToException( RequestInterface $request, ResponseInterface $response ) {
		if ( $response->getStatusCode() >= 400 && $response->getStatusCode() < 500 ) {
			$responseData = @json_decode( $response->getBody(), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new ServerErrorException( "Response not valid JSON", $request, $response );
			}

			$msg = '';
			if ( isset( $responseData['error']['cause'] ) ) {
				$msg .= $responseData['error']['cause'] . ': ';
			}
			if ( isset( $responseData['error']['explanation'] ) ) {
				$msg .= $responseData['error']['explanation'];
			}

			$this->logger->error( $msg );
			throw new ClientErrorException( $msg, $request, $response );
		}

		if ( $response->getStatusCode() >= 500 && $response->getStatusCode() < 600 ) {
			$this->logger->error( $response->getReasonPhrase() );
			throw new ServerErrorException( $response->getReasonPhrase(), $request, $response );
		}

		return $response;
	}
}

class Mastercard_ApiLoggerPlugin implements Plugin {
	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var Formatter
	 */
	private $formatter;

	/**
	 * @inheritdoc
	 */
	public function __construct( LoggerInterface $logger, Formatter $formatter = null ) {
		$this->logger    = $logger;
		$this->formatter = $formatter ?: new SimpleFormatter();
	}

	/**
	 * @inheritdoc
	 */
	public function handleRequest( \Psr\Http\Message\RequestInterface $request, callable $next, callable $first ) {
		$reqBody = @json_decode( $request->getBody(), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$reqBody = $request->getBody();
		}

		$this->logger->info( sprintf( 'Emit request: "%s"', $this->formatter->formatRequest( $request ) ),
			[ 'request' => $reqBody ] );

		return $next( $request )->then( function ( ResponseInterface $response ) use ( $request ) {
			$body = @json_decode( $response->getBody(), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$body = $response->getBody();
			}
			$this->logger->info(
				sprintf( 'Receive response: "%s" for request: "%s"', $this->formatter->formatResponse( $response ),
					$this->formatter->formatRequest( $request ) ),
				[
					'response' => $body,
				]
			);

			return $response;
		}, function ( \Exception $exception ) use ( $request ) {
			if ( $exception instanceof Exception\HttpException ) {
				$this->logger->error(
					sprintf( 'Error: "%s" with response: "%s" when emitting request: "%s"', $exception->getMessage(),
						$this->formatter->formatResponse( $exception->getResponse() ),
						$this->formatter->formatRequest( $request ) ),
					[
						'request'   => $request,
						'response'  => $exception->getResponse(),
						'exception' => $exception,
					]
				);
			} else {
				$this->logger->error(
					sprintf( 'Error: "%s" when emitting request: "%s"', $exception->getMessage(),
						$this->formatter->formatRequest( $request ) ),
					[
						'request'   => $request,
						'exception' => $exception,
					]
				);
			}

			throw $exception;
		} );
	}
}


class Mastercard_GatewayResponseException extends \Exception {

}

class Mastercard_GatewayService {
	/**
	 * @var GuzzleMessageFactory
	 */
	protected $messageFactory;

	/**
	 * @var string
	 */
	protected $apiUrl;

	/**
	 * @var HttpClientRouter
	 */
	protected $client;

	/**
	 * @var string|null
	 */
	protected $webhookUrl;

	/**
	 * GatewayService constructor.
	 *
	 * @param string $baseUrl
	 * @param string $apiVersion
	 * @param string $merchantId
	 * @param string $password
	 * @param string $webhookUrl
	 * @param int $loggingLevel
	 *
	 * @throws \Exception
	 */
	public function __construct(
		$baseUrl,
		$apiVersion,
		$merchantId,
		$password,
		$webhookUrl,
		$loggingLevel = \Monolog\Logger::DEBUG
	) {
		$this->webhookUrl = $webhookUrl;

		$logger = new Logger( 'mastercard' );
		$logger->pushHandler( new StreamHandler(
			WP_CONTENT_DIR . '/mastercard.log',
			$loggingLevel
		) );

		$this->messageFactory = new GuzzleMessageFactory();

		$this->apiUrl = 'https://' . $baseUrl . '/api/rest/' . $apiVersion . '/merchant/' . $merchantId . '/';

		$username = 'merchant.' . $merchantId;

		$client = new PluginClient(
			HttpClientDiscovery::find(),
			array(
				new ContentLengthPlugin(),
				new HeaderSetPlugin( [ 'Content-Type' => 'application/json;charset=UTF-8' ] ),
				new AuthenticationPlugin( new BasicAuth( $username, $password ) ),
				new Mastercard_ApiErrorPlugin( $logger ),
				new Mastercard_ApiLoggerPlugin( $logger ),
			)
		);

		$requestMatcher = new RequestMatcher( null, $baseUrl );

		$this->client = new HttpClientRouter();
		$this->client->addClient(
			$client,
			$requestMatcher
		);
	}

	/**
	 * Data format is: CART_X.X.X_DEV_X.X.X o e.g MAGENTO_2.0.2_CARTDEV_2.0.0
	 * where MAGENTO_2.0.2 represents Magento version 2.0.2 and CARTDEV_2.0.0 represents extension developer CARTDEV and extension version 2.0.0
	 *
	 * @return string
	 */
	protected function getSolutionId() {
		return 'WC_' . WC()->version . '_ONTAP_' . MPGS_MODULE_VERSION;
	}

	/**
	 * @param $value
	 * @param int $limited
	 *
	 * @return bool|string|null
	 */
	public static function safe( $value, $limited = 0 ) {
		if ( $value === "" ) {
			return null;
		}

		if ( $limited > 0 && Tools::strlen( $value ) > $limited ) {
			return Tools::substr( $value, 0, $limited );
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 *
	 * @return string
	 */
	public static function numeric( $value ) {
		return number_format( $value, 2, '.', '' );
	}

	/**
	 * @param $data
	 *
	 * @throws Mastercard_GatewayResponseException
	 */
	public function validateCheckoutSessionResponse( $data ) {
		if ( ! isset( $data['result'] ) || $data['result'] !== 'SUCCESS' ) {
			throw new Mastercard_GatewayResponseException( 'Missing or invalid session result.' );
		}

		if ( ! isset( $data['session']['id'] ) ) {
			throw new Mastercard_GatewayResponseException( 'Missing session or ID.' );
		}
	}

	/**
	 * @param $data
	 *
	 * @throws Mastercard_GatewayResponseException
	 */
	public function validateSessionResponse( $data ) {
		if ( ! isset( $data['session']['id'] ) ) {
			throw new Mastercard_GatewayResponseException( 'Missing session or ID.' );
		}
	}

	/**
	 * @param array $data
	 */
	public function validateTxnResponse( $data ) {
		// @todo
	}

	/**
	 * @param array $data
	 */
	public function validateOrderResponse( $data ) {
		// @todo
	}

	/**
	 * @param array $data
	 */
	public function validateVoidResponse( $data ) {
		// @todo
	}

	/**
	 * @param $response
	 *
	 * @return bool
	 */
	public function isApproved( $response ) {
		$gatewayCode = $response['response']['gatewayCode'];

		if ( ! in_array( $gatewayCode, array( 'APPROVED', 'APPROVED_AUTO' ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Interprets the authentication response returned from the card Issuer's Access Control Server (ACS)
	 * after the cardholder completes the authentication process. The response indicates the success
	 * or otherwise of the authentication.
	 * The 3DS AuthId is required so that merchants can submit payloads multiple times
	 * without producing duplicates in the database.
	 * POST https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/3DSecureId/{3DSecureId}
	 *
	 * @param string $tds_id
	 * @param string $paRes
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function process3dsResult( $tds_id, $paRes ) {
		$uri = $this->apiUrl . '3DSecureId/' . $tds_id;

		$request = $this->messageFactory->createRequest( 'POST', $uri, array(), json_encode( array(
			'apiOperation' => 'PROCESS_ACS_RESULT',
			'3DSecure'     => array(
				'paRes' => $paRes
			)
		) ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		return $response;
	}

	/**
	 * Request to check a cardholder's enrollment in the 3DSecure scheme.
	 * PUT https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/3DSecureId/{3DSecureId}
	 *
	 * @param array $data
	 * @param array $order
	 * @param array|null $session
	 * @param array|null $source_of_funds
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function check3dsEnrollment( $data, $order, $session = null, $source_of_funds = array() ) {
		$tds_id = uniqid( sprintf( '3DS-' ), true );
		$uri    = $this->apiUrl . '3DSecureId/' . $tds_id;

		$request = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( array(
			'apiOperation'  => 'CHECK_3DS_ENROLLMENT',
			'3DSecure'      => $data,
			'order'         => $order,
			'session'       => $session,
			'sourceOfFunds' => $source_of_funds ?: null,
		) ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		return $response;
	}


	/**
	 * Initiate Checkout
	 * Request to create a session identifier for the checkout interaction.
	 * The session identifier, when included in the Checkout.configure() function,
	 * allows you to return the payer to the merchant's website after completing the payment attempt.
	 * https://mtf.gateway.mastercard.com/api/rest/version/63/merchant/{merchantId}/session
	 *
	 * @param array $order
	 * @param array $interaction
	 * @param array $customer
	 * @param array $billing
	 * @param array $shipping
	 *
	 * @return array
	 * @throws Exception
	 * @throws Mastercard_GatewayResponseException
	 */
	public function initiateCheckout(
		$order = array(),
		$interaction = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$txnId = uniqid( sprintf( '%s-', $order['id'] ) );
		$uri   = $this->apiUrl . 'session';

		$requestData = array(
			'apiOperation'      => 'INITIATE_CHECKOUT',
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge( $order, array(
				'notificationUrl' => $this->webhookUrl,
				'reference'       => $order['id']
			) ),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'interaction'       => $interaction,
			'customer'          => $customer,
			'transaction'       => [
				'reference' => $txnId,
				'source'    => 'INTERNET',
			]
		);

		$request = $this->messageFactory->createRequest( 'POST', $uri, array(), json_encode( $requestData ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateCheckoutSessionResponse( $response );

		return $response;
	}

	/**
	 * Create Checkout Session
	 * Request to create a session identifier for the checkout interaction.
	 * The session identifier, when included in the Checkout.configure() function,
	 * allows you to return the payer to the merchant's website after completing the payment attempt.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/session
	 *
	 * @param array $order
	 * @param array $interaction
	 * @param array $customer
	 * @param array $billing
	 * @param array $shipping
	 *
	 * @return array
	 * @throws Exception
	 * @throws Mastercard_GatewayResponseException
	 *
	 * @todo Remove with Legacy Hosted Checkout
	 */
	public function createCheckoutSession(
		$order = array(),
		$interaction = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$txnId = uniqid( sprintf( '%s-', $order['id'] ) );
		$uri   = $this->apiUrl . 'session';

		$requestData = array(
			'apiOperation'      => 'CREATE_CHECKOUT_SESSION',
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge( $order, array(
				'notificationUrl' => $this->webhookUrl,
				'reference'       => $order['id']
			) ),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'interaction'       => $interaction,
			'customer'          => $customer,
			'transaction'       => [
				'reference' => $txnId,
				'source'    => 'INTERNET',
			]
		);

		$request = $this->messageFactory->createRequest( 'POST', $uri, array(), json_encode( $requestData ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateCheckoutSessionResponse( $response );

		return $response;
	}

	/**
	 * Request to add or update request fields contained in the session.
	 * PUT    https://test-gateway.mastercard.com/api/rest/version/58/merchant/{merchantId}/session/{sessionId}
	 *
	 * @param $session_id
	 * @param array $order
	 * @param array $customer
	 * @param array $billing
	 * @param array $shipping
	 * @param array $authentication
	 * @param array $token
	 *
	 * @return mixed
	 * @throws Exception
	 * @throws Mastercard_GatewayResponseException
	 */
	public function update_session(
		$session_id,
		$order = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array(),
		$authentication = array(),
		$token = array()
	) {
		$gateway = new Mastercard_Gateway();
		$uri     = $this->apiUrl . 'session/' . $session_id;
		$params  = array(
			'order_id'   => $gateway->remove_order_prefix( $order['id'] ),
			'session_id' => $session_id
		);

		if ( ! empty( $authentication ) && ! isset( $authentication['acceptVersions'] ) ) {
			$authentication['redirectResponseUrl'] = add_query_arg(
				                                         'wc-api',
				                                         Mastercard_Gateway::class,
				                                         home_url( '/' )
			                                         ) . '&' . http_build_query( $params );
		}

		$requestData = array(
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge( $order, array(
				'notificationUrl' => $this->webhookUrl
			) ),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'customer'          => $customer,
			'sourceOfFunds'     => array_merge( $token, array(
				'type' => 'CARD'
			) ),
		);

		if ( ! empty( $authentication ) ) {
			$requestData['authentication'] = $authentication;
		}

		$request = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( $requestData ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateSessionResponse( $response );

		return $response;
	}

	/**
	 * Request to create a payment session. A payment session can be used to temporarily store any of the request
	 * fields of operations that allow a session identifier as a request field.
	 * The request fields stored in the session may then be used in these operations by providing the session
	 * identifier. They may be updated and obtained using the Update Session and
	 * Retrieve Session operation respectively.
	 *
	 * POST https://test-gateway.mastercard.com/api/rest/version/58/merchant/{merchantId}/session
	 *
	 * @return array
	 * @throws Exception
	 */
	public function create_session() {
		$uri      = $this->apiUrl . 'session';
		$request  = $this->messageFactory->createRequest( 'POST', $uri, array() );
		$response = $this->client->sendRequest( $request );

		return json_decode( $response->getBody(), true );
	}

	/**
	 * Request to obtain an authorization for a proposed funds transfer.
	 * An authorization is a response from a financial institution indicating that payment information
	 * is valid and funds are available in the payers account.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}/transaction/{transactionid}
	 *
	 * @param string $txnId
	 * @param string $orderId
	 * @param array $order
	 * @param array $authentication
	 * @param string|null $tds_id
	 * @param array $session
	 * @param array $customer
	 * @param array $billing
	 * @param array $shipping
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function authorize(
		$txnId,
		$orderId,
		$order,
		$authentication,
		$tds_id = null,
		$session = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$uri = $this->apiUrl . 'order/' . $orderId . '/transaction/' . $txnId;

		$requestData = array(
			'apiOperation'      => 'AUTHORIZE',
			'3DSecureId'        => $tds_id,
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge( $order, array(
				'notificationUrl' => $this->webhookUrl,
				'reference'       => $orderId,
			) ),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'customer'          => $customer,
			'session'           => $session,
			'transaction'       => [
				'reference' => $txnId,
				'source'    => 'INTERNET',
			]
		);

		if ( ! empty( $authentication ) ) {
			$requestData['authentication'] = $authentication;
		}

		$request = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( $requestData ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * A single transaction to authorise the payment and transfer funds from the payer's account to your account.
	 *
	 * For card payments, Pay is a mode where the Authorize and Capture operations are completed at the same time.
	 * Pay is the most common type of payment model used by merchants to accept card payments.
	 * The Pay model is used when the merchant is allowed to bill the cardholder's account immediately,
	 * for example when providing services or goods on the spot.
	 * PUT https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}/transaction/{transactionid}
	 *
	 * @param string $txnId
	 * @param string $orderId
	 * @param array $order
	 * @param array $authentication
	 * @param string|null $tds_id
	 * @param array $session
	 * @param array $customer
	 * @param array $billing
	 * @param array $shipping
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function pay(
		$txnId,
		$orderId,
		$order,
		$authentication,
		$tds_id = null,
		$session = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$uri = $this->apiUrl . 'order/' . $orderId . '/transaction/' . $txnId;

		$requestData = array(
			'apiOperation'      => 'PAY',
			'3DSecureId'        => $tds_id,
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge( $order, array(
				'notificationUrl' => $this->webhookUrl,
				'reference'       => $orderId
			) ),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'customer'          => $customer,
			'session'           => $session,
			'transaction'       => [
				'reference' => $txnId,
				'source'    => 'INTERNET',
			]
		);

		if ( ! empty( $authentication ) ) {
			$requestData['authentication'] = $authentication;
		}

		$request = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( $requestData ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Retrieve order
	 * Request to retrieve the details of an order and all transactions associated with this order.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}
	 *
	 * @param string $orderId
	 *
	 * @return array
	 * @throws \Http\Client\Exception
	 */
	public function retrieveOrder( $orderId ) {
		$uri = $this->apiUrl . 'order/' . $orderId;

		$request  = $this->messageFactory->createRequest( 'GET', $uri );
		$response = $this->client->sendRequest( $request );

		$response = json_decode( $response->getBody(), true );

		$this->validateOrderResponse( $response );

		return $response;
	}

	/**
	 * Helper method to find the authorisation transaction
	 *
	 * @param string $orderId
	 * @param array $response
	 *
	 * @return null|array
	 * @throws Exception
	 */
	public function getAuthorizationTransaction( $orderId, $response = array() ) {
		if ( empty( $response ) ) {
			$response = $this->retrieveOrder( $orderId );
		}

		// @todo: Find only the first one
		foreach ( $response['transaction'] as $txn ) {
			if ( $txn['transaction']['type'] === 'AUTHORIZATION' && $txn['result'] === 'SUCCESS' ) {
				return $txn;
			}
		}

		return null;
	}

	/**
	 * Helper method to find the capture/pay transaction
	 *
	 * @param string $orderId
	 * @param array $response
	 *
	 * @return null|array
	 * @throws Exception
	 */
	public function getCaptureTransaction( $orderId, $response = array() ) {
		if ( empty( $response ) ) {
			$response = $this->retrieveOrder( $orderId );
		}

		// @todo: Find only the first one
		foreach ( $response['transaction'] as $txn ) {
			if ( ( $txn['transaction']['type'] === 'CAPTURE' || $txn['transaction']['type'] === 'PAYMENT' ) && $txn['result'] === 'SUCCESS' ) {
				return $txn;
			}
		}

		return null;
	}

	/**
	 * Request to retrieve the details of a transaction. For example you can retrieve the details of an authorization that you previously executed.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}/transaction/{transactionid}
	 *
	 * @param string $orderId
	 * @param string $txnId
	 *
	 * @return array
	 * @throws Exception
	 */
	public function retrieveTransaction( $orderId, $txnId ) {
		$uri = $this->apiUrl . 'order/' . $orderId . '/transaction/' . $txnId;

		$request  = $this->messageFactory->createRequest( 'GET', $uri );
		$response = $this->client->sendRequest( $request );

		$response = json_decode( $response->getBody(), true );

		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Request to void a previous transaction. A void will reverse a previous transaction.
	 * Typically voids will only be successful when processed not long after the original transaction.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}/transaction/{transactionid}
	 *
	 * @param string $orderId
	 * @param string $txnId
	 *
	 * @return mixed|\Psr\Http\Message\ResponseInterface
	 * @throws Exception
	 */
	public function voidTxn( $orderId, $txnId ) {
		$newTxnId = 'void-' . $txnId;
		$uri      = $this->apiUrl . 'order/' . $orderId . '/transaction/' . $newTxnId;

		$request  = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( array(
			'apiOperation'      => 'VOID',
			'partnerSolutionId' => $this->getSolutionId(),
			'transaction'       => array(
				'targetTransactionId' => $txnId,
				'reference'           => $txnId,
			)
		) ) );
		$response = $this->client->sendRequest( $request );

		$response = json_decode( $response->getBody(), true );

		$this->validateVoidResponse( $response );

		return $response;
	}

	/**
	 * Request to capture funds previously reserved by an authorization.
	 * A Capture transaction triggers the movement of funds from the payer's account to the merchant's account.
	 * Typically, a Capture is linked to the authorization through the orderId - you provide the original orderId,
	 * a new transactionId, and the amount you wish to capture.
	 * You may provide other fields (such as shipping address) if you want to update their values; however,
	 * you must NOT provide sourceOfFunds.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}/transaction/{transactionid}
	 *
	 * @param string $orderId
	 * @param string $txnId
	 * @param $amount
	 * @param $currency
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function captureTxn( $orderId, $txnId, $amount, $currency ) {
		$newTxnId = 'capture-' . $txnId;
		$uri      = $this->apiUrl . 'order/' . $orderId . '/transaction/' . $newTxnId;

		$request = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( array(
			'apiOperation'      => 'CAPTURE',
			'partnerSolutionId' => $this->getSolutionId(),
			'transaction'       => array(
				'amount'    => $amount,
				'currency'  => $currency,
				'reference' => $newTxnId,
			),
			'order'             => array(
				'notificationUrl' => $this->webhookUrl,
				'reference'       => $orderId,
			)
		) ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Request to refund previously captured funds to the payer.
	 * Typically, a Refund is linked to the Capture or Pay through the orderId - you provide the original orderId,
	 * a new transactionId, and the amount you wish to refund. You may provide other fields if you want to update their values;
	 * however, you must NOT provide sourceOfFunds.
	 * In rare situations, you may want to refund the payer without associating the credit to a previous transaction (see Standalone Refund).
	 * In this case, you need to provide the sourceOfFunds and a new orderId.
	 * https://mtf.gateway.mastercard.com/api/rest/version/50/merchant/{merchantId}/order/{orderid}/transaction/{transactionid}
	 *
	 * @param $orderId
	 * @param $txnId
	 * @param $amount
	 * @param $currency
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function refund( $orderId, $txnId, $amount, $currency ) {
		$newTxnId = 'refund-' . $txnId;
		$uri      = $this->apiUrl . 'order/' . $orderId . '/transaction/' . $newTxnId;

		$request = $this->messageFactory->createRequest( 'PUT', $uri, array(), json_encode( array(
			'apiOperation'      => 'REFUND',
			'partnerSolutionId' => $this->getSolutionId(),
			'transaction'       => array(
				'amount'    => $amount,
				'currency'  => $currency,
				'reference' => $newTxnId,
			),
			'order'             => array(
				'notificationUrl' => $this->webhookUrl,
				'reference'       => $orderId,
			)
		) ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Request to retrieve the options available for processing a payment, for example, the credit cards and currencies.
	 * https://mtf.gateway.mastercard.com/api/rest/version/51/merchant/{merchantId}/paymentOptionsInquiry
	 */
	public function paymentOptionsInquiry() {
		$uri = $this->apiUrl . 'paymentOptionsInquiry';

		$request  = $this->messageFactory->createRequest( 'GET', $uri );
		$response = $this->client->sendRequest( $request );

		$response = json_decode( $response->getBody(), true );

		return $response;
	}

	/**
	 * Request for the gateway to store payment instrument (e.g. credit or debit cards, gift cards,
	 * ACH bank account details) against a token, where the system generates the token id.
	 * https://eu-gateway.mastercard.com/api/rest/version/54/merchant/{merchantId}/token
	 *
	 * @param string $session_id
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception
	 */
	public function createCardToken( $session_id ) {
		$uri = $this->apiUrl . 'token';

		$request = $this->messageFactory->createRequest( 'POST', $uri, array(), json_encode( array(
			'session'       => array(
				'id' => $session_id
			),
			'sourceOfFunds' => array(
				'type' => 'CARD'
			)
		) ) );

		$response = $this->client->sendRequest( $request );
		$response = json_decode( $response->getBody(), true );

		return $response;
	}
}
