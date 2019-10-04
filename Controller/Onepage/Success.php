<?php
namespace Fasterpay\Fasterpay\Controller\Onepage;

use \Magento\Checkout\Controller\Onepage as OnepageAction;

class Success extends OnepageAction
{
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        return $resultPage;
    }
}
