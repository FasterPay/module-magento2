<?php
namespace Fasterpay\Fasterpay\Controller\Index;

use \Magento\Framework\App\Action\Action as Action;
use \Magento\Framework\App\Action\Context as ActionContext;
use \Fasterpay\Fasterpay\Model\Pingback as PingbackModel;

class Pingback extends Action
{

    protected $pingbackModel;

    public function __construct(
        ActionContext $context,
        PingbackModel $pingbackModel
    ) {
        parent::__construct($context);
        $this->pingbackModel = $pingbackModel;
    }

    public function execute()
    {
        $this->getResponse()->setBody($this->pingbackModel->pingback($this->_request));
    }
}
