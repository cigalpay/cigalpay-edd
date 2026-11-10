# CigalPay for Easy Digital Downloads

Accept crypto payments (Bitcoin, USDT, Ethereum, Solana and more) for your digital products with
[Easy Digital Downloads](https://easydigitaldownloads.com). Prices stay in USD or EUR; customers pick the coin on the
CigalPay checkout, and the order is completed (receipt and download links) once the payment is confirmed on the network.

- Signed payment notifications, checked against the checkout the plugin created for the order and its price.
- Refund in crypto from the order page (needs the API key).
- Settings in Downloads → Settings → Extensions → CigalPay; enable the gateway in Downloads → Settings → Payments.
- Requires Easy Digital Downloads 3.1+, WordPress 6.7+, PHP 8.0+. Shop currency USD or EUR; up to 5,000 per order.

The WordPress.org listing text is in [`readme.txt`](readme.txt).

## Release

1. Bump the version in `cigalpay-for-easy-digital-downloads.php` (header and `CIGALPAY_EDD_VERSION`) and the
   `Stable tag` in `readme.txt`, and add a changelog entry.
2. Push a tag `vX.Y.Z`: GitHub Actions checks the versions and attaches `cigalpay-for-easy-digital-downloads.zip` to a
   GitHub release.
3. Publish to WordPress.org with SVN (trunk, then `tags/X.Y.Z`); directory assets are in `.wordpress-org/`.

## License

[MIT](LICENSE)
