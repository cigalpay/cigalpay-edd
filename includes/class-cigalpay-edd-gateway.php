<?php
/**
 * CigalPay gateway for Easy Digital Downloads: sends the customer to the
 * CigalPay hosted checkout and completes the order from CigalPay's signed
 * payment notification.
 */

defined( 'ABSPATH' ) || exit;

final class CigalPay_EDD_Gateway {

	const ID = 'cigalpay';

	/** CigalPay prices orders in these currencies. */
	const CURRENCIES = array( 'USD', 'EUR' );

	/** CigalPay's largest payment, in the shop currency (USD or EUR). */
	const MAX_AMOUNT = 5000;
	const LANGS      = array( 'en', 'fr', 'es', 'de' );

	/** Order meta: checkouts created for the order (one per attempt), the latest link, payment statuses already handled, CigalPay refunds. */
	const META_CHECKOUTS = '_cigalpay_checkout_ids';
	const META_LINK      = '_cigalpay_checkout_url';
	const META_SEEN      = '_cigalpay_seen';
	const META_REFUNDS   = '_cigalpay_refund_ids';

	public static function init() {
		add_filter( 'edd_payment_gateways', array( __CLASS__, 'register' ) );
		add_filter( 'edd_enabled_payment_gateways', array( __CLASS__, 'hide_when_unavailable' ) );
		add_action( 'edd_' . self::ID . '_cc_form', array( __CLASS__, 'checkout_description' ) );
		add_action( 'edd_gateway_' . self::ID, array( __CLASS__, 'process_purchase' ) );
		add_action( 'init', array( __CLASS__, 'listen' ) );
		add_filter( 'edd_payment_confirm_' . self::ID, array( __CLASS__, 'confirmation_page' ) );
		add_action( 'edd_after_submit_refund_table', array( __CLASS__, 'refund_checkbox' ) );
		add_action( 'edd_refund_order', array( __CLASS__, 'maybe_refund' ), 10, 3 );

		// Settings: Downloads → Settings → Extensions → CigalPay (EDD's guidelines for third-party extensions).
		add_filter( 'edd_settings_sections_extensions', array( __CLASS__, 'settings_section' ) );
		add_filter( 'edd_settings_extensions', array( __CLASS__, 'settings' ) );
	}

	// ── Registration and settings ─────────────────────────────────────────

	public static function register( $gateways ) {
		$gateways[ self::ID ] = array(
			'admin_label'    => 'CigalPay',
			'checkout_label' => self::option( 'checkout_label', __( 'Crypto (Bitcoin, USDT, Ethereum…)', 'cigalpay-for-easy-digital-downloads' ) ),
			'supports'       => array(),
		);
		return $gateways;
	}

	public static function settings_section( $sections ) {
		$sections[ self::ID ] = __( 'CigalPay', 'cigalpay-for-easy-digital-downloads' );
		return $sections;
	}

	public static function settings( $settings ) {
		$fields = array(
			'cigalpay_header'         => array(
				'id'   => 'cigalpay_header',
				'name' => '<strong>' . esc_html__( 'CigalPay crypto payments', 'cigalpay-for-easy-digital-downloads' ) . '</strong>',
				'desc' => '',
				'type' => 'header',
			),
			'cigalpay_intro'          => array(
				'id'   => 'cigalpay_intro',
				'name' => __( 'Setup', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => self::setup_text(),
				'type' => 'descriptive_text',
			),
			'cigalpay_account_id'     => array(
				'id'   => 'cigalpay_account_id',
				'name' => __( 'Account ID', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => __( 'CigalPay dashboard → Settings → API credentials.', 'cigalpay-for-easy-digital-downloads' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'cigalpay_ipn_secret'     => array(
				'id'   => 'cigalpay_ipn_secret',
				'name' => __( 'Notification signing secret', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => __( 'Same page. Used to check that payment notifications really come from CigalPay.', 'cigalpay-for-easy-digital-downloads' ),
				'type' => 'password',
				'size' => 'regular',
			),
			'cigalpay_api_key'        => array(
				'id'   => 'cigalpay_api_key',
				'name' => __( 'API key', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => __( 'Same page. Only needed to refund orders in crypto from Easy Digital Downloads.', 'cigalpay-for-easy-digital-downloads' ),
				'type' => 'password',
				'size' => 'regular',
			),
			'cigalpay_checkout_label' => array(
				'id'   => 'cigalpay_checkout_label',
				'name' => __( 'Checkout label', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => __( 'What customers see at checkout.', 'cigalpay-for-easy-digital-downloads' ),
				'type' => 'text',
				'size' => 'regular',
				'std'         => __( 'Crypto (Bitcoin, USDT, Ethereum…)', 'cigalpay-for-easy-digital-downloads' ),
				// Show the default instead of an empty field.
				'allow_blank' => false,
			),
			'cigalpay_description'    => array(
				'id'   => 'cigalpay_description',
				'name' => __( 'Description', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => __( 'Shown at checkout when the customer picks CigalPay.', 'cigalpay-for-easy-digital-downloads' ),
				'type' => 'textarea',
				'std'  => __( 'Pay with Bitcoin, USDT, Ethereum, Solana and more. You choose the coin on the next page.', 'cigalpay-for-easy-digital-downloads' ),
			),
			'cigalpay_debug'          => array(
				'id'   => 'cigalpay_debug',
				'name' => __( 'Debug log', 'cigalpay-for-easy-digital-downloads' ),
				'desc' => __( 'Log every notification in the Easy Digital Downloads debug log (Downloads → Tools → Debug Log). Errors are always logged.', 'cigalpay-for-easy-digital-downloads' ),
				'type' => 'checkbox',
			),
		);
		// EDD 2.5+ groups extension settings by section.
		$settings[ self::ID ] = $fields;
		return $settings;
	}

	protected static function setup_text() {
		$text = esc_html__( 'Enable CigalPay in Downloads → Settings → Payments, then enter your credentials below. Payment notifications are sent to this address automatically, nothing to set up:', 'cigalpay-for-easy-digital-downloads' )
			. ' <code>' . esc_html( self::notify_url() ) . '</code>';
		if ( ! in_array( edd_get_currency(), self::CURRENCIES, true ) ) {
			$text .= '<br><strong>' . esc_html(
				sprintf(
					/* translators: %s: shop currency code */
					__( 'CigalPay prices orders in USD or EUR. Your shop currency is %s, so CigalPay is hidden at checkout.', 'cigalpay-for-easy-digital-downloads' ),
					edd_get_currency()
				)
			) . '</strong>';
		}
		return $text;
	}

	/** CigalPay is offered only when it can take the order. */
	public static function hide_when_unavailable( $gateways ) {
		if ( ! isset( $gateways[ self::ID ] ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $gateways;
		}
		$ready = '' !== self::option( 'account_id' )
			&& '' !== self::option( 'ipn_secret' )
			&& in_array( edd_get_currency(), self::CURRENCIES, true )
			// Hidden above CigalPay's limit, instead of failing after the customer picks it.
			&& ( ! function_exists( 'edd_get_cart_total' ) || (float) edd_get_cart_total() <= self::MAX_AMOUNT );
		if ( ! $ready ) {
			unset( $gateways[ self::ID ] );
		}
		return $gateways;
	}

	/** Instead of a card form: what happens next. */
	public static function checkout_description() {
		$text = self::option( 'description', __( 'Pay with Bitcoin, USDT, Ethereum, Solana and more. You choose the coin on the next page.', 'cigalpay-for-easy-digital-downloads' ) );
		if ( '' !== $text ) {
			echo '<div class="edd-cigalpay-description"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	// ── Purchase ───────────────────────────────────────────────────────────

	/** @param array $purchase_data from EDD's checkout */
	public static function process_purchase( $purchase_data ) {
		if ( empty( $purchase_data['gateway_nonce'] ) || ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( esc_html__( 'Nonce verification has failed', 'cigalpay-for-easy-digital-downloads' ), esc_html__( 'Error', 'cigalpay-for-easy-digital-downloads' ), array( 'response' => 403 ) );
		}
		$back = '?payment-mode=' . self::ID;

		$amount = round( (float) $purchase_data['price'], 2 );
		if ( $amount <= 0 || $amount > self::MAX_AMOUNT || ! in_array( edd_get_currency(), self::CURRENCIES, true ) ) {
			edd_set_error( 'cigalpay_unavailable', __( 'Crypto payment is not available for this order. Please choose another payment method.', 'cigalpay-for-easy-digital-downloads' ) );
			edd_send_back_to_checkout( $back );
			return;
		}

		$order_data = array(
			'price'        => $purchase_data['price'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'downloads'    => $purchase_data['downloads'],
			'user_info'    => $purchase_data['user_info'],
			'cart_details' => $purchase_data['cart_details'],
			'gateway'      => self::ID,
			'status'       => 'pending',
		);
		if ( ! empty( $purchase_data['date'] ) ) {
			$order_data['date'] = $purchase_data['date'];
		}
		$order_id = edd_build_order( $order_data );
		if ( ! $order_id ) {
			edd_record_gateway_error( 'CigalPay', 'The order could not be created before sending the customer to CigalPay.' );
			edd_set_error( 'cigalpay_order', __( 'Your order could not be created. Please try again.', 'cigalpay-for-easy-digital-downloads' ) );
			edd_send_back_to_checkout( $back );
			return;
		}
		// EDD 3.7.2+: an order whose discount uses could not be claimed is failed before payment.
		if ( class_exists( '\EDD\Discounts\OrderValidation' ) && \EDD\Discounts\OrderValidation::refuse_unclaimed( $order_id ) ) {
			edd_send_back_to_checkout( $back );
			return;
		}
		EDD()->session->set( 'edd_resume_payment', $order_id );

		$order    = edd_get_order( $order_id );
		$checkout = CigalPay_EDD_API::create_checkout(
			array(
				'accountId'   => self::option( 'account_id' ),
				'amount'      => $amount,
				'currency'    => edd_get_currency(),
				'extraId'     => (string) $order_id,
				'description' => self::order_description( $order ),
				'lang'        => self::lang(),
				'callback'    => self::return_url( $order ),
				'ipn'         => self::notify_url(),
			)
		);
		if ( is_wp_error( $checkout ) ) {
			self::log( 'error', sprintf( 'Order %d: checkout not created: %s', $order_id, $checkout->get_error_message() ) );
			edd_record_gateway_error( 'CigalPay', sprintf( 'Checkout not created: %s', $checkout->get_error_message() ), $order_id );
			edd_update_order_status( $order_id, 'failed' );
			edd_set_error( 'cigalpay_checkout', __( 'Crypto payment is unavailable right now. Please try again in a moment or choose another payment method.', 'cigalpay-for-easy-digital-downloads' ) );
			edd_send_back_to_checkout( $back );
			return;
		}

		// Every checkout made for this order is accepted: a customer can come back and pay an earlier one.
		$ids   = self::meta_list( $order_id, self::META_CHECKOUTS );
		$ids[] = (string) $checkout['id'];
		edd_update_order_meta( $order_id, self::META_CHECKOUTS, array_values( array_unique( $ids ) ) );
		edd_update_order_meta( $order_id, self::META_LINK, (string) $checkout['chargeLink'] );

		wp_redirect( $checkout['chargeLink'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the CigalPay checkout CigalPay's API just returned.
		exit;
	}

	// ── Payment notification ───────────────────────────────────────────────

	/** CigalPay's payment notification: POST /index.php?edd-listener=cigalpay */
	public static function listen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a server-to-server notification, authenticated by its signature below.
		if ( ! isset( $_GET['edd-listener'] ) || self::ID !== sanitize_key( wp_unslash( $_GET['edd-listener'] ) ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			exit;
		}
		$raw = file_get_contents( 'php://input' );
		$sig = isset( $_SERVER['HTTP_X_CIGALPAY_SIG'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CIGALPAY_SIG'] ) ) : '';

		if ( ! CigalPay_EDD_API::verify_notification( $raw, $sig, self::option( 'ipn_secret' ) ) ) {
			self::log( 'error', 'Notification rejected: bad or missing signature. Check the notification signing secret in Downloads → Settings → Extensions → CigalPay.' );
			status_header( 401 );
			exit;
		}
		$notification = json_decode( $raw, true );
		if ( ! is_array( $notification ) ) {
			status_header( 400 );
			exit;
		}
		self::apply_notification( $notification );
		status_header( 200 );
		exit;
	}

	/**
	 * Trusted only when it is for a checkout this shop created for that
	 * order, at that order's price.
	 *
	 * @param array $p a verified notification
	 */
	public static function apply_notification( array $p ) {
		$payment_id = isset( $p['id'] ) ? (string) $p['id'] : '';
		$status     = isset( $p['status'] ) ? (string) $p['status'] : '';
		$order      = isset( $p['extraId'] ) && ctype_digit( (string) $p['extraId'] ) ? edd_get_order( (int) $p['extraId'] ) : false;

		self::log( 'info', sprintf( 'Notification: payment %s, order %s, status %s', $payment_id, isset( $p['extraId'] ) ? $p['extraId'] : '-', $status ) );

		if ( ! $order || self::ID !== $order->gateway ) {
			self::log( 'info', 'Ignored: no CigalPay order with this reference.' );
			return;
		}

		$checkout_id = isset( $p['checkoutId'] ) ? (string) $p['checkoutId'] : '';
		if ( '' === $checkout_id || ! in_array( $checkout_id, self::meta_list( $order->id, self::META_CHECKOUTS ), true ) ) {
			// Anyone can create a checkout with this shop's account and an order number; only the checkouts this shop made count.
			self::log( 'error', sprintf( 'Order %d: ignored payment %s, it is not for a checkout this shop created for the order.', $order->id, $payment_id ) );
			return;
		}

		// Each payment status is handled once (notifications can repeat).
		$seen = self::meta_list( $order->id, self::META_SEEN );
		$key  = $payment_id . ':' . $status;
		if ( in_array( $key, $seen, true ) ) {
			return;
		}
		$seen[] = $key;
		edd_update_order_meta( $order->id, self::META_SEEN, array_slice( $seen, -50 ) );

		$coin = self::format_coin( isset( $p['amount'] ) ? $p['amount'] : '', $p );

		if ( CigalPay_EDD_API::is_paid( $status ) ) {
			self::on_paid( $order, $p, $coin );
			return;
		}
		if ( self::is_complete( $order ) ) {
			return;
		}

		switch ( $status ) {
			case CigalPay_EDD_API::STATUS_WAITING:
				/* translators: %s: amount and coin, e.g. 0.0012 BTC */
				self::note( $order->id, sprintf( __( 'CigalPay: customer chose to pay %s.', 'cigalpay-for-easy-digital-downloads' ), $coin ) );
				break;
			case CigalPay_EDD_API::STATUS_CONFIRMING:
				edd_update_order_status( $order->id, 'processing' );
				/* translators: %s: amount and coin */
				self::note( $order->id, sprintf( __( 'CigalPay: payment of %s detected, waiting for network confirmations.', 'cigalpay-for-easy-digital-downloads' ), $coin ) );
				break;
			case CigalPay_EDD_API::STATUS_PARTIALLY_PAID:
				edd_update_order_status( $order->id, 'on_hold' );
				self::note(
					$order->id,
					sprintf(
						/* translators: 1: amount received, 2: amount expected (with coin) */
						__( 'CigalPay: the customer sent only %1$s of %2$s. Contact them before giving access.', 'cigalpay-for-easy-digital-downloads' ),
						self::format_coin( isset( $p['amountPaid'] ) ? $p['amountPaid'] : 0, $p ),
						$coin
					)
				);
				break;
			case CigalPay_EDD_API::STATUS_EXPIRED:
			case CigalPay_EDD_API::STATUS_FAILED:
				/* translators: %s: amount and coin */
				self::note( $order->id, sprintf( __( 'CigalPay: the payment of %s expired without being paid.', 'cigalpay-for-easy-digital-downloads' ), $coin ) );
				break;
		}
	}

	protected static function on_paid( $order, array $p, $coin ) {
		$payment_id = (string) $p['id'];

		if ( self::is_complete( $order ) ) {
			if ( (string) $order->get_transaction_id() !== $payment_id ) {
				/* translators: 1: amount and coin, 2: payment id */
				self::note( $order->id, sprintf( __( 'CigalPay: a second payment of %1$s was received for this already paid order (payment %2$s). Refund the customer if it was a mistake.', 'cigalpay-for-easy-digital-downloads' ), $coin, $payment_id ) );
			}
			return;
		}

		$paid_fiat = isset( $p['checkoutAmount'] ) ? (float) $p['checkoutAmount'] : -1;
		$paid_ccy  = isset( $p['checkoutCurrency'] ) ? strtoupper( (string) $p['checkoutCurrency'] ) : '';
		if ( strtoupper( (string) $order->currency ) !== $paid_ccy || abs( $paid_fiat - (float) $order->total ) > 0.01 ) {
			edd_update_order_status( $order->id, 'on_hold' );
			self::note(
				$order->id,
				sprintf(
					/* translators: 1: amount paid, 2: order total */
					__( 'CigalPay: payment received for %1$s, but the order total is %2$s. Check before giving access.', 'cigalpay-for-easy-digital-downloads' ),
					edd_currency_filter( edd_format_amount( $paid_fiat ), $paid_ccy ),
					edd_currency_filter( edd_format_amount( $order->total ), $order->currency )
				)
			);
			return;
		}

		/* translators: 1: amount and coin, 2: payment id */
		$note = sprintf( __( 'CigalPay: paid %1$s (payment %2$s).', 'cigalpay-for-easy-digital-downloads' ), $coin, $payment_id );
		if ( ! empty( $p['swapFromCurrency'] ) ) {
			/* translators: 1: amount, 2: coin */
			$note .= ' ' . sprintf( __( 'The customer paid in %1$s %2$s, converted automatically.', 'cigalpay-for-easy-digital-downloads' ), isset( $p['swapFromAmount'] ) ? $p['swapFromAmount'] : '', $p['swapFromCurrency'] );
		}
		self::note( $order->id, $note );
		edd_set_payment_transaction_id( $order->id, $payment_id );
		// Gives access to the files and sends the purchase receipt, as for any completed order.
		edd_update_order_status( $order->id, 'complete' );
	}

	// ── Back from the CigalPay checkout ────────────────────────────────────

	/**
	 * The confirmation page (?payment-confirmation=cigalpay): EDD's
	 * "processing" screen until the notification completes the order, then
	 * the receipt.
	 */
	public static function confirmation_page( $content ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order is checked against the receipt hash or the purchase session.
		$order_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : 0;
		$hash     = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable
		$order = $order_id ? edd_get_order( $order_id ) : false;
		if ( ! $order || self::ID !== $order->gateway ) {
			return $content;
		}
		$session         = edd_get_purchase_session();
		$session_matches = is_array( $session ) && ! empty( $session['purchase_key'] ) && hash_equals( (string) $order->payment_key, (string) $session['purchase_key'] );
		if ( ! $session_matches && ( '' === $hash || ! $order->is_receipt_hash_valid( $hash ) ) ) {
			return $content;
		}
		if ( ! $session_matches ) {
			edd_set_purchase_session( array( 'purchase_key' => $order->payment_key ) );
		}
		// Back from CigalPay: the cart has been bought (kept until now in case the customer gave up).
		edd_empty_cart();
		if ( in_array( $order->status, array( 'pending', 'processing' ), true ) ) {
			ob_start();
			edd_get_template_part( 'payment', 'processing' );
			$link = edd_get_order_meta( $order->id, self::META_LINK, true );
			if ( $link && 'pending' === $order->status ) {
				echo '<p><a class="button" href="' . esc_url( $link ) . '">' . esc_html__( 'Not paid yet? Go back to the payment page', 'cigalpay-for-easy-digital-downloads' ) . '</a></p>';
			}
			return ob_get_clean();
		}
		edd_redirect( edd_get_success_page_uri() );
	}

	// ── Refunds ────────────────────────────────────────────────────────────

	/** "Refund in crypto via CigalPay", in EDD's refund window, once the API key is set. */
	public static function refund_checkbox( $order ) {
		if ( ! $order || self::ID !== $order->gateway || '' === self::option( 'api_key' ) || ! $order->get_transaction_id() ) {
			return;
		}
		?>
		<div class="edd-form-group edd-cigalpay-refund-transaction">
			<div class="edd-form-group__control">
				<input type="checkbox" id="edd-cigalpay-refund" name="edd-cigalpay-refund" class="edd-form-group__input" value="1">
				<label for="edd-cigalpay-refund" class="edd-form-group__label">
					<?php esc_html_e( 'Refund in crypto via CigalPay (the customer gets an email to enter their address)', 'cigalpay-for-easy-digital-downloads' ); ?>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Refunds in the coin the customer paid, from the CigalPay balance. The
	 * customer gets an email with a link to enter their address; CigalPay
	 * sends the coin once the refund is reviewed.
	 *
	 * @param int  $order_id
	 * @param int  $refund_id the EDD refund record
	 * @param bool $all_refunded
	 */
	public static function maybe_refund( $order_id, $refund_id, $all_refunded ) {
		if ( ! current_user_can( 'edit_shop_payments', $order_id ) || empty( $_POST['data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD checked the refund nonce before running edd_refund_order.
			return;
		}
		$order = edd_get_order( $order_id );
		if ( ! $order || self::ID !== $order->gateway ) {
			return;
		}
		parse_str( wp_unslash( $_POST['data'] ), $form ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above; only the checkbox below is read, as a boolean.
		if ( empty( $form['edd-cigalpay-refund'] ) ) {
			self::note( $order_id, __( 'CigalPay: not refunded in crypto, as the CigalPay box was not ticked.', 'cigalpay-for-easy-digital-downloads' ) );
			return;
		}
		$refund = edd_get_order( $refund_id );
		$amount = $refund ? round( abs( (float) $refund->total ), 2 ) : 0;
		if ( $amount <= 0 ) {
			return;
		}

		$body = array(
			'paymentId'      => (string) $order->get_transaction_id(),
			'checkoutAmount' => $amount,
			/* translators: %s: order number */
			'reason'         => sprintf( __( 'Refund of order %s', 'cigalpay-for-easy-digital-downloads' ), $order->get_number() ),
		);
		if ( is_email( $order->email ) ) {
			$body['customerEmail'] = $order->email;
		}
		$res = CigalPay_EDD_API::create_refund( self::option( 'api_key' ), $body );
		if ( is_wp_error( $res ) ) {
			self::log( 'error', sprintf( 'Order %d: refund of %s refused: %s', $order_id, $amount, $res->get_error_message() ) );
			/* translators: %s: CigalPay's reason */
			self::note( $order_id, sprintf( __( 'CigalPay refused the crypto refund: %s. Nothing was sent to the customer.', 'cigalpay-for-easy-digital-downloads' ), $res->get_error_message() ) );
			return;
		}

		$ids   = self::meta_list( $order_id, self::META_REFUNDS );
		$ids[] = (string) $res['refund']['id'];
		edd_update_order_meta( $order_id, self::META_REFUNDS, $ids );
		$note = sprintf(
			/* translators: 1: amount in the shop currency, 2: amount and coin, 3: the customer's link */
			__( 'CigalPay: refund of %1$s started (%2$s). The customer got an email to enter their address: %3$s. CigalPay sends it once reviewed.', 'cigalpay-for-easy-digital-downloads' ),
			edd_currency_filter( edd_format_amount( $amount ), $order->currency ),
			self::format_coin( isset( $res['refund']['amount'] ) ? $res['refund']['amount'] : '', array( 'currency' => isset( $res['refund']['currency'] ) ? $res['refund']['currency'] : '' ) ),
			isset( $res['link'] ) ? esc_url_raw( $res['link'] ) : ''
		);
		self::note( $order_id, $note );
		self::note( $refund_id, $note );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/** A CigalPay setting (stored in EDD's settings as cigalpay_<key>), trimmed. */
	protected static function option( $key, $fallback = '' ) {
		$value = edd_get_option( 'cigalpay_' . $key, $fallback );
		return is_string( $value ) ? trim( $value ) : $fallback;
	}

	public static function notify_url() {
		return add_query_arg( 'edd-listener', self::ID, home_url( 'index.php' ) );
	}

	protected static function return_url( $order ) {
		return add_query_arg(
			array(
				'payment-confirmation' => self::ID,
				'payment-id'           => $order->id,
				'order'                => $order->get_receipt_hash(),
				'id'                   => $order->id,
			),
			edd_get_confirmation_page_uri()
		);
	}

	protected static function lang() {
		$lang = strtolower( substr( determine_locale(), 0, 2 ) );
		return in_array( $lang, self::LANGS, true ) ? $lang : 'en';
	}

	protected static function order_description( $order ) {
		/* translators: 1: order number, 2: site name */
		$text = sprintf( __( 'Order %1$s - %2$s', 'cigalpay-for-easy-digital-downloads' ), $order ? $order->get_number() : '', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
	}

	protected static function is_complete( $order ) {
		return in_array( $order->status, array( 'complete', 'refunded', 'partially_refunded', 'revoked' ), true );
	}

	/** A list kept in order meta. */
	protected static function meta_list( $order_id, $key ) {
		$value = edd_get_order_meta( $order_id, $key, true );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	protected static function note( $order_id, $text ) {
		edd_add_note(
			array(
				'object_id'   => $order_id,
				'object_type' => 'order',
				'content'     => $text,
			)
		);
	}

	protected static function format_coin( $amount, array $p ) {
		$coin = isset( $p['currency'] ) ? (string) $p['currency'] : '';
		$coin = 'USDT_TRX' === $coin ? 'USDT (TRC-20)' : str_replace( '_', ' ', $coin );
		return trim( $amount . ' ' . $coin );
	}

	protected static function log( $level, $message ) {
		if ( 'info' === $level && ! edd_get_option( 'cigalpay_debug', false ) ) {
			return;
		}
		edd_debug_log( 'CigalPay: ' . $message, 'error' === $level );
	}
}
