<?php

namespace Fasterpay\Fasterpay\Plugin\Framework\App\Request;

use \Magento\Framework\App\Request\CsrfValidator;
use \Magento\Framework\App\RequestInterface;
use \Magento\Framework\App\ActionInterface;

class CsrfValidatorSkipPlugin
{
    /**
     * @param \Magento\Framework\App\Request\CsrfValidator $subject
     * @param \Closure $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ActionInterface $action
     */
    public function aroundValidate(
        CsrfValidator $subject,
        \Closure $proceed,
        RequestInterface $request,
        ActionInterface $action
    ) {
        if ($request->getModuleName() == 'fasterpay') {
            return; // Skip CSRF check for issue break post request in magento 2.3
        }
        $proceed($request, $action); // Proceed Magento 2 core functionalities
    }
}