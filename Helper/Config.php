<?php
namespace Fasterpay\Fasterpay\Helper;

use \Magento\Framework\App\Config\ScopeConfigInterface;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $config;

    public function __construct(
        ScopeConfigInterface $config
    ) {
        $this->config = $config;
    }

    public function getConfig($name, $type = 'fasterpay')
    {
        return $this->config->getValue("payment/{$type}/{$name}");
    }
}
