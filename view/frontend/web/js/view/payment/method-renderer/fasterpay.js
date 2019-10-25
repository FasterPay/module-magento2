define(
    [
        'Magento_Checkout/js/view/payment/default',
        'Fasterpay_Fasterpay/js/action/redirect-to-widget',
        'Magento_Checkout/js/model/quote',
    ],
    function (Component, redirectToWidget, quote) {
        'use strict';

        return Component.extend({
            redirectAfterPlaceOrder: false,
            defaults: {
                template: 'Fasterpay_Fasterpay/payment/form',
                transactionResult: ''
            },
            getPaymentLogoUrl: function () {
                return window.checkoutConfig.payment.fasterpay.logoUrl;
            },
            initObservable: function () {

                this._super()
                    .observe([
                        'transactionResult'
                    ]);
                return this;
            },

            getCode: function () {
                return 'fasterpay';
            },

            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        
                    }
                };
            },


            beforePlaceOrder: function (data) {
                if (quote.billingAddress() === null && typeof data.details.billingAddress !== 'undefined') {
                    this.setBillingAddress(data.details, data.details.billingAddress);
                }

            },

            afterPlaceOrder: function () {
                redirectToWidget.execute();
            },

            setBillingAddress: function (customer, address) {
                var billingAddress = {
                    street: [address.streetAddress],
                    city: address.locality,
                    postcode: address.postalCode,
                    countryId: address.countryCodeAlpha2,
                    email: customer.email,
                    firstname: customer.firstName,
                    lastname: customer.lastName,
                    telephone: customer.phone
                };

                billingAddress['region_code'] = address.region;
                billingAddress = createBillingAddress(billingAddress);
                quote.billingAddress(billingAddress);
            },
        });
    }
);