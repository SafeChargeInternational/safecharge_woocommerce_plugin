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
	invalid	: 'invalid'
};
// styles for the fields
var fieldsStyle = {
	base: {
		fontSize: '15px',
		fontFamily: 'sans-serif',
		color: '#43454b',
		fontSmoothing: 'antialiased',
		'::placeholder': {
			color: 'gray'
		}
	}
};
//var scOrderAmount, scOrderCurr,
var scMerchantId, scMerchantSiteId, scOpenOrderToken, webMasterId, scUserTokenId, locale;

/**
 * Function scUpdateCart
 * The first step of the checkout validation
 */
function scUpdateCart() {
	console.log('scUpdateCart()');
	
	jQuery('#sc_loader_background').show();
	
	selectedPM = jQuery('input[name="sc_payment_method"]:checked').val();
	
	if (typeof selectedPM == 'undefined' || selectedPM == '') {
		scFormFalse();
		return;
	}
	
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action			: 'sc-ajax-action',
			security		: scTrans.security,
			sc_request		: 'updateOrder'
		},
		dataType: 'json'
	})
		.fail(function(){
			scValidateAPMFields();
		})
		.done(function(resp) {
			console.log(resp);
			
			if(
				resp.hasOwnProperty('sessionToken')
				&& '' != resp.sessionToken
				&& resp.sessionToken != scData.sessionToken
			) {
				scData.sessionToken = resp.sessionToken;
				jQuery('#lst').val(resp.sessionToken);
				
				sfc			= SafeCharge(scData);
				scFields	= sfc.fields({ locale: locale });
				
				scFormFalse(scTrans.paymentError);
				
				jQuery('#sc_second_step_form .input-radio').prop('checked', false);
				jQuery('.apm_fields, #sc_loader_background').hide();
			}
			
			scValidateAPMFields();
		});
}

 /**
  * Function validateScAPMsModal
  * Second step of checkout validation.
  * When click save on modal, check for mandatory fields and validate them.
  */
function scValidateAPMFields() {
//	jQuery('#sc_loader_background').show();
	console.log('scValidateAPMFields');
	
//	selectedPM = jQuery('input[name="sc_payment_method"]:checked').val();
	
//	if (typeof selectedPM == 'undefined' || selectedPM == '') {
//		scFormFalse();
//		return;
//	}
	
//	jQuery.ajax({
//		type: "POST",
//		url: scTrans.ajaxurl,
//		data: {
//			action      : 'sc-ajax-action',
//			security    : scTrans.security,
//			checkCart	: 1
//		},
//		dataType: 'json'
//	})
//		.fail(function(){
//			console.error('Cart check failed.');
//		})
//		.done(function(resp) {
//			console.log(resp);
//	
//			if (
//				resp === null
//				|| ! resp.hasOwnProperty('success')
//				|| ! resp.hasOwnProperty('isCartChanged')
//				|| resp.success == 0
//			) {
//				console.error('Cart check error.');
//			}
////			else if(1 == resp.isCartChanged && resp.hasOwnProperty('amount')) {
////				scOrderAmount = resp.amount;
////			}
//			else {
//				console.error('Cart check report - cart was not changed or there is no amount.');
//			}
//		});
		
	var formValid			= true;	
	var nuveiPaymentParams	= {
		sessionToken    : scOpenOrderToken,
		merchantId      : scMerchantId,
		merchantSiteId  : scMerchantSiteId,
		webMasterId		: scTrans.webMasterId,
		userTokenId		: scUserTokenId
	};

	console.log(selectedPM)

	// use cards
	if (selectedPM == 'cc_card') {
		if (
			typeof scOpenOrderToken == 'undefined'
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
		sfc.createPayment(nuveiPaymentParams, function(resp) {
			afterSdkResponse(resp);
		});
		return;
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
			sfc.createPayment(nuveiPaymentParams, function(resp){
				afterSdkResponse(resp);
			});

			return;
		}

		// if not using SDK submit form
		jQuery('#place_order').trigger('click');
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
			jQuery('#place_order').trigger('click');
			
			closeScLoadingModal();
			return;
		}
		else if (resp.result == 'DECLINED') {
			scFormFalse(scTrans.paymentDeclined);
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
 
function closeScLoadingModal() {
	jQuery('#sc_loader_background').hide();
}

function scFormFalse(text) {
	console.log('scFormFalse()');
	
	// uncheck radios and hide fileds containers
	jQuery('.sc_payment_method_field').attr('checked', false);
	jQuery('.apm_fields').hide();
	
	if (typeof text == 'undefined') {
		text = scTrans.choosePM;
	}
	
	jQuery('#sc_checkout_messages').append(
	   '<div class="woocommerce-error" role="alert">'
		   +'<strong>'+ text +'</strong>'
	   +'</div>'
	);
	
	jQuery(window).scrollTop(0);
	closeScLoadingModal();
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
	console.log('getNewSessionToken()');
	
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			sc_request	: 'OpenOrder',
			scFormData	: jQuery('form.woocommerce-checkout').serialize()
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
		
		closeScLoadingModal();
	}
}

function scPrintApms(data) {
	console.log('scPrintApms()');
	
	if(jQuery('.wpmc-step-payment').length > 0) { // multi-step checkout
		console.log('multi-step checkout');
		
		jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	else { // default checkout
		console.log('default checkout');
		
		jQuery("form.woocommerce-checkout *:not(form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	
	jQuery("form.woocommerce-checkout #sc_second_step_form").show();
	
	jQuery(window).scrollTop(0);
	jQuery('#lst').val(data.sessonToken);
	
	scOpenOrderToken			= data.sessonToken;
	scUserTokenId				= data.userTokenId;
	scData.sessionToken			= data.sessonToken;
	scData.sourceApplication	= scTrans.webMasterId;
	
	if(Object.keys(data.upos).length > 0) {
		var upoHtml = '';
		
		for(var i in data.upos) {
			if ('cc_card' == data.upos[i]['paymentMethodName']) {
				var img = '<img src="' + data.pluginUrl + 'icons/visa_mc_maestro.svg" alt="'
					+ data.upos[i]['name'] + '" style="height: 36px;" />';
			} else {
				var img = '<img src="' + data.upos[i].logoURL.replace('/svg/', '/svg/solid-white/')
					+ '" alt="' + data.upos[i]['name'] + '" />';
			}
			
			upoHtml +=
				'<li class="upo_container" id="upo_cont_' + data.upos[i]['userPaymentOptionId'] + '">'
					+ '<label class="apm_title">'
						+ '<input id="sc_payment_method_' + data.upos[i]['userPaymentOptionId'] + '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' + data.upos[i]['userPaymentOptionId'] + '" data-upo-name="' + data.upos[i]['paymentMethodName'] + '" />&nbsp;'
						+ img + '&nbsp;&nbsp;'
						+ '<span>';
				
			// add upo identificator
			if ('cc_card' == data.upos[i]['paymentMethodName']) {
				upoHtml += data.upos[i]['upoData']['ccCardNumber'];
			} else if ('' != data.upos[i]['upoName']) {
				upoHtml += data.upos[i]['upoName'];
			}

			upoHtml +=
						'</span>&nbsp;&nbsp;';
				
			// add remove icon
			upoHtml +=
						'<span id="#sc_remove_upo_' + data.upos[i]['userPaymentOptionId'] + '" class="dashicons dashicons-trash" data-upo-id="' + data.upos[i]['userPaymentOptionId'] + '"></span>'
					+ '</label>';
			
			if ('cc_card' === data.upos[i]['paymentMethodName']) {
					upoHtml +=
						'<div class="apm_fields" id="sc_' + data.upos[i]['userPaymentOptionId'] + '">'
							+ '<div id="sc_upo_' + data.upos[i]['userPaymentOptionId'] + '_cvc"></div>'
						+ '</div>';
				}
				
				upoHtml +=
					'</li>';
		}
		
		jQuery('#sc_second_step_form #sc_upos_list').html(upoHtml);
	}
	
	if(Object.keys(data.apms).length > 0) {
		var apmHmtl = '';
		
		for(var j in data.apms) {
			var pmMsg = '';
			
			if (
				data.apms[j]['paymentMethodDisplayName'].hasOwnProperty(0)
				&& data.apms[j]['paymentMethodDisplayName'][0].hasOwnProperty('message')
			) {
				pmMsg = data.apms[j]['paymentMethodDisplayName'][0]['message'];
			} else if ('' != data.apms[j]['paymentMethod']) {
				// fix when there is no display name
				pmMsg = data.apms[j]['paymentMethod'].replace('apmgw_', '');
				pmMsg = pmMsg.replace('_', ' ');
			}
			
			var newImg = pmMsg;
			
			if ('cc_card' == data.apms[j]['paymentMethod']) {
				newImg = '<img src="' + data.pluginUrl + 'icons/visa_mc_maestro.svg" alt="'
					+ pmMsg + '" style="height: 36px;" />';
			} else if (
				data.apms[j].hasOwnProperty('logoURL')
				&& data.apms[j]['logoURL'] != ''
			) {
				newImg = '<img src="' + data.apms[j]['logoURL'].replace('/svg/', '/svg/solid-white/')
					+ '" alt="' + pmMsg + '" />';
			} else {
				newImg = '<img src="#" alt="' + pmMsg + '" />';
			}
			
			apmHmtl +=
					'<li class="apm_container">'
						+ '<label class="apm_title">'
							+ '<input id="sc_payment_method_' + data.apms[j]['paymentMethod'] + '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' + data.apms[j]['paymentMethod'] + '" data-nuvei-is-direct="'
								+ ( typeof data.apms[j]['isDirect'] != 'undefined' ? data.apms[j]['isDirect'] : 'false' ) + '" />&nbsp;'
							+ newImg
						+ '</label>';
			
			if ('cc_card' == data.apms[j]['paymentMethod']) {
				apmHmtl +=
						'<div class="apm_fields" id="sc_' + data.apms[j]['paymentMethod'] + '">'
							+ '<input type="text" id="sc_card_holder_name" name="' + data.apms[j]['paymentMethod'] + '[cardHolderName]" placeholder="Card holder name" />'

							+ '<div id="sc_card_number"></div>'
							+ '<div id="sc_card_expiry"></div>'
							+ '<div id="sc_card_cvc"></div>';
			} else if (data.apms[j]['fields'].length > 0) {
				apmHmtl +=
						'<div class="apm_fields">';

				for (var f in data.apms[j]['fields']) {
					var pattern = '';
					if ('' != data.apms[j]['fields'][f]['regex']) {
						pattern = data.apms[j]['fields'][f]['regex'];
					}

					var placeholder = '';
					if (
						data.apms[j]['fields'][f]['caption'].hasOwnProperty(0)
						&& data.apms[j]['fields'][f]['caption'][0].hasOwnProperty('message')
						&& '' != data.apms[j]['fields'][f]['caption'][0]['message']
					) {
						placeholder = data.apms[j]['fields'][f]['caption'][0]['message'];
					} else {
						placeholder = data.apms[j]['fields'][f]['name'].replace('_', ' ');
					}
					
					apmHmtl +=
							'<input id="' + data.apms[j]['paymentMethod'] + '_' + data.apms[j]['fields'][f]['name']
								+ '" name="' + data.apms[j]['paymentMethod'] + '[' + data.apms[j]['fields'][f]['name'] + ']'
								+ '" type="' + data.apms[j]['fields'][f]['type']
								+ '" pattern="' + pattern
								+ '" placeholder="' + placeholder
								+ '" autocomplete="new-password" />';
				}
				
				apmHmtl +=
						'</div>';
			}
			
			apmHmtl +=
					'</li>';
		}
		
		jQuery('#sc_second_step_form #sc_apms_list').html(apmHmtl);
	}
}

jQuery(function() {
	jQuery('body').on('change', 'input[name="sc_payment_method"]', function() {
		console.log('click on APM/UPO');
		
		// hide all containers with fields
		jQuery('.apm_fields').hide();
		
		if(scDeleteUpoFlag) {
			console.log('scDeleteUpoFlag', scDeleteUpoFlag);
			
			scDeleteUpoFlag = false;
			return;
		}
		
		var currInput		= jQuery(this);
		var filedsToShowId	= currInput.closest('li').find('.apm_fields');
		
		// reset sc fields holders
		cardNumber = sfcFirstField = cardExpiry = cardCvc = null;
		if(lastCvcHolder !== '') {
			jQuery(lastCvcHolder).html('');
		}
		
		if('undefined' != filedsToShowId) {
			filedsToShowId.slideToggle('fast');
		}

		// CC - load webSDK fields
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
		else if( // CC UPO - load webSDK fields
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
	
	jQuery('body').on('click', '#sc_second_step_form span.dashicons-trash', function(e) {
		e.preventDefault();
		deleteScUpo(jQuery(this).attr('data-upo-id'));
	});
	
	// when on multistep checkout -> APMs view, someone click on previous button
	jQuery('body').on('click', '#wpmc-prev', function() {
		if(jQuery('#sc_second_step_form').css('display') == 'block') {
			jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(.payment_box, form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").show('slow');
			
			jQuery("form.woocommerce-checkout #sc_second_step_form").hide();
			
			jQuery('input[name="payment_method"]').prop('checked', false);
		}
	});
});
// document ready function END
