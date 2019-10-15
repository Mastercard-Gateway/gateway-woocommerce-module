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

require_once dirname( __FILE__ ) . '/AbstractBuilder.php';

class Mastercard_Model_CheckoutBuilder extends Mastercard_Model_AbstractBuilder {
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
	 * @return array
	 */
	public function getCustomer() {
		return array(
			'email'     => $this->order->get_billing_email(),
			'firstName' => self::safe($this->order->get_billing_first_name(), 50),
			'lastName'  => self::safe($this->order->get_shipping_last_name(), 50),
		);
	}

	/**
	 * @return array
	 */
	public function getOrder() {
		return array(
			'amount'      => (float) $this->order->get_total(),
			'currency'    => get_woocommerce_currency(),
			'id'          => (string) $this->order->get_id(),
			'description' => 'Ordered goods'
		);
	}

	/**
	 * @return array
	 */
	public function getInteraction() {
		return array(
			'operation' => 'PURCHASE',
			'merchant'  => array(
				'name' => esc_html( get_bloginfo( 'name', 'display' ) )
			),
			'displayControl' => array(
				'shipping' => 'HIDE',
			)
		);
	}
}
