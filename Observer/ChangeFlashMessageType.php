<?php
namespace Fasterpay\Fasterpay\Observer;

class ChangeFlashMessageType implements \Magento\Framework\Event\ObserverInterface
{
	protected $objectManager;

	public function __construct(
		\Magento\Framework\ObjectManagerInterface $objectManager
	)
	{
		$this->objectManager = $objectManager;
	}

	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		$fpModel = $this->objectManager->create('Fasterpay\Fasterpay\Model\Fasterpay');
		$responseFactory = $this->objectManager->create('\Magento\Framework\App\ResponseFactory');
		$request = $this->objectManager->get('\Magento\Framework\App\Request\Http');
		$urlBuilder =  $this->objectManager->create('\Magento\Framework\UrlInterface');
		$messageManager =  $this->objectManager->create('\Magento\Framework\Message\ManagerInterface');

		// change message type and override redirect url
		$flashMessages = $messageManager->getMessages();
		$items = $flashMessages->getItems();
		foreach ($items as $item) {
			if ($item->getText() == \Fasterpay\Fasterpay\Model\Fasterpay::REFUND_PENDING_MESSAGE) {
				$flashMessages->clear();
				$messageManager->addNotice(\Fasterpay\Fasterpay\Model\Fasterpay::REFUND_PENDING_MESSAGE);
				$customRedirectionUrl = $urlBuilder->getUrl('sales/order/view/order_id/' . $request->getParam('order_id'));
				$responseFactory->create()->setRedirect($customRedirectionUrl)->sendResponse();
				exit;
			}
		}

		return $this;
	}
}