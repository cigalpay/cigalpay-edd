=== CigalPay for Easy Digital Downloads ===
Contributors: cigalpay
Tags: crypto, bitcoin, usdt, payment gateway, easy digital downloads
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept Bitcoin, USDT, Ethereum, Solana and more for your digital products. Prices stay in USD or EUR.

== Description ==

CigalPay adds a crypto payment option to your Easy Digital Downloads checkout.

* Your prices stay in USD or EUR. The customer picks the coin on the CigalPay checkout and sees the exact amount to send.
* Accepted coins: Bitcoin, USDT (TRC-20), Ethereum, Solana, Litecoin, Dogecoin, Dash, Bitcoin Cash, Polygon and TRX. Customers can also pay in many other coins, converted automatically.
* The order is completed once the payment is confirmed on the network: the customer gets their purchase receipt and download links, as for any completed order.
* Every payment notification is signed. The plugin also checks that the payment is for the checkout it created for that order, at the order's price.
* Refund from the order page: the customer gets back the coin they paid, from your CigalPay balance.

= How it works =

1. The customer chooses "Crypto" at checkout.
2. They're sent to the CigalPay checkout, pick a coin and pay.
3. CigalPay notifies your site, and the order is completed. While the network confirms, the customer sees Easy Digital Downloads' "processing" page.

= External service =

This plugin connects to CigalPay (https://cigalpay.com) to take payments. When a customer places an order with CigalPay, the plugin sends CigalPay:

* the order total and currency, the order number and the site name;
* the return address and the notification address of your site;
* the customer's language.

No customer names, emails or addresses are sent. CigalPay then sends payment status notifications back to your site.

When you refund an order in crypto with CigalPay, the plugin sends CigalPay the payment id, the amount, the order number and the customer's email, so CigalPay can email them the link to enter their refund address.

* Terms of service: https://cigalpay.com/terms-of-service
* Privacy policy: https://cigalpay.com/privacy-policy

== Installation ==

1. Install and activate the plugin. Easy Digital Downloads 3.1 or later must be active.
2. Create a free CigalPay account at https://cigalpay.com.
3. In the CigalPay dashboard, open Settings → API credentials. Copy your Account ID and your notification signing secret, and your API key to refund from Easy Digital Downloads.
4. In Downloads → Settings → Extensions → CigalPay, paste the values.
5. In Downloads → Settings → Payments, enable CigalPay.

Your shop currency must be USD or EUR. CigalPay is hidden at checkout for orders above 5,000 USD or EUR, the largest payment CigalPay accepts.

== Frequently Asked Questions ==

= Do I need to set up a webhook? =

No. The plugin sends its notification address with every payment.

= What if the customer sends too little? =

The order is put on hold with a note that shows the amount received, so you can contact the customer before giving access.

= Can I refund from Easy Digital Downloads? =

Yes. Add your API key in the plugin settings, then on the order page click Refund, tick "Refund in crypto via CigalPay" and confirm. The amount is converted into the coin the customer paid, at the payment's rate, and comes out of your CigalPay balance with the network fee. The customer gets an email to enter their address, and CigalPay sends the coin once the refund is reviewed. CigalPay's fee on the payment is not refunded. A payment forwarded to your own wallet isn't on your CigalPay balance: refund it from that wallet.

= Where are the logs? =

Downloads → Tools → Debug Log. Turn on "Debug log" in the plugin settings to log every notification. Errors are always logged, and failed checkouts also appear in Downloads → Tools → Logs → Payment Errors.

== Screenshots ==

1. CigalPay at checkout.
2. The CigalPay checkout: the customer picks a coin.
3. Plugin settings.

== Changelog ==

= 1.0.0 =
* First release.
