<?php
namespace Fasterpay\Fasterpay\Model;

use Magento\Sales\Model\Order;
use Magento\Customer\Model\Customer;

/**
 * Class Fasterpay
 *
 * @method \Magento\Quote\Api\Data\PaymentMethodExtensionInterface getExtensionAttributes()
 */
class Fasterpay extends \Magento\Payment\Model\Method\AbstractMethod
{
    const PAYMENT_METHOD_CODE = 'fasterpay';
    const MODULE_SOURCE = 'magento2';
    const DUPLICATE_REFERENCE_ID_STATE = 23000;
    const REFUND_PENDING_MESSAGE = 'Your refund transaction is being processed.';

    protected $_code = self::PAYMENT_METHOD_CODE;
    protected $objectManager;
    protected $urlBuilder;
    protected $helper;
    protected $resourceConnection;
    protected $url;
    protected $responseFactory;
    protected $messageManager;
    protected $request;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Fasterpay\Fasterpay\Helper\Config $helperConfig,
        \Fasterpay\Fasterpay\Helper\Helper $helper,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Framework\App\ResponseFactory $responseFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->objectManager = $objectManager;
        $this->urlBuilder = $urlBuilder;
        $this->_storeManager = $storeManager;
        $this->helperConfig = $helperConfig;
        $this->helper = $helper;
        $this->resourceConnection = $resourceConnection;
        $this->responseFactory = $responseFactory;
        $this->messageManager = $messageManager;
        $this->request = $request;
    }

    public function generateForm(\Magento\Sales\Model\Order $order)
    {
        if ($order->hasBillingAddressId()) {
            $billingData = $order->getBillingAddress()->getData();
        }

        $form = $this->getGateway()->paymentForm()->buildForm(
            [
                'description' => 'Order #' . $order->getIncrementId(),
                'amount' => $order->getData('total_due'),
                'currency' => $order->getOrderCurrency()->getCode(),
                'merchant_order_id' => $order->getIncrementId(),
                'success_url' => $this->urlBuilder->getUrl('fasterpay/onepage/success/'),
                'pingback_url' => $this->urlBuilder->getUrl('fasterpay/index/pingback/'),
                'sign_version' => \FasterPay\Services\Signature::SIGN_VERSION_2,
                'module_source' => self::MODULE_SOURCE,
                'email' =>  !empty($billingData['email']) ? $billingData['email'] : '',
                'first_name' => !empty($billingData['firstname']) ? $billingData['firstname'] : '',
                'last_name' => !empty($billingData['lastname']) ? $billingData['lastname'] : '',
                'city' => !empty($billingData['city']) ? $billingData['city'] : '',
                'zip' => !empty($billingData['postcode']) ? $billingData['postcode'] : ''
            ],
            [
                'autoSubmit' => true,
                'hidePayButton' => true
            ]
        );

        return $form;
    }

    public function getGateway()
    {
        if (empty($this->gateway)) {
            $this->gateway = new \FasterPay\Gateway([
                'publicKey' => $this->helperConfig->getConfig('public_key'),
                'privateKey' => $this->helperConfig->getConfig('private_key'),
                'isTest' => $this->helperConfig->getConfig('is_test')
            ]);
        }

        return $this->gateway;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        parent::refund($payment, $amount);

        $captureTxnId = $this->_getParentTransactionId($payment);
        if ($captureTxnId) {
            $order = $payment->getOrder();
            $canRefundMore = $payment->getCreditmemo()->getInvoice()->canRefund();
            // $isFullRefund = !$canRefundMore && 0 == ((double)$order->getBaseTotalOnlineRefunded() + (double)$order->getBaseTotalOfflineRefunded());

            if ($this->_isCalledFromPingback()) {
                $this->_importRefundResultToPayment($captureTxnId, $payment, $canRefundMore);
                return $this;
            }

            $refundResponse = $this->_createRefund($captureTxnId, $amount);
            $reponseData = $refundResponse->getResponse('data');
            $referenceId = $reponseData['reference_id'];
            $fpTxnId = $reponseData['id'];
            $isRefundSuccess = $this->isRefundSuccess($reponseData['status']);

            // avoid catch LocalizedException error on ver2.0
            $this->messageManager->addError(__(self::REFUND_PENDING_MESSAGE));
            throw new \Magento\Framework\Exception\LocalizedException(
                __(self::REFUND_PENDING_MESSAGE)
            );
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('We can\'t issue a refund transaction because there is no capture transaction.')
            );
        }
    }

    public function isRefundSuccess($status)
    {
        return in_array($status, Pingback::PINGBACK_REFUND_STATUS_SUCCESS);
    }

    protected function _createRefund($transactionId, $amount)
    {
        try {
            $refundResponse = $this->getGateway()->paymentService()->refund($transactionId, $amount);
        } catch (FasterPay\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage())
            );
        }

        if (!$refundResponse->isSuccessful()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($refundResponse->getErrors()->getMessage())
            );
        }

        return $refundResponse;
    }

    protected function _importRefundResultToPayment($transactionId, $payment, $canRefundMore)
    {
        $payment->setTransactionId($transactionId)
            ->setIsTransactionClosed(true)
            ->setShouldCloseParentTransaction(!$canRefundMore);
    }

    protected function _getParentTransactionId(\Magento\Framework\DataObject $payment)
    {
        return $payment->getParentTransactionId();
    }

    protected function _isCalledFromPingback()
    {
        $areaCode = $this->_appState->getAreaCode();

        if ($areaCode == \Magento\Framework\App\Area::AREA_FRONTEND) {
            return true;
        }

        return false;
    }
}
