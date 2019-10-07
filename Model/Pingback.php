<?php
namespace Fasterpay\Fasterpay\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use \Magento\Sales\Model\Order as OrderModel;
use \Magento\Sales\Model\Service\InvoiceService;
use \Magento\Framework\DB\Transaction as DBTransaction;
use \Magento\Sales\Model\Order\Payment as OrderPayment;
use \Magento\Sales\Model\Order\Email\Sender\OrderSender;
use \Magento\Checkout\Model\Session as CheckoutSession;
use \Magento\Sales\Model\Service\CreditmemoService;
use \Magento\Sales\Model\Order\CreditmemoFactory;
use \Magento\Sales\Model\Order\Invoice;
use \FasterPay\Services\Signature as FPSignature;

class Pingback
{
    protected $orderModel;
    protected $invoiceService;
    protected $dbTransaction;
    protected $payment;
    protected $orderSender;
    protected $checkoutSession;
    protected $creditmemoService;
    protected $creditmemoFactory;
    protected $paymentModel;
    protected $invoiceModel;
    protected $dataObject;

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
        OrderModel $orderModel,
        InvoiceService $invoiceService,
        DBTransaction $dbTransaction,
        OrderPayment $payment,
        OrderSender $orderSender,
        CheckoutSession $checkoutSession,
        CreditmemoService $creditmemoService,
        CreditmemoFactory $creditmemoFactory,
        Invoice $invoiceModel,
        Fasterpay $paymentModel
    ) {
        $this->orderModel = $orderModel;
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
            $request;
            $gateway = $this->paymentModel->getGateway();

            $signVersion = FPSignature::SIGN_VERSION_1;
            if (!empty($request->getHeader('x-fasterpay-signature-version'))) {
                $signVersion = $request->getHeader('x-fasterpay-signature-version');
            }
            $validationParams = [];

            switch ($signVersion) {
                case FPSignature::SIGN_VERSION_1:
                    $validationParams = ['apiKey' => $request->getHeader('x-apikey')];
                    $pingbackData = $request->getPost();
                    break;
                case FPSignature::SIGN_VERSION_2:
                    $validationParams = [
                        'pingbackData' => $request->getContent(),
                        'signVersion' => $signVersion,
                        'signature' => $request->getHeader('x-fasterpay-signature'),
                    ];
                    $pingbackData = json_decode($validationParams['pingbackData'], true);
                    break;
                default:
                    throw new \Exception('Invalid Signature Version');
            }

            if (empty($pingbackData)) {
                throw new \Exception('Invalid Pingback Data');
            }

            if (!$this->paymentModel->getGateway()->pingback()->validate($validationParams)) {
                throw new \Exception('Invalid Pingback Data');
            }

            if ($this->_isPaymentEvent($pingbackData)) {
                $this->_paymentPingbackHandle($pingbackData);
            } elseif ($this->_isRefundEvent($pingbackData)) {
                $this->_refundPingbackHandle($pingbackData);
            } else {
                throw new \Exception('Invalid Pingback Event');
            }

            return self::PINGBACK_OK;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function createOrderInvoice(OrderModel $order, $pingback)
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

    protected function _isPaymentEvent($pingbackData)
    {
        return $pingbackData['event'] == self::PINGBACK_EVENT_PAYMENT;
    }

    protected function _isRefundEvent($pingbackData)
    {
        return in_array($pingbackData['event'], self::PINGBACK_EVENT_REFUND);
    }

    protected function _paymentPingbackHandle($pingbackData)
    {
        $this->_paymentPingbackValidate($pingbackData);

        if ($pingbackData['payment_order']['status'] != self::PINGBACK_PAYMENT_ORDER_STATUS_SUCCESS) {
            return;
        }

        $orderModel = $this->orderModel;
        $orderStatus = $orderModel::STATE_PROCESSING;
        $this->createOrderInvoice($orderModel, $pingbackData);
        $orderModel->setStatus($orderStatus);
        $orderModel->save();
        $this->checkoutSession->setForceOrderMailSentOnSuccess(true);
        $this->orderSender->send($orderModel, true);
    }

    protected function _paymentPingbackValidate($pingbackData)
    {
        if (empty($pingbackData['payment_order']['id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (empty($pingbackData['payment_order']['merchant_order_id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($pingbackData['payment_order']['status'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        $orderIncrementId = $pingbackData['payment_order']['merchant_order_id'];
        $orderModel = $this->orderModel;
        $orderModel->loadByIncrementId($orderIncrementId);

        if (!$orderModel->getId()) {
            throw new \Exception('Invalid Order');
        }
    }

    protected function _refundPingbackHandle($pingbackData)
    {
        $this->_refundPingbackValidate($pingbackData);

        $paymentOrder = $pingbackData['payment_order'];
        $amount = $paymentOrder['refund_amount'];
        $referenceId = $paymentOrder['reference_id'];
        $fpTxnId = $paymentOrder['id'];

        if (!$this->paymentModel->isRefundSuccess($paymentOrder['status'])) {
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
            'shipping_amount' => 0,
            'adjustment_negative' = 0
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

    protected function _refundPingbackValidate($pingbackData)
    {
        if (empty($pingbackData['payment_order']['id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (empty($pingbackData['payment_order']['merchant_order_id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($pingbackData['payment_order']['total_refunded_amount'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($pingbackData['payment_order']['reference_id'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($pingbackData['payment_order']['status'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        if (!isset($pingbackData['payment_order']['refund_amount'])) {
            throw new \Exception('Invalid Pingback Data');
        }

        $orderIncrementId = $pingbackData['payment_order']['merchant_order_id'];
        $orderModel = $this->orderModel;
        $orderModel->loadByIncrementId($orderIncrementId);

        if (!$orderModel->getId()) {
            throw new \Exception('Invalid Order');
        }
    }
}
