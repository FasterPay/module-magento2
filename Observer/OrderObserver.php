<?php
namespace Fasterpay\Fasterpay\Observer;

use \Magento\Framework\Event\Observer;

class OrderObserver extends UpdateDeliveryData
{
    protected function getOrder(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        return $order;
    }
}