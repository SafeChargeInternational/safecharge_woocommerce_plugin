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
			color: '#52545A'
		}
	}
};
var scOrderAmount, scOrderCurr, scMerchantId, scMerchantSiteId, scOpenOrderToken, webMasterId;

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
function scValidateAPMFields() {
	console.log('scValidateAPMFields')
	
	if ('sc' != jQuery('input[name="payment_method"]:checked').val()) {
		jQuery('form.woocommerce-checkout').submit();
		return;
	}
	
	jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
	jQuery('#custom_loader').show();
	
	jQuery('#confirm-order-flag').val(''); // reset the fake parameter flag
	
	// WC check for the terms when try to submit the form, the idea is just to prevent webSDK call
	if(jQuery('#terms').length > 0 && !jQuery('#terms').is(':checked')) {
		selectedPM = 'dummy text';
	}
	
	selectedPM = jQuery('input[name="sc_payment_method"]:checked').val();
	
	if (typeof selectedPM == 'undefined' || selectedPM == '') {
		scFormFalse();
		jQuery('#custom_loader').hide();
		return;
	}
		
	var formValid			= true;	
	var nuveiPaymentParams	= {
		sessionToken    : scOpenOrderToken,
		merchantId      : scMerchantId,
		merchantSiteId  : scMerchantSiteId,
		currency        : scOrderCurr,
		amount          : scOrderAmount,
		webMasterId		: scTrans.webMasterId
	};

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
		sfc.createPayment(nuveiPaymentParams, function(resp) {
			afterSdkResponse(resp);
		});
	}
	// use CC UPO
	else if(
		typeof jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name') != 'undefined'
		&& 'cc_card' == jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name')
	) {
		if( ! jQuery('#sc_upo_'+ selectedPM +'_cvc.sfc-complete').length == 0) {
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
		jQuery('#custom_loader').hide();
		jQuery('form.woocommerce-checkout').submit();
	}
}

function afterSdkResponse(resp) {
	console.log(resp);

	if (typeof resp.result != 'undefined') {
		console.log(resp.result)

		if (resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
			jQuery('#sc_transaction_id').val(resp.transactionId);
			jQuery('form.woocommerce-checkout').submit();
		}
		else if (resp.result == 'DECLINED') {
			scFormFalse(scTrans.paymentDeclined);

			jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
			scCard = null;
			getAPMs();
		}
		else {
			if (resp.errorDescription != 'undefined' && resp.errorDescription != '') {
				scFormFalse(resp.errorDescription);
			} else {
				scFormFalse(scTrans.paymentError);
			}

			jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
			scCard = null;
			getAPMs();
		}
	}
	else {
		scFormFalse(scTrans.unexpectedError);
		console.error('Error with SDK response: ' + resp);

		jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
		scCard = null;
		getAPMs();
		return;
	}
}
 
function scFormFalse(text) {
	// clear the error
	jQuery('.woocommerce-error').remove();
	
	if (typeof text == 'undefined') {
		text = scTrans.choosePM;
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

	if (jQuery('#error_'+elemId).css('display') == 'block') {
		jQuery('#error_'+elemId).hide();
	} else {
		jQuery('#error_'+elemId).show();
	}
}

function getAPMs() {
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			sc_request	: 'OpenOrder'
		},
		dataType: 'json'
	})
		.fail(function(){
			alert(scTrans.errorWithPMs);
			jQuery('#custom_loader').hide();
			return;
		})
		.done(function(resp) {
			console.log(resp)

			if (resp === null) {
				alert(scTrans.errorWithPMs);
				return;
			}

			if (
				typeof resp != 'undefined'
				&& resp.status == 1
				&& typeof resp.data['paymentMethods'] != 'undefined'
				&& resp.data['paymentMethods'].length > 0
			) {
				try {
					scOpenOrderToken	= resp.sessionToken;
					scOrderCurr			= resp.currency;
					scMerchantId		= resp.merchantId;
					scMerchantSiteId	= resp.merchantSiteId;
					scOrderAmount		= resp.amount;

					scData.merchantSiteId		= resp.merchantSiteId;
					scData.merchantId			= resp.merchantId;
					scData.sessionToken			= resp.sessionToken;
					scData.sourceApplication	= scTrans.webMasterId;

					if (resp.testEnv == 'yes') {
						scData.env = 'test';
					}

					sfc = SafeCharge(scData);

					// prepare fields
					scFields = sfc.fields({ locale: resp.langCode });
				} catch (exception) {
					alert(scTrans.missData);
					console.error(exception);
					jQuery('#custom_loader').hide();
					return;
				}

				var html_upos = '';
				var html_apms = '';
				var pMethods  = resp.data['paymentMethods'];

				// prepare UPOs
				for (var j in resp.data['upos']) {
					if ('cc_card' === resp.data['upos'][j].paymentMethodName) {
						var upoImg = '<img src="'+ scTrans.plugin_dir_url
							+'icons/visa_mc_maestro.svg" alt="'+ resp.data['upos'][j].name +'" style="height: 26px;" />';
					}
					else {
						var upoImg = '<img src="'+ resp.data['upos'][j].logoURL.replace('/svg/', '/svg/solid-white/')
							+'" alt="'+ resp.data['upos'][j].name +'" />';
					}
					
					html_upos +=
						'<li class="upo_container" id="upo_cont_'+ resp.data['upos'][j].userPaymentOptionId +'">'
							+ '<div class="apm_title">'
								+ '<input id="sc_payment_method_'+ resp.data['upos'][j].userPaymentOptionId +'" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="'+ resp.data['upos'][j].userPaymentOptionId +'" data-upo-name="'+ resp.data['upos'][j].paymentMethodName +'" />'
								+ upoImg;
						
					html_upos +=
								'&nbsp;<span>';
						
						// add upo identificator
						if('cc_card' == resp.data['upos'][j].paymentMethodName) {
							html_upos += resp.data['upos'][j].upoData.ccCardNumber;
						}
						else {
							html_upos += resp.data['upos'][j].upoName;
						}
						
						// add remove icon
						html_upos +=
								'</span>&nbsp;'
								+ '<span id="#sc_remove_upo_'+ resp.data['upos'][j].userPaymentOptionId +'" onclick="deleteScUpo('+ resp.data['upos'][j].userPaymentOptionId +')" class="dashicons dashicons-trash"></span>'
								+ '<div class="blockUI custom_loaders"></div>'
							+ '</div>';
					
					if('cc_card' === resp.data['upos'][j].paymentMethodName) {
						html_upos +=
							'<div class="apm_fields" id="sc_'+ resp.data['upos'][j].userPaymentOptionId +'">'
								+ '<div id="sc_upo_'+ resp.data['upos'][j].userPaymentOptionId +'_cvc"></div>'
							+ '</div>';
					}
					
					html_upos +=
						'</li>';
				}
				// prepare UPOs END

				// prepare APMs
				for (var i in pMethods) {
					var pmMsg = '';
					if (
						pMethods[i]['paymentMethodDisplayName'].length > 0
						&& typeof pMethods[i]['paymentMethodDisplayName'][0].message != 'undefined'
					) {
						pmMsg = pMethods[i]['paymentMethodDisplayName'][0].message;
					}
					// fix when there is no display name
					else if (pMethods[i]['paymentMethod'] != '') {
						pmMsg = pMethods[i]['paymentMethod'].replace('apmgw_', '');
						pmMsg = pmMsg.replace(/_/g, ' ');
					}

					var newImg = pmMsg;

					if ('cc_card' == pMethods[i]['paymentMethod']) {
						newImg = '<img src="'+ scTrans.plugin_dir_url
							+'icons/visa_mc_maestro.svg" alt="'+ pmMsg +'" style="height: 26px;" />';
					} else if (typeof pMethods[i]['logoURL'] != 'undefined') {
						newImg = '<img src="'+ pMethods[i]['logoURL'].replace('/svg/', '/svg/solid-white/')
							+'" alt="'+ pmMsg +'" />';
					} else {
						newImg = '<img src="#" alt="'+ pmMsg +'" />';
					}

					html_apms +=
						'<li class="apm_container">'
							+ '<div class="apm_title">'
								+ '<input id="sc_payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="'+ pMethods[i].paymentMethod +'" data-nuvei-is-direct="' + pMethods[i].isDirect + '" />&nbsp;'
								+ newImg
							+ '</div>';

					if (pMethods[i].paymentMethod == 'cc_card' || pMethods[i].paymentMethod == 'dc_card') {
						html_apms +=
							'<div class="apm_fields" id="sc_'+ pMethods[i].paymentMethod +'">'
								+ '<input type="text" id="sc_card_holder_name" name="'+ pMethods[i].paymentMethod +'[cardHolderName]" placeholder="Card holder name" />'

								+ '<div id="sc_card_number"></div>'
								+ '<div id="sc_card_expiry"></div>'
								+ '<div id="sc_card_cvc"></div>';
					}
					else if (pMethods[i].fields.length > 0) {
						html_apms +=
							'<div class="apm_fields">';

						for (var j in pMethods[i].fields) {
							var pattern = '';
							try {
								pattern = pMethods[i].fields[j].regex;
								if (pattern === undefined) {
									pattern = '';
								}
							} catch (e) {}

							var placeholder = '';
							try {
								if (typeof pMethods[i].fields[j].caption[0] == 'undefined') {
									placeholder = pMethods[i].fields[j].name;
									placeholder = placeholder.replace(/_/g, ' ');
								} else {
									placeholder = pMethods[i].fields[j].caption[0].message;
								}
							} catch (e) {}

							html_apms +=
								'<input id="'+ pMethods[i].paymentMethod +'_'+ pMethods[i].fields[j].name 
									+'" name="'+ pMethods[i].fields[j].name 
									+'" type="'+ pMethods[i].fields[j].type 
									+'" pattern="'+ pattern 
									+ '" placeholder="'+ placeholder 
									+'" autocomplete="new-password" />';
						}
					}

					html_apms +=
							'</div>'
						+ '</li>';
				}
				// prepare APMs END

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
			else if (resp.status == 0) {
				jQuery('#place_order')
					.attr('type', 'submit')
					.attr('onclick', '');
				
				var scErrorMsg = scTrans.proccessError;
				
				if(resp.hasOwnProperty('message') && '' != resp.message) {
					scErrorMsg = resp.message;
				}
				
				
				jQuery('form.woocommerce-checkout').prepend(
					'<ul class="woocommerce-error" role="alert">'
						+'<li><strong>'+ scErrorMsg +'</strong></li>'
					+'</ul>'
				);

				window.location.hash = '#main';
				jQuery('#custom_loader').hide();
				jQuery('.custom_loaders').remove();
			}
		});
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
	if (jQuery('form.woocommerce-checkout').find('#sc_upos_list').length == 0) {
		jQuery('div.payment_method_sc').append(
			'<b>'+ scTrans.chooseUPO +':</b><ul id="sc_upos_list"></div>');
	} else {
		// remove old upos
		jQuery('#sc_upos_list').html('');
	}
	
	// apend APMs holder
	if (jQuery('form.woocommerce-checkout').find('#sc_apms_list').length == 0) {
		jQuery('div.payment_method_sc').append(
			'<b>'+ scTrans.chooseAPM +':</b><ul id="sc_apms_list"></div>');
	} else {
		// remove old apms
		jQuery('#sc_apms_list').html('');
	}
	
	// insert the html
	jQuery('#sc_upos_list').append(upos);
	
	jQuery('#sc_apms_list')
		.append(apms)
		.promise()
		.done(function(){
			jQuery('#custom_loader').hide();
		});
}

function deleteScUpo(upoId) {
	scDeleteUpoFlag = true;
	
	if(confirm(scTrans.AskDeleteUpo)) {
		jQuery('#sc_remove_upo_' + upoId).closest('.apm_title').find('.custom_loaders').show();
		jQuery('#sc_remove_upo_' + upoId).hide();

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
					jQuery('form.woocommerce-checkout').find('#upo_cont_' + upoId).remove();
				}
				else {
					jQuery('#sc_remove_upo_' + upoId).closest('.apm_title').find('.custom_loaders').hide();
					jQuery('#sc_remove_upo_' + upoId).show();

					scFormFalse(res.msg);
				}
			}
			else {
				jQuery('#sc_remove_upo_' + upoId).closest('.apm_title').find('.custom_loaders').hide();
				jQuery('#sc_remove_upo_' + upoId).show();
			}
		})
		.fail(function(e) {
			jQuery('#sc_remove_upo_' + upoId).closest('.apm_title').find('.custom_loaders').hide();
			jQuery('#sc_remove_upo_' + upoId).show();
		});
	}
}

function checkoutStep2AddLoader() {
	jQuery('.blockUI.blockOverlay').remove();
	
	jQuery('#customer_details .col-1, #customer_details .col-2, .woocommerce-form-coupon-toggle')
		.css('position', 'relative')
		.append('<div class="blockOverlay custom_loaders"></div>');

	jQuery('#order_review .woocommerce-checkout-review-order-table')
		.css('position', 'relative')
		.append('<div class="blockOverlay custom_loaders"></div>');

	jQuery('.wc_payment_methods .wc_payment_method').each(function(){
		if(!jQuery(this).hasClass('payment_method_sc')) {
			jQuery(this).append('<div class="blockOverlay custom_loaders"></div>');
		}
	});
}

// after click on Go back button
function checkoutGoBackStep() {
	jQuery('.custom_loaders').remove();
	jQuery('#sc_go_back').hide();
	jQuery('.payment_method_sc b, .payment_method_sc #sc_apms_list').remove();
	
	jQuery('#place_order')
		.html(jQuery('#place_order').attr('data-sc-text'))
		.attr('type', 'submit')
		.attr('onclick', '')
		.prop('disabled', false);
}

jQuery(function() {
	// Prepare REST payment
	if (jQuery('#custom_loader_2').length == 0) {
		jQuery('.wc_payment_methods ').append(
			'<div style="width: 100%; height: 100%;position: absolute; top: 0px;opacity:'
			+' 0.7; z-index: 3; background: white;"><div id="custom_loader_2" class="blockUI blockOverlay"></div></div>');
	} else {
		jQuery('#custom_loader_2').parent('div').show();
	}
	
	jQuery('#custom_loader_2').parent('div').hide();
	// Prepare REST payment END
	
	jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
	
	// when click on APM payment method
	jQuery('form.woocommerce-checkout').on('click', '.apm_title', function() {
		if(scDeleteUpoFlag) {
			scDeleteUpoFlag = false;
			return;
		}
		
		// unchck SC payment methods
		jQuery('form.woocommerce-checkout').find('sc_payment_method_field').attr('checked', false);
		
		// check current radio
		var currInput = jQuery(this).find('input');
		currInput.attr('checked', true);
		
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
		jQuery('#sc_apms_list, #sc_upos_list').find('.apm_fields').each(function(){
			var self = jQuery(this);
			if (self.css('display') == 'block') {
				self.slideToggle('slow');
			}
		});
		
		// expand payment fields
		if (jQuery(this).parent('li').find('.apm_fields').css('display') == 'none') {
			jQuery(this).parent('li').find('.apm_fields').slideToggle('slow');
		}
	});
	
	// on last step modify Place order button
	jQuery(document.body).on('checkout_error', function () {
		if (jQuery('.woocommerce-error').attr('id') == 'sc_fake_error') { // Validation Passed (Just the Fake Error Exists)
			jQuery( 'html, body' ).stop(); // stop scroll to the errors
			
			checkoutStep2AddLoader();
			getAPMs();
			
			jQuery('form.woocommerce-checkout button[type=submit]')
				.attr('type', 'button')
				.attr('onclick', 'scValidateAPMFields()')
				.prop('disabled', false)
				.html(jQuery('#place_order').attr('data-default-text'));
		
			if(jQuery('#sc_go_back').length == 0) {
				jQuery('<button type="button" class="button alt" id="sc_go_back" onclick="checkoutGoBackStep()" style="margin-bottom: 5px;">'+ scTrans.goBack +'</button>')
					.insertBefore(jQuery('#place_order'));
			}
			else {
				jQuery('#sc_go_back').show();
			}
		}
    });
	
	jQuery('form.woocommerce-checkout').on('checkout_place_order', function () {
		if (jQuery('#confirm-order-flag').length == 0) {
			jQuery(this).append('<input type="hidden" id="confirm-order-flag" name="confirm-order-flag" value="1">');
		}
		return true;
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
	
});
// document ready function END
