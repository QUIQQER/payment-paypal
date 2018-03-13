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
            context        : false,
            basketid       : false,
            orderprocessurl: false,
            checkout       : false,
            display        : ''
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
            var Elm     = this.getElm();
            var BtnElm  = false;
            var context = this.getAttribute('context');

            switch (context) {
                case 'basket':
                    var OrderProcessElm = Elm.getParent(
                        '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]'
                    );

                    if (OrderProcessElm) {
                        BtnElm = OrderProcessElm.getElement('.quiqqer-order-ordering-buttons');
                    }
                    break;

                case 'smallbasket':
                    var MiniBasketElm = Elm.getParent('.quiqqer-order-basket-small-container');

                    if (!MiniBasketElm) {
                        break;
                    }

                    var MiniBasketBtnElm = MiniBasketElm.getElement('.quiqqer-order-basket-small-buttons');

                    if (MiniBasketBtnElm) {
                        BtnElm = MiniBasketBtnElm;
                    }
                    break;
            }

            if (BtnElm) {
                new ExpressBtn({
                    context        : context,
                    basketid       : this.getAttribute('basketid'),
                    orderprocessurl: this.getAttribute('orderprocessurl'),
                    checkout       : this.getAttribute('checkout'),
                    displaysize    : this.getAttribute('displaysize'),
                    displaycolor   : this.getAttribute('displaycolor'),
                    displayshape   : this.getAttribute('displayshape')
                }).inject(BtnElm);
            }

            this.destroy();
        }
    });
});