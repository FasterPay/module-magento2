<?php

namespace Fasterpay\Fasterpay\Plugin\Sales\Order\Email\Container;

use Fasterpay\Fasterpay\Model\Fasterpay;
use \Magento\Sales\Model\Order\Email\Container\OrderIdentity;
use \Magento\Checkout\Model\Session as CheckoutSession;

class OrderIdentityPlugin
{
    /**
     * @var \Magento\Checkout\Model\Session $checkoutSession
     */
    protected $checkoutSession;

    /**
     * @param \Magento\Checkout\Model\Session $checkoutSession
     *
     * @codeCoverageIgnore
     */
    public function __construct(
        CheckoutSession $checkoutSession
    )
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param \Magento\Sales\Model\Order\Email\Container\OrderIdentity $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundIsEnabled(OrderIdentity $subject, callable $proceed)
    {
        if ($this->checkoutSession->getQuote()->getPayment()->getMethod() == Fasterpay::PAYMENT_METHOD_CODE) {
            $forceOrderMailSentOnSuccess = $this->checkoutSession->getForceOrderMailSentOnSuccess();

            if (isset($forceOrderMailSentOnSuccess) && $forceOrderMailSentOnSuccess) {
                $this->checkoutSession->unsForceOrderMailSentOnSuccess();
                return $proceed();
            }
            return false;
        }
        return $proceed();
    }
}
