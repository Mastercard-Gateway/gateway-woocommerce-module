/*
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
jQuery(function ($) {
    'use strict';
    var wc_mastercard_admin = {
        init: function () {
            var sandbox_username = $('#woocommerce_mpgs_gateway_sandbox_username').parents('tr').eq(0),
                sandbox_password = $('#woocommerce_mpgs_gateway_sandbox_password').parents('tr').eq(0),
                username = $('#woocommerce_mpgs_gateway_username').parents('tr').eq(0),
                password = $('#woocommerce_mpgs_gateway_password').parents('tr').eq(0),
                threedsecure = $('#woocommerce_mpgs_gateway_threedsecure').parents('tr').eq(0),
                gateway_url = $('#woocommerce_mpgs_gateway_custom_gateway_url').parents('tr').eq(0),
                hc_interaction = $('#woocommerce_mpgs_gateway_hc_interaction').parents('tr').eq(0),
                hc_type = $('#woocommerce_mpgs_gateway_hc_type').parents('tr').eq(0),
                saved_cards = $('#woocommerce_mpgs_gateway_saved_cards').parents('tr').eq(0);

            $('#woocommerce_mpgs_gateway_sandbox').on('change', function () {
                if ($(this).is(':checked')) {
                    sandbox_username.show();
                    sandbox_password.show();
                    username.hide();
                    password.hide();
                } else {
                    sandbox_username.hide();
                    sandbox_password.hide();
                    username.show();
                    password.show();
                }
            }).change();

            $('#woocommerce_mpgs_gateway_method').on('change', function () {
                if ($(this).val() === 'newhostedcheckout') {
                    // Hosted Checkout
                    threedsecure.hide();
                    hc_interaction.show();
                    hc_type.hide();
                    saved_cards.hide();
                } else if ($(this).val() === 'hostedcheckout') {
                    // Legacy Hosted Checkout
                    // @todo Remove after removal of Legacy Hosted Checkout
                    threedsecure.hide();
                    hc_interaction.hide();
                    hc_type.show();
                    saved_cards.hide();
                } else {
                    // Hosted Session
                    threedsecure.show();
                    hc_interaction.hide();
                    hc_type.hide();
                    saved_cards.show();
                }
            }).change();

            $('#woocommerce_mpgs_gateway_gateway_url').on('change', function () {
                if ($(this).val() === 'custom') {
                    gateway_url.show();
                } else {
                    gateway_url.hide();
                }
            }).change();
        }
    };
    wc_mastercard_admin.init();
});
