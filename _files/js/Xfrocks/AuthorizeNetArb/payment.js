!function (window, _undefined) {
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

            this.hiddenInput = this.target.querySelector(this.options.hiddenInputSelector);
            if (!this.hiddenInput) {
                console.error('Form must contain a data-hidden-input-selector attribute.');
                return;
            }

            this.progressText = this.target.querySelector(this.options.progressTextSelector);

            XF.on(this.target, 'ajax-submit:before', this.onBeforeAjaxSubmit.bind(this));
            XF.on(this.target, 'ajax-submit:always', this.onBeforeAjaxAlways.bind(this));
            XF.on(this.target, 'submit', this.onSubmit.bind(this));
        },

        onBeforeAjaxSubmit: function (e) {
            // increase AJAX timeout to account for slow api requests
            e.ajaxOptions.timeout = 90000;

            if (this.hiddenInput.value) {
                this.progressText.textContent = this.options.phraseProcessing;

                return true;
            }

            e.preventDefault();
        },

        onBeforeAjaxAlways: function () {
            // reset Accept.js data to avoid "Invalid OTS Token." error
            // assuming server always consumes the token upon submission
            this.hiddenInput.value = '';

            this.progressText.textContent = '';
        },

        onSubmit: function (e) {
            if (this.hiddenInput.value) {
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

                    var input = that.target.querySelector('input[rel=' + rel + ']');
                    var val = input ? input.value : _undefined;
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
            this.progressText.textContent = this.options.phrasePreparing;

            // noinspection JSUnresolvedFunction
            Accept.dispatchData(secureData, this.onAcceptJsResponse.bind(this));

            return false;
        },

        onAcceptJsResponse: function (response) {
            this.progressText.textContent = '';
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
            this.hiddenInput.value = JSON.stringify(response.opaqueData);

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
            var handler = XF.Element.getHandler(this.target, 'ajax-submit');
            if (!handler) {
                return;
            }

            return handler[method].apply(handler, args);
        }
    });

    XF.Element.register('authorizenet-payment-form', 'XF.Xfrocks_AuthorizeNetArb_PayForm');
}(window);