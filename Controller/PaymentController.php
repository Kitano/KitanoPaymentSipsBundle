<?php

namespace Kitano\PaymentSipsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function paymentRequestAction()
    {
        $request = $this->getRequest()->request;
        $mode = strtolower($request->get('mode', ''));
        $data = $request->all();
        switch ($mode) {
            case 'accept':
                $data['code'] = 1;
                $redirectUrl = $request->get('return_url_ok');
                break;

            case 'refuse':
                $data['code'] = 0;
                $redirectUrl = $request->get('return_url_err');
                break;

            default:
                $data['code'] = -1;
                $redirectUrl = $request->get('return_url_err');
        }

        $this->sendPaymentNotification($data, $request->get('notification_url'));

        return $this->redirect($redirectUrl."?transactionId=".$data["transactionId"]);
    }

    public function captureRequestAction()
    {
        $postData = $this->getRequest()->request;

        return new Response('code=1');
    }

    private function sendPaymentNotification(array $data, $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        // Get the response and close the channel.
        $response = curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @return \Kitano\PaymentBundle\PaymentSystem\CreditCardInterface
     */
    public function getPaymentSystem()
    {
        return $this->get('kitano_payment_sips.payment_system.sips');
    }
}

