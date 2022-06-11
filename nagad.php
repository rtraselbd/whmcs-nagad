<?php

/**
 * NAGAD WHMCS Gateway
 *
 * Copyright (c) 2022 RtRasel
 * Website: https://rtrasel.com
 * Developer: facebook.com/rtraselbd
 * 
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function nagad_MetaData()
{
    return array(
        'DisplayName' => 'NAGAD Gateway',
        'APIVersion' => '1.0',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function nagad_config($params)
{
    $systemUrl = $params['systemurl'];
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'NAGAD Gateway',
        ),
        'nagad_merchant_id' => array(
            'FriendlyName' => 'NAGAD Merchant ID',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Enter Your NAGAD Merchant ID',
        ),
        'nagad_merchant_number' => array(
            'FriendlyName' => 'NAGAD Merchant Number',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Enter Your NAGAD Merchant Number',
        ),
        'nagad_public_key' => array(
            'FriendlyName' => 'NNAGAD Public Key',
            'Type' => 'textarea',
            'Rows' => '3',
            'Cols' => '60',
            'Description' => 'Enter Your NAGAD Public Key',
        ),
        'nagad_private_key' => array(
            'FriendlyName' => 'NAGAD Private Key',
            'Type' => 'textarea',
            'Rows' => '3',
            'Cols' => '60',
            'Description' => 'Enter Your NAGAD Private Key',
        ),
        'nagad_logo_url' => array(
            'FriendlyName' => 'Your Logo URL',
            'Type' => 'text',
            'Size' => '255',
            'Default' => '',
            'Description' => 'Enter a link to your logo for the NAGAD checkout',
        ),
        'sandbox'      => [
            'FriendlyName' => 'Sandbox',
            'Type'         => 'yesno',
            'Description'  => 'Tick to enable sandbox mode',
        ],
        'webhookUrl'      => [
            'FriendlyName' => 'Webhook URL',
            'Description'  => $systemUrl . 'modules/gateways/callback/nagad.php',
        ],
    );
}


function nagad_link($params)
{
    // Set Session
    session_start();
    if (isset($_SESSION['NagadMsg'])) {
            $htmlOutput = '<font style="display:block;" color="red">' . $_SESSION['NagadMsg'] . '</font>';
            unset($_SESSION['NagadMsg']);
            return $htmlOutput;
        }

    $response = nagad_payment_url($params);
    if (isset($response['status']) && $response['status'] == 'success') {
        return '<form action="' . $response['url'] . ' " method="GET">
        <input class="btn btn-primary" type="submit" value="' . $params['langpaynow'] . '" />
        </form>';
    }

    return $response['message'];
}

function nagad_payment_url($params)
{
    $baseUrl = 'https://api.mynagad.com/api/dfs/';
    if (!empty($params['sandbox'])) {
        $baseUrl = 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs/';
    }

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];

    // System Parameters
    $systemUrl = $params['systemurl'];

    // Set Session
    session_start();
    $_SESSION['InvoiceURL'] = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

    // Start Nagad Payment
    $dateTime = date('YmdHis');
    $merchantId = $params['nagad_merchant_id'];
    $invoiceNo = 'NG' . rand(1000, 9999) . Date('YmdH') . rand(1000, 10000);
    $merchantCallbackURL = $systemUrl . 'modules/gateways/callback/nagad.php';

    $sensitiveData = [
        'merchantId' => $merchantId,
        'datetime' => $dateTime,
        'orderId' => $invoiceNo,
        'challenge' => GenerateRandomString()
    ];

    $postData = [
        'accountNumber' => $params['nagad_merchant_number'], //optional
        'dateTime' => $dateTime,
        'sensitiveData' => EncryptDataWithPublicKey($params, json_encode($sensitiveData)),
        'signature' => SignatureGenerate($params, json_encode($sensitiveData))
    ];

    $url = $baseUrl . "check-out/initialize/" . $merchantId . "/" . $invoiceNo;
    $resultData = HttpPostMethod($url, $postData);

    if (!isset($resultData['sensitiveData']) || !isset($resultData['signature'])) {
        return [
            'status'    => 'error',
            'message'   => 'Sensitive data or Signature is missing.'
        ];
    }

    if (empty($resultData['sensitiveData']) || empty($resultData['signature']) || $resultData['sensitiveData'] == "" || $resultData['signature'] == "") {
        return [
            'status'    => 'error',
            'message'   => 'Sensitive data or Signature is empty.'
        ];
    }

    $plainResponse = json_decode(DecryptDataWithPrivateKey($params, $resultData['sensitiveData']), true);
    if (!isset($plainResponse['paymentReferenceId']) || !isset($plainResponse['challenge'])) {
        return [
            'status'    => 'error',
            'message'   => 'Payment reference id or challenge is missing.'
        ];
    }

    $paymentReferenceId = $plainResponse['paymentReferenceId'];
    $randomServer = $plainResponse['challenge'];

    $sensitiveDataOrder = [
        'merchantId' => $merchantId,
        'orderId' => $invoiceNo,
        'currencyCode' => '050',
        'amount' => $amount,
        'challenge' => $randomServer
    ];

    $merchantAdditionalInfo = [
        'serviceLogoURL'    => $params['nagad_logo_url'],
        'invoice_id'        => $invoiceId
    ];

    $postDataOrder = [
        'sensitiveData' => EncryptDataWithPublicKey($params, json_encode($sensitiveDataOrder)),
        'signature' => SignatureGenerate($params, json_encode($sensitiveDataOrder)),
        'merchantCallbackURL' => $merchantCallbackURL,
        'additionalMerchantInfo' => (object)$merchantAdditionalInfo
    ];

    $orderSubmitUrl = $baseUrl . "check-out/complete/" . $paymentReferenceId;
    $resultDataOrder = HttpPostMethod($orderSubmitUrl, $postDataOrder);

    if ($resultDataOrder['status'] == "Success") {
        return [
            'status'    => 'success',
            'url'       => $resultDataOrder['callBackUrl'],
            'message'   => 'Return URL Found.'
        ];
    } else {
        return [
            'status'    => 'error',
            'message'   => 'Could not generate payment link.'
        ];
    }

    return [
        'status'    => 'error',
        'message'   => 'Invalid response from Nagad API.'
    ];
}

/**
 * Generate Random String
 */

function GenerateRandomString($length = 40)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Generate Public Key
 */

function EncryptDataWithPublicKey($params, $data)
{
    $nagadPublicKey = $params['nagad_public_key'];
    $publicKey = "-----BEGIN PUBLIC KEY-----\n" . $nagadPublicKey . "\n-----END PUBLIC KEY-----";
    $keyResource = openssl_get_publickey($publicKey);
    openssl_public_encrypt($data, $cryptText, $keyResource);
    return base64_encode($cryptText);
}

/**
 * Generate Plain Text
 */

function DecryptDataWithPrivateKey($params, $cryptText)
{
    $nagadPrivateKey = $params['nagad_private_key'];
    $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . $nagadPrivateKey . "\n-----END RSA PRIVATE KEY-----";
    openssl_private_decrypt(base64_decode($cryptText), $plainText, $privateKey);
    return $plainText;
}

/**
 * Generate Signature
 */

function SignatureGenerate($params, $data)
{
    $nagadPrivateKey = $params['nagad_private_key'];
    $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . $nagadPrivateKey . "\n-----END RSA PRIVATE KEY-----";
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}

/**
 * Get Clinet IP
 */

function GetClientIP()
{
    $ipAddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
    else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if (isset($_SERVER['HTTP_X_FORWARDED']))
        $ipAddress = $_SERVER['HTTP_X_FORWARDED'];
    else if (isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipAddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if (isset($_SERVER['HTTP_FORWARDED']))
        $ipAddress = $_SERVER['HTTP_FORWARDED'];
    else if (isset($_SERVER['REMOTE_ADDR']))
        $ipAddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipAddress = 'UNKNOWN';
    return $ipAddress;
}

/**
 * Decode Data
 */

function HttpPostMethod($postURL, $postData)
{
    $url = curl_init($postURL);
    $postToken = json_encode($postData);
    $header = array(
        'Content-Type:application/json',
        'X-KM-Api-Version:v-0.2.0',
        'X-KM-IP-V4:' . GetClientIP(),
        'X-KM-Client-Type:PC_WEB'
    );

    curl_setopt($url, CURLOPT_HTTPHEADER, $header);
    curl_setopt($url, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($url, CURLOPT_POSTFIELDS, $postToken);
    curl_setopt($url, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($url, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($url, CURLOPT_SSL_VERIFYPEER, 0);
    $resultdata = curl_exec($url);
    $ResultArray = json_decode($resultdata, true);
    curl_close($url);
    return $ResultArray;
}