<?php
/**
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order $order
 */
?>

<script async src="<?php echo $gateway->get_hosted_checkout_js() ?>"
        data-error="errorCallback"
        data-cancel="cancelCallback"
        data-complete="completeCallback">
</script>

<script type="text/javascript">
    function errorCallback(error) {
        console.log(JSON.stringify(error));
    }

    function cancelCallback() {
        window.location.href = '<?php echo $order->get_cancel_order_url() ?>';
    }

    function completeCallback(resultIndicator, sessionVersion) {
        window.location.href = '<?php echo $order->get_checkout_payment_url() ?>';
    }

    (function ($) {
        function configureHostedCheckout(sessionData) {
            let config = {
                merchant: '<?php echo $gateway->get_merchant_id() ?>',
                session: {
                    id: sessionData.session.id,
                    version: sessionData.session.version
                },
                order: {
                    description: 'Customer Order'
                }
            };
            Checkout.configure(config);
        }

        let xhr = $.ajax({
            method: 'GET',
            url: '<?php echo $gateway->get_create_session_url( $order->get_id() ) ?>',
            dataType: 'json'
        });

        $.when(xhr)
            .done($.proxy(configureHostedCheckout, this))
            .fail($.proxy(errorCallback, this));

    })(jQuery);
</script>

<input type="button" value="Pay with Lightbox" onclick="Checkout.showLightbox();"/>
<input type="button" value="Pay with Payment Page" onclick="Checkout.showPaymentPage();"/>
