<?php
namespace Fasterpay\Fasterpay\Observer;

use \Fasterpay\Fasterpay\Model\Fasterpay;
use \Magento\Sales\Api\OrderRepositoryInterface;
use \Magento\Framework\Event\Observer;

class TrackingInfoObserver extends UpdateDeliveryData
{
    protected $orderRepository;

    public function __construct(
        Fasterpay $paymentModel,
        OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct(
            $paymentModel
        );

        $this->orderRepository = $orderRepository;
    }

    protected function getOrder(Observer $observer)
    {
        $track = $observer->getEvent()->getTrack();
        $order = $this->orderRepository->get($track->getOrderId());
        return $order;
    }
}