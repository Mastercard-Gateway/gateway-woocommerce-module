<?php
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

class Mastercard_Payment_Gateway_CC extends WC_Payment_Gateway_CC
{
	/**
	 * Outputs fields for entering credit card information.
	 */
	public function form() {
		wp_enqueue_script( 'wc-credit-card-form' );

		$fields = array();

		$cvc_field = '<p class="form-row form-row-wide">
			<label for="' . esc_attr( $this->id ) . '-card-cvc">' . esc_html__( 'Card code', 'mastercard' ) . '&nbsp;<span class="required">*</span></label>
			<input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" readonly="readonly" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" />
		</p>';

		$default_fields = array(
			'card-number-field' => '<p class="form-row form-row-wide">
				<label for="' . esc_attr( $this->id ) . '-card-number">' . esc_html__( 'Card number', 'mastercard' ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" readonly="readonly" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" ' . $this->field_name( 'card-number' ) . ' />
			</p>',
			'card-expiry-month-field' => '<p class="form-row form-row-first">
				<label for="' . esc_attr( $this->id ) . '-card-expiry-month">' . esc_html__( 'Expiry (MM)', 'mastercard' ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-expiry-month" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" readonly="readonly" autocomplete="cc-exp-month" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" ' . $this->field_name( 'card-expiry-month' ) . ' />
			</p>',
			'card-expiry-year-field' => '<p class="form-row form-row-last">
				<label for="' . esc_attr( $this->id ) . '-card-expiry-year">' . esc_html__( 'Expiry (YY)', 'mastercard' ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-expiry-year" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" readonly="readonly" autocomplete="cc-exp-year" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" ' . $this->field_name( 'card-expiry-year' ) . ' />
			</p>',
		);

        $default_fields['card-cvc-field'] = $cvc_field;

		$fields = wp_parse_args( $fields, apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, $this->id ) );
		?>

		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class='wc-credit-card-form wc-payment-form'>
			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
			<?php
			foreach ( $fields as $field ) {
				echo $field; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php

		foreach ( $this->get_tokens() as $token ) {
			echo '<fieldset class="token-cvc" id="token-cvc-'.$token->get_id().'">
                <p class="form-row form-row-wide">
			        <label for="' . esc_attr( $this->id ) . '-saved-card-cvc-'.$token->get_id().'">' . esc_html__( 'Card code', 'mastercard' ) . '&nbsp;<span class="required">*</span></label>
			        <input id="' . esc_attr( $this->id ) . '-saved-card-cvc-'.$token->get_id().'" class="input-text wc-credit-card-form-card-cvc" readonly="readonly" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" style="width:100px" />
                </p>
            </fieldset>';
		}

	}
}
