'use strict';

var isAjaxCalled              = false;
var manualChangedCountry      = false;
var selectedPM                = '';
var billing_country_first_val = '';
// for the fields
var sfc           = null;
var scFields      = null;
var sfcFirstField = null;
var scCard        = null;
var cardNumber    = null;
var cardExpiry    = null;
var cardCvc       = null;
var scData        = {};
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
	if ('sc' != jQuery('input[name="payment_method"]:checked').val()) {
		jQuery('form.woocommerce-checkout').submit();
		return;
	}
	
	jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
	jQuery('#custom_loader').show();
	
	var formValid = true;
	selectedPM    = jQuery('input[name="sc_payment_method"]:checked').val();
	
	if (typeof selectedPM != 'undefined' && selectedPM != '') {
		// use cards
		if (selectedPM == 'cc_card' || selectedPM == 'dc_card') {
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
	
			// create payment with WebSDK
			sfc.createPayment({
				sessionToken    : scOpenOrderToken,
				merchantId      : scMerchantId,
				merchantSiteId  : scMerchantSiteId,
				currency        : scOrderCurr,
				amount          : scOrderAmount,
				cardHolderName  : document.getElementById('sc_card_holder_name').value,
				paymentOption   : sfcFirstField,
				webMasterId		: scTrans.webMasterId
			}, function(resp){
				console.log(resp);

				if (typeof resp.result != 'undefined') {
					console.log(resp.result)
					
					if (resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
						jQuery('#sc_transaction_id').val(resp.transactionId);
						jQuery('form.woocommerce-checkout').submit();
					} else if (resp.result == 'DECLINED') {
						scFormFalse(scTrans.paymentDeclined);
						
						jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
						scCard = null;
						getAPMs();
					} else {
						if (resp.errorDescription != 'undefined' && resp.errorDescription != '') {
							scFormFalse(resp.errorDescription);
						} else {
							scFormFalse(scTrans.paymentError);
						}
						
						jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
						scCard = null;
						getAPMs();
					}
				} else {
					scFormFalse(scTrans.unexpectedError);
					console.error('Error with SDK response: ' + resp);
					
					jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
					scCard = null;
					getAPMs();
					
					return;
				}
			});
		}
		// use APM data
		else {
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
						apmField.parent('.apm_field').find('.apm_error').show();

						formValid = false;
					} else {
						apmField.parent('.apm_field').find('.apm_error').hide();
					}
				} else if (apmField.val() == '') {
					formValid = false;
				}
			});

			if (!formValid) {
				scFormFalse();
				jQuery('#custom_loader').hide();
				return;
			}

			jQuery('#custom_loader').hide();
			jQuery('form.woocommerce-checkout').submit();
		}
	} else {
		scFormFalse();
		jQuery('#custom_loader').hide();
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

//	jQuery('#custom_loader').hide();
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

/**
 * Function scGetOrderTotal
 * Try to get Order Total amount
 * 
 * @return string
 */
function scGetOrderTotal() {
	var totalHtmlContent = jQuery('.order-total .woocommerce-Price-amount').html().split('</span>');
	var amount           = '';
		
	if (
		typeof totalHtmlContent == 'object'
		&& totalHtmlContent.length >= 2
		&& '' != totalHtmlContent[1]
	) {
		amount = totalHtmlContent[1].replace(scTrans.wcThSep, '');

		// format the number
		if ('.' != scTrans.wcDecSep) {
			amount = amount.replace(scTrans.wcDecSep, '.');
		}

		return parseFloat(amount).toFixed(2);
	}
	
	return amount;
}

function getAPMs() {
	// you are not on checkout page
	if (
		typeof scOrderAmount == 'undefined'
		|| typeof jQuery("#billing_email").val() == 'undefined'
		|| jQuery("#billing_email").val() == ''
		|| typeof jQuery("#billing_country").val() == 'undefined'
		|| jQuery("#billing_country").val() == ''
	) {
		return;
	}
	
	if (jQuery("#billing_country").val() != billing_country_first_val) {
		manualChangedCountry      = true;
		billing_country_first_val = jQuery("#billing_country").val();
	}

	if (isAjaxCalled === false || manualChangedCountry === true) {
		if ('' == jQuery("#billing_email").val() || '' == jQuery("#billing_country").val()) {
			alert(scTrans.fillFields);
			return;
		}
		
		isAjaxCalled   = true;
		var orderTotal = scGetOrderTotal();
		
		if ('' != orderTotal && orderTotal != scOrderAmount) {
			scOrderAmount = orderTotal;
		}

		jQuery.ajax({
			type: "POST",
			url: scTrans.ajaxurl,
			data: {
				action      : 'sc-ajax-action',
				security    : scTrans.security,
				country     : jQuery("#billing_country").val(),
				amount      : scOrderAmount,
				userMail    : jQuery("#billing_email").val(),
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
						scOpenOrderToken = resp.sessionToken;
						scOrderCurr      = resp.currency;
						scMerchantId     = resp.merchantId;
						scMerchantSiteId = resp.merchantSiteId;
						
						scData.merchantSiteId    = resp.merchantSiteId;
						scData.merchantId        = resp.merchantId;
						scData.sessionToken      = resp.sessionToken;
						scData.sourceApplication = scTrans.webMasterId;
						
						if (resp.testEnv == 'yes') {
							scData.env = 'test';
						}

						sfc = SafeCharge(scData);

						// prepare fields
						scFields = sfc.fields({
							locale: resp.langCode
						});
					} catch (exception) {
						alert(scTrans.missData);
						console.error(exception);
						jQuery('#custom_loader').hide();
						return;
					}

					var html_upos = '';
					var html_apms = '';
					var pMethods  = resp.data['paymentMethods'];
					
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
									+ newImg
									+ '<input id="sc_payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="'+ pMethods[i].paymentMethod +'" />'
									+ '<span class=""></span>'
								+ '</div>';

						if (pMethods[i].paymentMethod == 'cc_card' || pMethods[i].paymentMethod == 'dc_card') {
							html_apms +=
								'<div class="apm_fields" id="sc_'+ pMethods[i].paymentMethod +'">'
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
						} else {
							html_apms +=
								'<div class="apm_fields">';
						
							// create fields for the APM
							if (pMethods[i].fields.length > 0) {
								for (var j in pMethods[i].fields) {
									var pattern = '';
									try {
										pattern = pMethods[i].fields[j].regex;
										if (pattern === undefined) {
											pattern = '';
										}
									} catch (e) {
									}

									var placeholder = '';
									try {
										if (typeof pMethods[i].fields[j].caption[0] == 'undefined') {
											placeholder = pMethods[i].fields[j].name;
											placeholder = placeholder.replace(/_/g, ' ');
										} else {
											placeholder = pMethods[i].fields[j].caption[0].message;
										}
									} catch (e) {
										placeholder = '';
									}

									var fieldErrorMsg = '';
									try {
										fieldErrorMsg = pMethods[i].fields[j].validationmessage[0].message;
										if (fieldErrorMsg === undefined) {
											fieldErrorMsg = '';
										}
									} catch (e) {
									}

									html_apms +=
											'<div class="apm_field">'
												+'<input id="'+ pMethods[i].paymentMethod +'_'+ pMethods[i].fields[j].name 
													+'" name="'+ pMethods[i].paymentMethod +'['+ pMethods[i].fields[j].name 
													+']" type="'+ pMethods[i].fields[j].type 
													+'" pattern="'+ pattern 
													+ '" placeholder="'+ placeholder 
													+'" autocomplete="new-password" '
													+ ('' == fieldErrorMsg ? 'style="width: 100%;"' : '')
													+' />';

									if ('' != fieldErrorMsg) {
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
					
					jQuery('#custom_loader').hide();
				}
				// show some error
				else if (resp.status == 0) {
					jQuery('form.woocommerce-checkout').prepend(
						'<ul class="woocommerce-error" role="alert">'
							+'<li><strong>'+ scTrans.proccessError +'</strong></li>'
						+'</ul>'
					);
			
					window.location.hash = '#main';
					jQuery('#custom_loader').hide();
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
	// apend APMs holder
	if (jQuery('form.woocommerce-checkout').find('#sc_apms_list').length == 0) {
		jQuery('div.payment_method_sc').append(
			'<b>'+ scTrans.chooseAPM +':</b><ul id="sc_apms_list"></div>');
	} else {
		// remove old apms
		jQuery('#sc_apms_list').html('');
	}
	
	// insert the html
	jQuery('#sc_apms_list')
		.append(apms)
		.promise()
		.done(function(){
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

jQuery(function() {
	// Prepare REST payment
	if (jQuery('#custom_loader_2').length == 0) {
		jQuery('.wc_payment_methods ').append(
			'<div style="width: 100%; height: 100%;position: absolute; top: 0px;opacity:'
			+' 0.7; z-index: 3; background: white;"><div id="custom_loader_2" class="blockUI blockOverlay"></div></div>');
	} else {
		jQuery('#custom_loader_2').parent('div').show();
	}
	
	if (jQuery('#sc_apms_list').length == 0) {
		jQuery('#place_order').prop('disabled', true);
		getAPMs();
	}

	jQuery('#custom_loader_2').parent('div').hide();
	// Prepare REST payment END
	
	jQuery('#payment').append('<div id="custom_loader" class="blockUI"></div>');
	
	billing_country_first_val = jQuery("#billing_country").val();
	
	// if user change the billing country get new payment methods
	jQuery("#billing_country ,#billing_email").on('change', function() {
		getAPMs();
	});

	// when click on APM payment method
	jQuery('form.woocommerce-checkout').on('click', '.apm_title', function() {
		// hide all check marks 
		jQuery('#sc_apms_list, #sc_upos_list').find('.apm_title span').removeClass('apm_selected');
		
		// hide all containers with fields
		jQuery('#sc_apms_list, #sc_upos_list').find('.apm_fields').each(function(){
			var self = jQuery(this);
			if (self.css('display') == 'block') {
				self.slideToggle('slow');
			}
		});
		
		// mark current payment method
		jQuery(this).find('span').addClass('apm_selected');
		
		// hide bottom border of apm_fields if the container is empty
		if (jQuery(this).parent('li').find('.apm_fields').html() == '') {
			jQuery(this).parent('li').find('.apm_fields').css('border-bottom', 0);
		}
		// expand payment fields
		if (jQuery(this).parent('li').find('.apm_fields').css('display') == 'none') {
			jQuery(this).parent('li').find('.apm_fields').slideToggle('slow');
		}
		
		// unchck SC payment methods
		jQuery('form.woocommerce-checkout').find('sc_payment_method_field').attr('checked', false);
		// check current radio
		jQuery(this).find('input').attr('checked', true);
		
		// hide errors
		jQuery('.apm_error').hide();
	});
});
// document ready function END

/**
 * Try to catch the changes in Order total, then create new Open Order request
 */
jQuery(document).ajaxStop(function() {
	if (
		jQuery('#billing_country').length > 0
		&& jQuery('#billing_email').length > 0
		&& !jQuery('#billing_country').closest('p').hasClass('woocommerce-invalid')
		&& !jQuery('#billing_email').closest('p').hasClass('woocommerce-invalid')
	) {
		var orderTotal = scGetOrderTotal();
		console.log('after ajax order total is: ', orderTotal)
		
		if ('' != orderTotal && orderTotal != scOrderAmount) {
			console.log('after ajax call getAPMs()')
			getAPMs();
		}
	}
});
