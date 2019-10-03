<?php
namespace Fasterpay\Fasterpay\Model;

use Magento\Framework\Exception\CouldNotSaveException;

class Pingback
{
    protected $objectManager;
    protected $helperConfig;
    protected $helper;
    protected $orderModel;
    protected $transactionSearchResult;
    protected $invoiceService;
    protected $dbTransaction;
    protected $payment;
    protected $pingbackFactory;
    protected $orderSender;
    protected $checkoutSession;
    protected $creditmemoService;
    protected $creditmemoFactory;
    protected $paymentModel;
    protected $invoiceModel;
    protected $request;
    protected $pingbackData;
    protected $validationParams;

    const PINGBACK_OK = 'OK';
    const PINGBACK_PAYMENT_ORDER_STATUS_SUCCESS = 'successful';
    const TRANSACTION_TYPE_ORDER = 'order';
    const TRANSACTION_TYPE_CAPTURE = 'capture';
    const STATE_PAID = 2;
    const PINGBACK_EVENT_PAYMENT = 'payment';
    const PINGBACK_EVENT_FULL_REFUND = 'refund';
    const PINGBACK_EVENT_PARTIAL_REFUND = 'partial_refund';
    const PINGBACK_EVENT_REFUND = [
        self::PINGBACK_EVENT_PARTIAL_REFUND,
        self::PINGBACK_EVENT_FULL_REFUND
    ];
    const PINGBACK_FULL_REFUND_STATUS_SUCCESS = 'reversal_refunded';
    const PINGBACK_PARTIAL_REFUND_STATUS_SUCCESS = 'reversal_refunded_partially';
    const PINGBACK_REFUND_STATUS_SUCCESS = [
        self::PINGBACK_FULL_REFUND_STATUS_SUCCESS,
        self::PINGBACK_PARTIAL_REFUND_STATUS_SUCCESS
    ];

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Sales\Model\Order $orderModel,
        \Magento\Sales\Api\Data\TransactionSearchResultInterface $transactionSearchResult,
        \Fasterpay\Fasterpay\Helper\Config $helperConfig,
        \Fasterpay\Fasterpay\Helper\Helper $helper,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $dbTransaction,
        \Magento\Sales\Model\Order\Payment $payment,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Order\Invoice $invoiceModel,
        Fasterpay $paymentModel
    ) {
        $this->objectManager = $objectManager;
        $this->orderModel = $orderModel;
        $this->helperConfig = $helperConfig;
        $this->helper = $helper;
        $this->transactionSearchResult = $transactionSearchResult;
        $this->invoiceService = $invoiceService;
        $this->dbTransaction = $dbTransaction;
        $this->payment = $payment;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->creditmemoService = $creditmemoService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->paymentModel = $paymentModel;
        $this->invoiceModel = $invoiceModel;
    }

    public function pingback($request)
    {
        try {
            $this->request = $request;
            $gateway = $this->paymentModel->getGateway();

            $signVersion = \FasterPay\Services\Signature::SIGN_VERSION_1;
            if (!empty($this->request->getHeader('x-fasterpay-signature-version'))) {
                $signVersion = $this->request->getHeader('x-fasterpay-signature-version');
            }
            $this->validationParams = [];

            switch ($signVersion) {
                case \FasterPay\Services\Signature::SIGN_VERSION_1:
                    $this->validationParams = ['apiKey' => $this->request->getHeader('x-apikey')];
                    $this->pingbackData = $this->request->getPost();
                    break;
                case \FasterPay\Services\Signature::SIGN_VERSION_2:
                    $this->validationParams = [
                        'pingbackData' => $this->request->getContent(),
                        'signVersion' => $signVersion,
                        'signature' => $this->request->getHeader('x-fasterpay-signature'),
                    ];
                    $this->pingbackData = json_decode($this->validationParams['pingbackData'], true);
                    break;
                default:
                    throw new \Exception('Invalid Signature Version');
            }

            if (empty($this->pingbackData)) {
                throw new \Exception('Invalid Pingback Data');
            }

            if ($this->_isPaymentEvent()) {
                $this->_paymentPingbackHandle();
            } elseif ($this->_isRefundEvent()) {
                $this->_refundPingbackHandle();
            } else {
                throw new \Exception('Invalid Pingback Event');
            }

            return self::PINGBACK_OK;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function createOrderInvoice(\Magento\Sales\Model\Order $order, $pingback)
    {
        try {
            if ($order->canInvoice()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->register();
                $invoice->setState(self::STATE_PAID);
                $invoice->setTransactionId($pingback['payment_order']['id']);
                $invoice->save();

                $transactionSave = $this->dbTransaction
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();

                $order->addStatusHistoryComment(__('Created invoice #%1.', $invoice->getId()))
                    ->setIsCustomerNotified(true)->save();
                $this->createTransaction($order, $pingback['payment_order']['id']);
            }
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('An error occurred when tried to create Order Invoice.'),
                $e
            );
        }
    }

    public function createTransaction($order, $referenceId, $type = self::TRANSACTION_TYPE_ORDER)
    {
        try {
            $payment = $this->payment;
            $payment->setTransactionId($referenceId);
            $payment->setOrder($order);
            $payment->setIsTransactionClosed(1);
            $transaction = $payment->addTransaction($type);
            $transaction->beforeSave();
            $transaction->save();
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('An error occurred when tried to create Order Transaction.'),
                $e
            );
        }
    }

    protected function _isPaymentEvent()
    {
        return $this->pingbackData['event'] == self::PINGBACK_EVENT_PAYMENT;
    }

    protected function _isRefundEvent()
    {
        return in_array($this->pingbackData['event'], self::PINGBACK_EVENT_REFUND);
    }

    protected function _paymentPingbackHandle()
    {
        $this->_paymentPingbackValidate();

        if ($this->pingbackData['payment_order']['status'] != self::PINGBACK_PAYMENT_ORDER_STATUS_SUCCESS) {
            return;
        }

        $orderModel = $this->orderModel;
        $orderStatus = $orderModel::STATE_PROCESSING;
        $this->createOrderInvoice($orderModel, $this->pingbackData);
        $orderModel->setStatus($orderStatus);
        $orderModel->save();
        $this->checkoutSession->setForceOrderMailSentOnSuccess(true);
        $this->orderSender->send($orderModel, true);
    }

    protected function _paymentPingbackValidate()
    {
        if (!isset($this->pingbackData['payment_order']['id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['merchant_order_id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['status'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        $orderIncrementId = $this->pingbackData['payment_order']['merchant_order_id'];
        $orderModel = $this->orderModel;
        $orderModel->loadByIncrementId($orderIncrementId);

        if (!$orderModel->getId()) {
            throw new \Exception('Invalid Order');
        }

        if (!$this->paymentModel->getGateway()->pingback()->validate($this->validationParams)) {
            throw new \Exception('Invalid Pingback Data');
        }
    }

    protected function _refundPingbackHandle()
    {
        $this->_refundPingbackValidate();

        $paymentOrder = $this->pingbackData['payment_order'];
        $amount = $paymentOrder['refund_amount'];
        $referenceId = $paymentOrder['reference_id'];
        $fpTxnId = $paymentOrder['id'];
        $status = $paymentOrder['status'];

        if (!$this->paymentModel->isRefundSuccess($status)) {
            return;
        }

        // TODO: refund programmatically
        $order = $this->orderModel;
        $status = $order->getStatus();
        $refundItems = [];
        // Must have, if omit this step creditMemoService will get all item quantity as refund quantity
        foreach ($order->getAllItems() as $orderItem) {
            $refundItems[$orderItem->getId()] = 0;
        }

        $invoices = $order->getInvoiceCollection();
        foreach ($invoices as $invoice) {
            $invoiceincrementid = $invoice->getIncrementId();
        }

        $invoiceobj = $this->invoiceModel->loadByIncrementId($invoiceincrementid);
        // Must have, if omit this shipping_amount creditMemoService will get shipping_amount as apart of refund amount
        $creditmemo = $this->creditmemoFactory->createByOrder($order, [
            'qtys' => $refundItems,
            'adjustment_positive' => $amount,
            'shipping_amount' => 0
        ]);

        // Don't set invoice if you want to do offline refund
        $creditmemo->setInvoice($invoiceobj);

        $this->creditmemoService->refund($creditmemo);

        // avoid order not change status to closed after full amount refund error on ver2.x
        // avoid order change status to pending after refund error on ver2.2
        $order->setStatus($status);
        if (!$order->canCreditmemo()) {
            $order->setStatus($order::STATE_CLOSED);
        }
        $order->save();
    }

    protected function _refundPingbackValidate()
    {
        if (!isset($this->pingbackData['payment_order']['id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['merchant_order_id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['total_refunded_amount'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['reference_id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['status'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($this->pingbackData['payment_order']['refund_amount'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        $orderIncrementId = $this->pingbackData['payment_order']['merchant_order_id'];
        $orderModel = $this->orderModel;
        $orderModel->loadByIncrementId($orderIncrementId);

        if (!$orderModel->getId()) {
            throw new \Exception('Invalid Order');
        }
    }
}
