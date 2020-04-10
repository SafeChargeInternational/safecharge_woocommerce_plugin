var scSettleBtn = null;
var scVoidBtn   = null;

// when the admin select to Settle or Void the Order
function settleAndCancelOrder(question, action, orderId) {
	console.log('settleAndCancelOrder')
	
	if (confirm(question)) {
		jQuery('#sc_void_btn, #sc_settle_btn').hide();
		jQuery('#custom_loader').show();
		
		var data = {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			orderId     : orderId
		};
		
		if (action == 'settle') {
			data.settleOrder = 1;
		} else if (action == 'void') {
			data.cancelOrder = 1;
		}
		
		jQuery.ajax({
			type: "POST",
			url: scTrans.ajaxurl,
			data: data,
			dataType: 'json'
		})
			.fail(function(){
				jQuery('#custom_loader').hide();
				alert('Response error.');
			})
			.done(function(resp) {
				if (resp && typeof resp.status != 'undefined' && resp.data != 'undefined') {
					if (resp.status == 1) {
						var urlParts    = window.location.toString().split('post.php');
						window.location = urlParts[0] + 'edit.php?post_type=shop_order';
					} else if (resp.data.reason != 'undefined' && resp.data.reason != '') {
						alert(resp.data.reason);
					} else if (resp.data.gwErrorReason != 'undefined' && resp.data.gwErrorReason != '') {
						alert(resp.data.gwErrorReason);
					} else {
						alert('Response error.');
					}
				} else {
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
	if (scVoidBtn !== null) {
		jQuery('.wc-order-bulk-actions p').append(scVoidBtn);
		scVoidBtn = null;
	}
	if (scSettleBtn !== null) {
		jQuery('.wc-order-bulk-actions p').append(scSettleBtn);
		scSettleBtn = null;
	}
}

jQuery(function() {
	// set the flags
	if (jQuery('#sc_settle_btn').length == 1) {
		scSettleBtn = jQuery('#sc_settle_btn');
	}
	
	if (jQuery('#sc_void_btn').length == 1) {
		scVoidBtn = jQuery('#sc_void_btn');
	}
	// set the flags END
	
	// hide Refund button if the status is refunded
	if (
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
