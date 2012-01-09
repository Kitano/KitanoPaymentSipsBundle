<?php

namespace Kitano\PaymentSipsBundle\PaymentSystem;

use Kitano\PaymentBundle\PaymentSystem\CreditCardInterface;
use Kitano\PaymentBundle\Model\Transaction;
use Kitano\PaymentBundle\Model\AuthorizationTransaction;
use Kitano\PaymentBundle\Model\CaptureTransaction;
use Kitano\PaymentBundle\KitanoPaymentEvents;
use Kitano\PaymentBundle\Event\PaymentNotificationEvent;
use Kitano\PaymentBundle\Event\PaymentCaptureEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Templating\EngineInterface;
use Kitano\PaymentBundle\Repository\TransactionRepositoryInterface;
use Symfony\Component\Routing\RouterInterface;

class SipsPaymentSystem implements CreditCardInterface
{
    /* @var EventDispatcherInterface */
    protected $dispatcher;

    /* @var EngineInterface */
    protected $templating;

    /* @var RouterInterface */
    protected $router;

    /* @var TransactionRepositoryInterface */
    protected $transactionRepository;

    /* @var string */
    protected $baseUrl;

    /* @var string */
    protected $notificationUrl = null;
    /* @var string */
    protected $returnUrlOk = null;
    /* @var string */
    protected $returnUrlErr = null;


    public function __construct(
        TransactionRepositoryInterface $transactionRepository,
        EventDispatcherInterface $dispatcher,
        EngineInterface $templating,
        RouterInterface $router,
        $notificationUrl,
        $returnUrlOk,
        $returnUrlErr
    )
    {
        $this->transactionRepository = $transactionRepository;
        $this->dispatcher = $dispatcher;
        $this->templating = $templating;
        $this->router = $router;
        if (!$notificationUrl) {
            $notificationUrl = $router->generate("kitano_payment_payment_notification");
        }
        $this->notificationUrl = $notificationUrl;
        $this->returnUrlOk = $returnUrlOk;
        $this->returnUrlErr = $returnUrlErr;
    }

    public function authorizeAndCapture(Transaction $transaction)
    {
        // Nothing to do
    }

    /**
     * {@inheritDoc}
     */
    public function renderLinkToPayment(Transaction $transaction)
    {
        return $this->templating->render('KitanoPaymentSipsBundle:PaymentSystem:link-to-payment.html.twig', array(
            'date'    => $this->formatDate($transaction->getDate()),
            'orderId' => $transaction->getOrderId(),
            'transactionId' => $transaction->getId(),
            'amount'  => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'notificationUrl' => $this->notificationUrl,
            'returnUrlOk' => $this->returnUrlOk,
            'returnUrlErr' => $this->returnUrlErr,
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function handlePaymentNotification(Request $request)
    {
        $requestData = $request->request;
        $transaction = $this->transactionRepository->find($requestData->get('transactionId', null));

        switch((int) $requestData->get('code', 999)) {
            case 1:
                $transaction->setState(AuthorizationTransaction::STATE_APPROVED);
                break;

            case 0:
                $transaction->setState(AuthorizationTransaction::STATE_REFUSED);
                break;

            case -1:
                $transaction->setState(AuthorizationTransaction::STATE_SERVER_ERROR);
                break;

            default:
                $transaction->setState(AuthorizationTransaction::STATE_SERVER_ERROR);
        }

        $transaction->setSuccess(true);
        $transaction->setExtraData($requestData->all());
        $this->transactionRepository->save($transaction);

        $event = new PaymentNotificationEvent($transaction);
        $this->dispatcher->dispatch(KitanoPaymentEvents::PAYMENT_NOTIFICATION, $event);

        return new Response('OK');
    }

    /**
     * {@inheritDoc}
     */
    public function capture(CaptureTransaction $transaction)
    {
        // Initialize session and set URL.
        $ch = curl_init();
        $url = $this->getBaseUrl() . $this->router->generate('kitano_payment_sips_capture', array(), false);
        curl_setopt($ch, CURLOPT_URL, $url);

        // Set so curl_exec returns the result instead of outputting it.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Accept any server(peer) certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $transaction->setBaseTransaction($this->transactionRepository->findAuthorizationByOrderId($transaction->getOrderId()));
        $captureList = $this->transactionRepository->findCapturesBy(array(
            'orderId' => $transaction->getOrderId(),
            'state' => CaptureTransaction::STATE_APPROVED,
        ));

        $captureAmountCumul = 0;
        foreach($captureList as $capture) {
            $captureAmountCumul += $capture->getAmount();
        }

        $remainingAmount = (float) ($transaction->getBaseTransaction()->getAmount() - $captureAmountCumul - $transaction->getAmount());

        // Data
        $data = array(
            'amount'            => $this->formatAmount($transaction->getBaseTransaction()->getAmount(), $transaction->getCurrency()),
            'capture_amount'    => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'captured_amount'   => $this->formatAmount($captureAmountCumul, $transaction->getCurrency()), // TODO
            'remaining_amount'  => $this->formatAmount($remainingAmount, $transaction->getCurrency()),
            'orderId'           => $this->formatAmount($transaction->getTransactionId(), $transaction->getCurrency()),
        );

        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->urlify($data));

        // Get the response and close the channel.
        $response = curl_exec($ch);
        curl_close($ch);

        $paymentResponse = explode('=', $response);
        if ($paymentResponse[1] == 1) {
            $transaction->setState(CaptureTransaction::STATE_APPROVED);
        }
        else {
            $transaction->setState(CaptureTransaction::STATE_REFUSED);
        }

        $transaction->setExtraData(array('response' => $response, 'sentData' => $data));
        $this->transactionRepository->save($transaction);

        $event = new PaymentCaptureEvent($transaction);
        $this->dispatcher->dispatch(KitanoPaymentEvents::PAYMENT_CAPTURE, $event);
    }

    /**
     * @param array $data
     * @return string
     */
    private function urlify(array $data)
    {
        return http_build_query($data);
    }

    /**
     * @param float  $amount
     * @param string $currency
     *
     * @return string
     */
    private function formatAmount($amount, $currency)
    {
        return ((string) $amount) . $currency;
    }

    /**
     * @param \DateTime $date
     * @return string
     */
    private function formatDate(\DateTime $date)
    {
        return $date->format('d/m/Y:H:i:s');
    }

    /**
     * @param string $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }
}