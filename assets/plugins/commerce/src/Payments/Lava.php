<?php

namespace Commerce\Payments;

class Lava extends Payment
{
    protected $debug;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('lava');
        $this->debug = $this->getSetting('debug') == '1';
    }

    public function getMarkup()
    {
        $settings = ['shop_id', 'secret_key', 'add_key'];
        foreach ($settings as $setting) {
            if (empty($setting)) {
                return '<span class="error" style="color: red;">' . $this->lang['lava.error.error_empty_params'] . '</span>';
            }
        }

        return '';
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $payment   = $this->createPayment($order['id'], $order['amount']);
        $data = [
            'sum' => $payment['amount'],
            'orderId' => $order['id'] . '-' . $payment['hash'],
            'hookUrl' => MODX_SITE_URL . 'commerce/lava/payment-process?paymentHash=' . $payment['hash'],
            'failUrl' => MODX_SITE_URL . 'commerce/lava/payment-failed',
            'successUrl' => MODX_SITE_URL . 'commerce/lava/payment-success',
            'expire' => 1440,
            'customFields' => '',
            'comment' => $this->lang['lava.order_description'] . ' ' . $order['id'],
            'shopId' => $this->getSetting('shop_id')
        ];
        try {
            $response = $this->request('invoice/create', $data);

            return $response['data']['url'];
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3,
                    'Request failed: <pre>' . print_r($data, true) . '</pre><pre>' . print_r($e->getMessage() . ' ' . $e->getCode(), true) . '</pre>', 'Commerce Lava Payment');
            }
        }

        return false;
    }

    public function handleCallback()
    {
        $input = file_get_contents('php://input');
        $response = json_decode($input, true);
        $hookSignature = getallheaders();
        if ($this->debug) {
            $this->modx->logEvent(0, 3, 'Callback start <pre>' . $input . '</pre><pre>' . print_r($response, true) . '</pre><pre>' . print_r($hookSignature, true) . '</pre>', 'Commerce Lava Payment Callback');
        }
        if (isset($response['invoice_id']) && isset($response['amount']) && isset($response['status']) && $response['status'] == 'success') {
            try {
                $hookSignature = $hookSignature['authorization'] ?? '';
                ksort($response);
                $signature = $this->getSignature(json_encode($response), $this->getSetting('add_key'));
                if ($signature !== $hookSignature) throw new \Exception();
            } catch (\Exception $e) {
                if ($this->debug) {
                    $this->modx->logEvent(0, 3, 'Wrong request signature', 'Commerce Lava Payment Callback');

                    return false;
                }
            }
            $paymentHash = $this->getRequestPaymentHash();
            $processor = $this->modx->commerce->loadProcessor();
            try {
                $payment = $processor->loadPaymentByHash($paymentHash);

                if (!$payment || $payment['amount'] != $response['amount']) {
                    throw new Exception('Payment "' . htmlentities(print_r($paymentHash, true)) . '" . not found!');
                }

                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (Exception $e) {
                if ($this->debug) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Lava Payment Callback');

                    return false;
                }
            }
        }

        return false;
    }

    public function getRequestPaymentHash()
    {
        if (!empty($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }

    protected function getSignature($data, $key)
    {
        return hash_hmac('sha256', $data, $key);
    }

    protected function request($method, array $data) {
        $curl = curl_init();
        $data = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $signature = $this->getSignature($data, $this->getSetting('secret_key'));
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.lava.ru/business/' . $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Signature: ' . $signature
            ],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!empty($response['error']) || $response['status'] !== 200) {
            throw new \Exception($response['error'], $response['status']);
        }

        return $response;
    }
}
