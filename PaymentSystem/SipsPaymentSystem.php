<?php

namespace Kitano\PaymentSipsBundle\PaymentSystem;

use Kitano\PaymentBundle\PaymentSystem\SimpleCreditCardInterface;
use Kitano\PaymentBundle\Model\Transaction;
use Kitano\PaymentBundle\KitanoPaymentEvents;
use Kitano\PaymentBundle\Repository\TransactionRepositoryInterface;
use Kitano\PaymentBundle\PaymentException;


use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SipsPaymentSystem
    implements SimpleCreditCardInterface
{
    /* @var TransactionRepositoryInterface */
    protected $transactionRepository;

    /** @var null|LoggerInterface */
    protected $logger = null;

    /** @var array */
    protected $bin = array();

    /** @var array */
    protected $config = array();


    public function __construct(
        TransactionRepositoryInterface $transactionRepository,
        LoggerInterface $logger,
        $sipsConfig,
        $sipsBin,
        $notificationUrl,
        $internalBackToShopUrl,
        $externalBackToShopUrl
    )
    {
        $this->transactionRepository = $transactionRepository;
        $this->logger = $logger;
        $this->config = $sipsConfig;
        $this->bin = $sipsBin;

        $this->notificationUrl = $notificationUrl;
        $this->internalBackToShopUrl = $internalBackToShopUrl;
        $this->externalBackToShopUrl = $externalBackToShopUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function renderLinkToPayment(Transaction $transaction)
    {
        $params = array();
        $params["merchant_id"] = $this->config["merchant_id"];
        $params["merchant_country"] = $this->config["merchant_country"];
        $params["amount"] = round($transaction->getAmount() * 100);
        $params["language"] = $this->config["default_language"];;
        $params["transaction_id"] = $this->getSipsTransactionId($transaction);
        $params["order_id"] = $transaction->getOrderId();
        $params["currency_code"] = $this->getCurrencySipsCode($this->config["default_currency"]);
        $params["pathfile"] = $this->config["pathfile"];
        $params["normal_return_url"] = $this->internalBackToShopUrl;
        $params["cancel_return_url"] = $this->internalBackToShopUrl;
        $params["automatic_response_url"] = $this->notificationUrl;
        $data = array(
            "TRANSACTION_ID" => $transaction->getId()
        );
        $params["data"] = $this->encodeDataField($data);

        $paramString = $this->arrayToSipsArgument($params);

        $requestBin = $this->bin["request_bin"];
        if (!is_file($requestBin)) {
            throw new PaymentException("file '$requestBin' doesn't exists, can't generate html button");
        }
        $this->logger->info("Execute SIPS request : $requestBin $paramString");
        $result = exec(escapeshellcmd($requestBin).' '.$paramString);

        $tableau = explode ("!", "$result");
        $sipsCode = $tableau[1];
        $sipsError = $tableau[2];
        $sipsMessage = $tableau[3];

        if (( $sipsCode == "" ) && ( $sipsError == "" ) ) {
            throw new PaymentException("call to $requestBin failed, result=".$result);
        }
        elseif ($sipsCode != 0) {
            throw new PaymentException("SIPS returns the following error: \n $sipsError");
        }

        return $sipsMessage;
    }


    /**
     * {@inheritDoc}
     */
    public function authorizeAndCapture(Transaction $transaction)
    {
        // Nothing to do
    }

    protected function handleSipsRequest(Request $request)
    {
        $sipsMessage = $request->request->get("DATA", null);
        if ($sipsMessage == null) {
            throw new PaymentException("no message received from SIPS");
        }
        $responseBin = $this->bin["response_bin"];
        $pathfile = $this->config["pathfile"];
        if (!is_file($responseBin)) {
            throw new PaymentException("file '$responseBin' doesn't exists, can't read SIPS response");
        }
        $this->logger->info("Execute SIPS response : $responseBin pathfile=$pathfile message=$sipsMessage");
        $result = exec("$responseBin pathfile=$pathfile message=$sipsMessage");
        $this->logger->info("SIPS response executed : result = $result");
        $tableau = explode ("!", $result);

        $code = $tableau[1];
        $error = $tableau[2];
        $merchant_id = $tableau[3];
        $merchant_country = $tableau[4];
        $amount = $tableau[5];
        $transaction_id = $tableau[6];
        $payment_means = $tableau[7];
        $transmission_date= $tableau[8];
        $payment_time = $tableau[9];
        $payment_date = $tableau[10];
        $response_code = $tableau[11];
        $payment_certificate = $tableau[12];
        $authorisation_id = $tableau[13];
        $currency_code = $tableau[14];
        $card_number = $tableau[15];
        $cvv_flag = $tableau[16];
        $cvv_response_code = $tableau[17];
        $bank_response_code = $tableau[18];
        $complementary_code = $tableau[19];
        $complementary_info = $tableau[20];
        $return_context = $tableau[21];
        $caddie = $tableau[22];
        $receipt_complement = $tableau[23];
        $merchant_language = $tableau[24];
        $language = $tableau[25];
        $customer_id = $tableau[26];
        $order_id = $tableau[27];
        $customer_email = $tableau[28];
        $customer_ip_address = $tableau[29];
        $capture_day = $tableau[30];
        $capture_mode = $tableau[31];
        $dataString = $tableau[32];
        $order_validity = $tableau[33];
        $transaction_condition = $tableau[34];
        $statement_reference = $tableau[35];
        $card_validity = $tableau[36];
        $score_value = $tableau[37];
        $score_color = $tableau[38];
        $score_info = $tableau[39];
        $score_threshold = $tableau[40];
        $score_profile = $tableau[41];


        // sips syntaxically wrong
        if (( $code == "" ) && ( $error == "" ) ) {
            throw new PaymentException("Wrong SIPS response, message=$sipsMessage \n\n result=$result");
        }
        // get transaction
        $data = $this->decodeDataField($dataString);
        $transaction = $this->transactionRepository->find($data["TRANSACTION_ID"]);
        if ($transaction == null) {
            throw new PaymentException("Unknown transaction in data field of the SIPS message, message=$sipsMessage \n\n result=$result");
        }
        // if transaction already computed by payment system
        if ($transaction->getState() != Transaction::STATE_NEW) {
            return $transaction;
        }
        // payment refused
        if ( ( $code != 0 ) || ($response_code != "00") ) {
            $transaction->setState(Transaction::STATE_REFUSED);
            $transaction->setSuccess(true);
            $transaction->setExtraData($request->all());
            $this->transactionRepository->save($transaction);
            return $transaction;
        }
        // payment accepted
        $transaction->setState(Transaction::STATE_APPROVED);
        $transaction->setSuccess(true);
        $transaction->setExtraData($request->request->all());
        $this->transactionRepository->save($transaction);
        return $transaction;
    }

    /**
     * {@inheritDoc}
     */
    public function handleBackToShop(Request $request)
    {
        $transaction = $this->handleSipsRequest($request);
        return new RedirectResponse($this->externalBackToShopUrl.'?transactionId='.$transaction->getId(), "302");
    }

    /**
     * {@inheritDoc}
     */
    public function handlePaymentNotification(Request $request)
    {
        $transaction = $this->handleSipsRequest($request);
        return new Response('OK');
    }

    /**
     * return an sips currency code from a currency iso name
     * @param string $currencyIso
     * @return string code SIPS for the currency
     */
    protected function getCurrencySipsCode($currencyIso)
    {
        // TODO complete this array according to the doc
        $currencyList = array(
            "EUR" => "978",
            "USD" => "840",
        );
        if (! array_key_exists($currencyIso, $currencyList)) {
            throw new PaymentException("unknown currency $currencyIso in the currencyList:".implode('|', array_keys($currencyList)));
        }
        return $currencyList[$currencyIso];
    }

    protected function arrayToSipsArgument($parameterList)
    {
        $str = "";
        foreach ($parameterList as $key=>$val) {
            $str .= $key.'='.$val.' ';
        }
        $str = trim($str);
        return $str;
    }

    protected function getSipsTransactionId(Transaction $transaction)
    {
        $this->logger->debug("SIPS ori transactionId=".$transaction->getTransactionId());
        if ($transaction->getTransactionId() == null) {
            // create a unique transactionId for the last 30 days
            $repo = $this->transactionRepository;
            $stateDate = new \DateTime();
            $stateDate->sub(new \DateInterval('P30D'));
            while ($transaction->getTransactionId() == null) {
                $transactionId = rand(100000, 999999);
                $this->logger->debug("SIPS transactionId=".$transactionId);
                $transactionId = (string)$transactionId;
                $transactionList = $repo->findByTransactionIdAndStateDate($transactionId, $stateDate);
                if (count($transactionList) == 0) {
                    $transaction->setTransactionId($transactionId);
                }
            }
            $repo->save($transaction);
        }
        return $transaction->getTransactionId();
    }

    protected function encodeDataField($data)
    {
        $tab = array();
        foreach ($data as $key=>$val) {
            $tab[] = $key.'='.$val;
        }
        return implode(',',$tab);
    }

    protected function decodeDataField($dataString)
    {
        $tab = explode(',', $dataString);
        $data = array();
        foreach ($tab as $field) {
            list($key, $val) = explode('=', $field);
            $data[$key] = $val;
        }
        return $data;
    }
}