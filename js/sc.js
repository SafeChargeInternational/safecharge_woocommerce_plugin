var isAjaxCalled = false;
var manualChangedCountry = false;
var tokAPMs = ['cc_card', 'paydotcom'];
var tokAPMsFields = {
    cardNumber: 'ccCardNumber'
    ,expirationMonth: 'ccExpMonth'
    ,expirationYear: 'ccExpYear'
    ,cardHolderName: 'ccNameOnCard'
    ,CVV: ''
};
var selectedPM = '';
var billing_country_first_val = '';
var scSettleBtn = null;

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
function scValidateAPMFields() {
    jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
    jQuery('#custom_loader').show();
    
    if(jQuery('input[name="payment_method"]:checked').val() != 'sc') {
        jQuery('form.woocommerce-checkout').submit();
        return; // just in case the submiting is too slow
    }
    
    var formValid = true;
    
    if(jQuery('li.apm_container').length > 0) {
        formValid = false;
        
        jQuery('li.apm_container').each(function(){
            var self = jQuery(this);
            var radioBtn = self.find('input.sc_payment_method_field');
            
            if(radioBtn.is(':checked')) {
                formValid = true;
                var apmField = self.find('.apm_field input');
                selectedPM = radioBtn.val();
                
                if (
                    typeof apmField.attr('pattern') != 'undefined'
                    && apmField.attr('pattern') !== false
                    && apmField.attr('pattern') != ''
                ) {
                    var regex = new RegExp(apmField.attr('pattern'), "i");
                    
                    // SHOW error
                    if(regex.test(apmField.val()) == false || apmField.val() == '') {
                        apmField.parent('.apm_field').find('.apm_error')
                            .removeClass('error_info')
                            .show();
                    
                        formValid = false;
                        jQuery('#custom_loader').remove();
                    }
                    // HIDE error
                    else {
                        apmField.parent('.apm_field').find('.error').hide();
                    }
                }
            }
        });
    }
    
    if(formValid) {
        // check if method needed tokenization
        if(tokAPMs.indexOf(selectedPM) > -1) {
            var payload = {
                merchantSiteId: '',
                // we set environment only if its test
                sessionToken:   '',
                billingAddress: {
                    city:       jQuery('#billing_city').val(),
                    country:    jQuery("#billing_country").val(),
                    zip:        jQuery("#billing_postcode").val(),
                    email:      jQuery("#billing_email").val(),
                    firstName:  jQuery("#billing_first_name").val(),
                    lastName:   jQuery("#billing_last_name").val(),
                }, 
                cardData: {
                    cardNumber:         jQuery('#' + selectedPM + '_' + tokAPMsFields.cardNumber).val(),
                    cardHolderName:     jQuery('#' + selectedPM + '_' + tokAPMsFields.cardHolderName).val(),
                    expirationMonth:    jQuery('#' + selectedPM + '_' + tokAPMsFields.expirationMonth).val(),
                    expirationYear:     jQuery('#' + selectedPM + '_' + tokAPMsFields.expirationYear).val(),
                    CVV:                null
                }
            };
            
            // call rest api to get first 3 parameters of payload
            jQuery.ajax({
                type: "POST",
                url: myAjax.ajaxurl,
                data: { needST: 1 },
                dataType: 'json'
            })
                .done(function(resp){
                    if(resp.status == 1 && typeof resp.data != 'undefined') {
                        payload.merchantSiteId = resp.data.merchantId;
                        payload.sessionToken = resp.data.sessionToken;
                        
                        if(resp.data.test == 'yes') {
                            payload.environment = 'test';
                        }
                        
                        // get tokenization card number
                        if(typeof Safecharge != 'undefined') {
                            Safecharge.card.createToken(payload, safechargeResultHandler);
                        }
                    }
                });
        }
        // or just submit the form
        else {
        //    jQuery('form.woocommerce-checkout').submit();
        }
    }
    else {
        jQuery('form.woocommerce-checkout').prepend(
            '<ul class="woocommerce-error" role="alert">'
                +'<li><strong>Please, choose payment method, and fill required fields!</strong></li>'
            +'</ul>'
        );

        window.location.hash = '#main';
    }
 }
 
/**
  * Function safechargeResultHandler
  * This function is just a handler for createToken method.
  * It just replaces the card number with a token.
  * 
  * @param {object} resp
  */
function safechargeResultHandler(resp) {
    if(resp.status == 'ERROR') {
        jQuery('#custom_loader').hide();
        
        var msg = 'Error when try to proceed the payment. Please, check you fields!';
        if(typeof resp.reason != 'undefined' && resp.reason != '') {
            msg = resp.reason;
        }
                 
        jQuery('form.woocommerce-checkout').prepend(
            '<ul class="woocommerce-error" role="alert">'
                +'<li><strong>'+msg+'</strong></li>'
            +'</ul>'
        );

        window.location.hash = '#main';
    //    window.location = window.location.hash;
    }
    else if(resp.status == 'SUCCESS') {
        jQuery('#' + selectedPM + '_' + tokAPMsFields.cardNumber).val(resp.ccTempToken);
        jQuery('#custom_loader').hide();
        
        jQuery('form.woocommerce-checkout')
            .append('<input type="hidden" name="lst", value="'+resp.sessionToken+'" />')
            .submit();
    }
}
 
 /**
  * Function showErrorLikeInfo
  * Show error message as information about the field.
  * 
  * @param {int} elemId
  */
function showErrorLikeInfo(elemId) {
    jQuery('#error_'+elemId).addClass('error_info');

    if(jQuery('#error_'+elemId).css('display') == 'block') {
        jQuery('#error_'+elemId).hide();
    }
    else {
        jQuery('#error_'+elemId).show();
    }
 }
 
function getAPMs() {
    // do not get SC APMs if user do not use SC paygate
    if(jQuery('input[name="payment_method"]:checked').val() != 'sc') {
        return;
    }
    
    if(jQuery("#billing_country").val() != billing_country_first_val) {
        manualChangedCountry = true;
        billing_country_first_val = jQuery("#billing_country").val();
    }

    if(isAjaxCalled === false || manualChangedCountry === true) {
        isAjaxCalled = true;

        jQuery.ajax({
            type: "POST",
            url: myAjax.ajaxurl,
            data: { country: jQuery("#billing_country").val() },
            dataType: 'json'
        })
            .done(function(resp) {
                if(resp === null) {
                    return;
                }
        
                // if resp.status == 2 the user use Cashier
                if(
                    typeof resp != 'undefined'
                    && resp.status == 1
                    && typeof resp.data['paymentMethods'] != 'undefined'
                    && resp.data['paymentMethods'].length > 0
                ) {
                    var html = '';
                    var pMethods = resp.data['paymentMethods'];
                    
                    for(var i in pMethods) {
                        var pmMsg = '';
                        if(
                            pMethods[i]['paymentMethodDisplayName'].length > 0
                            && typeof pMethods[i]['paymentMethodDisplayName'][0].message != 'undefined'
                        ) {
                            pmMsg = pMethods[i]['paymentMethodDisplayName'][0].message;
                        }
                        // fix when there is no display name
                        else if(pMethods[i]['paymentMethod'] != '') {
                            pmMsg = pMethods[i]['paymentMethod'].replace('apmgw_', '');
                            pmMsg = pmMsg.replace(/_/g, ' ');
                        }

                        var newImg = pmMsg;
                        if(typeof pMethods[i]['logoURL'] != 'undefined') {
                            newImg = '<img src="'+ pMethods[i]['logoURL'].replace('/svg/', '/svg/solid-white/')
                                +'" alt="'+ pmMsg +'" />';
                        }

                        // for cc_card CVV field is mandtory, if miss, add it:
                        if(pMethods[i].paymentMethod == 'cc_card') {
                            var addCVVField = true;

                            for(var f in pMethods[i].fields) {
                                if(pMethods[i].fields[f].name.toLowerCase() == 'cvv') {
                                    addCVVField = false;
                                    break;
                                }
                            }

                            if(addCVVField) {
                                pMethods[i].fields.push({
                                    name: 'CVV'
                                    ,regex: '^[0-9]{3,4}$'
                                    ,type: 'text'
                                    ,validationmessage: [{
                                        message: 'CVV must be 3 or 4 digits!'
                                        ,language: 'en'
                                    }]
                                    ,caption: [{
                                        message: 'CVV Number'
                                        ,language: 'en'
                                    }]
                                });
                            }
                        }

                        html +=
                            '<li class="apm_container">'
                                +'<div class="apm_title">'
                                    +newImg
                                    +'<input id="sc_payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="payment_method_sc" value="'+ pMethods[i].paymentMethod +'" />'
                                    +'<span class=""></span>'
                                +'</div>'

                                +'<div class="apm_fields">';

                        // create fields for the APM
                        if(pMethods[i].fields.length > 0) {
                            for(var j in pMethods[i].fields) {
                                var pattern = '';
                                try {
                                    pattern = pMethods[i].fields[j].regex;
                                    if(pattern === undefined) {
                                        pattern = '';
                                    }
                                }
                                catch(e) {}

                                var placeholder = '';
                                try {
                                    if(typeof pMethods[i].fields[j].caption[0] == 'undefined') {
                                        placeholder = pMethods[i].fields[j].name;
                                        placeholder = placeholder.replace(/_/g, ' ');
                                    }
                                    else {
                                        placeholder = pMethods[i].fields[j].caption[0].message;
                                    }
                                }
                                catch(e) {
                                    placeholder = '';
                                }

                                var fieldErrorMsg = '';
                                try {
                                    fieldErrorMsg = pMethods[i].fields[j].validationmessage[0].message;
                                    if(fieldErrorMsg === undefined) {
                                        fieldErrorMsg = '';
                                    }
                                }
                                catch(e) {}

                                html +=
                                        '<div class="apm_field">'
                                            +'<input id="'+ pMethods[i].paymentMethod +'_'+ pMethods[i].fields[j].name +'" name="'+ pMethods[i].paymentMethod +'['+ pMethods[i].fields[j].name +']" type="'+ pMethods[i].fields[j].type +'" pattern="'+ pattern + '" placeholder="'+ placeholder +'" autocomplete="new-password" />';

                                if(pattern != '') {
                                    html +=
                                            '<span class="question_mark" onclick="showErrorLikeInfo(\'sc_'+ pMethods[i].fields[j].name +'\')"><span class="tooltip-icon"></span></span>'
                                            +'<div class="apm_error" id="error_sc_'+ pMethods[i].fields[j].name +'">'
                                                +'<label>'+fieldErrorMsg+'</label>'
                                            +'</div>';
                                }

                                html +=
                                        '</div>';
                            }
                        }

                        html +=
                                '</div>'
                            +'</li>';
                    }

                    print_apms_options(html)

                    // WP js trigger - wait until checkout is updated, but because it not always fired
                    // print the APMs one more time befor it
                    jQuery( document.body ).on( 'updated_checkout', function(){
                        console.log('updated_checkout')
                        print_apms_options(html)
                    });
                }
                // show some error
                else if(resp.status == 0) {
                    jQuery('form.woocommerce-checkout').prepend(
                        '<ul class="woocommerce-error" role="alert">'
                            +'<li><strong>Error when try to get APMs. Please, try again later!</strong></li>'
                        +'</ul>'
                    );
            
                    window.location.hash = '#main';
                }
            });
    }
}

/**
 * @param {string} html - html code for the options
 */
function print_apms_options(html) {
    // apend APMs holder
    if(jQuery('form.woocommerce-checkout').find('#sc_apms_list').length == 0) {
        jQuery('div.payment_method_sc').append('<ul id="sc_apms_list"></div>');
    }
    else {
        // remove old apms
        jQuery('#sc_apms_list').html('');
    }

    // insert the html
    jQuery('#sc_apms_list').append(html);

    // change submit button type and behavior
    jQuery('form.woocommerce-checkout button[type=submit]')
        .attr('type', 'button')
        .attr('onclick', 'scValidateAPMFields()');
}
 
// when the admin select to Settle or Void the Order
function settleAndCancelOrder(question, action, orderId) {
    if(confirm(question)) {
        jQuery('#custom_loader').show();
        
        var data = {};
        
        if(action == 'settle') {
            data.settleOrder = 1;
        }
        else if(action == 'void') {
            data.cancelOrder = 1;
        }
        
        jQuery.ajax({
            type: "POST",
            url: myAjax.ajaxurl,
            data: data,
            dataType: 'json'
        })
            .done(function(resp) {
                if(resp && typeof resp.status != 'undefined' && resp.data != 'undefined') {
                    if(resp.status == 1) {
                        jQuery('#custom_loader').hide();
                        alert('You will be redirected to Orders list.');

                        var urlParts = window.location.toString().split('post.php');
                        window.location = urlParts[0] + 'edit.php?post_type=shop_order';
                    }
                    else if(resp.data.reason != 'undefined') {
                        jQuery('#custom_loader').hide();
                        alert(resp.data.reason);
                    }
                }
                else {
                    alert('Response error.');
                }
            });
    }
 }
 
/**
 * Function deleteOldestLogs
 * Delete the oldest logs in Logs directory.
 */
function deleteOldestLogs() {
    if(confirm('Are you sure you wanto to delete log files?')) {
        jQuery('#custom_loader').show();
        jQuery('form#mainform #message').remove();

        jQuery.ajax({
            type: "POST",
            url: myAjax.ajaxurl,
            data: { deleteLogs: 1 },
            dataType: 'json'
        })
            .done(function(resp){
                if(resp.status == 1) {
                    jQuery('form#mainform h3').prepend(
                        '<div id="message" class="updated inline"><p><strong>Success.</strong></p></div>');
                }
                else {
                    jQuery('form#mainform h3').prepend(
                            '<div id="message" class="error inline"><p><strong>Error: '
                            + resp.msg +'</strong></p></div>'
                    );
                }

                window.location.hash = '#wpbody';
                window.location = window.location.hash;
                jQuery('#custom_loader').hide();
            })
    }
}

/**
 * Function enableDisableSCCheckout
 * Enable or disable SC Checkout
 * 
 * @param {string} action - disable|enable
 */
function enableDisableSCCheckout(action) {
    // add another loader
    if(jQuery('#custom_loader_2').length == 0) {
        jQuery('.wc_payment_methods ').append('<div style="width: 100%; height: 100%;position: absolute; top: 0px;opacity: 0.7; z-index: 3; background: white;"><div id="custom_loader_2" class="blockUI blockOverlay"></div></div>');
    }
    else {
        jQuery('#custom_loader_2').parent('div').show();
    }
    
    jQuery.ajax({
        type: "POST",
        url: myAjax.ajaxurl,
        data: { enableDisableSCCheckout: action },
        dataType: 'json'
    })
        .done(function(resp) {
            // go to DMN page to change order status
            if(resp && typeof resp.status != 'undefined' && resp.status == 1) {
                if(jQuery('#sc_apms_list').length == 0) {
                    getAPMs();
                }
            }
            else {
                alert('Error with the Response.');
            }
            
            jQuery('#custom_loader_2').parent('div').hide();
        });
}

/**
 * Function returnSCSettleBtn
 * Returns the SC Settle button
 */
function returnSCSettleBtn() {
    if(scSettleBtn !== null) {
        jQuery('.wc-order-bulk-actions p').append(scSettleBtn);
        scSettleBtn = null;
    }
}

jQuery(function() {
    // listener for the iFrane
    window.addEventListener('message', function(event) {
        if(window.location.origin === event.origin && event.data.scAction === 'scRedirect') {
            window.location.href = event.data.scUrl;
        }
    }, false);
    
    jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
    
    billing_country_first_val = jQuery("#billing_country").val();
    
    jQuery("#billing_country").on('change', function() {
        console.log('test');
        getAPMs();
    });
    
    if(jQuery('input[name="payment_method"]').length > 0) {
        enableDisableSCCheckout(jQuery('input[name="payment_method"]:checked').val() == 'sc' ? 'enable' : 'disable');
    }
    
    // when click on APM payment method
    jQuery('form.woocommerce-checkout').on('click', '.apm_title', function() {
        // hide all check marks 
        jQuery('#sc_apms_list').find('.apm_title span').removeClass('apm_selected');
        
        // hide all containers with fields
        jQuery('#sc_apms_list').find('.apm_fields').each(function(){
            var self = jQuery(this);
            if(self.css('display') == 'block') {
                self.slideToggle('slow');
            }
        });
        
        // mark current payment method
        jQuery(this).find('span').addClass('apm_selected');
        
        // hide bottom border of apm_fields if the container is empty
        if(jQuery(this).parent('li').find('.apm_fields').html() == '') {
            jQuery(this).parent('li').find('.apm_fields').css('border-bottom', 0);
        }
        // expand payment fields
        if(jQuery(this).parent('li').find('.apm_fields').css('display') == 'none') {
            jQuery(this).parent('li').find('.apm_fields').slideToggle('slow');
        }
        
        // unchck SC payment methods
        jQuery('form.woocommerce-checkout').find('sc_payment_method_field').attr('checked', false);
        // check current radio
        jQuery(this).find('input').attr('checked', true);
        
        // hide errors
        jQuery('.apm_error').hide();
    });
    
    // when we change selected paymenth method (the radio buttons)
    jQuery('form.woocommerce-checkout').on('change', 'input[name="payment_method"]', function(){
        enableDisableSCCheckout(jQuery(this).val() == 'sc' ? 'enable' : 'disable');
    });
    
    // in the settings when user change 'Cashier in IFrame' or 'Payment API' option
    jQuery('#woocommerce_sc_cashier_in_iframe').on('change', function(){
        jQuery('#woocommerce_sc_payment_api option').prop('selected', false);
        jQuery('#woocommerce_sc_payment_api option:first').prop('selected', true);
    });
    
    jQuery('#woocommerce_sc_payment_api').on('change', function() {
        if(jQuery(this).val() == 'rest') {
            jQuery('#woocommerce_sc_cashier_in_iframe').prop('checked', false);
            jQuery('#woocommerce_sc_cashier_in_iframe').val(0);
        }
    });
    // in the settings when user change 'Cashier in IFrame' or 'Payment API' option END
    
    jQuery('#i_frame').on('load', function(){
        jQuery('#sc_pay_msg').hide();
    });
    
    // set the flag
    if(jQuery('#sc_settle_btn').length == 1) {
       scSettleBtn = jQuery('#sc_settle_btn');
    }
});
// document ready function END
