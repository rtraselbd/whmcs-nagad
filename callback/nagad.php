<?php

/**
 * NAGAD WHMCS Gateway
 *
 * Copyright (c) 2022 NAGAD
 * Website: https://rtrasel.com
 * Developer: facebook.com/rtraselbd
 * 
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

class Nagad
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var integer
     */
    protected $customerCurrency;

    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var float
     */
    protected $fee;

    /**
     * @var int
     */
    public $invoiceID;

    /**
     * @var float
     */
    public $total;

    /**
     * @var string
     */
    public $baseUrl;

    /**
     * Nagad constructor.
     */
    public function __construct()
    {
        $this->setGateway();
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new Nagad;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isActive          = !empty($this->gatewayParams['type']);
        $baseUrl = 'https://api.mynagad.com/api/dfs/';
        if (!empty($this->gatewayParams['sandbox'])) {
            $baseUrl = 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs/';
        }
        $this->baseUrl   =  $baseUrl;
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->invoiceID
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    /**
     * Set currency.
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Check if transaction if exists.
     *
     * @param string $trxId
     *
     * @return mixed
     */
    private function checkTransaction($trxId)
    {
        return localAPI(
            'GetTransactions',
            ['transid' => $trxId]
        );
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            $payload,
            $payload['status']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $trxId
     *
     * @return array
     */
    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => $this->fee,
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    private function HttpGet($url)
    {
        $ch = curl_init();
        $timeout = 10;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/0 (Windows; U; Windows NT 0; zh-CN; rv:3)");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $file_contents = curl_exec($ch);
        echo curl_error($ch);
        curl_close($ch);
        return $file_contents;
    }

    /**
     * Execute the payment by ID.
     *
     * @return array
     */
    private function executePayment()
    {
        // Check Payment Reference
        $paymentRefId = htmlspecialchars($_GET['payment_ref_id']);
        if (empty($paymentRefId) || is_null($paymentRefId)) {
            return [
                'status'    => 'error',
                'message'   => 'Invalid response from Nagad API.'
            ];
        }

        // Verify Data From Nagad Server
        $url = $this->baseUrl . "verify/payment/" . $paymentRefId;
        $json = $this->HttpGet($url);
        $data = json_decode($json, true);

        if (is_array($data)) {
            return $data;
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from Nagad API.'
        ];
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $executePayment = $this->executePayment();

        // Payment Aborted
        if (isset($executePayment['status']) && $executePayment['status'] === 'Aborted') {
            return [
                'status'    => 'error',
                'message'   => 'Payment Cancelled.',
            ];
        }

        if (isset($executePayment['status']) && $executePayment['status'] === 'Success') {

            $getAdditionalData = json_decode($executePayment['additionalMerchantInfo'], true);

            $this->invoiceID = $getAdditionalData['invoice_id'];
            $this->setInvoice();

            $existing = $this->checkTransaction($executePayment['issuerPaymentRefNo']);

            if ($existing['totalresults'] > 0) {
                return [
                    'status'    => 'error',
                    'message'   => 'The transaction has been already used.'
                ];
            }

            if ($executePayment['amount'] < $this->total) {
                return [
                    'status'    => 'error',
                    'message'   => 'You\'ve paid less than amount is required.'
                ];
            }

            $this->logTransaction($executePayment);

            $trxAddResult = $this->addTransaction($executePayment['issuerPaymentRefNo']);

            if ($trxAddResult['result'] === 'success') {
                return [
                    'status'  => 'success',
                    'message' => 'The payment has been successfully paid.',
                ];
            }
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid Response.',
        ];
    }
}





if (!isset($_GET['payment_ref_id'])) {
    die("Direct access forbidden.");
}

$Nagad = Nagad::init();


if (!$Nagad->isActive) {
    die("The gateway is unavailable.");
}

$response = $Nagad->makeTransaction();

session_start();
if (isset($_SESSION['InvoiceURL'])) {
    if (isset($response['status']) && $response['status'] == 'error') {
        $_SESSION['NagadMsg']   = $response['message'];
    }
    $InvoiceURL = $_SESSION['InvoiceURL'];
    unset($_SESSION['InvoiceURL']);
    header("Location: {$InvoiceURL}");
    exit();
}
