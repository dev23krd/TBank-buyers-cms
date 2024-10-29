<?php

/*if(!defined("DOCUMENT_ROOT"))
exit(http_response_code(403));*/

ini_set('ignore_repeated_errors', true);
ini_set('display_errors', false);
ini_set('log_errors', true);
ini_set('error_log', dirname(__FILE__). '/1c_errors.log');

require_once(__DIR__ . '/../../api/manyclients.php');
require_once(__DIR__ . '/TBankMerchantAPI.php');

class TBank extends ManyClients 
{
    public function checkout_form($order_id, $button_text = null)
    {
        if (isset($_GET['Success']) && $_GET['Success'] = 'true') {
            return false;
        }

        if (empty($button_text)) $button_text = 'Перейти к оплате';

        $button = '';
        $order = $this->orders->get_order((int)$order_id);

        if ($order->status == 0) {
            $total = $order->total_price - (max(0, $order->discount)) - (max(0, $order->coupon_discount));
            if(!$order->separate_delivery)
                $total += $order->delivery_price;    

            $payment_method = $this->payment->get_payment_method($order->payment_method_id);
            $payment_currency = $this->money->get_currency(intval($payment_method->currency_id));
            $settings = $this->payment->get_payment_settings($payment_method->id);
            $amount = round($this->money->convert($total, $payment_method->currency_id, false), 2);
            // описание заказа
            $config = new Config();

            $requestParams = array(
                'Amount' => round($amount * 100),
                'OrderId' => $order->id,
                'DATA' => array(
                    'Phone' => $order->phone,
                    'Connection_type' => 'ManyClients',
                ),
            );
            // если включена отправка данных о налогах в настройках модуля
            if ($settings['tbank_send_check']) {
                //подготовка массива товаров
                $vat = $settings['tbank_product_tax'];
                $products = $this->orders->get_purchases(array('order_id' => intval($order->id)));
                $receiptItems = array();

                foreach ($products as $product) {
                    $price = round($this->money->convert($product->price, $payment_method->currency_id, false), 2);
                    $receiptItems[] = array(
                        'Name' => mb_substr($product->product_name,0,64),
                        'Price' => round($price * 100),
                        'Quantity' => $product->amount,
                        'Amount' => round($price * $product->amount * 100),
                        'PaymentMethod' => trim($settings['tbank_payment_method']),
                        'PaymentObject' => trim($settings['tbank_payment_object']),
                        'Tax' => $vat,
                    );
                }

                $isShipping = false;
                if ($order->delivery_id) {
                    $delivery = $this->delivery->get_delivery($order->delivery_id);
                    $deliveryPrice = ($order->total_price > $delivery->free_from && $order->total_price > 0) ? 0 : $delivery->price;
                    $deliveryPrice = round($this->money->convert($deliveryPrice, $payment_method->currency_id, false), 2);
                    if ($deliveryPrice > 0 && !$delivery->separate_payment) {
                        //добавление данных о доставке
                        $receiptItems[] = array(
                            'Name' => mb_substr($delivery->name,0,64),
                            'Price' => round($deliveryPrice * 100),
                            'Quantity' => 1,
                            'Amount' => round($deliveryPrice * 100),
                            'PaymentMethod' => trim($settings['tbank_payment_method']),
                            'PaymentObject' => 'service',
                            'Tax' => $settings['tbank_delivery_tax'],
                        );
                        $isShipping = true;
                    }
                }

                $items_balance = $this->balanceAmount($isShipping, $receiptItems, $amount);

				$emailCompany = false != $settings['tbank_email_company'] ? substr($settings['tbank_email_company'],0,64) : null;
                $requestParams['Receipt'] = array(
                    'EmailCompany' => $emailCompany,
                    'Phone' => $order->phone,
                    'Taxation' => $settings['tbank_taxation'],
                    'Items' => $items_balance,
                );
            }

            if ($settings['tbank_language'] == 'en') {
                $requestParams['Language'] = 'en';
            }

            $requestParams['SuccessURL'] = $this->config->root_url.'/order/'.$order->url;
            $requestParams['FailURL'] = $this->config->root_url.'/order/'.$order->url;

            $TBank = new TBankMerchantAPI($settings['tbank_terminal'], $settings['tbank_secret']);
            $request = $TBank->buildQuery('Init', $requestParams);
            $this->logs($requestParams, $request);
            $request = json_decode($request);

            if (isset($request->PaymentURL)) {
                return '<a class="btn btn--default btn--sm has-ripple" href="' . $request->PaymentURL . '">' . $button_text . '</a>';
            } else {
                return 'Запрос к сервису ТКС завершился неудачей';
            }
        }
    }

    function balanceAmount($isShipping, $items, $amount)
    {
        $itemsWithoutShipping = $items;

        if ($isShipping) {
            $shipping = array_pop($itemsWithoutShipping);
        }

        $sum = 0;

        foreach ($itemsWithoutShipping as $item) {
            $sum += $item['Amount'];
        }

        if (isset($shipping)) {
            $sum += $shipping['Amount'];
        }

        $amount = round($amount * 100);

        if ($sum != $amount) {
            $sumAmountNew = 0;
            $difference = $amount - $sum;
            $amountNews = array();

            foreach ($itemsWithoutShipping as $key => $item) {
                $itemsAmountNew = $item['Amount'] + floor($difference * $item['Amount'] / $sum);
                $amountNews[$key] = $itemsAmountNew;
                $sumAmountNew += $itemsAmountNew;
            }

            if (isset($shipping)) {
                $sumAmountNew += $shipping['Amount'];
            }

            if ($sumAmountNew != $amount) {
                $max_key = array_keys($amountNews, max($amountNews))[0];    // ключ макс значения
                $amountNews[$max_key] = max($amountNews) + ($amount - $sumAmountNew);
            }

            foreach ($amountNews as $key => $item) {
                $items[$key]['Amount'] = $amountNews[$key];
            }
        }
        return $items;

    }

    function logs($requestData, $request)
    {
        // log send
        $log = '[' . date('D M d H:i:s Y', time()) . '] ';
        $log .= json_encode($requestData, JSON_UNESCAPED_UNICODE);
        $log .= "\n";
        file_put_contents(dirname(__FILE__) . "/tbank.log", $log, FILE_APPEND);

        $log = '[' . date('D M d H:i:s Y', time()) . '] ';
        $log .= $request;
        $log .= "\n";
        file_put_contents(dirname(__FILE__) . "/tbank.log", $log, FILE_APPEND);
    }
}