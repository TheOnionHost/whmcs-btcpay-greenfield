<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2018 BitPay, BTCPay server (c) 2019-2026
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

function btcpaygreenfield_MetaData()
{
    return [
      'DisplayName' => 'BTCPay Server Greenfield (v1)',
      'failedEmail' => 'Credit Card Payment Failed',
      'successEmail' => 'BTCPay Payment Success',
      'pendingEmail' => 'BTCPay Payment Pending',
      'APIVersion' => '1.1',
    ];
}

/**
 * Returns configuration options array.
 *
 * @return array
 */
function btcpaygreenfield_config()
{
    $configarray = array(
        "FriendlyName" => array(
            "Type" => "System",
            "Value" => "Bitcoin payments via BTCPay Server (Greenfield)"
        ),
        'apiKey' => array(
            'FriendlyName' => 'Greenfield API Key',
            'Type' => 'text',
            'Description' => 'Greenfield API key (Authorization: token <key>). Required scopes: btcpay.store.canviewinvoices and btcpay.store.cancreateinvoice. Create one in BTCPay Server store settings > Access Tokens.',
        ),
        'btcpayUrl' => array(
            'FriendlyName' => 'BTCPay Server URL',
            'Type' => 'text',
            'Description' => 'The URL of your BTCPay Server instance, e.g., https://btcpay.example.com.',
        ),
        'storeId' => array(
            'FriendlyName' => 'Store ID',
            'Type' => 'text',
            'Description' => 'The BTCPay Store ID used for Greenfield API requests.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Description' => 'The signing secret from your BTCPay store webhook. Used to verify BTCPay-Sig on payment callbacks.',
        ),
        'btcpayUrlTor' => array(
            'FriendlyName' => 'BTCPay Server Tor URL (optional)',
            'Type' => 'text',
            'Description' => 'The Tor URL of your BTCPay Server instance. This is optional and only used if WHMCS is accessed via a .onion domain.',
        ),
        'redirectURL' => array(
            'FriendlyName' => 'Redirect URL (optional)',
            'Type' => 'text',
            'Description' => 'URL to redirect to after payment. Leave blank to use the default WHMCS order confirmation page.',
        ),
        'transactionSpeed' => array(
            'FriendlyName' => 'Transaction Speed',
            'Type'         => 'dropdown',
            'Options'      => 'low,medium,high',
            'Default'      => 'medium',
            'Description'  => 'The transaction speed to use for the invoice. Medium is recommended. Maps to BTCPay speedPolicy (LowSpeed/MediumSpeed/HighSpeed).',
        ),
        'paymentTolerance' => array(
            'FriendlyName' => 'Payment Tolerance (%)',
            'Type' => 'text',
            'Default' => '1',
            'Description' => 'Allowed underpayment tolerance when reconciling webhook payments (e.g. 1 = within 1%).',
        ),
        'allowManuallyMarked' => array(
            'FriendlyName' => 'Allow Manually Marked Invoices',
            'Type' => 'yesno',
            'Description' => 'If enabled, invoices manually marked as Settled in BTCPay (additionalStatus: Marked) credit the full WHMCS invoice total even without payment. For testing/staging only unless you intentionally use BTCPay admin mark-as-settled.',
        ),
    );

    return $configarray;
}

/**
 * Returns html form.
 *
 * @param  array  $params
 * @return string
 */
function btcpaygreenfield_link($params)
{
    if (false === isset($params) || true === empty($params)) {
        die('[ERROR] In modules/gateways/btcpaygreenfield.php::btcpaygreenfield_link() function: Missing or invalid $params data.');
    }

    $invoiceid = $params['invoiceid'];
    $email = $params['clientdetails']['email'];

    $parsedurl = parse_url($params['systemurl']);
    $is_tor_enabled = preg_match("/\.onion$/", $_SERVER['HTTP_HOST']) && $params['btcpayUrlTor'] != '';
    if (is_array($parsedurl) && $is_tor_enabled) {
        $systemtor = "http://{$_SERVER['HTTP_HOST']}" . $parsedurl['path'];
    } else {
        $systemtor = "";
    }

    $systemurl = $systemtor != '' ? $systemtor : $params['systemurl'];

    $post = array(
        'invoiceId' => $invoiceid,
        'systemURL' => $systemurl,
        'buyerEmail' => $email,
    );

    $form = '<form action="' . $systemurl . '/modules/gateways/btcpaygreenfield/createinvoice.php" method="POST">';

    foreach ($post as $key => $value) {
        $form .= '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" />';
    }

    $form .= '<input type="submit" value="' . $params['langpaynow'] . '" />';
    $form .= '</form>';

    return $form;
}
