/**
 * Injects and loads the PayPal Express button in the correct DOMNode depending on context
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/ExpressBtnLoader', [

    'qui/controls/Control',
    'package/quiqqer/payment-paypal/bin/controls/ExpressBtn'

], function (QUIControl, ExpressBtn) {
    "use strict";

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/ExpressBtnLoader',

        Binds: [
            '$onInject'
        ],

        options: {
            context   : false,
            baskethash: false
        },

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onInject: this.$onInject
            });
        },

        /**
         * Event: onImport
         */
        $onInject: function () {
            var Elm    = this.getElm();
            var BtnElm = false;

            switch (this.getAttribute('context')) {
                case 'basket':
                    var OrderProcessElm = Elm.getParent(
                        '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]'
                    );

                    if (OrderProcessElm) {
                        BtnElm = OrderProcessElm.getElement('.quiqqer-order-ordering-buttons');
                    }
                    break;

                case 'minibasket':
                    // @todo
                    break;
            }

            if (BtnElm) {
                new ExpressBtn({
                    baskethash: this.getAttribute('baskethash')
                }).inject(BtnElm);
            }
        }
    });
});