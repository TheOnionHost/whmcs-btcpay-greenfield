<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2018 BitPay, BTCPay server (c) 2019-2026
 */

use WHMCS\Database\Capsule;

include '../../../includes/functions.php';
include '../../../includes/gatewayfunctions.php';
include '../../../includes/invoicefunctions.php';

require 'greenfield.php';

if (file_exists('../../../dbconnect.php')) {
    include '../../../dbconnect.php';
} else if (file_exists('../../../init.php')) {
    include '../../../init.php';
} else {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: include error: Cannot find dbconnect.php or init.php');
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: include error: Cannot find dbconnect.php or init.php');
}

$gatewaymodule = 'btcpaygreenfield';
$GATEWAY = getGatewayVariables($gatewaymodule);

if (empty($_POST['invoiceId'])) {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Missing invoiceId');
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Missing invoiceId');
}

$invoiceId = (int) $_POST['invoiceId'];

if (empty($GATEWAY['apiKey']) || empty($GATEWAY['btcpayUrl']) || empty($GATEWAY['storeId'])) {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Gateway not configured (apiKey, btcpayUrl, storeId required)');
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Gateway not configured');
}

$result = Capsule::connection()->select(
    'SELECT tblinvoices.total, tblinvoices.status, tblinvoices.userid, tblcurrencies.code
     FROM tblinvoices
     INNER JOIN tblclients ON tblinvoices.userid = tblclients.id
     INNER JOIN tblcurrencies ON tblclients.currency = tblcurrencies.id
     WHERE tblinvoices.id = ?',
    array($invoiceId)
);

if (empty($result)) {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: No invoice found for invoice id #' . $invoiceId);
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invalid invoice id #' . $invoiceId);
}

$data = (array) $result[0];
$price = $data['total'];
$currency = $data['code'];
$status = $data['status'];
$invoiceUserId = (int) $data['userid'];

if (!isset($_SESSION['uid']) || (int) $_SESSION['uid'] !== $invoiceUserId) {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: IDOR rejected for invoice #' . $invoiceId);
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Access denied');
}

if ($status != 'Unpaid') {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invoice status must be Unpaid. Status: ' . $status);
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Bad invoice status of ' . $status);
}

$convertTo = false;
$convertResult = Capsule::connection()->select(
    "SELECT value FROM tblpaymentgateways WHERE gateway = ? AND setting = 'convertto'",
    array($gatewaymodule)
);

if (!empty($convertResult)) {
    $convertData = (array) $convertResult[0];
    if (!empty($convertData['value'])) {
        $convertTo = $convertData['value'];
    }
}

if ($convertTo) {
    $currentCurrencyResult = Capsule::connection()->select(
        'SELECT rate FROM tblcurrencies WHERE code = ?',
        array($currency)
    );
    $currentCurrency = !empty($currentCurrencyResult) ? (array) $currentCurrencyResult[0] : null;

    if (!$currentCurrency) {
        btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invalid invoice currency of ' . $currency);
        die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invalid invoice currency of ' . $currency);
    }

    $convertToCurrencyResult = Capsule::connection()->select(
        'SELECT code, rate FROM tblcurrencies WHERE id = ?',
        array((int) $convertTo)
    );
    $convertToCurrency = !empty($convertToCurrencyResult) ? (array) $convertToCurrencyResult[0] : null;

    if (!$convertToCurrency) {
        btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invalid convertTo currency of ' . $convertTo);
        die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invalid convertTo currency of ' . $convertTo);
    }

    $currency = $convertToCurrency['code'];
    $price = $price / $currentCurrency['rate'] * $convertToCurrency['rate'];
}

$systemURL = isset($_POST['systemURL']) ? $_POST['systemURL'] : '';
$buyerEmail = isset($_POST['buyerEmail']) ? $_POST['buyerEmail'] : '';

$redirectURL = !empty($GATEWAY['redirectURL'])
    ? $GATEWAY['redirectURL']
    : $systemURL . 'viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true';

$requestBody = array(
    'amount' => btcpaygreenfieldFormatAmount($price),
    'currency' => $currency,
    'metadata' => array(
        'orderId' => (string) $invoiceId,
        'buyerEmail' => $buyerEmail,
    ),
    'checkout' => array(
        'redirectURL' => $redirectURL,
        'speedPolicy' => btcpaygreenfieldMapSpeedPolicy($GATEWAY['transactionSpeed']),
    ),
);

$btcpayUrl = btcpaygreenfieldNormalizeBaseUrl($GATEWAY['btcpayUrl']);
$btcpayUrlTor = !empty($GATEWAY['btcpayUrlTor']) ? btcpaygreenfieldNormalizeBaseUrl($GATEWAY['btcpayUrlTor']) : '';
$storeId = trim($GATEWAY['storeId']);
$path = '/api/v1/stores/' . rawurlencode($storeId) . '/invoices';

$response = btcpaygreenfieldApiRequest($btcpayUrl, $GATEWAY['apiKey'], 'POST', $path, $requestBody);

if ($response['error'] !== null) {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: API error: ' . $response['error']);
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Invoice error: ' . $response['error']);
}

if ($response['httpCode'] !== 200 || empty($response['body'])) {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: API HTTP ' . $response['httpCode'] . ' body: ' . $response['rawBody']);
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Failed to create invoice (HTTP ' . $response['httpCode'] . ')');
}

$invoice = $response['body'];
$checkoutLink = '';

if (!empty($invoice['checkoutLink'])) {
    $checkoutLink = $invoice['checkoutLink'];
} elseif (!empty($invoice['id'])) {
    $checkoutLink = btcpaygreenfieldBuildUrl($btcpayUrl, '/i/' . $invoice['id']);
}

if ($checkoutLink === '') {
    btcpaygreenfieldLog('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: No checkout link in API response');
    die('[ERROR] In modules/gateways/btcpaygreenfield/createinvoice.php: Something went wrong when creating the invoice and redirecting to BTCPay Server.');
}

$is_tor_enabled = preg_match("/\.onion$/", $_SERVER['HTTP_HOST']) && $btcpayUrlTor !== '';
if ($is_tor_enabled) {
    $checkoutLink = str_replace($btcpayUrl, $btcpayUrlTor, $checkoutLink);
}

header('Location: ' . $checkoutLink);
exit;
