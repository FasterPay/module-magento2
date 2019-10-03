<?php
namespace Fasterpay\Fasterpay\Controller\Gateway;

class Fasterpay extends \Magento\Checkout\Controller\Onepage
{

    public function execute()
    {
        $session = $this->getOnepage()->getCheckout();
        if (!$this->_objectManager->get('Magento\Checkout\Model\Session\SuccessValidator')->isValid()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        $session->clearQuote();

        $form = $this->_view->getLayout()
            ->createBlock('Fasterpay\Fasterpay\Block\Fasterpay')
            ->setTemplate('Fasterpay_Fasterpay::fasterpay.phtml')
            ->toHtml();

        $this->_eventManager->dispatch(
            'checkout_onepage_controller_success_action',
            ['order_ids' => [$session->getLastOrderId()]]
        );

        $this->getResponse()->setBody($form);
    }
}
