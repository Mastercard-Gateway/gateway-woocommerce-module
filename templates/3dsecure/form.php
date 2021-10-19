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

/**
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order
 * @var array $authenticationRedirect
 * @var string $returnUrl
 */
?>
<!doctype html>
<html>
    <head>
        <title><?php echo __( 'Processing Secure Payment', 'mastercard' ) ?></title>
        <meta http-equiv="content-type" content="text/html; charset=utf-8"/>
        <meta name="description" content="<?php echo __( 'Processing Secure Payment', 'mastercard' ) ?>"/>
        <meta name="robots" content="noindex"/>
        <style type="text/css">
            body {
                font-family: "Trebuchet MS", sans-serif;
                background-color: #FFFFFF;
            }

            #msg {
                border: 5px solid #666;
                background-color: #fff;
                margin: 20px;
                padding: 25px;
                max-width: 40em;
                -webkit-border-radius: 10px;
                -khtml-border-radius: 10px;
                -moz-border-radius: 10px;
                border-radius: 10px;
            }

            #submitButton {
                text-align: center;
            }

            #footnote {
                font-size: 0.8em;
            }
        </style>
    </head>
<?php if ( ! isset( $authenticationRedirect['acsUrl'], $authenticationRedirect['paReq'] ) ): ?>
    <body>
        <p>Data Error</p>
    </body>
<?php else: ?>
    <body onload="return window.document.echoForm.submit()">
        <form name="echoForm" method="post" action="<?php echo $authenticationRedirect['acsUrl'] ?>" accept-charset="UTF-8"
              id="echoForm">
            <input type="hidden" name="PaReq" value="<?php echo $authenticationRedirect['paReq'] ?>"/>
            <input type="hidden" name="TermUrl" value="<?php echo $returnUrl ?>"/>
            <input type="hidden" name="MD" value=""/>
            <noscript>
                <div id="msg">
                    <div id="submitButton">
                        <input type="submit" value="<?php echo __( 'Click here to continue', 'mastercard' ) ?>"
                               class="button"/>
                    </div>
                </div>
            </noscript>
        </form>
    </body>
<?php endif; ?>
</html>
