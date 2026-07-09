<?php
/**
 * BTCPay Server Greenfield API helper functions.
 *
 * Copyright (c) 2011-2018 BitPay, BTCPay server (c) 2019-2026
 *
 * The MIT License (MIT)
 */

/**
 * @param string $message
 */
function btcpaygreenfieldLog($message)
{
    error_log($message);
}

/**
 * @param  string $baseUrl
 * @return string
 */
function btcpaygreenfieldNormalizeBaseUrl($baseUrl)
{
    return rtrim(trim($baseUrl), '/');
}

/**
 * @param  string $baseUrl
 * @param  string $path
 * @return string
 */
function btcpaygreenfieldBuildUrl($baseUrl, $path)
{
    return btcpaygreenfieldNormalizeBaseUrl($baseUrl) . '/' . ltrim($path, '/');
}

/**
 * @param  float|string $amount
 * @return string
 */
function btcpaygreenfieldFormatAmount($amount)
{
    return number_format((float) $amount, 8, '.', '');
}

/**
 * @param  string $transactionSpeed
 * @return string
 */
function btcpaygreenfieldMapSpeedPolicy($transactionSpeed)
{
    $map = array(
        'low' => 'LowSpeed',
        'medium' => 'MediumSpeed',
        'high' => 'HighSpeed',
    );

    $speed = strtolower(trim((string) $transactionSpeed));

    return isset($map[$speed]) ? $map[$speed] : 'MediumSpeed';
}

/**
 * @param  string      $baseUrl
 * @param  string      $apiKey
 * @param  string      $method
 * @param  string      $path
 * @param  array|null  $body
 * @return array
 */
function btcpaygreenfieldApiRequest($baseUrl, $apiKey, $method, $path, $body = null)
{
    $url = btcpaygreenfieldBuildUrl($baseUrl, $path);
    $curl = curl_init($url);

    $headers = array(
        'Authorization: token ' . $apiKey,
        'Accept: application/json',
    );

    $postBody = null;
    if ($body !== null) {
        $postBody = json_encode($body);
        if ($postBody === false) {
            return array(
                'httpCode' => 0,
                'body' => null,
                'error' => 'Failed to encode JSON request body',
                'rawBody' => '',
            );
        }
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($postBody);
    }

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);

    if ($postBody !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postBody);
    }

    $rawBody = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    curl_close($curl);

    if ($rawBody === false) {
        return array(
            'httpCode' => $httpCode,
            'body' => null,
            'error' => 'cURL errno ' . $curlErrno . ': ' . $curlError,
            'rawBody' => '',
        );
    }

    $decoded = json_decode($rawBody, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE && $rawBody !== '' && $rawBody !== 'null') {
        return array(
            'httpCode' => $httpCode,
            'body' => null,
            'error' => 'JSON decode failed: ' . json_last_error_msg(),
            'rawBody' => $rawBody,
        );
    }

    return array(
        'httpCode' => $httpCode,
        'body' => is_array($decoded) ? $decoded : null,
        'error' => null,
        'rawBody' => $rawBody,
    );
}

/**
 * @param  string $rawBody
 * @param  string $secret
 * @param  string $sigHeader
 * @return bool
 */
function btcpaygreenfieldVerifyWebhookSignature($rawBody, $secret, $sigHeader)
{
    if ($secret === '' || $sigHeader === '') {
        return false;
    }

    $expected = '';
    if (preg_match('/sha256=([a-f0-9]+)/i', $sigHeader, $matches)) {
        $expected = strtolower($matches[1]);
    }

    if ($expected === '') {
        return false;
    }

    $actual = hash_hmac('sha256', $rawBody, $secret);

    return hash_equals($actual, $expected);
}

/**
 * @return string
 */
function btcpaygreenfieldGetWebhookSignatureHeader()
{
    if (isset($_SERVER['HTTP_BTCPAY_SIG'])) {
        return (string) $_SERVER['HTTP_BTCPAY_SIG'];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'BTCPay-Sig') === 0) {
                return (string) $value;
            }
        }
    }

    return '';
}

/**
 * @param  array $paymentMethods
 * @return float
 */
function btcpaygreenfieldSumFiatPaidFromPaymentMethods($paymentMethods)
{
    $total = 0.0;

    if (!is_array($paymentMethods)) {
        return $total;
    }

    foreach ($paymentMethods as $method) {
        if (!is_array($method)) {
            continue;
        }

        $paid = isset($method['paymentMethodPaid']) ? (float) $method['paymentMethodPaid'] : 0.0;
        $rate = isset($method['rate']) ? (float) $method['rate'] : 0.0;

        if ($paid > 0 && $rate > 0) {
            $total += $paid * $rate;
        }
    }

    return $total;
}

/**
 * @param  float $paidFiat
 * @param  float $btcpayAmount
 * @param  float $whmcsTotal
 * @return float
 */
function btcpaygreenfieldComputeWhmcsCreditAmount($paidFiat, $btcpayAmount, $whmcsTotal)
{
    $paidFiat = (float) $paidFiat;
    $btcpayAmount = (float) $btcpayAmount;
    $whmcsTotal = (float) $whmcsTotal;

    if ($whmcsTotal <= 0 || $btcpayAmount <= 0) {
        return 0.0;
    }

    $credit = $whmcsTotal * ($paidFiat / $btcpayAmount);

    if ($credit > $whmcsTotal) {
        $credit = $whmcsTotal;
    }

    if ($credit < 0) {
        $credit = 0.0;
    }

    return round($credit, 2);
}

/**
 * @param  string $message
 * @param  int    $httpCode
 */
function btcpaygreenfieldCallbackReject($message, $httpCode = 400)
{
    btcpaygreenfieldLog('[ERROR] BTCPay Greenfield callback: ' . $message);
    http_response_code($httpCode);
    echo $message;
    exit;
}

/**
 * @param  string $message
 */
function btcpaygreenfieldCallbackOk($message = 'OK')
{
    http_response_code(200);
    echo $message;
    exit;
}
