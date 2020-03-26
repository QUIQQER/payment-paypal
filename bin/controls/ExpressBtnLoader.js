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
            orderhash      : false,
            orderprocessurl: false,
            checkout       : false,
            display        : '',
            sandbox        : true
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
            var context = this.getAttribute('context');

            if (context === 'basket') {
                new ExpressBtn({
                    context        : context,
                    basketid       : this.getAttribute('basketid'),
                    orderhash      : this.getAttribute('orderhash'),
                    orderprocessurl: this.getAttribute('orderprocessurl'),
                    checkout       : this.getAttribute('checkout'),
                    displaysize    : this.getAttribute('displaysize'),
                    displaycolor   : this.getAttribute('displaycolor'),
                    displayshape   : this.getAttribute('displayshape'),
                    sandbox        : this.getAttribute('sandbox')
                }).inject(Elm, 'after');
            }

            if (context === 'smallbasket') {
                var MiniBasketElm = Elm.getParent('.quiqqer-order-basket-small-container');

                if (!MiniBasketElm) {
                    return;
                }

                var MiniBasketBtnElm = MiniBasketElm.getElement('.quiqqer-order-basket-small-buttons');

                if (!MiniBasketBtnElm) {
                    return;
                }

                new ExpressBtn({
                    context        : context,
                    basketid       : this.getAttribute('basketid'),
                    orderprocessurl: this.getAttribute('orderprocessurl'),
                    checkout       : this.getAttribute('checkout'),
                    displaysize    : this.getAttribute('displaysize'),
                    displaycolor   : this.getAttribute('displaycolor'),
                    displayshape   : this.getAttribute('displayshape')
                }).inject(MiniBasketBtnElm, 'after');
            }

            this.destroy();
        }
    });
});