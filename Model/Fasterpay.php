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
use \Magento\Framework\UrlInterface;
use \Fasterpay\Fasterpay\Helper\Config as FPConfigHelper;
use \Magento\Framework\Message\ManagerInterface;
use \Magento\Framework\Model\ResourceModel\AbstractResource;
use \Magento\Framework\Data\Collection\AbstractDb;
use \Magento\Payment\Model\Method\AbstractMethod;
use \FasterPay\Services\Signature as FPSignature;
use \FasterPay\Gateway as FPGateway;
use \Magento\Framework\DataObject;
use \Magento\Framework\Exception\LocalizedException;
use \Magento\Payment\Model\InfoInterface;
use \Magento\Framework\App\RequestInterface;
use \Magento\Framework\HTTP\ClientInterface;
use \Magento\Sales\Model\Order\Payment\Transaction\Repository;
use \Magento\Framework\Api\SearchCriteriaBuilder;
use \Magento\Framework\Api\FilterBuilder;
use \Magento\Framework\ObjectManagerInterface;

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
    const DELIVERY_STATUS_DELIVERING = 'order_shipped';
    const DELIVERY_STATUS_ORDER_PLACED = 'order_placed';
    const DELIVERY_STATUS_DELIVERED = 'delivered';
    const DELIVERY_PRODUCT_TYPE_PHYSICAL = 'physical';
    const DELIVERY_PRODUCT_TYPE_DIGITAL = 'digital';

    protected $_code = self::PAYMENT_METHOD_CODE;
    protected $objectManager;
    protected $urlBuilder;
    protected $helper;
    protected $url;
    protected $messageManager;
    protected $request;
    protected $filterBuilder;
    protected $searchCriteriaBuilder;
    protected $transactionRepository;
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
        UrlInterface $urlBuilder,
        FPConfigHelper $helperConfig,
        ManagerInterface $messageManager,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        SearchCriteriaBuilder  $searchCriteriaBuilder,
        Repository $transactionRepository,
        ObjectManagerInterface $objectManager,
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
        $this->request = $request;
        $this->urlBuilder = $urlBuilder;
        $this->helperConfig = $helperConfig;
        $this->messageManager = $messageManager;
        $this->filterBuilder = $filterBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->transactionRepository = $transactionRepository;
        $this->objectManager = $objectManager;
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
                'isTest' => $this->helperConfig->getConfig('test_mode')
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

            $this->_createRefund($captureTxnId, $amount);

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

    public function sendDeliveryInformation(Order $order, $status)
    {
        $params = $this->prepareDeliveryData($order, $status);
        $this->logger->debug($params, null, true);
        $client = $this->objectManager->get(ClientInterface::class);
        $client->post($this->getGateway()->getConfig()->getApiBaseUrl() . '/api/v1/deliveries', $params);
        $this->logger->debug([$client->getBody()], null, true);
        return $client->getBody();
    }

    protected function prepareDeliveryData(Order $order, $status)
    {
        if (!$order->getIsVirtual()) {
            $shipmentCreatedAt = $order->getCreatedAt();
            $shippingData = $order->getShippingAddress()->getData();

            if ($order->hasShipments()) {
                $shipmentsCollection = $order->getShipmentsCollection();
                $shipments = $shipmentsCollection->getItems();
                $shipment = array_shift($shipments);
                $shipmentCreatedAt = $shipment->getCreatedAt();
                $shippingData = $shipment->getShippingAddress()->getData();
                $tracksCollection = $shipment->getTracksCollection();
                $tracks = $tracksCollection->getItems();
                $track = array_shift($tracks);
                $carrierCode = empty($track) ? '' : $track->getCarrierCode();
                $trackNumber = empty($track) ? '' : $track->getTrackNumber();
            }

            $prodtype = self::DELIVERY_PRODUCT_TYPE_PHYSICAL;
        } else {
            $shipmentCreatedAt = $order->getCreatedAt();
            $shippingData = $order->getBillingAddress()->getData();
            $prodtype = self::DELIVERY_PRODUCT_TYPE_DIGITAL; // digital products don't have shipment
        }

        // not update delivery status if physical product, status is not order_placed and empty track number
        if ($prodtype == self::DELIVERY_PRODUCT_TYPE_PHYSICAL && $status != self::DELIVERY_STATUS_ORDER_PLACED && empty($trackNumber)) {
            return;
        }

        $filters[] = $this->filterBuilder->setField('payment_id')
            ->setValue($order->getPayment()->getId())
            ->create();

        $filters[] = $this->filterBuilder->setField('order_id')
            ->setValue($order->getId())
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder->addFilters($filters)
            ->create();

        $transactionList = $this->transactionRepository->getList($searchCriteria);
        $transactionItems = $transactionList->getItems();
        $fasterpayReferenceId = null;
        foreach ($transactionItems as $trans) {
            if ($trans->getTxnId()) {
                $fasterpayReferenceId = $trans->getTxnId();
                break;
            }
        }

        $params = [
            'payment_order_id' => $fasterpayReferenceId,
            'merchant_reference_id' => (string)$order->getIncrementId(),
            'type' => $prodtype,
            'status' => $status,
            'estimated_delivery_datetime' => date('Y-m-d H:i:s O', strtotime($shipmentCreatedAt)),
            'refundable' => true,
            'shipping_address' => [
                'country_code' => $shippingData['country_id'],
                'city' => $shippingData['city'],
                'zip' => $shippingData['postcode'],
                'state' => !empty($shippingData['region']) ? $shippingData['region'] : 'N/A',
                'street' => !empty($shippingData['street']) ? $shippingData['street'] : 'N/A',
                'phone' => $shippingData['telephone'],
                'first_name' => $shippingData['firstname'],
                'last_name' => $shippingData['lastname'],
                'email' => $shippingData['email'],
            ],
            'carrier_type' => empty($carrierCode) ? 'N/A' : $carrierCode,
            'carrier_tracking_id' => empty($trackNumber) ? 'N/A' : $trackNumber,
            'reason' => 'none',
            'attachments' => ['N/A'],
            'public_key' => $this->helperConfig->getConfig('public_key'),
            'sign_version' => FPSignature::SIGN_VERSION_2,
            'details' => 'Magento 2 delivery action'
        ];
        $params['hash'] = $this->getGateway()->signature()->calculateWidgetSignature($params);

        return $params;
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
        return $this->request->getRouteName() == 'fasterpay'
            && $this->request->getModuleName() == 'fasterpay'
            && $this->request->getControllerName() == 'index'
            && $this->request->getActionName() == 'pingback';
    }
}
