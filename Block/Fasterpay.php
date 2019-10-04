<?php
namespace Fasterpay\Fasterpay\Block;

use \Magento\Sales\Model\Order;
use \Magento\Framework\Controller\Result\RedirectFactory;
use \Magento\Framework\View\Element\Template\Context as TemplateContext;
use \Magento\Customer\Model\Session as CustomerSession;
use \Magento\Checkout\Model\Session as CheckoutSession;
use \Fasterpay\Fasterpay\Model\Fasterpay as FPModel;
use \Magento\Framework\View\Element\Template as ElementTemplate;

class Fasterpay extends ElementTemplate
{

    protected $checkoutSession;
    protected $customerSession;
    protected $redirectFactory;
    protected $paymentModel;

    public function __construct(
        TemplateContext $context,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        FPModel $paymentModel,
        RedirectFactory $redirectFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->paymentModel = $paymentModel;
        $this->redirectFactory = $redirectFactory;
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

        $this->addData(
            ['form' => $this->paymentModel->generateForm($order)]
        );

        return true;
    }
}
