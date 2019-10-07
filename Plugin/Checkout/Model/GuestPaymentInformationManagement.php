<?php
namespace Fasterpay\Fasterpay\Plugin\Checkout\Model;

use \Magento\Framework\Exception\CouldNotSaveException;
use \Magento\Framework\App\ProductMetadataInterface;
use \Magento\Checkout\Model\GuestPaymentInformationManagement as GuestPaymentInformationManagementModel;

class GuestPaymentInformationManagement
{
    protected $prdMetadata;

    public function __construct(
        ProductMetadataInterface $prdMetadata
    ) {
        $this->prdMetadata = $prdMetadata;
    }

    public function aroundSavePaymentInformationAndPlaceOrder(
        GuestPaymentInformationManagementModel $subject,
        callable $proceed,
        ...$args
    ) {
        try {
            $result = $proceed(...$args);
            return $result;
        } catch (CouldNotSaveException $e) {
            $version = $this->prdMetadata->getVersion();
            if (version_compare($version, '2.1', '>')) {
                throw new CouldNotSaveException(
                    __($e->getPrevious()->getMessage()),
                    $e->getPrevious()
                );
            } else {
                throw new CouldNotSaveException(
                    __($e->getMessage()),
                    $e
                );
            }
        }
    }
}
