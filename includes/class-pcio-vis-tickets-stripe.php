<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Thin Stripe REST client (no SDK) used by the Tickets extension.
 *
 * All calls go through wp_remote_* with the secret key in the Authorization
 * header. The secret key is read from the admin settings and never leaves the
 * server. Amounts are always in minor units (øre/cents).
 */
class PCIO_VIS_Tickets_Stripe {

    const API_BASE = 'https://api.stripe.com/v1';

    /**
     * Create a hosted Stripe Checkout Session for an order.
     *
     * @param array $args {
     *   @type string $order_no     Human order number (client_reference_id).
     *   @type int    $order_id     Internal order id (metadata).
     *   @type string $token        Order token (metadata + return URLs).
     *   @type string $email        Buyer email (prefilled).
     *   @type string $currency     ISO-4217 (e.g. DKK).
     *   @type string $success_url  Redirect on success.
     *   @type string $cancel_url   Redirect on cancel.
     *   @type array  $line_items   List of [name, unit_amount_cents, qty].
     * }
     * @return array|WP_Error  Decoded session ['id','url',…] or WP_Error.
     */
    public static function create_checkout_session( array $args ) {
        $secret = PCIO_VIS_Tickets_Admin::stripe_secret_key();
        if ( '' === $secret ) {
            return new WP_Error( 'pcio_met_no_key', __( 'Stripe is not configured.', 'pcio-vis-tickets' ) );
        }

        $currency   = strtolower( $args['currency'] ?? 'dkk' );
        $line_items = [];
        foreach ( (array) ( $args['line_items'] ?? [] ) as $li ) {
            $line_items[] = [
                'quantity'   => max( 1, (int) ( $li['qty'] ?? 1 ) ),
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => max( 0, (int) ( $li['unit_amount_cents'] ?? 0 ) ),
                    'product_data' => [
                        'name' => (string) ( $li['name'] ?? __( 'Ticket', 'pcio-vis-tickets' ) ),
                    ],
                ],
            ];
        }

        $body = [
            'mode'                => 'payment',
            'success_url'         => (string) ( $args['success_url'] ?? '' ),
            'cancel_url'          => (string) ( $args['cancel_url'] ?? '' ),
            'client_reference_id' => (string) ( $args['order_no'] ?? '' ),
            'line_items'          => $line_items,
            'metadata'            => [
                'order_id' => (string) ( $args['order_id'] ?? '' ),
                'token'    => (string) ( $args['token'] ?? '' ),
            ],
        ];

        $email = sanitize_email( $args['email'] ?? '' );
        if ( $email ) {
            $body['customer_email'] = $email;
        }

        return self::post( '/checkout/sessions', $body );
    }

    /**
     * Retrieve a Checkout Session (used to reconcile on the return page).
     *
     * @return array|WP_Error
     */
    public static function get_checkout_session( string $session_id ) {
        return self::get( '/checkout/sessions/' . rawurlencode( $session_id ) );
    }

    /**
     * Refund a payment intent (full or partial). Phase 6.
     *
     * @param string $payment_intent
     * @param int    $amount_cents 0 = full refund.
     * @return array|WP_Error
     */
    public static function create_refund( string $payment_intent, int $amount_cents = 0 ) {
        $body = [ 'payment_intent' => $payment_intent ];
        if ( $amount_cents > 0 ) {
            $body['amount'] = $amount_cents;
        }
        return self::post( '/refunds', $body );
    }

    /**
     * Pay out an available Stripe balance to the connected bank account
     * (port of the legacy Fjordglimt event-report payout).
     *
     * @param int    $amount_cents Amount in minor units (must be > 0).
     * @param string $currency     ISO-4217 (e.g. DKK).
     * @param array  $metadata     Additional metadata (internal reference).
     * @return array|WP_Error Payout object ['id','amount','status','arrival_date','balance_transaction',…].
     */
    public static function create_payout( int $amount_cents, string $currency, array $metadata = [] ) {
        if ( $amount_cents <= 0 ) {
            return new WP_Error( 'pcio_met_payout_amount', __( 'Payout amount must be positive.', 'pcio-vis-tickets' ) );
        }
        $body = [
            'amount'   => $amount_cents,
            'currency' => strtolower( $currency ),
        ];
        foreach ( $metadata as $key => $value ) {
            $body[ 'metadata[' . $key . ']' ] = (string) $value;
        }
        return self::post( '/payouts', $body );
    }

    /**
     * Retrieve a payout (used to refresh pending/in_transit status).
     *
     * @return array|WP_Error
     */
    public static function get_payout( string $payout_id, ?string $mode = null ) {
        return self::get( '/payouts/' . rawurlencode( $payout_id ), $mode );
    }

    /**
     * Current balance of the account. The response contains 'available' and
     * 'pending' arrays of [ 'amount' => minor units, 'currency' => ISO ].
     *
     * @return array|WP_Error
     */
    public static function get_balance() {
        return self::get( '/balance' );
    }

    /**
     * Fee transactions for a payment intent (exact Stripe processing cost).
     *
     * @return array|WP_Error { 'data' => [ [ 'fee' => int ], … ] }
     */
    public static function balance_transactions( string $payment_intent, ?string $mode = null ) {
        return self::get( '/balance_transactions?payment_intent=' . rawurlencode( $payment_intent ) . '&limit=100', $mode );
    }

    /**
     * Available balance for one currency (minor units) or null when unknown.
     */
    public static function available_balance_for( string $currency ): ?int {
        $balance = self::get_balance();
        if ( is_wp_error( $balance ) || ! isset( $balance['available'] ) || ! is_array( $balance['available'] ) ) {
            return null;
        }
        $currency = strtolower( $currency );
        foreach ( $balance['available'] as $entry ) {
            if ( isset( $entry['currency'] ) && strtolower( (string) $entry['currency'] ) === $currency ) {
                return (int) $entry['amount'];
            }
        }
        return null;
    }

    // ── Transport ─────────────────────────────────────────────────

    /**
     * @return array|WP_Error
     */
    private static function post( string $path, array $body ) {
        $secret = PCIO_VIS_Tickets_Admin::stripe_secret_key();
        if ( '' === $secret ) {
            return new WP_Error( 'pcio_met_no_key', __( 'Stripe is not configured.', 'pcio-vis-tickets' ) );
        }
        $resp = wp_remote_post( self::API_BASE . $path, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => $body,
        ] );
        return self::handle( $resp );
    }

    /**
     * @return array|WP_Error
     */
    private static function get( string $path, ?string $mode = null ) {
        $secret = null !== $mode
            ? PCIO_VIS_Tickets_Admin::stripe_secret_key_for( $mode )
            : PCIO_VIS_Tickets_Admin::stripe_secret_key();
        if ( '' === $secret ) {
            return new WP_Error( 'pcio_met_no_key', __( 'Stripe is not configured.', 'pcio-vis-tickets' ) );
        }
        $resp = wp_remote_get( self::API_BASE . $path, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
            ],
        ] );
        return self::handle( $resp );
    }

    /**
     * @param array|WP_Error $resp
     * @return array|WP_Error
     */
    private static function handle( $resp ) {
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = isset( $json['error']['message'] )
                ? (string) $json['error']['message']
                : sprintf( /* translators: %d: HTTP status code */ __( 'Stripe error (HTTP %d).', 'pcio-vis-tickets' ), $code );
            return new WP_Error( 'pcio_met_stripe_http', $msg, [ 'status' => $code ] );
        }
        return is_array( $json ) ? $json : [];
    }
}
