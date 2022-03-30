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

/**
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order $order
 * @var WC_Payment_Gateway_CC $cc_form
 */
?>
<script src="<?php echo $gateway->get_hosted_session_js() ?>"></script>

<?php if ($gateway->use_3dsecure_v1() || $gateway->use_3dsecure_v2()): ?>
<script src="<?php echo $gateway->get_threeds_js() ?>"></script>
<?php endif; ?>

<style id="antiClickjack">body{display:none !important;}</style>

<div id="3DSUI"></div>

<form class="mpgs_hostedsession wc-payment-form" action="<?php echo $gateway->get_payment_return_url( $order->get_id() ) ?>" method="post">

    <div class="payment_box">
        <?php $cc_form->payment_fields(); ?>
    </div>

    <input type="hidden" name="session_id" value="" />
    <input type="hidden" name="session_version" value="" />
    <input type="hidden" name="check_3ds_enrollment" value="" />

    <div id="hostedsession_errors" style="color: red; display: none;" class="errors"></div>

    <p class="form-row form-row-wide">
        <button type="button" id="mpgs_pay" onclick="mpgsPayWithSelectedInstrument()"><?php echo __( 'Pay', 'mastercard' ) ?></button>
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

    function mpgsPayWithSelectedInstrument() {
        var selected = document.querySelectorAll('[name=wc-mpgs_gateway-payment-token]:checked')[0];
        if (selected === undefined) {
            // Options not displayed at all
            PaymentSession.updateSessionFromForm('card', undefined, 'new');
        } else if (selected.value === 'new') {
            // New card options was selected
            PaymentSession.updateSessionFromForm('card', undefined, 'new');
        } else {
            // Token
            PaymentSession.updateSessionFromForm('card', undefined, selected.value);
        }
    }

    (function ($) {
        var paymentSessionLoaded = {};

        $(':input.woocommerce-SavedPaymentMethods-tokenInput').on('change', function () {
            $('.token-cvc').hide();
            $('#token-cvc-' + $(this).val()).show();
        });

        $.when(createSession()).done(function (response) {
            if (is3DsV2Enabled()) {
                ThreeDS.configure({
                    merchantId: '<?php echo $gateway->get_merchant_id() ?>',
                    sessionId: response.session.id,
                    containerId: "3DSUI",
                    callback: function () {
                    },
                    configuration: {
                        wsVersion: <?php echo $gateway->get_api_version_num() ?>
                    }
                });
            }

            var tokenChoices = $('[name=wc-mpgs_gateway-payment-token]');
            if (tokenChoices.length > 1) {
                tokenChoices.on('change', function() {
                    initSelectedPaymentMethod(response);
                });
                initSelectedPaymentMethod(response);
            } else {
                initializeNewPaymentSession(response.session.id);
            }
        })
        .fail(console.error);

        function initSelectedPaymentMethod(response) {
            var errorsContainer = document.getElementById('hostedsession_errors');
            errorsContainer.style.display = 'none';

            var selectedPayment = $('[name=wc-mpgs_gateway-payment-token]:checked').val();
            if ('new' === selectedPayment) {
                initializeNewPaymentSession(response.session.id);
            } else {
                initializeTokenPaymentSession(response.session.id, selectedPayment);
            }
        }

        function is3DsV1Enabled() {
		    <?php if ($gateway->use_3dsecure_v1()): ?>
            return true;
		    <?php else: ?>
            return false;
		    <?php endif; ?>
        }

        function is3DsV2Enabled() {
		    <?php if ($gateway->use_3dsecure_v2()): ?>
            return true;
		    <?php else: ?>
            return false;
		    <?php endif; ?>
        }

        function initiateAuthentication() {
            var txnId = '3DS-' + new Date().getTime().toString();

            ThreeDS.initiateAuthentication(
                '<?php echo $gateway->add_order_prefix($order->get_id()) ?>',
                txnId,
                function (data) {
                    authenticatePayer(txnId, data);
                }
            );
        }

        function displayChallengeAuth(data) {
            if (!data.error) {
                document.body.innerHTML = data.htmlRedirectCode;
            } else {
                placeOrderFail(data.error);
            }
        }

        function authenticatePayer(txnId, data) {
            if (data && data.error) {
                var error = data.error;
                console.error("error.code : ", error.code);
                console.error("error.msg : ", error.msg);
                console.error("error.result : ", error.result);
                console.error("error.status : ", error.status);
                placeOrderFail(error);
            } else {
                switch (data.gatewayRecommendation) {
                    case "PROCEED":
                        ThreeDS.authenticatePayer(
                            '<?php echo $gateway->add_order_prefix($order->get_id()) ?>',
                            txnId,
                            displayChallengeAuth,
                            {
                                fullScreenRedirect: true
                            }
                        );
                        break;
                    case "DO_NOT_PROCEED":
                        // merchant's method, you can offer the payer the option to try another payment method.
                        alert("Payment was declined, please try again later.");
                        break;
                }
            }
        }

        function placeOrderFail (error) {
            alert("Payment was declined, please try again later.");
        }

        function getPaymentData() {
            return {
                '_wpnonce': '<?php echo wp_create_nonce( 'wp_rest' ) ?>',
                'save_new_card': $('[name=wc-mpgs_gateway-new-payment-method]').is(':checked'),
                'wc-mpgs_gateway-payment-token': $('[name=wc-mpgs_gateway-payment-token]').val()
            }
        }

        function savePayment(data) {
            return $.ajax({
                url: '<?php echo $gateway->get_save_payment_url( $order->get_id() ) ?>',
                method: 'post',
                data: data,
                dataType: 'json'
            });
        }

        function placeOrder(response) {
            $.when(savePayment(
                getPaymentData()
            )).done(function (response) {
                    if (is3DsV2Enabled()) {
                        initiateAuthentication();
                    } else {
                        document.querySelector('form.mpgs_hostedsession > input[name=session_id]').value = response.session.id;
                        document.querySelector('form.mpgs_hostedsession > input[name=session_version]').value = response.session.version;
                        document.querySelector('form.mpgs_hostedsession').submit();
                    }
                }).fail(console.error);
        }

        function initializeTokenPaymentSession(session_id, id) {
            if (paymentSessionLoaded[id] === true) {
                return;
            }

            var config = {
                session: session_id,
                fields: {
                    card: {
                        securityCode: '#mpgs_gateway-saved-card-cvc-' + id
                    }
                },
                frameEmbeddingMitigation: ["javascript"],
                callbacks: {
                    formSessionUpdate: function (response) {
                        var errorsContainer = document.getElementById('hostedsession_errors');
                        errorsContainer.innerText = '';
                        errorsContainer.style.display = 'none';

                        if (!response.status) {
                            errorsContainer.innerText = hsLoadingFailedMsg + ' (invalid response)';
                            errorsContainer.style.display = 'block';
                            return;
                        }
                        if (response.status === "ok") {
                            if (is3DsV1Enabled()) {
                                document.querySelector('form.mpgs_hostedsession > input[name=check_3ds_enrollment]').value = '1';
                            }
                            placeOrder(response);
                        } else {
                            errorsContainer.innerText = hsLoadingFailedMsg + ' (unexpected status: ' + response.status + ')';
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
            };

            PaymentSession.configure(config, id);
            paymentSessionLoaded[id] = true;
        }

        function createSession() {
            return $.ajax({
                url: '<?php echo $gateway->get_create_session_url( $order->get_id() ) ?>',
                method: 'get',
                dataType: 'json'
            });
        }

        function initializeNewPaymentSession(session_id) {
            if (paymentSessionLoaded['new'] === true) {
                return;
            }

            var config = {
                session: session_id,
                fields: {
                    card: hsFieldMap()
                },
                frameEmbeddingMitigation: ["javascript"],
                callbacks: {
                    formSessionUpdate: function (response) {
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
                        } else if (response.status === "ok") {
                            if (is3DsV1Enabled()) {
                                document.querySelector('form.mpgs_hostedsession > input[name=check_3ds_enrollment]').value = '1';
                            }
                            placeOrder(response);
                        } else {
                            errorsContainer.innerText = hsLoadingFailedMsg + ' (unexpected status: ' + response.status + ')';
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
            };

            PaymentSession.configure(config, 'new');
            paymentSessionLoaded['new'] = true;
        }
    })(jQuery);
</script>
