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

/**
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order $order
 * @var WC_Payment_Gateway_CC $cc_form
 */
?>

<script src="<?php echo $gateway->get_hosted_session_js() ?>"></script>

<style id="antiClickjack">body{display:none !important;}</style>


<form class="mpgs_hostedsession" action="<?php echo $gateway->get_payment_return_url( $order->get_id() ) ?>" method="post">

	<?php $cc_form->payment_fields(); ?>

    <input type="hidden" name="session_id" value="" />
    <input type="hidden" name="session_version" value="" />
    <input type="hidden" name="check_3ds_enrollment" value="" />

    <div id="hostedsession_errors" style="color: red; display: none;" class="errors"></div>

    <p class="form-row form-row-wide">
        <button type="button" id="mpgs_pay" disabled="disabled" onclick="PaymentSession.updateSessionFromForm('card');"><?php echo __( 'Pay', 'woocommerce' ) ?></button>
    </p>
</form>

<script type="text/javascript">
    if (self === top) {
        var antiClickjack = document.getElementById("antiClickjack");
        antiClickjack.parentNode.removeChild(antiClickjack);
    } else {
        top.location = self.location;
    }

    function hsFieldMap() {
        return {
            cardNumber: "#mpgs_gateway-card-number",
            number: "#mpgs_gateway-card-number",
            securityCode: "#mpgs_gateway-card-cvc",
            expiryMonth: "#mpgs_gateway-card-expiry-month",
            expiryYear: "#mpgs_gateway-card-expiry-year"
        };
    }

    function hsErrorsMap() {
        return {
            cardNumber: "<?php echo __( 'Invalid Card Number', 'woocommerce') ?>",
            securityCode: "<?php echo __( 'Invalid Security Code', 'woocommerce') ?>",
            expiryMonth: "<?php echo __( 'Invalid Expiry Month', 'woocommerce') ?>",
            expiryYear: "<?php echo __( 'Invalid Expiry Year', 'woocommerce') ?>"
        };
    }

    function is3DsEnabled() {
        <?php if ($gateway->use_3dsecure()): ?>
            return true;
        <?php else: ?>
            return false;
        <?php endif; ?>
    }

    function placeOrder(data) {
        document.querySelector('form.mpgs_hostedsession > input[name=session_id]').value = data.session.id;
        document.querySelector('form.mpgs_hostedsession > input[name=session_version]').value = data.session.version;
        document.querySelector('form.mpgs_hostedsession').submit();
    }

    (function ($) {
        function togglePay() {
            $('#mpgs_pay').prop('disabled', function (i, v) {
                return !v;
            });
        }

        PaymentSession.configure({
            fields: {
                card: hsFieldMap()
            },
            frameEmbeddingMitigation: ["javascript"],
            callbacks: {
                initialized: function(response) {
                    togglePay();
                },
                formSessionUpdate: function(response) {
                    var fields = hsFieldMap();
                    for (var field in fields) {
                        var input = document.getElementById(fields[field].substr(1));
                        input.style['border-color'] = 'inherit';
                    }

                    var errorsContainer = document.getElementById('hostedsession_errors');
                    errorsContainer.innerText = '';
                    errorsContainer.style.display = 'none';

                    if (!response.status) {
                        errorsContainer.innerText = hsLoadingFailedMsg + ' (invalid response)';
                        errorsContainer.style.display = 'block';
                        return;
                    }

                    if (response.status === "fields_in_error") {
                        if (response.errors) {
                            var errors = hsErrorsMap(),
                                message = "";
                            for (var field in response.errors) {
                                if (!response.errors.hasOwnProperty(field)) {
                                    continue;
                                }

                                var input = document.getElementById(fields[field].substr(1));
                                input.style['border-color'] = 'red';

                                message += errors[field] + "\n";
                            }
                            errorsContainer.innerText = message;
                            errorsContainer.style.display = 'block';
                        }
                    }  else if (response.status === "ok") {
                        if (is3DsEnabled()) {
                            document.querySelector('form.mpgs_hostedsession > input[name=check_3ds_enrollment]').value = '1';
                        }
                        placeOrder(response);
                    } else {
                        errorsContainer.innerText = hsLoadingFailedMsg + ' (unexpected status: '+response.status+')';
                        errorsContainer.style.display = 'block';
                    }
                }
            },
            interaction: {
                displayControl: {
                    invalidFieldCharacters: 'REJECT',
                    formatCard: 'EMBOSSED'
                }
            }
        });
    })(jQuery);
</script>
