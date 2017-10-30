!function ($, window, document, _undefined) {
    "use strict";

    XF.Xfrocks_AuthorizeNetArb_PayForm = XF.Element.newHandler({

        options: {
            apiLoginId: null,
            publicClientKey: null,

            hiddenInputSelector: '[name=opaque_data]'
        },

        xhr: null,

        init: function () {
            if (!this.options.apiLoginId) {
                console.error('Form must contain a data-api-login-id attribute.');
                return;
            }

            if (!this.options.publicClientKey) {
                console.error('Form must contain a data-public-client-key attribute.');
                return;
            }

            this.$hiddenInput = this.$target.find(this.options.hiddenInputSelector);
            if (this.$hiddenInput.length !== 1) {
                console.error('Form must contain a data-hidden-input-selector attribute.');
                return;
            }

            this.$target.bind({
                'ajax-submit:before': $.proxy(this, 'onBeforeAjaxSubmit'),
                'submit': $.proxy(this, 'onSubmit')
            });
        },

        onBeforeAjaxSubmit: function (e) {
            if (this.$hiddenInput.val()) {
                return true;
            }

            e.preventDefault();
        },

        onSubmit: function (e) {
            if (this.$hiddenInput.val()) {
                return true;
            }

            e.preventDefault();

            var secureData = {},
                authData = {},
                cardData = {},
                that = this,
                fill = function (obj, key, rel) {
                    if (rel === _undefined) {
                        rel = key;
                    }

                    var val = that.$target.find('input[rel=' + rel + ']').val();
                    if (!val) {
                        return;
                    }

                    obj[key] = val;
                };

            fill(cardData, 'cardNumber', 'card-number');
            fill(cardData, 'month');
            fill(cardData, 'year');
            fill(cardData, 'cardCode', 'card-code');
            fill(cardData, 'fullName', 'full-name');
            fill(cardData, 'zip');
            secureData.cardData = cardData;

            authData.apiLoginID = this.options.apiLoginId;
            authData.clientKey = this.options.publicClientKey;
            secureData.authData = authData;

            // noinspection JSUnresolvedFunction
            Accept.dispatchData(secureData, $.proxy(this, 'onAcceptJsResponse'));

            return false;
        },

        onAcceptJsResponse: function (response) {
            // noinspection JSUnresolvedVariable
            if (response.messages && response.messages.resultCode === "Error") {
                // noinspection JSUnresolvedVariable
                var messages = response.messages,
                    text = [];
                for (var i = 0; i < messages.message.length; i++) {
                    var message = messages.message[i];
                    console.error(message.code + ": " + message.text);
                    text.push(message.text);
                }

                XF.alert(text.join('<br />'));

                return;
            }

            // noinspection JSUnresolvedVariable
            this.$hiddenInput.val(JSON.stringify(response.opaqueData));

            var handlers = this.$target.data('xf-element-handlers');

            handlers['ajax-submit'].submit();
        }
    });

    XF.Element.register('authorizenet-payment-form', 'XF.Xfrocks_AuthorizeNetArb_PayForm');
}(jQuery, window, document);