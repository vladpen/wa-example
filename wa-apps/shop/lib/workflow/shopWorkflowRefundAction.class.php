<?php

class shopWorkflowRefundAction extends shopWorkflowAction
{
    public function isAvailable($order)
    {
        if (!empty($order['id']) && $this->getPaymentPlugin($order['id'])) {
            $this->setOption('html', true);
        }

        return parent::isAvailable($order);
    }

    protected function partialRefund(shopOrder &$order, $refund_items)
    {
        $order_items = array();

        $items = $order->items;

        $discount = 0.0;
        foreach ($order->items as $item) {
            $discount += $item['total_discount'];
        }

        $extra_total_discount = max(0, $order->discount - $discount) / $order->subtotal;

        $refund_discount = 0.0;

        foreach ($items as $item_id => &$item) {

            if (!empty($item['parent_id'])) {
                $item_id = $item['parent_id'];
            } else {
                $item_id = $item['id'];
            }

            if ($refund_items === true) {
                $quantity = true;
            } elseif (is_array($refund_items) && isset($refund_items[$item_id]['quantity'])) {
                $quantity = max(0, $refund_items[$item_id]['quantity']);
                $quantity = min($quantity, $item['quantity']);
                if ($quantity === 0) {
                    $quantity = false;
                }
            } else {
                $quantity = false;
            }

            if ($quantity !== false) {


                $order_items[$item['id']] = $item;
                if ($item['quantity']) {
                    if ($quantity || $extra_total_discount) {
                        $item_discount = $extra_total_discount + $item['total_discount'] / ($item['price'] * $item['quantity']);

                        if ($quantity) {
                            if ($quantity === true) {
                                $quantity = $item['quantity'];
                            }
                            $order_items[$item['id']]['total_discount'] = $item['price'] * $item_discount * $quantity;
                            $order_items[$item['id']]['quantity'] = $quantity;

                            $refund_discount += $order_items[$item['id']]['total_discount'];
                        }

                        $item['total_discount'] = $item_discount * ($item['quantity'] - $quantity) / $item['quantity'];
                        $item['quantity'] -= $quantity;

                    }
                }


            }
            unset($item);
        }

        if ($refund_items !== true) {

            $shipping = $order->shipping;
            $discount = $order->discount;
            $order->items = $items;
            if ($refund_discount > 0) {
                $order->discount = max(0, $discount - $refund_discount);
            } else {
                $order->discount = $discount;
            }

            //XXX refund shipping delta?
            $order->shipping = $shipping;


            #Don't change state after action execute
            $this->state_id = null;
        }

        return $order_items;
    }

    public function execute($options = null)
    {
        $result = true;
        if (is_array($options)) {
            # it's payment plugin callback
            $order_id = $options['order_id'];

        } else {
            $order_id = $options;
            $options = array();
        }

        # use $options['action_options'] for tests
        $refund = ifset($options, 'action_options', 'refund', waRequest::post('refund'));
        $refund_amount = ifset($options, 'action_options', 'refund_amount', waRequest::post('refund_amount'));
        $refund_mode = ifset($options, 'action_options', 'refund_mode', waRequest::post('refund_mode'));
        $return_stock = ifset($options, 'action_options', 'return_stock', waRequest::post('return_stock'));

        $refund_items = ifset($options, 'action_options', 'refund_items', waRequest::post('refund_items'));

        if ($refund) {
            $plugin = $this->getPaymentPlugin($order_id);
        } else {
            $plugin = null;
        }


        if ($refund_mode === 'partial') {
            $order_options = array(
                'ignore_stock_validate' => true,
            );
            $order = new shopOrder($order_id, $order_options);

            $refund_items = $this->partialRefund($order, $refund_items);

            $refund_items = $this->workupOrderItems($order, $plugin, $refund_items);
            $refund_amount = 0.0;
            foreach ($refund_items as $refund_item) {
                $refund_amount += ($refund_item['price'] * $refund_item['quantity']) - ifset($refund_item['total_discount'], 0);
            }
        } elseif ($refund_amount === null) {
            $refund_amount = true;
            $refund_items = null;
        } else {
            if ($refund_amount !== true) {
                $refund_amount = max(0, floatval($refund_amount));
            }
            $refund_items = null;
        }


        if ($refund && !empty($plugin)) {
            $result = $this->refundPayment($plugin, $order_id, $refund_amount, $refund_items);
        } elseif ($refund_items) {
            $result = array(
                'params' => array(
                    'refund_amount' => $refund_amount,
                    'refund_items'  => $refund_items,
                ),
            );
        }

        if ($result) {
            if (!empty($order)) {
                shopAffiliate::cancelBonus($order->id);
                $order->save();
            }
            if ($return_stock) {
                if (!is_array($result)) {
                    $result = array();
                }
                $result['params']['return_stock'] = intval($return_stock);
            }
            $text = nl2br(htmlspecialchars(trim(waRequest::post('text', '')), ENT_QUOTES, 'utf-8'));
            if (strlen($text)) {
                if (!is_array($result)) {
                    $result = array();
                }
                $result['text'] = $text;
            }
        }

        return $result;
    }

    /**
     * @param waPayment|waIPaymentRefund $plugin
     * @param int                        $order_id
     * @param float                      $refund_amount
     * @param array[]                    $refund_items
     * @return array|bool
     */
    protected function refundPayment($plugin, $order_id, $refund_amount, $refund_items)
    {
        if ($transaction = shopPayment::isRefundAvailable($order_id, $plugin)) {
            try {

                if (isset($transaction['amount']) && ($refund_amount > $transaction['amount'])) {
                    throw new waException('Specified amount exceeds transaction amount');
                }

                if (!empty($transaction['refunded_amount']) && empty($refund_items)) {
                    $order = new shopOrder($order_id);
                    $refund_items = $this->workupOrderItems($order, $plugin, $order->items);
                }

                $response = $plugin->refund(compact('transaction', 'refund_amount', 'refund_items'));

                if (empty($response)) {
                    $result = false;
                } else {
                    if (($response['result'] !== 0)) {
                        throw new waException('Transaction error: '.$response['description']);
                    }

                    $template = _w('Refunded %s via %s payment gateway.');
                    if ($refund_amount === true) {
                        $refund_amount_html = shop_currency_html($transaction['amount'], $transaction['currency_id']);
                        $refund_amount = $transaction['amount'];
                    } else {
                        $refund_amount_html = shop_currency_html($refund_amount, $transaction['currency_id']);
                    }

                    $result = array(
                        'params' => array(
                            'refund_amount' => $refund_amount,
                            'refund_items'  => $refund_items,
                        ),
                        'text'   => sprintf($template, $refund_amount_html, $plugin->getName()),
                    );
                }
            } catch (waException $ex) {
                $result = false;
                $data = compact('transaction', 'refund_amount', 'refund_items');
                if (!empty($response)) {
                    $data['response'] = $response;
                }
                $message = sprintf(
                    "Error during refund order #%d: %s\nDATA:%s",
                    $order_id,
                    $ex->getMessage(),
                    var_export($data, true)
                );
                waLog::log($message, 'shop/workflow/refund.error.log');
            }
        } else {
            $result = false;
            $message = sprintf(
                "Refund order #%d not available\nDATA:%s",
                $order_id,
                var_export(compact('transaction', 'refund_amount', 'refund_items'), true)
            );
            waLog::log($message, 'shop/workflow/refund.error.log');
        }

        return $result;
    }

    public function postExecute($order_id = null, $result = null)
    {
        $data = parent::postExecute($order_id, $result);

        if (!empty($data)) {
            if ($order_id != null) {
                $order = $this->getOrder($order_id);
                if ($this->state_id) {
                    $this->waLog('order_refund', $order_id);
                    $this->order_model->updateById($order_id, array(
                        'paid_date'    => null,
                        'paid_year'    => null,
                        'paid_month'   => null,
                        'paid_quarter' => null,

                        'auth_date' => null,
                    ));

                    // for logging changes in stocks
                    shopProductStocksLogModel::setContext(
                        shopProductStocksLogModel::TYPE_ORDER,
                        'Order %s was refunded',
                        array(
                            'order_id' => $order_id,
                        )
                    );

                    // refund, so return
                    $return_stock = ifempty($result, 'params', 'return_stock', null);
                    $this->order_model->returnProductsToStocks($order_id, null, $return_stock);
                    shopProductStocksLogModel::clearContext();

                    shopAffiliate::refundDiscount($order);
                    shopAffiliate::cancelBonus($order);
                    $this->order_model->recalculateProductsTotalSales($order_id);
                    shopCustomer::recalculateTotalSpent($order['contact_id']);
                    $params = array(
                        'shipping_data' => waRequest::post('shipping_data'),
                        'log'           => true,
                    );
                    $this->setPackageState(waShipping::STATE_CANCELED, $order, $params);
                } else {
                    #partial refund
                    $this->waLog('order_partial_refund', $order_id);

                    // for logging changes in stocks
                    shopProductStocksLogModel::setContext(
                        shopProductStocksLogModel::TYPE_ORDER,
                        'Order %s was refunded',
                        array(
                            'order_id' => $order_id,
                        )
                    );

                    // refund, so return
                    $refund_items = ifempty($result, 'params', 'refund_items', null);
                    $return_stock = ifempty($result, 'params', 'return_stock', null);

                    $this->order_model->returnProductsToStocks($order_id, $refund_items, $return_stock);
                    shopProductStocksLogModel::clearContext();

                    //XXX
                    //shopAffiliate::refundDiscount($order);
                    $order['items'] = $result['params']['refund_items'];

                    shopAffiliate::applyBonus($order);

                    $this->order_model->recalculateProductsTotalSales($order_id);
                    shopCustomer::recalculateTotalSpent($order['contact_id']);

                    $this->setPackageState(waShipping::STATE_DRAFT, $order);
                }
            }
        }

        return $data;
    }

    public function getHTML($order_id)
    {
        $order_id = intval($order_id);

        /** @var waPayment|null $plugin */
        $plugin = $this->getPaymentPlugin($order_id);

        $transaction_data = $plugin ? shopPayment::isRefundAvailable($order_id, $plugin) : false;
        $shipping_controls = $this->getShippingFields($order_id, waShipping::STATE_CANCELED);

        $partial_refund = $transaction_data ? $plugin->getProperties('partial_refund') : false;
        $order = new shopOrder($order_id);

        $button_class = $this->getOption('button_class');

        $currency_id = ($transaction_data && $plugin) ? $plugin->allowedCurrency() : $order->currency;
        $currency = $this->getConfig()->getCurrencies($currency_id);
        $currency = reset($currency);

        $locale_info = waLocale::getInfo(wa()->getLocale());

        $currency_info = array(
            'code'             => $currency['code'],
            'fraction_divider' => ifset($locale_info, 'decimal_point', '.'),
            'fraction_size'    => ifset($currency, 'precision', 2),
            'group_divider'    => ifset($locale_info, 'thousands_sep', ''),
            'group_size'       => 3,

            'pattern_html' => str_replace('0', '%s', waCurrency::format('%{h}', 0, $currency_id)),
            'pattern_text' => str_replace('0', '%s', waCurrency::format('%{s}', 0, $currency_id)),
        );

        $app_settings_model = new waAppSettingsModel();
        if (!$app_settings_model->get('shop', 'disable_stock_count')) {
            $model = new shopStockModel();
            $stocks = $model->getAll();
            if (count($stocks) <= 1) {
                $stocks = array();
            }
        } else {
            $stocks = array();
        }

        $order_items = $this->partialRefund($order, true);

        $order_items = $this->workupOrderItems($order, $transaction_data ? $plugin : null, $order_items);
        foreach ($order_items as &$item) {
            if ($item['quantity']) {
                $item['price_with_discount'] = $item['price'] - $item['total_discount'] / $item['quantity'];
            } else {
                $item['price_with_discount'] = $item['price'];
            }
        }

        $this->getView()->assign(compact('transaction_data', 'partial_refund', 'shipping_controls', 'button_class', 'order_items', 'order', 'currency_info', 'stocks'));

        $this->setOption('html', true);
        return parent::getHTML($order_id);
    }

    protected function workupOrderItems(shopOrder $order, waPayment $plugin = null, $items = null)
    {
        if (!$items) {
            $items = $order->items;
        }

        if ($plugin) {
            $refund_options = array(
                'currency'       => $plugin->allowedCurrency(),
                'order_currency' => $order->currency,
            );
            foreach ($items as $id => $item) {
                if (empty($item['quantity'])) {
                    unset($items[$id]);
                }
            }
            $items = shopHelper::workupOrderItems($items, $refund_options);
        }

        return $items;

    }
}
