<?php
namespace Fasterpay\Fasterpay\Observer;

use Magento\Framework\Event\ObserverInterface;
use \Fasterpay\Fasterpay\Model\Fasterpay;
use \Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;

abstract class UpdateDeliveryData implements ObserverInterface
{
    protected $paymentModel;
    protected $orderRepository;

    public function __construct(
        Fasterpay $paymentModel
    ) {
        $this->paymentModel = $paymentModel;
    }

    public function execute(Observer $observer)
    {
        //Observer execution code...
        $order = $this->getOrder($observer);

        if (empty($order) || $order->getIsVirtual()) {
            return;
        }

        $paymentMethod = $order->getPayment()->getMethod();
        if ($paymentMethod == Fasterpay::PAYMENT_METHOD_CODE && $order->getState() == Order::STATE_COMPLETE) {

            return $this->paymentModel->sendDeliveryInformation($order, Fasterpay::DELIVERY_STATUS_DELIVERING);
        }
    }

    abstract protected function getOrder(Observer $observer);
}
