<div id="sc_checkout_messages"></div>
<div id="sc_second_step_form" style="display: none;">
	<style><?php echo trim($this->sc_get_setting('merchant_style')); ?></style>
	
	<input type="hidden" name="sc_transaction_id" id="sc_transaction_id" value="" />
	<input type="hidden" name="lst" id="lst" value="" />
	
	<div id="sc_loader_background">
		<img src="<?php echo $this->plugin_url; ?>icons/loader.gif" alt="loading..." />
	</div>

	<h3><?php echo __('Choose from yours preferred payment methods', 'nuvei');?></h3>
	<ul id="sc_upos_list"></ul>

	<h3><?php echo __('Choose from the payment options', 'nuvei'); ?></h3>
	<ul id="sc_apms_list"></ul>

	<button type="button" onclick="scValidateAPMFields()" class="button alt" name="woocommerce_checkout_place_order" value="<?php echo __('Pay'); ?>" data-value="<?php echo __('Pay'); ?>" data-default-text="Place order"><?php echo __('Pay'); ?></button>
	
	<script>
		var locale			= "<?php echo $this->formatLocation(get_locale()); ?>";
		scOrderCurr			= "<?php echo get_woocommerce_currency(); ?>";
		scMerchantId		= scData.merchantId = "<?php echo $this->sc_get_setting('merchantId'); ?>";
		scMerchantSiteId	= scData.merchantSiteId = "<?php echo $this->sc_get_setting('merchantSiteId'); ?>";

		<?php if ($this->sc_get_setting('test') == 'yes'): ?>
			scData.env = "test";
		<?php endif; ?>
		
		sfc = SafeCharge(scData);
		scFields = sfc.fields({ locale: locale });
	</script>
</div>

