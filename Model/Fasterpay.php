<?php
namespace Fasterpay\Fasterpay\Model;

use \Magento\Sales\Model\Order;
use \Magento\Customer\Model\Customer;
use \Magento\Framework\Model\Context as ModelContext;
use \Magento\Framework\Registry;
use \Magento\Framework\Api\ExtensionAttributesFactory;
use \Magento\Framework\Api\AttributeValueFactory;
use \Magento\Payment\Helper\Data as DataHelper;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Payment\Model\Method\Logger;
use \Magento\Framework\ObjectManagerInterface;
use \Magento\Framework\UrlInterface;
use \Magento\Store\Model\StoreManagerInterface;
use \Fasterpay\Fasterpay\Helper\Config as FPConfigHelper;
use \Fasterpay\Fasterpay\Helper\Helper as FPHelper;
use \Magento\Framework\Message\ManagerInterface;
use \Magento\Framework\App\Request\Http as HttpRequest;
use \Magento\Framework\Model\ResourceModel\AbstractResource;
use \Magento\Framework\Data\Collection\AbstractDb;
use \Magento\Payment\Model\Method\AbstractMethod;
use \FasterPay\Services\Signature as FPSignature;
use \FasterPay\Gateway as FPGateway;
use \Magento\Framework\DataObject;
use \Magento\Framework\App\Area as AppArea;
use \Magento\Framework\Exception\LocalizedException;
use \Magento\Payment\Model\InfoInterface;

/**
 * Class Fasterpay
 *
 * @method \Magento\Quote\Api\Data\PaymentMethodExtensionInterface getExtensionAttributes()
 */
class Fasterpay extends AbstractMethod
{
    const PAYMENT_METHOD_CODE = 'fasterpay';
    const MODULE_SOURCE = 'magento2';
    const DUPLICATE_REFERENCE_ID_STATE = 23000;
    const REFUND_PENDING_MESSAGE = 'Your refund transaction is being processed.';

    protected $_code = self::PAYMENT_METHOD_CODE;
    protected $objectManager;
    protected $urlBuilder;
    protected $helper;
    protected $url;
    protected $messageManager;
    protected $request;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    public function __construct(
        ModelContext $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        DataHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ObjectManagerInterface $objectManager,
        UrlInterface $urlBuilder,
        StoreManagerInterface $storeManager,
        FPConfigHelper $helperConfig,
        FPHelper $helper,
        ManagerInterface $messageManager,
        HttpRequest $request,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
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
        $this->messageManager = $messageManager;
        $this->request = $request;
    }

    public function generateForm(Order $order)
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
                'sign_version' => FPSignature::SIGN_VERSION_2,
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
            $this->gateway = new FPGateway([
                'publicKey' => $this->helperConfig->getConfig('public_key'),
                'privateKey' => $this->helperConfig->getConfig('private_key'),
                'isTest' => $this->helperConfig->getConfig('is_test'),
                'apiBaseUrl' => 'http://develop.pay2.fasterpay.bamboo.stuffio.com'
            ]);
        }

        return $this->gateway;
    }

    public function refund(InfoInterface $payment, $amount)
    {
        parent::refund($payment, $amount);

        $captureTxnId = $this->_getParentTransactionId($payment);
        if ($captureTxnId) {
            $order = $payment->getOrder();
            $canRefundMore = $payment->getCreditmemo()->getInvoice()->canRefund();

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
            throw new LocalizedException(
                __(self::REFUND_PENDING_MESSAGE)
            );
        } else {
            throw new LocalizedException(
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
            throw new LocalizedException(
                __($e->getMessage())
            );
        }

        if (!$refundResponse->isSuccessful()) {
            throw new LocalizedException(
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

    protected function _getParentTransactionId(DataObject $payment)
    {
        return $payment->getParentTransactionId();
    }

    protected function _isCalledFromPingback()
    {
        $areaCode = $this->_appState->getAreaCode();

        if ($areaCode == AppArea::AREA_FRONTEND) {
            return true;
        }

        return false;
    }
}
