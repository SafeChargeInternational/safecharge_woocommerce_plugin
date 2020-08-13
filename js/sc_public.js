'use strict';

var isAjaxCalled              = false;
var manualChangedCountry      = false;
var selectedPM                = '';
var billing_country_first_val = '';
// for the fields
var sfc				= null;
var scFields		= null;
var sfcFirstField	= null;
var scCard			= null;
var cardNumber		= null;
var cardExpiry		= null;
var cardCvc			= null;
var scData			= {};
var lastCvcHolder	= '';
var scDeleteUpoFlag = false;

// set some classes for the Fields
var elementClasses = {
	focus	: 'focus',
	empty	: 'empty',
	invalid	: 'invalid',
};
// styles for the fields
var fieldsStyle = {
	base: {
		fontSize: 15.5
		,fontFamily: 'sans-serif'
		,color: '#43454b'
		,fontSmoothing: 'antialiased'
		,'::placeholder': {
			color: 'gray'
		}
	}
};
var scOrderAmount, scOrderCurr, scMerchantId, scMerchantSiteId, scOpenOrderToken, webMasterId, scUserTokenId, locale;

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
function scValidateAPMFields() {
	console.log('scValidateAPMFields')
	
	selectedPM = jQuery('input[name="sc_payment_method"]:checked').val();
	
	if (typeof selectedPM == 'undefined' || selectedPM == '') {
		scFormFalse();
		return;
	}
		
	var formValid			= true;	
	var nuveiPaymentParams	= {
		sessionToken    : scOpenOrderToken,
		merchantId      : scMerchantId,
		merchantSiteId  : scMerchantSiteId,
		currency        : scOrderCurr,
		amount          : scOrderAmount,
		webMasterId		: scTrans.webMasterId,
		userTokenId		: scUserTokenId
	};

	console.log(selectedPM)

	// use cards
	if (selectedPM == 'cc_card') {
		if (
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

		if(jQuery('#sc_card_holder_name').val() == '') {
			scFormFalse(scTrans.CCNameIsEmpty);
			return;
		}

		if(
			jQuery('#sc_card_number.empty').length > 0
			|| jQuery('#sc_card_number.sfc-complete').length == 0
		) {
			scFormFalse(scTrans.CCNumError);
			return;
		}

		if(
			jQuery('#sc_card_expiry.empty').length > 0
			|| jQuery('#sc_card_expiry.sfc-complete').length == 0
		) {
			scFormFalse(scTrans.CCExpDateError);
			return;
		}

		if(
			jQuery('#sc_card_cvc.empty').length > 0
			|| jQuery('#sc_card_cvc.sfc-complete').length == 0
		) {
			scFormFalse(scTrans.CCCvcError);
			return;
		}

		nuveiPaymentParams.cardHolderName	= document.getElementById('sc_card_holder_name').value;
		nuveiPaymentParams.paymentOption	= sfcFirstField;

		// create payment with WebSDK
		jQuery('#sc_loader_background').show();
		sfc.createPayment(nuveiPaymentParams, function(resp) {
			afterSdkResponse(resp);
		});
	}
	// use CC UPO
	else if(
		typeof jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name') != 'undefined'
		&& 'cc_card' == jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name')
	) {
		if(jQuery('#sc_upo_'+ selectedPM +'_cvc.sfc-complete').length == 0) {
			scFormFalse(scTrans.CCCvcError);
			return;
		}
		
		nuveiPaymentParams.paymentOption = {
			userPaymentOptionId: selectedPM,
			card: {
				CVV: cardCvc
			}
		};
		
		// create payment with WebSDK
		jQuery('#sc_loader_background').show();
		sfc.createPayment(nuveiPaymentParams, function(resp){
			afterSdkResponse(resp);
		});
	}
	// use APM data
	else {
		nuveiPaymentParams.paymentOption = {
			alternativePaymentMethod: {
				paymentMethod: selectedPM
			}
		};

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
				if (apmField.val() == '' || regex.test(apmField.val()) == false) {
					formValid = false;
				}
			} else if (apmField.val() == '') {
				formValid = false;
			}

			nuveiPaymentParams.paymentOption.alternativePaymentMethod[apmField.attr('name')] = apmField.val();
		});

		if (!formValid) {
			scFormFalse();
			jQuery('#custom_loader').hide();
			return;
		}

		// direct APMs can use the SDK
		if(jQuery('input[name="sc_payment_method"]:checked').attr('data-nuvei-is-direct') == 'true') {
			jQuery('#sc_loader_background').show();
			sfc.createPayment(nuveiPaymentParams, function(resp){
				afterSdkResponse(resp);
			});

			return;
		}

		// if not using SDK submit form
		jQuery('#sc_second_step_form').submit();
	}
}

/**
 * 
 * @param {type} resp
 * @returns {undefined}
 */
function afterSdkResponse(resp) {
	console.log('afterSdkResponse');
	console.log(resp);

	if (typeof resp.result != 'undefined') {
		console.log(resp.result)

		if (resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
			jQuery('#sc_transaction_id').val(resp.transactionId);
			jQuery('#sc_second_step_form').submit();
		}
		else if (resp.result == 'DECLINED') {
			scFormFalse(scTrans.paymentDeclined);

			jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
			scCard = null;
			getNewSessionToken();
		}
		else {
			if (resp.errorDescription != 'undefined' && resp.errorDescription != '') {
				scFormFalse(resp.errorDescription);
			} else {
				scFormFalse(scTrans.paymentError);
			}

			jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
			scCard = null;
			getNewSessionToken();
		}
	}
	else {
		scFormFalse(scTrans.unexpectedError);
		console.error('Error with SDK response: ' + resp);

		jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
		scCard = null;
		getNewSessionToken();
		return;
	}
}
 
function scFormFalse(text) {
	jQuery('#sc_checkout_messages').html('');
	
	if (typeof text == 'undefined') {
		text = scTrans.choosePM;
	}
	
	jQuery('#sc_checkout_messages').append(
	   '<div class="woocommerce-error" role="alert">'
		   +'<strong>'+ text +'</strong>'
	   +'</div>'
	);
	
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

	if (jQuery('#error_'+elemId).css('display') == 'block') {
		jQuery('#error_'+elemId).hide();
	} else {
		jQuery('#error_'+elemId).show();
	}
}

function getNewSessionToken() {
	console.log('getNewSessionToken');
	
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			sc_request	: 'OpenOrder',
			order_id	: orderId
		},
		dataType: 'json'
	})
		.fail(function(){
			scFormFalse(scTrans.errorWithPMs);
			return;
		})
		.done(function(resp) {
			console.log(resp);
	
			if (
				resp === null
				|| typeof resp.status == 'undefined'
				|| typeof resp.sessionToken == 'undefined'
			) {
				scFormFalse(scTrans.errorWithSToken);
				return;
			}
			
			if (resp.status == 0) {
				window.location.reload();
				return;
			}
			
			scOpenOrderToken = scData.sessionToken = resp.sessionToken;
			
			sfc			= SafeCharge(scData);
			scFields	= sfc.fields({ locale: locale });
			
			jQuery('#sc_second_step_form .input-radio').prop('checked', false);
			jQuery('.apm_fields, #sc_loader_background').hide();
		});
}

function deleteScUpo(upoId) {
	scDeleteUpoFlag = true;
	
	if(confirm(scTrans.AskDeleteUpo)) {
		jQuery('#sc_remove_upo_' + upoId).hide();
		jQuery('#sc_loader_background').show();

		jQuery.ajax({
			type: "POST",
			dataType: "json",
			url: scTrans.ajaxurl,
			data: {
				action      : 'sc-ajax-action',
				security	: scTrans.security,
				scUpoId		: upoId
			}
		})
		.done(function(res) {
			console.log('delete UPO response', res);

			if(typeof res.status != 'undefined') {
				if('success' == res.status) {
					jQuery('#upo_cont_' + upoId).remove();
				}
				else {
					scFormFalse(res.msg);
				}
			}
			else {
				jQuery('#sc_remove_upo_' + upoId).show();
			}
		})
		.fail(function(e) {
			jQuery('#sc_remove_upo_' + upoId).show();
		});
		
		jQuery('#sc_loader_background').hide();
	}
}

jQuery(function() {
	jQuery('.apm_title').on('click', function() {
		if(scDeleteUpoFlag) {
			scDeleteUpoFlag = false;
			return;
		}
		
		var clickedTitle	= jQuery(this);
		var currInput		= jQuery('input[name="sc_payment_method"]:checked');
		
		// reset sc fields holders
		cardNumber = sfcFirstField = cardExpiry = cardCvc = null;
		if(lastCvcHolder !== '') {
			jQuery(lastCvcHolder).html('');
		}
		
		// load webSDK fields
		if(currInput.val() == 'cc_card') {
			jQuery('#sc_card_number').html('');
			cardNumber = sfcFirstField = scFields.create('ccNumber', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardNumber.attach('#sc_card_number');

			jQuery('#sc_card_expiry').html('');
			cardExpiry = scFields.create('ccExpiration', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardExpiry.attach('#sc_card_expiry');

			lastCvcHolder = '#sc_card_cvc';

			jQuery(lastCvcHolder).html('');
			cardCvc = scFields.create('ccCvc', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardCvc.attach(lastCvcHolder);
		}
		else if(
			!isNaN(currInput.val())
			&& typeof currInput.attr('data-upo-name') != 'undefined'
			&& currInput.attr('data-upo-name') === 'cc_card'
		) {
			lastCvcHolder = '#sc_upo_' + currInput.val() + '_cvc';
			
			cardCvc = scFields.create('ccCvc', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardCvc.attach(lastCvcHolder);
		}
		
		jQuery('.SfcField').addClass('input-text');
		// load webSDK fields END
		
		// hide all containers with fields
		jQuery('.apm_fields').hide();
		
		clickedTitle.closest('li').find('.apm_fields').slideToggle('slow');
	});
	
	// change text on Place order button
	jQuery('form.woocommerce-checkout').on('change', 'input[name=payment_method]', function(){
		if(jQuery('input[name=payment_method]:checked').val() == 'sc') {
			jQuery('#place_order').html(jQuery('#place_order').attr('data-sc-text'));
		}
		else if(jQuery('#place_order').html() == jQuery('#place_order').attr('data-sc-text')) {
			jQuery('#place_order').html(jQuery('#place_order').attr('data-default-text'));
		}
	});
	
	jQuery('#sc_second_step_form span.dashicons-trash').on('click', function(e) {
		e.preventDefault();
		deleteScUpo(jQuery(this).attr('data-upo-id'));
	});
});
// document ready function END
