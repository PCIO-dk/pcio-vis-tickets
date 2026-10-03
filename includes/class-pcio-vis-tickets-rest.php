<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the Tickets extension (namespace pcio-vis/v1).
 *
 * Phase 2 — event editor "Tickets" tab
 * ────────────────────────────────────
 *   GET    /tickets/events/{eid}                 settings + types + capacity (manage)
 *   PUT    /tickets/events/{eid}/settings        update event ticket settings (manage)
 *   POST   /tickets/events/{eid}/types           create a ticket type (manage)
 *   PUT    /tickets/events/{eid}/types/{tid}     update a ticket type (manage)
 *   DELETE /tickets/events/{eid}/types/{tid}     delete a ticket type (manage)
 *
 * Public checkout, webhook and refund routes are added in later phases.
 */
class PCIO_VIS_Tickets_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/tickets/events/(?P<eid>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_event' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'eid' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/tickets/events/(?P<eid>\d+)/settings', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_settings' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'eid' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/tickets/events/(?P<eid>\d+)/types', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_type' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'eid' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/tickets/events/(?P<eid>\d+)/types/(?P<tid>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_type' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'eid' => self::id_arg(), 'tid' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_type' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'eid' => self::id_arg(), 'tid' => self::id_arg() ],
            ],
        ] );

        // Public checkout — create an order and (paid) a Stripe session, or
        // (free) confirm immediately. Open to guests; CSRF-guarded by a nonce.
        register_rest_route( $ns, '/tickets/checkout', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'checkout' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Stripe webhook — confirms paid orders. Open (called by Stripe);
        // authenticity is verified via the Stripe-Signature HMAC.
        register_rest_route( $ns, '/tickets/webhook', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'webhook' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Admin: refund an order (full or partial). Requires the refund cap.
        register_rest_route( $ns, '/tickets/orders/(?P<id>\d+)/refund', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'refund_order' ],
                'permission_callback' => [ $this, 'can_refund' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        // Admin: resend the confirmation email + PDF. Requires the manage cap.
        register_rest_route( $ns, '/tickets/orders/(?P<id>\d+)/resend', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'resend_order' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        // Finance: read-only order list shaped for the Finance "Orders" table.
        register_rest_route( $ns, '/tickets/orders', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_orders_finance' ],
                'permission_callback' => [ $this, 'can_view_finance' ],
            ],
        ] );
    }

    private static function id_arg(): array {
        return [
            'validate_callback' => static fn( $v ) => is_numeric( $v ),
            'sanitize_callback' => 'absint',
        ];
    }

    // ── Permissions ───────────────────────────────────────────────

    public function can_manage(): bool {
        return current_user_can( 'vis_tickets_manage' );
    }

    public function can_refund(): bool {
        return current_user_can( 'vis_tickets_refund' );
    }

    /** Finance managers (or ticket managers) may view the orders list. */
    public function can_view_finance(): bool {
        return current_user_can( 'vis_manage_finance' ) || current_user_can( 'vis_tickets_manage' );
    }

    /**
     * List orders shaped for the Finance "Orders" table (newest first).
     */
    public function list_orders_finance( WP_REST_Request $req ): WP_REST_Response {
        try {
            $orders = PCIO_VIS_Tickets_DB::list_orders( [ 'limit' => 1000, 'offset' => 0 ] );
            $titles = [];
            $rows   = [];
            $stripe_base = PCIO_VIS_Tickets_Admin::stripe_mode() === 'live'
                ? 'https://dashboard.stripe.com/payments/'
                : 'https://dashboard.stripe.com/test/payments/';
            foreach ( $orders as $order ) {
                $eid = (int) $order['event_id'];
                if ( ! array_key_exists( $eid, $titles ) ) {
                    $event          = PCIO_VIS_Events_DB::get( $eid );
                    $titles[ $eid ] = $event['title'] ?? '';
                }
                $customer = ! empty( $order['customer_id'] )
                    ? PCIO_VIS_Tickets_DB::get_customer( (int) $order['customer_id'] )
                    : null;

                $status     = (string) $order['status'];
                $total      = (int) $order['amount_cents'];
                $refunded   = (int) $order['refunded_cents'];
                $refundable = max( 0, $total - $refunded );
                $can_refund = in_array( $status, [ 'paid', 'partly_refunded', 'free_confirmed' ], true )
                    && ( $refundable > 0 || $total <= 0 );
                $can_resend = in_array( $status, [ 'paid', 'free_confirmed', 'partly_refunded' ], true );

                $rows[] = [
                    'id'               => (int) $order['id'],
                    'entry_date'       => (string) ( $order['paid_at'] ?: $order['created'] ),
                    'order_no'         => (string) $order['order_no'],
                    'buyer'            => $customer && ! empty( $customer['name'] ) ? (string) $customer['name'] : (string) $order['email'],
                    'email'            => (string) $order['email'],
                    'event_title'      => (string) $titles[ $eid ],
                    'amount_cents'     => $total,
                    'refunded_cents'   => $refunded,
                    'refundable_cents' => $refundable,
                    'currency'         => (string) $order['currency'],
                    'status'           => self::order_status_label( $status ),
                    'status_key'       => $status,
                    'stripe_url'       => ! empty( $order['stripe_payment_intent'] ) ? $stripe_base . $order['stripe_payment_intent'] : '',
                    'can_refund'       => $can_refund,
                    'can_resend'       => $can_resend,
                ];
            }
            return new WP_REST_Response( $rows, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /** Human-readable label for an order status. */
    public static function order_status_label( string $status ): string {
        $labels = [
            'pending'         => __( 'Pending', 'pcio-vis-tickets' ),
            'paid'            => __( 'Paid', 'pcio-vis-tickets' ),
            'free_confirmed'  => __( 'Confirmed (free)', 'pcio-vis-tickets' ),
            'refunded'        => __( 'Refunded', 'pcio-vis-tickets' ),
            'partly_refunded' => __( 'Partly refunded', 'pcio-vis-tickets' ),
            'cancelled'       => __( 'Cancelled', 'pcio-vis-tickets' ),
        ];
        return $labels[ $status ] ?? $status;
    }

    // ── Handlers ──────────────────────────────────────────────────

    /**
     * Full editor payload for one event: settings, ticket types and a small
     * capacity summary used by the tab UI.
     */
    public function get_event( WP_REST_Request $req ): WP_REST_Response {
        $eid = (int) $req->get_param( 'eid' );
        return new WP_REST_Response( $this->event_payload( $eid ), 200 );
    }

    public function update_settings( WP_REST_Request $req ): WP_REST_Response {
        $eid  = (int) $req->get_param( 'eid' );
        $body = (array) $req->get_json_params();
        try {
            PCIO_VIS_Tickets_DB::save_settings( $eid, $body );
            return new WP_REST_Response( $this->event_payload( $eid ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function create_type( WP_REST_Request $req ): WP_REST_Response {
        $eid  = (int) $req->get_param( 'eid' );
        $body = (array) $req->get_json_params();
        try {
            $id = PCIO_VIS_Tickets_DB::create_type( $eid, $body );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create ticket type.', 'pcio-vis-tickets' ) ], 500 );
            }
            return new WP_REST_Response( [
                'type'     => PCIO_VIS_Tickets_DB::get_type( $id ),
                'capacity' => $this->capacity( $eid ),
            ], 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_type( WP_REST_Request $req ): WP_REST_Response {
        $eid  = (int) $req->get_param( 'eid' );
        $tid  = (int) $req->get_param( 'tid' );
        $body = (array) $req->get_json_params();

        $existing = PCIO_VIS_Tickets_DB::get_type( $tid );
        if ( ! $existing || (int) $existing['event_id'] !== $eid ) {
            return new WP_REST_Response( [ 'message' => __( 'Ticket type not found.', 'pcio-vis-tickets' ) ], 404 );
        }
        try {
            PCIO_VIS_Tickets_DB::update_type( $tid, $body );
            return new WP_REST_Response( [
                'type'     => PCIO_VIS_Tickets_DB::get_type( $tid ),
                'capacity' => $this->capacity( $eid ),
            ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_type( WP_REST_Request $req ): WP_REST_Response {
        $eid = (int) $req->get_param( 'eid' );
        $tid = (int) $req->get_param( 'tid' );

        $existing = PCIO_VIS_Tickets_DB::get_type( $tid );
        if ( ! $existing || (int) $existing['event_id'] !== $eid ) {
            return new WP_REST_Response( [ 'message' => __( 'Ticket type not found.', 'pcio-vis-tickets' ) ], 404 );
        }
        if ( ! PCIO_VIS_Tickets_DB::delete_type( $tid ) ) {
            return new WP_REST_Response(
                [ 'message' => __( 'This ticket type cannot be deleted because tickets have already been sold.', 'pcio-vis-tickets' ) ],
                409
            );
        }
        return new WP_REST_Response( [ 'capacity' => $this->capacity( $eid ) ], 200 );
    }

    // ── Shapers ───────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function event_payload( int $eid ): array {
        return [
            'eventId'  => $eid,
            'mode'     => PCIO_VIS_Tickets_DB::event_mode( $eid ),
            'settings' => PCIO_VIS_Tickets_DB::get_settings( $eid ),
            'types'    => PCIO_VIS_Tickets_DB::get_types( $eid ),
            'capacity' => $this->capacity( $eid ),
        ];
    }

    /**
     * @return array{maxGuests:int,entranceSold:int,entranceAvailable:int,mode:string}
     */
    private function capacity( int $eid ): array {
        $settings  = PCIO_VIS_Tickets_DB::get_settings( $eid );
        $available = PCIO_VIS_Tickets_DB::entrance_available( $eid );
        return [
            'maxGuests'         => (int) $settings['max_guests'],
            'entranceSold'      => PCIO_VIS_Tickets_DB::entrance_sold( $eid ),
            'entranceAvailable' => $available === PHP_INT_MAX ? -1 : $available,
            'mode'              => PCIO_VIS_Tickets_DB::event_mode( $eid ),
        ];
    }

    // ── Public checkout (Phase 4) ─────────────────────────────────

    /**
     * Create an order from a public checkout submission.
     *
     * Body: { nonce, event_id, name, email, phone?, items: [{type_id, qty}] }
     *
     * All prices, availability, sales windows and capacity are re-validated
     * server-side — the client total is never trusted. Returns a redirect URL:
     *   • paid  → the hosted Stripe Checkout Session URL
     *   • free  → the local "accepted" page (order already confirmed + minted)
     */
    public function checkout( WP_REST_Request $req ): WP_REST_Response {
        $body = (array) $req->get_json_params();

        // CSRF guard (the checkout page issues this nonce; works for guests).
        $nonce = isset( $body['nonce'] ) ? sanitize_text_field( $body['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'pcio_met_checkout' ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Your session expired. Please reload the page and try again.', 'pcio-vis-tickets' ) ], 403 );
        }

        $event_id = absint( $body['event_id'] ?? 0 );
        $event    = $event_id ? PCIO_VIS_Events_DB::get( $event_id ) : null;
        if ( ! $event ) {
            return new WP_REST_Response( [ 'message' => __( 'Event not found.', 'pcio-vis-tickets' ) ], 404 );
        }
        if ( PCIO_VIS_Tickets_DB::event_mode( $event_id ) !== 'tickets' ) {
            return new WP_REST_Response( [ 'message' => __( 'This event does not sell tickets.', 'pcio-vis-tickets' ) ], 400 );
        }

        $name  = sanitize_text_field( $body['name'] ?? '' );
        $email = sanitize_email( $body['email'] ?? '' );
        $phone = sanitize_text_field( $body['phone'] ?? '' );
        if ( '' === $name || ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Please provide your name and a valid email address.', 'pcio-vis-tickets' ) ], 422 );
        }

        // Validate & price the requested items server-side.
        $settings   = PCIO_VIS_Tickets_DB::get_settings( $event_id );
        $per_buyer  = (int) $settings['max_entrance_per_buyer'];
        $items      = [];
        $line_items = [];
        $total      = 0;
        $entrance   = 0;
        $summary    = [];

        foreach ( (array) ( $body['items'] ?? [] ) as $raw ) {
            $type_id = absint( $raw['type_id'] ?? 0 );
            $qty     = absint( $raw['qty'] ?? 0 );
            if ( ! $type_id || $qty < 1 ) {
                continue;
            }
            $type = PCIO_VIS_Tickets_DB::get_type( $type_id );
            if ( ! $type || (int) $type['event_id'] !== $event_id ) {
                return new WP_REST_Response( [ 'message' => __( 'One of the selected tickets is not available.', 'pcio-vis-tickets' ) ], 409 );
            }
            $max = PCIO_VIS_Tickets_DB::type_purchasable_qty( $type );
            if ( $max < 1 ) {
                return new WP_REST_Response(
                    [ 'message' => sprintf( /* translators: %s: ticket type name */ __( '“%s” is sold out or not on sale.', 'pcio-vis-tickets' ), $type['name'] ) ],
                    409
                );
            }
            if ( $qty > $max ) {
                return new WP_REST_Response(
                    [ 'message' => sprintf( /* translators: 1: ticket type name, 2: max quantity */ __( 'Only %2$d × “%1$s” are available.', 'pcio-vis-tickets' ), $type['name'], $max ) ],
                    409
                );
            }

            $unit  = (int) $type['price_cents'];
            $total += $unit * $qty;
            if ( ! empty( $type['is_entrance'] ) ) {
                $entrance += $qty;
            }
            $items[]      = [ 'type' => $type, 'qty' => $qty, 'unit' => $unit ];
            $line_items[] = [ 'name' => $type['name'], 'unit_amount_cents' => $unit, 'qty' => $qty ];
            $summary[]    = $qty . ' × ' . $type['name'];
        }

        if ( ! $items ) {
            return new WP_REST_Response( [ 'message' => __( 'Please select at least one ticket.', 'pcio-vis-tickets' ) ], 422 );
        }

        // Per-buyer entrance cap and overall capacity re-check.
        if ( $per_buyer > 0 && $entrance > $per_buyer ) {
            return new WP_REST_Response(
                [ 'message' => sprintf( /* translators: %d: max entrance tickets per buyer */ __( 'You can buy at most %d entrance tickets.', 'pcio-vis-tickets' ), $per_buyer ) ],
                409
            );
        }
        $available = PCIO_VIS_Tickets_DB::entrance_available( $event_id );
        if ( $entrance > $available ) {
            return new WP_REST_Response(
                [ 'message' => __( 'There are not enough tickets left for this event.', 'pcio-vis-tickets' ) ],
                409
            );
        }

        $currency = PCIO_VIS_Tickets_Admin::currency();

        // Persist customer + pending order + items.
        try {
            $customer_id = PCIO_VIS_Tickets_DB::upsert_customer( $email, $name, $phone );
            $order_id    = PCIO_VIS_Tickets_DB::create_order( [
                'event_id'     => $event_id,
                'customer_id'  => $customer_id,
                'email'        => $email,
                'status'       => 'pending',
                'summary'      => implode( ', ', $summary ),
                'amount_cents' => $total,
                'currency'     => $currency,
            ] );
            if ( ! $order_id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create the order. Please try again.', 'pcio-vis-tickets' ) ], 500 );
            }
            foreach ( $items as $it ) {
                PCIO_VIS_Tickets_DB::add_order_item( $order_id, (int) $it['type']['id'], (int) $it['qty'], (int) $it['unit'] );
            }
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }

        $order = PCIO_VIS_Tickets_DB::get_order( $order_id );

        // FREE path — no payment needed. Confirm + mint immediately.
        if ( $total <= 0 ) {
            if ( PCIO_VIS_Tickets_DB::claim_order( $order_id, 'pending', 'free_confirmed' ) ) {
                PCIO_VIS_Tickets_DB::update_order(
                    $order_id,
                    [ 'paid_at' => current_time( 'mysql' ) ],
                    [ '%s' ]
                );
                PCIO_VIS_Tickets_DB::mint_tickets( $order_id );

                // Capacity-race guard: if concurrent free confirmations pushed
                // this event past its limit, roll this order back rather than
                // oversell (no money is involved on the free path).
                if ( $entrance > 0 && PCIO_VIS_Tickets_DB::entrance_sold( $event_id ) > (int) $settings['max_guests'] ) {
                    PCIO_VIS_Tickets_DB::void_order( $order_id );
                    return new WP_REST_Response(
                        [ 'message' => __( 'There are not enough tickets left for this event.', 'pcio-vis-tickets' ) ],
                        409
                    );
                }

                /**
                 * Fires when an order is confirmed (free or paid). Phase 5 uses
                 * this to generate the PDF and email the buyer.
                 *
                 * @param int $order_id
                 */
                do_action( 'pcio_met_order_confirmed', $order_id );
            }
            return new WP_REST_Response( [
                'free'     => true,
                'redirect' => $this->return_url( 'accepted', $order['token'] ),
            ], 200 );
        }

        // PAID path — require Stripe and create a hosted Checkout Session.
        if ( ! PCIO_VIS_Tickets_Admin::is_configured() ) {
            return new WP_REST_Response( [ 'message' => __( 'Online payment is not available right now. Please contact the organiser.', 'pcio-vis-tickets' ) ], 503 );
        }

        $session = PCIO_VIS_Tickets_Stripe::create_checkout_session( [
            'order_no'    => $order['order_no'],
            'order_id'    => $order_id,
            'token'       => $order['token'],
            'email'       => $email,
            'currency'    => $currency,
            'success_url' => $this->return_url( 'accepted', $order['token'] ),
            'cancel_url'  => $this->return_url( 'rejected', $order['token'] ),
            'line_items'  => $line_items,
        ] );

        if ( is_wp_error( $session ) ) {
            return new WP_REST_Response( [ 'message' => $session->get_error_message() ], 502 );
        }

        PCIO_VIS_Tickets_DB::update_order(
            $order_id,
            [ 'stripe_session_id' => (string) ( $session['id'] ?? '' ) ],
            [ '%s' ]
        );

        return new WP_REST_Response( [
            'free'     => false,
            'redirect' => (string) ( $session['url'] ?? '' ),
        ], 200 );
    }

    /**
     * Build a return URL for the checkout flow.
     */
    private function return_url( string $view, string $token ): string {
        return add_query_arg(
            [ 'order' => rawurlencode( $token ) ],
            home_url( 'tickets/' . $view . '/' )
        );
    }

    // ── Stripe webhook ────────────────────────────────────────────

    /**
     * Handle Stripe webhook events. The request body is the raw event JSON and
     * its authenticity is proven by the Stripe-Signature header (HMAC-SHA256
     * over "{timestamp}.{body}" keyed with the webhook signing secret).
     *
     * On checkout.session.completed we atomically move the order pending → paid
     * (so concurrent webhook retries / the return page never double-mint),
     * record the payment intent, mint tickets and fire pcio_met_order_confirmed.
     */
    public function webhook( WP_REST_Request $req ): WP_REST_Response {
        $payload = $req->get_body();
        $sig     = $req->get_header( 'stripe_signature' ); // WP normalises Stripe-Signature → stripe_signature
        $secret  = PCIO_VIS_Tickets_Admin::stripe_webhook_secret();

        if ( '' === (string) $secret ) {
            // Not configured — refuse rather than trust an unverified event.
            return new WP_REST_Response( [ 'message' => 'Webhook secret not configured.' ], 500 );
        }
        if ( ! $this->verify_stripe_signature( (string) $payload, (string) $sig, (string) $secret ) ) {
            return new WP_REST_Response( [ 'message' => 'Invalid signature.' ], 400 );
        }

        $event = json_decode( (string) $payload, true );
        if ( ! is_array( $event ) || empty( $event['type'] ) ) {
            return new WP_REST_Response( [ 'message' => 'Malformed event.' ], 400 );
        }

        // We only act on a completed checkout. Acknowledge everything else 200
        // so Stripe does not retry events we intentionally ignore.
        if ( 'checkout.session.completed' === $event['type'] ) {
            $this->fulfil_from_session( (array) ( $event['data']['object'] ?? [] ) );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Verify a Stripe-Signature header against the raw payload.
     */
    private function verify_stripe_signature( string $payload, string $header, string $secret ): bool {
        if ( '' === $header ) {
            return false;
        }
        $timestamp  = '';
        $signatures = [];
        foreach ( explode( ',', $header ) as $part ) {
            $kv = explode( '=', trim( $part ), 2 );
            if ( count( $kv ) !== 2 ) {
                continue;
            }
            if ( 't' === $kv[0] ) {
                $timestamp = $kv[1];
            } elseif ( 'v1' === $kv[0] ) {
                $signatures[] = $kv[1];
            }
        }
        if ( '' === $timestamp || empty( $signatures ) ) {
            return false;
        }
        // Reject events older than 5 minutes to blunt replay attacks.
        if ( abs( time() - (int) $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
            return false;
        }
        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        foreach ( $signatures as $candidate ) {
            if ( hash_equals( $expected, $candidate ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Confirm and fulfil the order referenced by a Checkout Session object.
     * Idempotent: only the first caller to win pending → paid mints tickets.
     */
    private function fulfil_from_session( array $session ): void {
        $order_id = (int) ( $session['metadata']['order_id'] ?? 0 );
        if ( ! $order_id && ! empty( $session['id'] ) ) {
            $found    = PCIO_VIS_Tickets_DB::get_order_by_session( (string) $session['id'] );
            $order_id = $found ? (int) $found['id'] : 0;
        }
        if ( ! $order_id ) {
            return;
        }

        // Only treat the session as paid when Stripe says so.
        $paid = ( ( $session['payment_status'] ?? '' ) === 'paid' )
            || ( ( $session['status'] ?? '' ) === 'complete' );
        if ( ! $paid ) {
            return;
        }

        if ( ! PCIO_VIS_Tickets_DB::claim_order( $order_id, 'pending', 'paid' ) ) {
            return; // already processed (or not pending) — idempotent no-op
        }

        $payment_intent = is_string( $session['payment_intent'] ?? null )
            ? (string) $session['payment_intent']
            : '';
        PCIO_VIS_Tickets_DB::update_order(
            $order_id,
            [
                'paid_at'               => current_time( 'mysql' ),
                'stripe_payment_intent' => $payment_intent,
            ],
            [ '%s', '%s' ]
        );
        PCIO_VIS_Tickets_DB::mint_tickets( $order_id );

        // Capacity re-check. The session was gated at creation, but a race can
        // still oversell. Payment already succeeded, so we honour the tickets
        // and instead signal the oversell for admin attention (e.g. notice).
        $order    = PCIO_VIS_Tickets_DB::get_order( $order_id );
        $event_id = $order ? (int) $order['event_id'] : 0;
        $settings = PCIO_VIS_Tickets_DB::get_settings( $event_id );
        if ( (int) $settings['max_guests'] > 0
            && PCIO_VIS_Tickets_DB::entrance_sold( $event_id ) > (int) $settings['max_guests'] ) {
            /**
             * Fires when a paid order pushes an event past its capacity.
             *
             * @param int $order_id
             */
            do_action( 'pcio_met_order_oversold', $order_id );
        }

        /** Fires when an order is confirmed (free or paid). @param int $order_id */
        do_action( 'pcio_met_order_confirmed', $order_id );
    }

    // ── Admin: refunds & resend ───────────────────────────────────

    /**
     * Refund an order, fully or partially.
     *
     * Body: { amount_cents?: int }. When amount_cents is omitted or >= the
     * remaining refundable amount the order is refunded in full (which releases
     * the tickets and frees entrance capacity). A partial refund only records
     * the money and leaves the tickets valid.
     */
    public function refund_order( WP_REST_Request $req ): WP_REST_Response {
        $order_id = (int) $req->get_param( 'id' );
        $order    = PCIO_VIS_Tickets_DB::get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'message' => __( 'Order not found.', 'pcio-vis-tickets' ) ], 404 );
        }

        $status = (string) $order['status'];
        if ( ! in_array( $status, [ 'paid', 'partly_refunded', 'free_confirmed' ], true ) ) {
            return new WP_REST_Response( [ 'message' => __( 'This order cannot be refunded.', 'pcio-vis-tickets' ) ], 409 );
        }

        $total          = (int) $order['amount_cents'];
        $already        = (int) $order['refunded_cents'];
        $refundable     = max( 0, $total - $already );
        $params         = (array) ( $req->get_json_params() ?? [] );
        $requested      = isset( $params['amount_cents'] ) ? max( 0, (int) $params['amount_cents'] ) : $refundable;
        $amount         = min( $requested, $refundable );
        $is_full        = ( $amount >= $refundable );

        // Free orders (nothing was charged) are simply cancelled.
        if ( $total <= 0 ) {
            PCIO_VIS_Tickets_DB::update_order(
                $order_id,
                [ 'status' => 'cancelled', 'refunded_at' => current_time( 'mysql' ) ],
                [ '%s', '%s' ]
            );
            PCIO_VIS_Tickets_DB::refund_all_tickets( $order_id );
            PCIO_VIS_Tickets_DB::restore_order_capacity( $order_id );
            do_action( 'pcio_met_order_refunded', $order_id, $amount, true );
            return new WP_REST_Response( [ 'order' => PCIO_VIS_Tickets_DB::get_order( $order_id ) ], 200 );
        }

        if ( $amount <= 0 ) {
            return new WP_REST_Response( [ 'message' => __( 'Nothing left to refund.', 'pcio-vis-tickets' ) ], 409 );
        }

        $payment_intent = (string) ( $order['stripe_payment_intent'] ?? '' );
        if ( '' === $payment_intent ) {
            return new WP_REST_Response( [ 'message' => __( 'No payment reference on this order — cannot refund via Stripe.', 'pcio-vis-tickets' ) ], 409 );
        }

        $refund = PCIO_VIS_Tickets_Stripe::create_refund( $payment_intent, $is_full ? 0 : $amount );
        if ( is_wp_error( $refund ) ) {
            return new WP_REST_Response( [ 'message' => $refund->get_error_message() ], 502 );
        }

        // Trust Stripe's reported amount when present.
        $refunded_now = isset( $refund['amount'] ) ? (int) $refund['amount'] : $amount;
        $new_total    = min( $total, $already + $refunded_now );
        $new_status   = $new_total >= $total ? 'refunded' : 'partly_refunded';

        PCIO_VIS_Tickets_DB::update_order(
            $order_id,
            [
                'refunded_cents' => $new_total,
                'status'         => $new_status,
                'refunded_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s' ]
        );

        // A full refund releases the tickets and frees entrance capacity.
        if ( 'refunded' === $new_status ) {
            PCIO_VIS_Tickets_DB::refund_all_tickets( $order_id );
            PCIO_VIS_Tickets_DB::restore_order_capacity( $order_id );
        }

        /**
         * Fires after an order is (partly) refunded.
         *
         * @param int  $order_id
         * @param int  $amount   Amount refunded in this operation (minor units).
         * @param bool $is_full  Whether the order is now fully refunded.
         */
        do_action( 'pcio_met_order_refunded', $order_id, $refunded_now, 'refunded' === $new_status );

        return new WP_REST_Response( [ 'order' => PCIO_VIS_Tickets_DB::get_order( $order_id ) ], 200 );
    }

    /**
     * Re-generate the PDF and re-send the confirmation email for an order.
     */
    public function resend_order( WP_REST_Request $req ): WP_REST_Response {
        $order_id = (int) $req->get_param( 'id' );
        $order    = PCIO_VIS_Tickets_DB::get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'message' => __( 'Order not found.', 'pcio-vis-tickets' ) ], 404 );
        }
        if ( ! in_array( (string) $order['status'], [ 'paid', 'free_confirmed', 'partly_refunded' ], true ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Only confirmed orders can be resent.', 'pcio-vis-tickets' ) ], 409 );
        }

        // Force a fresh PDF in case ticket data changed, then email.
        PCIO_VIS_Tickets_PDF::generate( $order_id, true );
        do_action( 'pcio_met_order_confirmed', $order_id );

        return new WP_REST_Response( [ 'sent' => true ], 200 );
    }
}
