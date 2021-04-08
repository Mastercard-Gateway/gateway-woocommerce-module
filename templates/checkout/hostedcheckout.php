<?php
/**
 * Copyright (c) 2019-2020 Mastercard
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
 */
?>

<?php if ( $gateway->use_modal() ): ?>
    <input type="button" id="mpgs_pay" value="<?php echo __( 'Pay', 'mastercard' ) ?>" onclick="Checkout.showLightbox();"/>
<?php else: ?>
    <input type="button" id="mpgs_pay" value="<?php echo __( 'Pay', 'mastercard' ) ?>" onclick="Checkout.showPaymentPage();"/>
<?php endif; ?>

<script async src="<?php echo $gateway->get_hosted_checkout_js() ?>"
        data-error="errorCallback"
        data-cancel="cancelCallback">
</script>
<script type="text/javascript">
    function errorCallback(error) {
        var err = JSON.stringify(error);
        console.error(err);
        alert('Error: ' + JSON.stringify(error));
    }

    function cancelCallback() {
        window.location.href = '<?php echo $order->get_cancel_order_url() ?>';
    }

    (function ($) {
        function togglePay() {
            $('#mpgs_pay').prop('disabled', function (i, v) {
                return !v;
            });
        }

        function waitFor(name, callback) {
            if (typeof window[name] === "undefined") {
                setTimeout(function () {
                    waitFor(name, callback);
                }, 200);
            } else {
                callback();
            }
        }

        function configureHostedCheckout(sessionData) {
            var config = {
                merchant: '<?php echo $gateway->get_merchant_id() ?>',
                session: {
                    id: sessionData.session.id,
                    version: sessionData.session.version
                }
            };

            waitFor('Checkout', function () {
                Checkout.configure(config);
                togglePay();
            });
        }

        var xhr = $.ajax({
            method: 'GET',
            url: '<?php echo $gateway->get_create_checkout_session_url( $order->get_id() ) ?>',
            dataType: 'json'
        });

        togglePay();

        $.when(xhr)
            .done($.proxy(configureHostedCheckout, this))
            .fail($.proxy(errorCallback, this));

    })(jQuery);
</script>
