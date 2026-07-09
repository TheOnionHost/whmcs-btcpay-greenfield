<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2015 BitPay, BTCPay server (c) 2019-2026
 */

use WHMCS\Database\Capsule;

include '../../../includes/functions.php';
include '../../../includes/gatewayfunctions.php';
include '../../../includes/invoicefunctions.php';

if (file_exists('../../../dbconnect.php')) {
    include '../../../dbconnect.php';
} else if (file_exists('../../../init.php')) {
    include '../../../init.php';
} else {
    error_log('[ERROR] In modules/gateways/callback/btcpaygreenfield.php: include error: Cannot find dbconnect.php or init.php');
    die('[ERROR] In modules/gateways/callback/btcpaygreenfield.php: include error: Cannot find dbconnect.php or init.php');
}

require_once '../btcpaygreenfield/greenfield.php';

$gatewaymodule = 'btcpaygreenfield';
$GATEWAY = getGatewayVariables($gatewaymodule);

if (!$GATEWAY['type']) {
    logTransaction($GATEWAY['name'], array(), 'Not activated');
    btcpaygreenfieldCallbackReject('BTCPay Greenfield module not activated.', 400);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    logTransaction($GATEWAY['name'], array(), 'Empty webhook body');
    btcpaygreenfieldCallbackReject('Empty webhook body', 400);
}

$webhookSecret = isset($GATEWAY['webhookSecret']) ? trim($GATEWAY['webhookSecret']) : '';
if ($webhookSecret === '') {
    logTransaction($GATEWAY['name'], array('body' => $rawBody), 'Webhook secret not configured');
    btcpaygreenfieldCallbackReject('Webhook secret not configured', 400);
}

$sigHeader = btcpaygreenfieldGetWebhookSignatureHeader();
if (!btcpaygreenfieldVerifyWebhookSignature($rawBody, $webhookSecret, $sigHeader)) {
    logTransaction($GATEWAY['name'], array('body' => $rawBody, 'sig' => $sigHeader), 'Invalid webhook signature');
    btcpaygreenfieldCallbackReject('Invalid webhook signature', 400);
}

$webhookPayload = json_decode($rawBody, true);
if (!is_array($webhookPayload)) {
    logTransaction($GATEWAY['name'], array('body' => $rawBody), 'Invalid webhook JSON');
    btcpaygreenfieldCallbackReject('Invalid webhook JSON', 400);
}

$btcpayInvoiceId = isset($webhookPayload['invoiceId']) ? trim((string) $webhookPayload['invoiceId']) : '';
if ($btcpayInvoiceId === '') {
    logTransaction($GATEWAY['name'], $webhookPayload, 'Missing invoiceId in webhook');
    btcpaygreenfieldCallbackReject('Missing invoiceId in webhook', 400);
}

if (empty($GATEWAY['apiKey']) || empty($GATEWAY['btcpayUrl']) || empty($GATEWAY['storeId'])) {
    logTransaction($GATEWAY['name'], $webhookPayload, 'Gateway not configured');
    btcpaygreenfieldCallbackReject('Gateway not configured', 400);
}

$btcpayUrl = btcpaygreenfieldNormalizeBaseUrl($GATEWAY['btcpayUrl']);
$configuredStoreId = trim($GATEWAY['storeId']);
$invoicePath = '/api/v1/stores/' . rawurlencode($configuredStoreId)
    . '/invoices/' . rawurlencode($btcpayInvoiceId);

$invoiceResponse = btcpaygreenfieldApiRequest($btcpayUrl, $GATEWAY['apiKey'], 'GET', $invoicePath);

if ($invoiceResponse['error'] !== null) {
    logTransaction($GATEWAY['name'], array('invoiceId' => $btcpayInvoiceId, 'error' => $invoiceResponse['error']), 'Failed to fetch invoice');
    btcpaygreenfieldCallbackReject('Failed to fetch invoice: ' . $invoiceResponse['error'], 400);
}

if ($invoiceResponse['httpCode'] !== 200 || !is_array($invoiceResponse['body'])) {
    logTransaction($GATEWAY['name'], array('invoiceId' => $btcpayInvoiceId, 'httpCode' => $invoiceResponse['httpCode']), 'Invoice fetch failed');
    btcpaygreenfieldCallbackReject('Invoice fetch failed (HTTP ' . $invoiceResponse['httpCode'] . ')', 400);
}

$invoice = $invoiceResponse['body'];

if (!isset($invoice['id']) || (string) $invoice['id'] !== $btcpayInvoiceId) {
    logTransaction($GATEWAY['name'], $invoice, 'Invoice ID mismatch');
    btcpaygreenfieldCallbackReject('Invoice ID mismatch', 400);
}

if (isset($invoice['storeId']) && (string) $invoice['storeId'] !== $configuredStoreId) {
    logTransaction($GATEWAY['name'], $invoice, 'Store ID mismatch');
    btcpaygreenfieldCallbackReject('Store ID mismatch', 400);
}

$invoiceStatus = isset($invoice['status']) ? (string) $invoice['status'] : '';
$ignoredStatuses = array('New', 'Processing', 'Expired', 'Invalid');

if (in_array($invoiceStatus, $ignoredStatuses, true)) {
    logTransaction($GATEWAY['name'], $invoice, 'Ignored invoice status: ' . $invoiceStatus);
    btcpaygreenfieldCallbackOk('OK');
}

if ($invoiceStatus !== 'Settled') {
    logTransaction($GATEWAY['name'], $invoice, 'Unhandled invoice status: ' . $invoiceStatus);
    btcpaygreenfieldCallbackOk('OK');
}

$orderId = '';
if (isset($invoice['metadata']) && is_array($invoice['metadata']) && isset($invoice['metadata']['orderId'])) {
    $orderId = trim((string) $invoice['metadata']['orderId']);
}

if ($orderId === '') {
    logTransaction($GATEWAY['name'], $invoice, 'Missing orderId in invoice metadata');
    btcpaygreenfieldCallbackReject('Missing orderId in invoice metadata', 400);
}

$whmcsId = checkCbInvoiceID($orderId, $GATEWAY['name']);

$whmcsInvoice = Capsule::table('tblinvoices')->where('id', $whmcsId)->first();
if (!$whmcsInvoice) {
    logTransaction($GATEWAY['name'], $invoice, 'WHMCS invoice not found');
    btcpaygreenfieldCallbackReject('WHMCS invoice not found', 400);
}

$paymentMethod = isset($whmcsInvoice->paymentmethod) ? trim((string) $whmcsInvoice->paymentmethod) : '';
if ($paymentMethod !== '' && $paymentMethod !== $gatewaymodule) {
    logTransaction($GATEWAY['name'], array('invoice' => $invoice, 'paymentmethod' => $paymentMethod), 'Gateway mismatch');
    btcpaygreenfieldCallbackReject('Gateway mismatch', 400);
}

checkCbTransID($btcpayInvoiceId);

$whmcsTotal = (float) $whmcsInvoice->total;
$isManuallyMarked = isset($invoice['additionalStatus']) && $invoice['additionalStatus'] === 'Marked';
$allowManual = isset($GATEWAY['allowManuallyMarked']) && $GATEWAY['allowManuallyMarked'] === 'on';

if ($isManuallyMarked && !$allowManual) {
    logTransaction($GATEWAY['name'], $invoice, 'Manually marked invoice rejected (option disabled)');
    btcpaygreenfieldCallbackReject('Manually marked invoice rejected (option disabled)', 400);
}

if ($isManuallyMarked && $allowManual) {
    $amount = round($whmcsTotal, 2);

    if ($amount <= 0) {
        logTransaction($GATEWAY['name'], array('whmcsTotal' => $whmcsTotal), 'Computed credit amount is zero');
        btcpaygreenfieldCallbackReject('Computed credit amount is zero', 400);
    }

    $fee = 0;
    addInvoicePayment($whmcsId, $btcpayInvoiceId, $amount, $fee, $gatewaymodule);

    logTransaction(
        $GATEWAY['name'],
        array(
            'invoice' => $invoice,
            'whmcsId' => $whmcsId,
            'transId' => $btcpayInvoiceId,
            'amount' => $amount,
            'manuallyMarked' => true,
        ),
        'Manually marked invoice credited'
    );

    btcpaygreenfieldCallbackOk('OK');
}

$btcpayAmount = isset($invoice['amount']) ? (float) $invoice['amount'] : 0.0;
$apiPaidAmount = isset($invoice['paidAmount']) ? (float) $invoice['paidAmount'] : 0.0;

$paymentMethodsPath = '/api/v1/stores/' . rawurlencode($configuredStoreId)
    . '/invoices/' . rawurlencode($btcpayInvoiceId) . '/payment-methods';
$paymentMethodsResponse = btcpaygreenfieldApiRequest($btcpayUrl, $GATEWAY['apiKey'], 'GET', $paymentMethodsPath);

if ($paymentMethodsResponse['error'] !== null) {
    logTransaction($GATEWAY['name'], array('invoiceId' => $btcpayInvoiceId, 'error' => $paymentMethodsResponse['error']), 'Failed to fetch payment methods');
    btcpaygreenfieldCallbackReject('Failed to fetch payment methods: ' . $paymentMethodsResponse['error'], 400);
}

if ($paymentMethodsResponse['httpCode'] !== 200 || !is_array($paymentMethodsResponse['body'])) {
    logTransaction($GATEWAY['name'], array('invoiceId' => $btcpayInvoiceId, 'httpCode' => $paymentMethodsResponse['httpCode']), 'Payment methods fetch failed');
    btcpaygreenfieldCallbackReject('Payment methods fetch failed (HTTP ' . $paymentMethodsResponse['httpCode'] . ')', 400);
}

$paidFiat = btcpaygreenfieldSumFiatPaidFromPaymentMethods($paymentMethodsResponse['body']);

if ($apiPaidAmount > 0) {
    $paidFiat = max($paidFiat, $apiPaidAmount);
}

$tolerance = 1.0;
if (isset($GATEWAY['paymentTolerance']) && $GATEWAY['paymentTolerance'] !== '') {
    $tolerance = max(0.0, (float) $GATEWAY['paymentTolerance']);
}

$minimumPaid = $btcpayAmount * (1 - ($tolerance / 100));

if ($btcpayAmount <= 0) {
    logTransaction($GATEWAY['name'], $invoice, 'Invalid BTCPay invoice amount');
    btcpaygreenfieldCallbackReject('Invalid BTCPay invoice amount', 400);
}

if ($paidFiat < $minimumPaid) {
    logTransaction(
        $GATEWAY['name'],
        array(
            'invoice' => $invoice,
            'paidFiat' => $paidFiat,
            'minimumPaid' => $minimumPaid,
            'tolerance' => $tolerance,
        ),
        'Underpayment rejected'
    );
    btcpaygreenfieldCallbackReject('Underpayment rejected', 400);
}

$amount = btcpaygreenfieldComputeWhmcsCreditAmount($paidFiat, $btcpayAmount, $whmcsTotal);

if ($amount <= 0) {
    logTransaction($GATEWAY['name'], array('paidFiat' => $paidFiat, 'whmcsTotal' => $whmcsTotal), 'Computed credit amount is zero');
    btcpaygreenfieldCallbackReject('Computed credit amount is zero', 400);
}

$fee = 0;
addInvoicePayment($whmcsId, $btcpayInvoiceId, $amount, $fee, $gatewaymodule);

logTransaction(
    $GATEWAY['name'],
    array(
        'invoice' => $invoice,
        'whmcsId' => $whmcsId,
        'transId' => $btcpayInvoiceId,
        'amount' => $amount,
        'paidFiat' => $paidFiat,
    ),
    'Payment credited'
);

btcpaygreenfieldCallbackOk('OK');
