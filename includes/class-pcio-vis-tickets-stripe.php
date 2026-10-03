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
    private static function get( string $path ) {
        $secret = PCIO_VIS_Tickets_Admin::stripe_secret_key();
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
