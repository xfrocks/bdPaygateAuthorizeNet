!function ($, window, document, _undefined) {
    "use strict";

    XF.Xfrocks_AuthorizeNetArb_PayForm = XF.Element.newHandler({

        options: {
            apiLoginId: null,
            hiddenInputSelector: '[name=opaque_data]',
            phrasePreparing: null,
            phraseProcessing: null,
            progressTextSelector: null,
            publicClientKey: null
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

            this.$progressText = this.$target.find(this.options.progressTextSelector);

            this.$target.bind({
                'ajax-submit:before': $.proxy(this, 'onBeforeAjaxSubmit'),
                'ajax-submit:always': $.proxy(this, 'onBeforeAjaxAlways'),
                'submit': $.proxy(this, 'onSubmit')
            });
        },

        onBeforeAjaxSubmit: function (e, config) {
            // increase AJAX timeout to account for slow api requests
            config.ajaxOptions.timeout = 90000;

            if (this.$hiddenInput.val()) {
                this.$progressText.text(this.options.phraseProcessing);

                return true;
            }

            e.preventDefault();
        },

        onBeforeAjaxAlways: function () {
            // reset Accept.js data to avoid "Invalid OTS Token." error
            // assuming server always consumes the token upon submission
            this.$hiddenInput.val('');

            this.$progressText.text('');
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
                
                    // Try to find an input control first
                    var val = that.$target.find('input[rel=' + rel + ']').val();
                
                    // If no input control is found or its value is empty, try to find a select control
                    if (!val) {
                        val = that.$target.find('select[rel=' + rel + '] option:selected').val();
                    }
                
                    // If no value is found, return
                    if (!val) {
                        return;
                    }
                
                    obj[key] = val;
                };                

            fill(cardData, 'cardNumber', 'card-number');
            fill(cardData, 'month');
            fill(cardData, 'year');
            fill(cardData, 'cardCode', 'card-code');
            secureData.cardData = cardData;

            authData.apiLoginID = this.options.apiLoginId;
            authData.clientKey = this.options.publicClientKey;
            secureData.authData = authData;

            this._callAjaxSubmit('disableButtons');
            this.$progressText.text(this.options.phrasePreparing);

            // noinspection JSUnresolvedFunction
            Accept.dispatchData(secureData, $.proxy(this, 'onAcceptJsResponse'));

            return false;
        },

        onAcceptJsResponse: function (response) {
            this.$progressText.text('');
            this._callAjaxSubmit('enableButtons');

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

            var that = this;
            window.setTimeout(function () {
                that._callAjaxSubmit('submit');
            }, 0);
        },

        /**
         * @param method string
         * @param args array
         * @private
         *
         * @see XF.AjaxSubmit
         */
        _callAjaxSubmit: function (method, args) {
            var handlers = this.$target.data('xf-element-handlers');
            if (handlers['ajax-submit'] === _undefined) {
                return;
            }

            return handlers['ajax-submit'][method].apply(handlers['ajax-submit'], args);
        }
    });

    XF.Element.register('authorizenet-payment-form', 'XF.Xfrocks_AuthorizeNetArb_PayForm');
}(jQuery, window, document);