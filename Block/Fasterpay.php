<?php
namespace Fasterpay\Fasterpay\Block;

use Magento\Customer\Model\Context;
use Magento\Sales\Model\Order;

class Fasterpay extends \Magento\Framework\View\Element\Template
{

    protected $checkoutSession;
    protected $customerSession;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Fasterpay\Fasterpay\Model\Fasterpay $paymentModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->paymentModel = $paymentModel;
    }

    /**
     * Initialize data and prepare it for output
     *
     * @return string
     */
    protected function _beforeToHtml()
    {
        $this->prepareBlockData();
        return parent::_beforeToHtml();
    }

    /**
     * Prepares block data
     *
     * @return void
     */
    protected function prepareBlockData()
    {
        $order = $this->checkoutSession->getLastRealOrder();
        // $customer = $this->customerSession->getCustomer();

        $this->addData(
            ['form' => $this->paymentModel->generateForm($order)]
        );

        return true;
    }
}
