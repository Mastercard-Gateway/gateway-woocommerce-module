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

class Mastercard_CheckoutBuilder {
	/**
	 * @var WC_Order
	 */
	protected $order;

	/**
	 * Mastercard_Model_AbstractBuilder constructor.
	 *
	 * @param WC_Order $order
	 */
	public function __construct( $order ) {
		$this->order = $order;
	}

	/**
	 * @param string $iso2country
	 *
	 * @return string
	 */
	public function iso2ToIso3( $iso2country ) {
		return MPGS_ISO3_COUNTRIES[ $iso2country ];
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

		if ( $limited > 0 && strlen( $value ) > $limited ) {
			return substr( $value, 0, $limited );
		}

		return $value;
	}

	/**
	 * @return array
	 */
	public function getBilling() {
		return array(
			'address' => array(
				'street'        => self::safe( $this->order->get_billing_address_1(), 100 ),
				'street2'       => self::safe( $this->order->get_billing_address_2(), 100 ),
				'city'          => self::safe( $this->order->get_billing_city(), 100 ),
				'postcodeZip'   => self::safe( $this->order->get_billing_postcode(), 10 ),
				'country'       => $this->iso2ToIso3( $this->order->get_billing_country() ),
				'stateProvince' => self::safe( $this->order->get_billing_state(), 20 )
			)
		);
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function orderIsVirtual( $order ) {
		if ( empty( $this->order->get_shipping_address_1() ) ) {
			return true;
		}

		if ( empty( $this->order->get_shipping_first_name() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @return array|null
	 */
	public function getShipping() {
		if ( $this->orderIsVirtual( $this->order ) ) {
			return null;
		}

		return array(
			'address' => array(
				'street'        => self::safe( $this->order->get_shipping_address_1(), 100 ),
				'street2'       => self::safe( $this->order->get_shipping_address_2(), 100 ),
				'city'          => self::safe( $this->order->get_shipping_city(), 100 ),
				'postcodeZip'   => self::safe( $this->order->get_shipping_postcode(), 10 ),
				'country'       => $this->iso2ToIso3( $this->order->get_shipping_country() ),
				'stateProvince' => self::safe( $this->order->get_shipping_state(), 20 )
			),
			'contact' => array(
				'firstName' => self::safe( $this->order->get_shipping_first_name(), 50 ),
				'lastName'  => self::safe( $this->order->get_shipping_last_name(), 50 ),
			),
		);
	}

	/**
	 * @return array
	 */
	public function getCustomer() {
		return array(
			'email'     => $this->order->get_billing_email(),
			'firstName' => self::safe( $this->order->get_billing_first_name(), 50 ),
			'lastName'  => self::safe( $this->order->get_billing_last_name(), 50 ),
		);
	}

	/**
	 * @return array
	 */
	public function getHostedCheckoutOrder() {
		$gateway = new Mastercard_Gateway();

		return array_merge( array(
			'id'          => (string) $gateway->add_order_prefix( $this->order->get_id() ),
			'description' => 'Ordered goods'
		), $this->getOrder() );
	}

	public function getOrder() {
		return array(
			'amount'   => (float) $this->order->get_total(),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * @param bool $capture
	 * @param string|null $returnUrl
	 *
	 * @return array
	 */
	public function getInteraction( $capture = true, $returnUrl = null ) {
		return array(
			'merchant'       => array(
				'name' => esc_html( get_bloginfo( 'name', 'display' ) )
			),
			'returnUrl'      => $returnUrl,
			'displayControl' => array(
				'customerEmail'  => 'HIDE',
				'billingAddress' => 'HIDE',
				'paymentTerms'   => 'HIDE',
				'shipping'       => 'HIDE',
			),
			'operation'      => $capture ? 'PURCHASE' : 'AUTHORIZE',
		);
	}

	/**
	 * @param bool $capture
	 * @param string|null $returnUrl
	 *
	 * @return array
	 * @deprecated
	 *
	 */
	public function getLegacyInteraction( $capture = true, $returnUrl = null ) {
		return array(
			'operation'      => $capture ? 'PURCHASE' : 'AUTHORIZE',
			'merchant'       => array(
				'name' => esc_html( get_bloginfo( 'name', 'display' ) )
			),
			'returnUrl'      => $returnUrl,
			'displayControl' => array(
				'shipping'            => 'HIDE',
				'billingAddress'      => 'HIDE',
				'orderSummary'        => 'HIDE',
				'paymentConfirmation' => 'HIDE',
				'customerEmail'       => 'HIDE'
			)
		);
	}
}
