/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*global define*/
define(
    [
        'jquery',
        'underscore',
        'Magento_Ui/js/form/form',
        'ko',
        'Magento_Customer/js/model/customer',
        'Magento_Customer/js/model/address-list',
        'Magento_Checkout/js/model/address-converter',
        'Magento_Checkout/js/model/quote',
        'Cminds_Marketplace/js/model/shipping-methods',
        'Magento_Checkout/js/action/create-shipping-address',
        'Magento_Checkout/js/action/select-shipping-address',
        'Magento_Checkout/js/model/shipping-rates-validator',
        'Magento_Checkout/js/model/shipping-address/form-popup-state',
        'Magento_Checkout/js/model/shipping-service',
        'Magento_Checkout/js/action/select-shipping-method',
        'Magento_Checkout/js/model/shipping-rate-registry',
        'Magento_Checkout/js/action/set-shipping-information',
        'Magento_Checkout/js/model/step-navigator',
        'Magento_Ui/js/modal/modal',
        'Magento_Checkout/js/model/checkout-data-resolver',
        'Magento_Checkout/js/checkout-data',
        'uiRegistry',
        'mage/translate',
        'Magento_Catalog/js/price-utils',
        'Magento_Checkout/js/model/shipping-rate-service'
    ],
    function (
        $,
        _,
        Component,
        ko,
        customer,
        addressList,
        addressConverter,
        quote,
        shippingMethods,
        createShippingAddress,
        selectShippingAddress,
        shippingRatesValidator,
        formPopUpState,
        shippingService,
        selectShippingMethodAction,
        rateRegistry,
        setShippingInformationAction,
        stepNavigator,
        modal,
        checkoutDataResolver,
        checkoutData,
        registry,
        $t,
        priceUtils
    ) {
        'use strict';

        var popUp = null;
        var items  = quote.getItems();


        return Component.extend({
            defaults: {
                template: 'Cminds_Marketplace/shipping-methods'
            },
            visible: ko.observable(!quote.isVirtual()),
            errorValidationMessage: ko.observable(false),
            isCustomerLoggedIn: customer.isLoggedIn,
            isFormPopUpVisible: formPopUpState.isVisible,
            isFormInline: addressList().length == 0,
            isNewAddressAdded: ko.observable(false),
            saveInAddressBook: 1,
            quoteIsVirtual: quote.isVirtual(),
            vendorsProducts: shippingMethods.getViewModel(),
            shippingMethodsEnabled: window.checkoutConfig.shippingMethodsEnabled,
            nonSupplierShippingPrice: window.checkoutConfig.nonSupplierShippingPrice,
            canDisplaySupplierShippingMethods: window.checkoutConfig.shippingMethodsEnabled
            && window.checkoutConfig.shippingMethodsExist,
            currency: window.checkoutConfig.currency,

            /**
             * @return {exports}
             */
            initialize: function () {
                var countryId = '';
                $(document).on('change', "[name='country_id']", function () {
                    // restrict ajax calls in payment page for performances issue
                    var mainFieldset = $(this).closest("form");
                    if(!mainFieldset.is(":visible")) {
                        return;
                    }
                   var countryId = $('[name="country_id"] > option:selected').val();
                   if (countryId == '') {
                        var countryId = quote.shippingAddress().countryId;
                   }
                   shippingMethods.getPruductsByVendors(items,countryId);
                });

                $(document).on('click', ".action-select-shipping-item", function () {
                    var countryId = quote.shippingAddress().countryId;
                    shippingMethods.getPruductsByVendors(items,countryId);
                    $('[name="country_id"] > option:selected').val(countryId);
                });

                var self = this,
                    hasNewAddress,
                    fieldsetName = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset';

                this._super();
                shippingRatesValidator.initFields(fieldsetName);

                if (!quote.isVirtual()) {
                    stepNavigator.registerStep(
                        'shipping',
                        '',
                        $t('Shipping'),
                        this.visible, _.bind(this.navigate, this),
                        10
                    );
                }
                checkoutDataResolver.resolveShippingAddress();

                hasNewAddress = addressList.some(function (address) {
                    return address.getType() == 'new-customer-address';
                });

                this.isNewAddressAdded(hasNewAddress);

                this.isFormPopUpVisible.subscribe(function (value) {
                    if (value) {
                        self.getPopUp().openModal();
                    }
                });

                quote.shippingMethod.subscribe(function () {
                    self.errorValidationMessage(false);
                });

                registry.async('checkoutProvider')(function (checkoutProvider) {
                    var shippingAddressData = checkoutData.getShippingAddressFromData();

                    if (shippingAddressData) {
                        checkoutProvider.set(
                            'shippingAddress',
                            $.extend({}, checkoutProvider.get('shippingAddress'), shippingAddressData)
                        );
                    }
                    checkoutProvider.on('shippingAddress', function (shippingAddressData) {
                        checkoutData.setShippingAddressFromData(shippingAddressData);
                    });
                });

                this.shippingMethodsEnabled = this.shippingMethodsEnabled && this.visible;

                return this;
            },


            /**
             * Load data from server for shipping step
             */
            navigate: function () {
                //load data from server for shipping step
            },

            /**
             * @return {*}
             */
            getPopUp: function () {
                var self = this,
                    buttons;

                if (!popUp) {
                    buttons = this.popUpForm.options.buttons;
                    this.popUpForm.options.buttons = [
                        {
                            text: buttons.save.text ? buttons.save.text : $t('Save Address'),
                            class: buttons.save.class ? buttons.save.class : 'action primary action-save-address',
                            click: self.saveNewAddress.bind(self)
                        },
                        {
                            text: buttons.cancel.text ? buttons.cancel.text : $t('Cancel'),
                            class: buttons.cancel.class ? buttons.cancel.class : 'action secondary action-hide-popup',
                            click: function () {
                                this.closeModal();
                            }
                        }
                    ];
                    this.popUpForm.options.closed = function () {
                        self.isFormPopUpVisible(false);
                    };
                    popUp = modal(this.popUpForm.options, $(this.popUpForm.element));
                }

                return popUp;
            },

            /**
             * Show address form popup
             */
            showFormPopUp: function () {
                this.isFormPopUpVisible(true);
            },

            /**
             * Save new shipping address
             */
            saveNewAddress: function () {
                var addressData,
                    newShippingAddress;

                this.source.set('params.invalid', false);
                this.source.trigger('shippingAddress.data.validate');

                if (!this.source.get('params.invalid')) {
                    addressData = this.source.get('shippingAddress');
                    // if user clicked the checkbox, its value is true or false. Need to convert.
                    addressData.save_in_address_book = this.saveInAddressBook ? 1 : 0;

                    // New address must be selected as a shipping address
                    newShippingAddress = createShippingAddress(addressData);
                    selectShippingAddress(newShippingAddress);
                    checkoutData.setSelectedShippingAddress(newShippingAddress.getKey());
                    checkoutData.setNewCustomerShippingAddress(addressData);
                    this.getPopUp().closeModal();
                    this.isNewAddressAdded(true);
                }
            },

            /**
             * Shipping Method View
             */
            rates: shippingService.getShippingRates(),
            isLoading: shippingService.isLoading,
            isSelected: ko.computed(function () {
                    return quote.shippingMethod() ?
                        quote.shippingMethod().carrier_code + '_' + quote.shippingMethod().method_code
                        : null;
                }
            ),

            setShippingPrice: function() {
                $.ajax({
                    url: window.checkoutConfig.baseUrl+'/marketplace/checkout/setshippingprice',
                    type: 'POST',
                    data: {
                        price: this.price,
                        method_id: this.id,
                        supplier_id: this.supplier_id,
                        currency_price: this.currency_price
                    },
                    dataType: 'json',
                    success: function (data) {
                        var price = priceUtils.formatPrice(data.price_total.toFixed(2), window.checkoutConfig.priceFormat);

                        $('#s_method_supplier_supplier')
                            .parent()
                            .next('td')
                            .html('<span><span class="text">'+ price +'</span></span>');

                        $('#s_method_supplier')
                            .parent()
                            .next('td')
                            .html('<span><span class="text">'+ price +'</span></span>');
                    }
                });

                return true;
            },

            /**
             * @param {Object} shippingMethod
             * @return {Boolean}
             */
            selectShippingMethod: function (shippingMethod) {
                selectShippingMethodAction(shippingMethod);
                checkoutData.setSelectedShippingRate(shippingMethod.carrier_code + '_' + shippingMethod.method_code);
                return true;
            },

            /**
             * Set shipping information handler
             */
            setShippingInformation: function () {
                if (this.validateShippingInformation()) {
                    setShippingInformationAction().done(
                        function () {
                            stepNavigator.next();
                        }
                    );
                }
            },

            /**
             * @return {Boolean}
             */
            validateShippingInformation: function () {
                var shippingAddress,
                    addressData,
                    loginFormSelector = 'form[data-role=email-with-possible-login]',
                    emailValidationResult = customer.isLoggedIn();

                if (!quote.shippingMethod()) {
                    this.errorValidationMessage('Please specify a shipping method.');

                    return false;
                }

                if (!customer.isLoggedIn()) {
                    $(loginFormSelector).validation();
                    emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid());
                }

                if (this.isFormInline) {
                    this.source.set('params.invalid', false);
                    this.source.trigger('shippingAddress.data.validate');

                    if (this.source.get('shippingAddress.custom_attributes')) {
                        this.source.trigger('shippingAddress.custom_attributes.data.validate');
                    }

                    if (this.source.get('params.invalid') ||
                        !quote.shippingMethod().method_code ||
                        !quote.shippingMethod().carrier_code ||
                        !emailValidationResult
                    ) {
                        return false;
                    }

                    shippingAddress = quote.shippingAddress();
                    addressData = addressConverter.formAddressDataToQuoteAddress(
                        this.source.get('shippingAddress')
                    );

                    //Copy form data to quote shipping address object
                    for (var field in addressData) {
                        if (addressData.hasOwnProperty(field) &&
                            shippingAddress.hasOwnProperty(field) &&
                            typeof addressData[field] != 'function' &&
                            _.isEqual(shippingAddress[field], addressData[field])
                        ) {
                            shippingAddress[field] = addressData[field];
                        } else if (typeof addressData[field] != 'function' &&
                            !_.isEqual(shippingAddress[field], addressData[field])) {
                            shippingAddress = addressData;
                            break;
                        }
                    }

                    if (customer.isLoggedIn()) {
                        shippingAddress.save_in_address_book = 1;
                    }
                    selectShippingAddress(shippingAddress);
                }

                if (!emailValidationResult) {
                    $(loginFormSelector + ' input[name=username]').focus();

                    return false;
                }

                var error_supplier_method = false;

                if($('#s_method_supplier_supplier').is(':checked') || $('#s_method_supplier').is(':checked')) {
                    $('.supplier_methods').each(function(){
                        var name = $(this).attr('name');

                        if(!$('input[name='+name+']:checked').is(':checked')) {
                            error_supplier_method = true;
                        }

                    });

                }

                if(error_supplier_method) {
                    this.errorValidationMessage('Please specify shipping method for each supplier.');
                    return false;
                }

                return true;
            },
        });
    }
);
