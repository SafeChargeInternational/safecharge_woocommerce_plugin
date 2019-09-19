'use strict';

var isAjaxCalled                = false;
var manualChangedCountry        = false;
var selectedPM                  = '';
var billing_country_first_val   = '';
var scSettleBtn                 = null;
var scVoidBtn                   = null;
// for the fields
var sfc                         = null;
var scFields                    = null;
var sfcFirstField               = null;
var scCard                      = null;
var cardNumber                  = null;
var cardExpiry                  = null;
var cardCvc                     = null;
var scData                      = {};
// set some classes for the Fields
var elementClasses = {
    focus: 'focus',
    empty: 'empty',
    invalid: 'invalid',
};
// styles for the fields
var fieldsStyle = {
    base: {
        fontSize: 15.5
        ,fontFamily: 'sans-serif'
        ,color: '#43454b'
        ,fontSmoothing: 'antialiased'
        ,'::placeholder': {
            color: '#52545A'
        }
    }
};
var scOrderAmount, scOrderCurr, scMerchantId, scMerchantSiteId, scOpenOrderToken;

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
function scValidateAPMFields() {
    if('sc' != jQuery('input[name="payment_method"]:checked').val()) {
        jQuery('form.woocommerce-checkout').submit();
        return;
    }
    
    jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
    jQuery('#custom_loader').show();
    
    var formValid = true;
    selectedPM = jQuery('input[name="sc_payment_method"]:checked').val();
    
    if(typeof selectedPM != 'undefined' && selectedPM != '') {
        // use cards
        if(selectedPM == 'cc_card' || selectedPM == 'dc_card') {
            if(
                typeof scOpenOrderToken == 'undefined'
                || typeof scOrderAmount == 'undefined'
                || typeof scOrderCurr == 'undefined'
                || typeof scMerchantId == 'undefined'
                || typeof scMerchantSiteId == 'undefined'
            ) {
                scFormFalse('Unexpected error, please try again later!');
                console.error('Missing SDK parameters.');
                return;
            }
    
            // create payment with WebSDK
            sfc.createPayment({
                sessionToken    : scOpenOrderToken,
                merchantId      : scMerchantId,
                merchantSiteId  : scMerchantSiteId,
                currency        : scOrderCurr,
                amount          : scOrderAmount,
                cardHolderName  : document.getElementById('sc_card_holder_name').value,
            //    paymentOption   : scCard
                paymentOption   : sfcFirstField
            }, function(resp){
                console.log(resp);

                if(typeof resp.result != 'undefined') {
                    console.log(resp.result)
                    
                    if(resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
                        jQuery('#sc_transaction_id').val(resp.transactionId);
                        jQuery('#lst').val(resp.transactionId);
                        
                    jQuery('form.woocommerce-checkout').submit();
                    }
                    else if(resp.result == 'DECLINED') {
                        scFormFalse('Your Payment was DECLINED. Please try another payment method!');
                    }
                    else {
                        if(resp.errorDescription != 'undefined' && resp.errorDescription != '') {
                            scFormFalse(resp.errorDescription);
                        }
                        else {
                            scFormFalse('Error with your Payment. Please try again later!');
                        }
                    }
                }
                else {
                    scFormFalse('Unexpected error, please try again later!');
                    console.error('Error with SDK response: ' + resp);
                    return;
                }
            });
        }
        // use APM data
        else if(isNaN(parseInt(selectedPM))) {
            var checkId = 'sc_payment_method_' + selectedPM;

            // iterate over payment fields
            jQuery('#' + checkId).closest('li.apm_container').find('.apm_fields input').each(function(){
                var apmField = jQuery(this);

                if (
                    typeof apmField.attr('pattern') != 'undefined'
                    && apmField.attr('pattern') !== false
                    && apmField.attr('pattern') != ''
                ) {
                    var regex = new RegExp(apmField.attr('pattern'), "i");

                    // SHOW error
                    if(apmField.val() == '' || regex.test(apmField.val()) == false) {
                        apmField.parent('.apm_field').find('.apm_error').show();

                        formValid = false;
                    }
                    else {
                        apmField.parent('.apm_field').find('.apm_error').hide();
                    }
                }
                else if(apmField.val() == '') {
                    formValid = false;
                }
            });

            if(!formValid) {
                scFormFalse();
                return;
            }

            jQuery('#custom_loader').hide();
            jQuery('form.woocommerce-checkout').submit();
        }
        // use UPO data
        else {
            if(
                jQuery('#upo_cvv_field_' + selectedPM).length > 0
                && jQuery('#upo_cvv_field_' + selectedPM).val() == ''
            ) {
                scFormFalse();
                return;
            }

            jQuery('#custom_loader').hide();
            jQuery('form.woocommerce-checkout').submit();
        }
    }
    else {
        scFormFalse();
        return;
    }
 }
 
 function scFormFalse(text) {
    // clear the error
    jQuery('.woocommerce-error').remove();
    
    if(typeof text == 'undefined') {
        text = "Please, choose payment method, and fill all fields!";
    }
    
    jQuery('form.woocommerce-checkout').prepend(
        '<div class="woocommerce-error" role="alert">'
            +'<strong>'+ text +'</strong>'
        +'</div>'
    );

    jQuery('#custom_loader').hide();
    window.location.hash = '#masthead';
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
        if('' == jQuery("#billing_email").val() || '' == jQuery("#billing_country").val()) {
            alert('Please fill all fields marked with * !');
            return;
        }
        
        isAjaxCalled = true;

        jQuery.ajax({
            type: "POST",
            url: scAjax.ajaxurl,
            data: {
                action      : 'sc-ajax-action',
                security    : scAjax.security,
                country     : jQuery("#billing_country").val(),
                amount      : scOrderAmount,
                userMail    : jQuery("#billing_email").val(),
            },
            dataType: 'json'
        })
            .fail(function(){
                alert('Error when try to get the Payment Methods. Please try again later or use different Payment Option!');
                return;
            })
            .done(function(resp) {
                if(resp === null) {
                    alert('Error when try to get the Payment Methods. Please try again later or use different Payment Option!');
                    return;
                }
        
                // if resp.status == 2 the user use Cashier
                if(
                    typeof resp != 'undefined'
                    && resp.status == 1
                    && typeof resp.data['paymentMethods'] != 'undefined'
                    && resp.data['paymentMethods'].length > 0
                ) {
                    try {
                        console.log(resp);
                        
                        scOpenOrderToken    = resp.sessionToken;
                        scOrderCurr         = resp.currency;
                        scMerchantId        = resp.merchantId;
                        scMerchantSiteId    = resp.merchantSiteId;
                        
                        scData.merchantSiteId   = resp.merchantSiteId;
                        scData.merchantId       = resp.merchantId;
                        scData.sessionToken     = resp.sessionToken;
                        
                        if(resp.testEnv == 'yes') {
                            scData.env = 'test';
                        }

                        sfc = SafeCharge(scData);

                        // prepare fields
                        scFields = sfc.fields({
                            locale: resp.langCode
                        });
                    }
                    catch (exception) {
                        alert('Mandatory data is missing, please try again later!');
                        console.error(exception);
                        return;
                    }

                    var html_upos = '';
                    var html_apms = '';
                    var upos = resp.data['upos'];
                    var pMethods = resp.data['paymentMethods'];
                    
                    for(var j in upos) {
                        html_upos +=
                            '<li class="apm_container">'
                                +'<div class="apm_title">';

                        try {
                            if(resp.data.icons[upos[j].upoData.brand]) {
                                html_upos += 
                                    '<img src="'+ resp.data.icons[upos[j].upoData.brand].replace('/svg/', '/svg/solid-white/') +'" />';

                                if(upos[j].upoData.ccCardNumber) {
                                    html_upos +=
                                    '<div>'+ upos[j].upoData.ccCardNumber +'</div>';
                                }
                            }
                            else if(resp.data.icons[upos[j].paymentMethodName]) {
                                html_upos += 
                                    '<img src="'+ resp.data.icons[upos[j].paymentMethodName].replace('/svg/', '/svg/solid-white/') +'" />';
                            }
                            else if(resp.data.icons[upos[j].paymentMethodName].search('apmgw_') > -1) {
                                html_upos += 
                                    '<img src="#" alt="'+ resp.data.icons[upos[j].paymentMethodName].replace('apmgw_', '') +'" />';
                            }
                        }
                        catch(exception) {}

                        html_upos +=             
                                    '<span class=""></span>'
                                    + '<input type="radio" class="sc_payment_method_field" name="sc_payment_method" value="'
                                        +upos[j].userPaymentOptionId +'" />'
                                +'</div>';
                        
                        try {
                            if(upos[j].paymentMethodName == 'cc_card' || upos[j].paymentMethodName == 'dc_card') {
                                html_upos +=
                                '<div class="apm_fields">'
                                    + '<div class="apm_field">'
                                        + '<input id="upo_cvv_field_'+ upos[j].userPaymentOptionId
                                            +'" class="upo_cvv_field" name="upo_cvv_field_'
                                            + upos[j].userPaymentOptionId
                                            +'" type="text" pattern="^[0-9]{3,4}$" placeholder="CVV Number">'
                                    + '</div>'
                                + '</div>';
                            }
                        }
                        catch(exception) {}
                        
                        html_upos +=             
                            '</li>';
                    }
                    
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
                        else {
                            newImg = '<img src="#" alt="'+ pmMsg +'" />';
                        }
                        
                        html_apms +=
                            '<li class="apm_container">'
                                +'<div class="apm_title">'
                                    +newImg
                                    +'<input id="sc_payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="'+ pMethods[i].paymentMethod +'" />'
                                    +'<span class=""></span>'
                                +'</div>';

                        if(pMethods[i].paymentMethod == 'cc_card' || pMethods[i].paymentMethod == 'dc_card') {
                            html_apms +=
                                '<div class="apm_fields" id="sc_'+ pMethods[i].paymentMethod +'">'
//                                    +'<div class="apm_field">'
//                                        + '<div id="card-field-placeholder"></div>'
//                                    +'</div>'
                            
                                    + '<div class="apm_field">'
                                        + '<input type="text" id="sc_card_holder_name" name="'+ pMethods[i].paymentMethod
                                            +'[cardHolderName]" placeholder="Card holder name" />'
                                    + '</div>'
                                    
                                    
                                    +'<div class="apm_field">'
                                        + '<div id="sc_card_number"></div>'
                                    +'</div>'
                            
                                     +'<div class="apm_field">'
                                        + '<div id="sc_card_expiry"></div>'
                                    +'</div>'
                                    
                                    +'<div class="apm_field">'
                                        + '<div id="sc_card_cvc"></div>'
                                    +'</div>';
                        }
                        else {
                            html_apms+=
                                '<div class="apm_fields">';
                        
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

                                    html_apms +=
                                            '<div class="apm_field">'
                                                +'<input id="'+ pMethods[i].paymentMethod +'_'+ pMethods[i].fields[j].name +'" name="'+ pMethods[i].paymentMethod +'['+ pMethods[i].fields[j].name +']" type="'+ pMethods[i].fields[j].type +'" pattern="'+ pattern + '" placeholder="'+ placeholder +'" autocomplete="new-password" />';

                                    if(pattern != '') {
                                        html_apms +=
                                                '<span class="question_mark" onclick="showErrorLikeInfo(\'sc_'+ pMethods[i].fields[j].name +'\')"><span class="tooltip-icon"></span></span>'
                                                +'<div class="apm_error" id="error_sc_'+ pMethods[i].fields[j].name +'">'
                                                    +'<label>'+fieldErrorMsg+'</label>'
                                                +'</div>';
                                    }

                                    html_apms +=
                                            '</div>';
                                }
                            }
                        }

                        html_apms +=
                                '</div>'
                            + '</li>';
                    }
                    
                    html_apms += '<input type="hidden" name="sc_transaction_id" id="sc_transaction_id" value="" />';
                    html_apms += '<input type="hidden" name="lst" id="lst" value="'+ resp.sessionToken +'" />';
                    
                    print_apms_options(html_upos, html_apms);
                    
                    // WP js trigger - wait until checkout is updated, but because it not always fired
                    // print the APMs one more time befor it
                    jQuery( document.body ).on( 'updated_checkout', function() {
                        print_apms_options(html_upos, html_apms);
                    });
                }
                // show some error
                else if(resp.status == 0) {
                    jQuery('form.woocommerce-checkout').prepend(
                        '<ul class="woocommerce-error" role="alert">'
                            +'<li><strong>Error in the proccess. Please, try again later!</strong></li>'
                        +'</ul>'
                    );
            
                    window.location.hash = '#main';
                }
            });
    }
}

/**
 * Function print_apms_options
 * Create and show the APMs in the page
 * 
 * @param {string} upos - html code for the UPOs
 * @param {string} apms - html code for the APMs
 */
function print_apms_options(upos, apms) {
    // apend UPOs holder
    if(upos != '') {
        if(jQuery('form.woocommerce-checkout').find('#sc_upos_list').length == 0) {
            jQuery('div.payment_method_sc').append(
                '<b>Choose from you prefered payment methods:</b><ul id="sc_upos_list"></div>');
        }
        else {
            // remove old upos
            jQuery('#sc_upos_list').html('');
        }

        jQuery('#sc_upos_list').append(upos);
    }
    
    /////////////////////////////
    
    // apend APMs holder
    if(jQuery('form.woocommerce-checkout').find('#sc_apms_list').length == 0) {
        jQuery('div.payment_method_sc').append(
            '<b>Choose from the other payment methods:</b><ul id="sc_apms_list"></div>');
    }
    else {
        // remove old apms
        jQuery('#sc_apms_list').html('');
    }
    
    // insert the html
    jQuery('#sc_apms_list')
        .append(apms)
        .promise()
        .done(function(){
            // create the Fields
//            scCard = scFields.create('card', {
//                iconStyle: 'solid',
//                style: {
//                    base: {
//                        iconColor: "#c4f0ff",
//                        color: "#000",
//                        fontWeight: 500,
//                        fontFamily: "sans-serif, Roboto, Open Sans, Segoe UI",
//                        fontSize: '16px',
//                        fontSmoothing: "antialiased",
//                        ":-webkit-autofill": {
//                            color: "#fce883"
//                        },
//                        "::placeholder": {
//                            color: "#52545A" 
//                        }
//                    },
//                    invalid: {
//                        iconColor: "#FFC7EE",
//                        color: "#FFC7EE"
//                    }
//                },
//                classes: elementClasses
//            });
//
//            scCard.attach('#card-field-placeholder');

            cardNumber = sfcFirstField = scFields.create('ccNumber', {
                classes: elementClasses
                ,style: fieldsStyle
            });
            cardNumber.attach('#sc_card_number');

            cardExpiry = scFields.create('ccExpiration', {
                classes: elementClasses
                ,style: fieldsStyle
            });
            cardExpiry.attach('#sc_card_expiry');

            cardCvc = scFields.create('ccCvc', {
                classes: elementClasses
                ,style: fieldsStyle
            });
            cardCvc.attach('#sc_card_cvc');
        });

    // change submit button type and behavior
    jQuery('form.woocommerce-checkout button[type=submit]')
        .attr('type', 'button')
        .attr('onclick', 'scValidateAPMFields()')
        .prop('disabled', false);
}

// when the admin select to Settle or Void the Order
//function settleAndCancelOrder(question, action, orderId) {
function settleAndCancelOrder(question, action, orderId) {
    if(confirm(question)) {
        jQuery('#custom_loader').show();
        
        var data = {
            action      : 'sc-ajax-action',
            security    : scAjax.security,
            orderId     : orderId
        };
        
        if(action == 'settle') {
            data.settleOrder = 1;
        }
        else if(action == 'void') {
            data.cancelOrder = 1;
        }
        
        jQuery.ajax({
            type: "POST",
            url: scAjax.ajaxurl,
            data: data,
            dataType: 'json'
        })
            .fail(function(){
                jQuery('#custom_loader').hide();
                alert('Response error.');
            })
            .done(function(resp) {
                if(resp && typeof resp.status != 'undefined' && resp.data != 'undefined') {
                    if(resp.status == 1) {
                        var urlParts = window.location.toString().split('post.php');
                        window.location = urlParts[0] + 'edit.php?post_type=shop_order';
                    }
                    else if(resp.data.reason != 'undefined' && resp.data.reason != '') {
                        alert(resp.data.reason);
                    }
                    else if(resp.data.gwErrorReason != 'undefined' && resp.data.gwErrorReason != '') {
                        alert(resp.data.gwErrorReason);
                    }
                    else {
                        alert('Response error.');
                    }
                }
                else {
                    alert('Response error.');
                }
                
                jQuery('#custom_loader').hide();
            });
    }
 }
 
/**
 * Function returnSCSettleBtn
 * Returns the SC Settle button
 */
function returnSCBtns() {
    if(scVoidBtn !== null) {
        jQuery('.wc-order-bulk-actions p').append(scVoidBtn);
        scVoidBtn = null;
    }
    if(scSettleBtn !== null) {
        jQuery('.wc-order-bulk-actions p').append(scSettleBtn);
        scSettleBtn = null;
    }
}

jQuery(function() {
    // Prepare REST payment
    if(jQuery('#custom_loader_2').length == 0) {
        jQuery('.wc_payment_methods ').append(
            '<div style="width: 100%; height: 100%;position: absolute; top: 0px;opacity:'
            +' 0.7; z-index: 3; background: white;"><div id="custom_loader_2" class="blockUI blockOverlay"></div></div>');
    }
    else {
        jQuery('#custom_loader_2').parent('div').show();
    }
    
    if(jQuery('#sc_apms_list').length == 0) {
        jQuery('#place_order').prop('disabled', true);
        getAPMs();
    }

    jQuery('#custom_loader_2').parent('div').hide();
    // Prepare REST payment END
    
    // listener for the iFrane
    window.addEventListener('message', function(event) {
        if(window.location.origin === event.origin && event.data.scAction === 'scRedirect') {
            window.location.href = event.data.scUrl;
        }
    }, false);
    
    jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
    
    billing_country_first_val = jQuery("#billing_country").val();
    
    // if user change the billing country get new payment methods
    jQuery("#billing_country").on('change', function() {
        console.log('on billing_country on change')
        getAPMs();
    });
    
    // when click on APM payment method
    jQuery('form.woocommerce-checkout').on('click', '.apm_title', function() {
        // hide all check marks 
        jQuery('#sc_apms_list, #sc_upos_list').find('.apm_title span').removeClass('apm_selected');
        
        // hide all containers with fields
        jQuery('#sc_apms_list, #sc_upos_list').find('.apm_fields').each(function(){
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
    
    // set the flags
    if(jQuery('#sc_settle_btn').length == 1) {
       scSettleBtn = jQuery('#sc_settle_btn');
    }
    
    if(jQuery('#sc_void_btn').length == 1) {
       scVoidBtn = jQuery('#sc_void_btn');
    }
    // set the flags END
    
    // hide Refund button if the status is refunded
    if(
        jQuery('#order_status').val() == 'wc-refunded'
        || jQuery('#order_status').val() == 'wc-cancelled'
        || jQuery('#order_status').val() == 'wc-pending'
        || jQuery('#order_status').val() == 'wc-on-hold'
        || jQuery('#order_status').val() == 'wc-failed'
    ) {
        jQuery('.refund-items').prop('disabled', true);
    }
    
    jQuery('#refund_amount').prop('readonly', false);
});
// document ready function END
