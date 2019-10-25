define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'fasterpay',
                component: 'Fasterpay_Fasterpay/js/view/payment/method-renderer/fasterpay'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
