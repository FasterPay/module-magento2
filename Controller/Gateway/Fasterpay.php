<?php
namespace Fasterpay\Fasterpay\Controller\Gateway;

use \Magento\Checkout\Controller\Onepage as OnepageAction;

class Fasterpay extends OnepageAction
{

    public function execute()
    {
        $session = $this->getOnepage()->getCheckout();
        if (!$this->_objectManager->get('Magento\Checkout\Model\Session\SuccessValidator')->isValid()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        $session->clearQuote();

        $resultPage = $this->resultPageFactory->create();

        $this->_eventManager->dispatch(
            'checkout_onepage_controller_success_action',
            ['order_ids' => [$session->getLastOrderId()]]
        );
        return $resultPage;
    }
}
