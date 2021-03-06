<?php

require_once(DIR_SYSTEM . 'library/plisio/PlisioClient.php');
require_once(DIR_SYSTEM . 'library/plisio/version.php');


class ControllerExtensionPaymentPlisio extends Controller
{
    /** @var PlisioClient */
    private $plisio;
    private $plisio_receive_currencies = array();

    public function index()
    {
        $this->load->language('extension/payment/plisio');
        $this->load->model('checkout/order');
        $this->setupPlisioClient();

        $currencies = $this->plisio->getCurrencies();
        $data['currencies'] = $currencies['data'];
        $selectedCurrencies = $this->model_setting_setting->getSettingValue('payment_plisio_receive_currencies');
        $selectedCurrencies = str_replace(['"', '[', ']'], '', $selectedCurrencies);
        $selectedCurrencies = explode(',', $selectedCurrencies);
        if (!is_array($selectedCurrencies)) $selectedCurrencies = [$selectedCurrencies];

        if (count($selectedCurrencies) > 0) {
            $data['currencies'] = array_filter($currencies['data'], function ($i) use ($selectedCurrencies) {
                return in_array($i['cid'], $selectedCurrencies);
            });

            if (!empty($data['currencies'])) {
                $data['currencies'] = array_values($data['currencies']);
            }


            $this->plisio_receive_currencies = $selectedCurrencies;
            usort($data['currencies'], function($a, $b) {
                $idxA = array_search($a['cid'], $this->plisio_receive_currencies);
                $idxB = array_search($b['cid'], $this->plisio_receive_currencies);

                $idxA = $idxA === false ? -1 : $idxA;
                $idxB = $idxB === false ? -1 : $idxB;

                if ($idxA < 0 && $idxB < 0) return -1;
                if ($idxA < 0 && $idxB >= 0) return 1;
                if ($idxA >= 0 && $idxB < 0) return -1;
                return $idxA - $idxB;
            });


            if (is_array($data['currencies']) && count($data['currencies']) == 1) {
                $buttonCaption = sprintf($this->language->get('button_currency_confirm'), $data['currencies'][0]['name'] . ' (' . $data['currencies'][0]['currency'] . ')');
                $data['pay_with_text'] = $buttonCaption;
                $data['button_confirm'] = $buttonCaption;
            }
        }
        if (!isset($data['button_confirm'])) {
            $data['button_confirm'] = $this->language->get('button_confirm');
        }

        $data['action'] = $this->url->link('extension/payment/plisio/checkout', '', true);

        return $this->load->view('extension/payment/plisio', $data);
    }

    public function checkout()
    {
        $this->setupPlisioClient();
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/plisio');

        $orderId = $this->session->data['order_id'];
        $order_info = $this->model_checkout_order->getOrder($orderId);

        $description = [];

        foreach ($this->cart->getProducts() as $product) {
            $description[] = $product['quantity'] . ' × ' . $product['name'];
        }

        $amount = $order_info['total'] * $this->currency->getvalue($order_info['currency_code']);
        $request = array(
            'source_amount' => number_format($amount, 8, '.', ''),
            'source_currency' => $order_info['currency_code'],
            'currency' => $this->request->post['currency'],
            'order_name' => $this->config->get('config_meta_title') . ' Order #' . $order_info['order_id'],
            'order_number' => $order_info['order_id'],
            'description' => join($description, ', '),
            'cancel_url' => $this->url->link('extension/payment/plisio/callback', '', true),
            'callback_url' => $this->url->link('extension/payment/plisio/callback', '', true),
            'success_url' => $this->url->link('extension/payment/plisio/success', '', true),
            'email' => $order_info['email'],
            'language' => $this->language->get('code'),
            'plugin' => 'opencart',
            'version' => PLISIO_OPENCART_EXTENSION_VERSION
        );

        $response = $this->plisio->createTransaction($request);
        if ($response && $response['status'] !== 'error' && !empty($response['data'])) {
            $this->model_extension_payment_plisio->addOrder(array(
                'order_id' => $order_info['order_id'],
                'plisio_invoice_id' => $response['data']['txn_id']
            ));

            $this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('payment_plisio_order_status_id'));
            $this->cart->clear();
            if ($this->model_setting_setting->getSettingValue('payment_plisio_white_label') == 'false') {
                $this->response->redirect($response['data']['invoice_url']);
            } else {
                $this->response->redirect($this->url->link('extension/payment/plisio/invoice', '', true));
            }
        } else {
            $this->log->write("Order #" . $order_info['order_id'] . " is not valid. " . (isset($response['data']) && isset($response['data']['message'])) ? $response['data']['message'] : '');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    public function invoice()
    {
        $this->load->model('extension/payment/plisio');
//        $this->load->model('checkout/order');
        $this->setupPlisioClient();

        $orderId = $this->session->data['order_id'];
        $plisioOrder = $this->model_extension_payment_plisio->getOrder($orderId);
        $invoiceId = $plisioOrder['plisio_invoice_id'];
        $plisioParsedUrl = parse_url($this->plisio->apiEndPoint);
        $plisioInvoiceUrl = $plisioParsedUrl['scheme'] . '://' . $plisioParsedUrl['host'] . '/invoice/' . $invoiceId;

        if ($this->model_setting_setting->getSettingValue('payment_plisio_white_label') == 'false'){
            $this->response->redirect($plisioInvoiceUrl);
        }

        $data = [
            'invoice_id' => $invoiceId,
            'invoice_url' => $plisioInvoiceUrl
        ];

        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $this->response->setOutput($this->load->view('extension/payment/plisio_invoice', $data));
    }

    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    public function success()
    {
        if (isset($this->session->data['order_id'])) {
            $this->load->model('checkout/order');
            $this->load->model('extension/payment/plisio');

            $order = $this->model_extension_payment_plisio->getOrder($this->session->data['order_id']);
        } else {
            $order = '';
        }

        if (empty($order)) {
            $this->response->redirect($this->url->link('common/home', '', true));
        } else {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        }
    }

    private function verifyCallbackData()
    {
        if (!isset($this->request->post['verify_hash'])) {
            $this->log->write('Callback data has no verify hash');
            return false;
        }
        $this->load->model('setting/setting');

        $post = $this->request->post;
        $verifyHash = $post['verify_hash'];
        unset($post['verify_hash']);
        ksort($post);
        $postString = serialize($post);
        $checkKey = hash_hmac('sha1', $postString, $this->model_setting_setting->getSettingValue('payment_plisio_api_secret_key'));
        if ($checkKey != $verifyHash) {
            $errorMessage = 'Callback data looks compromised';
            $this->log->write($errorMessage);
            return false;
        }

        return true;
    }

    public function callback()
    {
        if ($this->verifyCallbackData()) {
            $this->load->model('checkout/order');
            $this->load->model('extension/payment/plisio');

            $order_id = $this->request->post['order_number'];
            $order_info = $this->model_checkout_order->getOrder($order_id);
            $ext_order = $this->model_extension_payment_plisio->getOrder($order_id);


            if (!empty($order_info) && !empty($ext_order)) {
                if ($ext_order) {
                    switch ($this->request->post['status']) {
                        case 'completed':
                            $cg_order_status = 'payment_plisio_paid_status_id';
                            break;
                        case 'confirming':
                            $cg_order_status = 'payment_plisio_confirming_status_id';
                            break;
                        case 'error':
                            $cg_order_status = 'payment_plisio_invalid_status_id';
                            break;
                        case 'cancelled':
                            $cg_order_status = 'payment_plisio_canceled_status_id';
                            break;
                        case 'expired':
                            if ($this->request->post['source_amount'] > 0) {
                                $cg_order_status = 'payment_plisio_invalid_status_id';
                            } else {
                                $cg_order_status = 'payment_plisio_canceled_status_id';
                            }
                            break;
                        case 'mismatch':
                            $cg_order_status = 'payment_plisio_changeback_status_id';
                            break;
                        default:
                            $cg_order_status = NULL;
                    }

                    if (!is_null($cg_order_status)) {
                        $comment = '';
                        if (isset($this->request->post['comment']) && !empty($this->request->post['comment'])) {
                            $comment = $this->request->post['comment'];
                        }
                        $this->model_checkout_order->addOrderHistory($order_id, $this->config->get($cg_order_status), $comment/*, true*/);
                    }
                }
            }
            $this->response->addHeader('HTTP/1.1 200 OK');
        } else {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
        }
    }

    private function setupPlisioClient()
    {
        $this->load->model('setting/setting');
        $secretKey = $this->model_setting_setting->getSettingValue('payment_plisio_api_secret_key');
        $this->plisio = new PlisioClient($secretKey);
    }
}
