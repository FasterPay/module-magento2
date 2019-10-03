<?php
namespace Fasterpay\Fasterpay\Helper;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $config;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $config
    ) {
        $this->config = $config;
    }

    public function getInitConfig()
    {
        \Fasterpay_Config::getInstance()->set([
            'api_type' => \Fasterpay_Config::API_GOODS,
            'public_key' => $this->config->getValue('payment/fasterpay/public_key'),
            'private_key' => $this->config->getValue('payment/fasterpay/private_key')
        ]);
    }

    public function getConfig($name, $type = 'fasterpay')
    {
        return $this->config->getValue("payment/{$type}/{$name}");
    }
}
