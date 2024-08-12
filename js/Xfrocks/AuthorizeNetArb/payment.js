((window, document) =>
{
    'use strict'
    
    XF.AuthorizeNetArb_PayForm = XF.Element.newHandler({

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


            document.addEventListener('ajax-submit:before', this.onBeforeAjaxSubmit.bind(this));
            document.addEventListener('ajax-submit:always', this.onBeforeAjaxAlways.bind(this));
            document.addEventListener('submit', this.onSubmit.bind(this));
        },

        onBeforeAjaxSubmit: function (e, config) {
            // increase AJAX timeout to account for slow api requests
            config.ajaxOptions.timeout = 90000;

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
            e.preventSubmit = true;

            var secureData = {},
                authData = {},
                cardData = {},
                that = this,
                fill = function (obj, key, rel) {
                    if (typeof rel === 'undefined') {
                        rel = key;
                    }

                    // Try to find an input control first
                    var inputElement = that.target.querySelector('input[rel="' + rel + '"]');
                    var val = inputElement ? inputElement.value : null;

                    // If no input control is found or its value is empty, try to find a select control
                    if (!val) {
                        const dropdown = that.target.querySelector('select[rel="' + rel +'"]');
                        val = dropdown ? dropdown.value : null;
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
            this.progressText.textContent = this.options.phrasePreparing;

            Accept.dispatchData(secureData, this.onAcceptJsResponse.bind(this));

            return false;
        },

        onAcceptJsResponse: function (response) {
            this.progressText.textContent = '';
            this._callAjaxSubmit('enableButtons');

            if (response.messages && response.messages.resultCode === "Error") {
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
        
            this.hiddenInput.value = JSON.stringify(response.opaqueData);
        
            var that = this;
            window.setTimeout(function () {
                that._callAjaxSubmit('submit');
            }, 0);
        },
        
        _callAjaxSubmit: function (method, args) {
            var handlers = this.target.dataset.xfElementHandlers ? JSON.parse(this.target.dataset.xfElementHandlers) : {};
        
            if (typeof handlers['ajax-submit'] === 'undefined') {
                return;
            }
        
            return handlers['ajax-submit'][method].apply(handlers['ajax-submit'], args);
        }        

    });

    XF.Element.register('authorizenet-payment-form', 'XF.AuthorizeNetArb_PayForm');
})(window, document);
