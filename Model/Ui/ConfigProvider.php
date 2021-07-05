<?php
namespace Fasterpay\Fasterpay\Model\Ui;

use \Magento\Checkout\Model\ConfigProviderInterface;
use \Magento\Framework\ObjectManagerInterface;

/**
 * Class ConfigProvider
 */
final class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'fasterpay';

    protected $objectManager;

    public function __construct(
        ObjectManagerInterface $objectManager
    ){
        $this->objectManager = $objectManager;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'defaultWidgetPageUrl' => 'fasterpay/gateway/fasterpay',
                    'logoUrl' => 'https://pay.fasterpay.com/images/logo/v1/fp-logo-dark.png'
                ]
            ]
        ];
    }

    public function getViewFileUrl($fileId, array $params = [])
    {
        $assetRepository = $this->objectManager->create('\Magento\Framework\View\Asset\Repository');
        $request = $this->objectManager->create('\Magento\Framework\App\RequestInterface');
        $params = array_merge(['_secure' => $request->isSecure()], $params);
        return $assetRepository->getUrlWithParams($fileId, $params);
    }
}
