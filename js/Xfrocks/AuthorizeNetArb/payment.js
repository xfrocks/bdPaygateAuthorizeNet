((window, document) =>
    {
        'use strict'
        
        XF.AuthorizeNetArb_PayForm = XF.Element.newHandler({
    
            options: {
                apiLoginId: null,
                hiddenInputSelector: '[name=opaque_data]',
                cardNotAcceptedSelector: null,
                acceptedCards: null,
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
                this.cardNotAcceptedText = this.target.querySelector(this.options.cardNotAcceptedSelector);

                if(this.options.acceptedCards.length > 0)
                    XF.on(this.target.querySelector('input[rel="card-number"]'), 'change', this.validateCardNumber.bind(this));
                XF.on(this.target, 'ajax-submit:before', this.onBeforeAjaxSubmit.bind(this));
                XF.on(this.target, 'ajax-submit:always', this.onBeforeAjaxAlways.bind(this));
                XF.on(this.target, 'submit', this.onSubmit.bind(this));
            },

            validateCardNumber: function(input) {
                const cardPatterns = {
                    'amex': /^3[47][0-9]{13}$/,
                    'diners_club': /^3(?:0[0-5]|[68][0-9])[0-9]{11}$/,
                    'discover': /^6(?:011|5[0-9]{2})[0-9]{12}$/,
                    'enroute': /^(?:2014|2149)\d{11}$/,
                    'jcb': /^(?:2131|1800|35\d{3})\d{11}$/,
                    'mastercard': /^5[1-5][0-9]{14}$/,
                    'visa': /^4[0-9]{12}(?:[0-9]{3})?$/
                };
           
                const cardType = Object.keys(cardPatterns).find(type => cardPatterns[type].test(input.target.value));
            
                if (!cardType || !this.options.acceptedCards.includes(cardType)) {
                    input.target.style.borderColor = 'red';
                    console.log(XF.phrases);
                    const friendlyCardType = {
                        'amex': XF.phrase('amex'),
                        'diners_club': XF.phrase('diners_club'),
                        'discover': XF.phrase('discover'),
                        'enroute': XF.phrase('enroute'),
                        'jcb': XF.phrase('jcb'),
                        'mastercard': XF.phrase('mastercard'),
                        'visa': XF.phrase('visa')
                    }[cardType] || XF.phrase('card_unsupported');
                    this.cardNotAcceptedText.textContent = XF.phrase('not_accepted', {'{friendlyCardType}':friendlyCardType});
                } else {
                    input.target.style.borderColor = '';
                    this.cardNotAcceptedText.textContent = '';
                }
            },
    
            onBeforeAjaxSubmit: function (e, config) {
                // increase AJAX timeout to account for slow api requests
                //config.ajaxOptions.timeout = 90000;
    
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
    
            onSubmit: function(e) {
                if (this.hiddenInput.value) {
                    return true;
                }
            
                e.preventDefault();
            
                const secureData = {};
                const authData = {};
                const cardData = {};
            
                const fill = (obj, key, rel = key) => {
                    const inputElement = this.target.querySelector(`input[rel="${rel}"]`);
                    let val = inputElement ? inputElement.value : null;
            
                    if (!val) {
                        const dropdown = this.target.querySelector(`select[rel="${rel}"]`);
                        val = dropdown ? dropdown.value : null;
                    }
            
                    if (val) {
                        obj[key] = val;
                    }
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
    
            onAcceptJsResponse: function(response) {
                this.progressText.textContent = '';
                this._callAjaxSubmit('enableButtons');
            
                if (response.messages?.resultCode === "Error") {
                    const { messages } = response;
                    const text = messages.message.map(message => {
                        console.error(`${message.code}: ${message.text}`);
                        return message.text;
                    });
            
                    XF.alert(text.join('\r\n'));
                    return;
                }
            
                this.hiddenInput.value = JSON.stringify(response.opaqueData);
            
                setTimeout(() => {
                    this._callAjaxSubmit('submit');
                }, 0);
            },        
            
            _callAjaxSubmit: function (method, args) {
                const handlers = this.target.dataset.xfElementHandlers ? JSON.parse(this.target.dataset.xfElementHandlers) : null;
                
                if (!handlers || !handlers['ajax-submit']) {
                    return;
                }
            
                const ajaxSubmit = handlers['ajax-submit'];
                return ajaxSubmit[method].apply(ajaxSubmit, args);
            }        
    
        });
    
        XF.Element.register('authorizenet-payment-form', 'XF.AuthorizeNetArb_PayForm');
    })(window, document);
    