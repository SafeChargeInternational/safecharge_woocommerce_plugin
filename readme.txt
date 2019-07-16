=== SafeCharge Payments ===

Tags: credit card, safecharge, woocommerce
Wordpress requirements: 
	- minimum v4.7
	- tested up to v5.2.2
WooCommerce requirements: 
	- minimum v 3.0
	- tested up to v3.6.5
Stable tag: 1.9.3

== Description ==

SafeCharge offers major international credit and debit cards enabling you to accept payments from your global customers. 

A wide selection of region-specific payment methods can help your business grow in new markets. Other popular payment methods from mobile payments to eWallets, can be easily implemented at your checkout page.

Right payment methods at the checkout page can bring you global reach, help you increase conversions and create a seamless experience for your customers. 

= Automatic installation =

Please note, this gateway requires WooCommerce 3.0 and above.

To do an automatic install of, log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

Upload the provided archive and install it. As a final step you should activate the plugin. 

= Manual installation =

1. Backup your site completely before proceeding.
2. To install a WordPress Plugin manually:
3. Download your WordPress Plugin to your desktop.
4. If downloaded as a zip archive, install it from WordPress > Plugins > Add New. Go to point 8.
5. If the plugin is extracted or prefer this way - continue.
6. Read through the "readme" file thoroughly to ensure you follow the installation instructions.
7. With your FTP program, upload the Plugin folder (safecharge_woocommerce_plugin) to the wp-content/plugins folder in your WordPress directory online.
8. Go to Plugins screen and find the newly uploaded Plugin in the list.
9. Click Activate to activate it.

== Support ==

Please, contact out Tech-Support team (tech-support@safecharge.com) in case of questions and difficulties.

== Changelog ==

= 1.9.3 - 2019-07-03 =
* New - The parameter user_token_id will be filled only for registered users. Added "/" before the "?" in the Notify URL, if there is non. The Notify URL field in the settings is hidden. It will be visible as text now.

= 1.9.2 - 2019-06-07 =
* New - Added option for the merchant to create local Refund - without sending request to the CPanel, in case her created the refund first in CPanel. Removed the DMN listener for CPanel Refund. Enabled Refund Amount field for WC 3.6+.

= 1.9.1 - 2019-05-13 =
* New - Hide Refund and Void buttons when they no need to be visible. Better Order Notes. Some code fixes.

= 1.9 - 2019-03-26 =
* New - Better logic for add and remove SC hooks for the checkout. Hide SC buttons when edit order if, it not made via SC. Removed option to show or hide the loading message on Checkout page.
* Add - Added functionality to create Full Refund, when merchant change order status to Refunded.

= 1.8.2 - 2018-12-19 =
* New - Added option in the settings, to use Cashier in iFrame.

= 1.8.1 - 2018-11-28 =
* New - Option in Admin to rewrite DMN URL and redirect to new one. This helps when the user have 404 page problem with "+", " " and "%20" symbols in the URL. Support of WP WPML and WC WPML plugins. Button in Admin to delete oldest logs, but kept last 30 of them.
* Bug Fix - When get DMN from Void / Refund - change the status of the order.

= 1.8 - 2018-11-26 =
* New - Add Transaction Type in the backend with two options - Auth and Settle  / Sale, and all logic connected with this option.

= 1.7 - 2018-11-22 =
* New - Option to cancel the order using Void button.

= 1.6.2 - 2018-11-19 =
* New - The Merchant will have option to force HTTP for the Notification URL.

= 1.6.1 - 2018-11-16 =
* New - Added more checks in SC_REST_API Class to prevent unexpected errors, code cleaned. The class was changed to static. Added new file sc_ajax.php to catch the Ajax call from the JS file.

= 1.6 - 2018-11-14 =
* Add - Map variables according names convention in the REST API.

= 1.5.1 - 2018-11-13 =
* Add - The merchant have an option to enable or disable creating logs for the plugin's work.

= 1.5 - 2018-11-01 =
* Add - Added Tokenization for card payment methods.

= 1.4 - 2018-10-24 =
* Add - Independent SC_REST_API class from the main shopping system.

= 1.3.1 - 2018-10-17 =
* Add - Added SC Novo Mobile like theme for the APM fields.

= 1.3 - 2018-10-02 =
* Add - Work with REST API payments integration.

= 1.2 - 2018-09-27 =
* Add - Support for Refund.

= 1.1 - 2018-08-23 =
* Add - Support for Dynamic Pricing including tax calculation and discount.
