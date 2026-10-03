<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; caching would hide real-time ticket/order changes.

/**
 * Database layer for the Tickets extension.
 *
 * Tables (all prefixed with $wpdb->prefix)
 * ────────────────────────────────────────
 *   me_t_event_settings – one row per event that uses tickets. `max_guests`
 *                         is the mode discriminator: 0 = member-joining mode
 *                         (core signup), > 0 = ticket-sales mode (public buys).
 *   me_t_ticket_types   – the ticket types offered for an event. Only
 *                         `is_entrance` types count toward `max_guests`.
 *   me_t_customers      – buyers (deduplicated by email).
 *   me_t_orders         – one purchase. `status` tracks the lifecycle; free
 *                         orders skip Stripe and go straight to free_confirmed.
 *   me_t_order_items    – the chosen ticket types + quantities for an order.
 *   me_t_tickets        – one row per issued ticket (one QR each).
 *
 * Amounts are stored in minor units (øre / cents) as integers.
 */
class PCIO_VIS_Tickets_DB {

    const TABLE_SETTINGS    = 'me_t_event_settings';
    const TABLE_TYPES       = 'me_t_ticket_types';
    const TABLE_CUSTOMERS   = 'me_t_customers';
    const TABLE_ORDERS      = 'me_t_orders';
    const TABLE_ORDER_ITEMS = 'me_t_order_items';
    const TABLE_TICKETS     = 'me_t_tickets';

    const ORDER_STATUSES = [
        'pending',          // awaiting payment (Stripe session open)
        'paid',             // Stripe payment completed
        'free_confirmed',   // 0-cost order confirmed without Stripe
        'refunded',         // fully refunded
        'partly_refunded',  // partially refunded
        'cancelled',        // abandoned / failed
    ];

    const TICKET_STATUSES = [ 'valid', 'scanned', 'refunded' ];

    // ── Table-name helpers ────────────────────────────────────────

    public static function t_settings(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SETTINGS;
    }
    public static function t_types(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TYPES;
    }
    public static function t_customers(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_CUSTOMERS;
    }
    public static function t_orders(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_ORDERS;
    }
    public static function t_order_items(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_ORDER_ITEMS;
    }
    public static function t_tickets(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TICKETS;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        if ( get_option( 'pcio_met_db_version' ) === PCIO_VIS_TICKETS_VERSION ) {
            return; // schema already current
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset  = $wpdb->get_charset_collate();
        $settings = self::t_settings();
        $types    = self::t_types();
        $cust     = self::t_customers();
        $orders   = self::t_orders();
        $items    = self::t_order_items();
        $tickets  = self::t_tickets();

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$settings} (
                event_id               INT          NOT NULL,
                max_guests             INT          NOT NULL DEFAULT 0,
                is_soldout             TINYINT(1)   NOT NULL DEFAULT 0,
                hide_remaining         TINYINT(1)   NOT NULL DEFAULT 0,
                max_entrance_per_buyer INT          NOT NULL DEFAULT 0,
                created                DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated                DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (event_id)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$types} (
                id           INT          NOT NULL AUTO_INCREMENT,
                event_id     INT          NOT NULL,
                name         VARCHAR(200) NOT NULL DEFAULT '',
                is_entrance  TINYINT(1)   NOT NULL DEFAULT 1,
                price_cents  INT          NOT NULL DEFAULT 0,
                currency     VARCHAR(3)   NOT NULL DEFAULT 'DKK',
                qty_offered  INT          NOT NULL DEFAULT 0,
                qty_sold     INT          NOT NULL DEFAULT 0,
                sort         INT          NOT NULL DEFAULT 0,
                active       TINYINT(1)   NOT NULL DEFAULT 1,
                sales_start  DATETIME     NULL DEFAULT NULL,
                sales_stop   DATETIME     NULL DEFAULT NULL,
                created      DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated      DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_types_event (event_id)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$cust} (
                id       INT          NOT NULL AUTO_INCREMENT,
                email    VARCHAR(190) NOT NULL DEFAULT '',
                name     VARCHAR(200) NOT NULL DEFAULT '',
                phone    VARCHAR(60)  NOT NULL DEFAULT '',
                created  DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_cust_email (email)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$orders} (
                id                    INT          NOT NULL AUTO_INCREMENT,
                order_no              VARCHAR(20)  NOT NULL DEFAULT '',
                token                 VARCHAR(64)  NOT NULL DEFAULT '',
                event_id              INT          NOT NULL,
                customer_id           INT          NOT NULL DEFAULT 0,
                email                 VARCHAR(190) NOT NULL DEFAULT '',
                status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
                summary               TEXT,
                amount_cents          INT          NOT NULL DEFAULT 0,
                refunded_cents        INT          NOT NULL DEFAULT 0,
                currency              VARCHAR(3)   NOT NULL DEFAULT 'DKK',
                stripe_session_id     VARCHAR(255) NULL DEFAULT NULL,
                stripe_payment_intent VARCHAR(255) NULL DEFAULT NULL,
                created               DATETIME     DEFAULT CURRENT_TIMESTAMP,
                paid_at               DATETIME     NULL DEFAULT NULL,
                refunded_at           DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_orders_token (token),
                UNIQUE KEY idx_orders_no (order_no),
                KEY idx_orders_event (event_id),
                KEY idx_orders_session (stripe_session_id)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$items} (
                id              INT NOT NULL AUTO_INCREMENT,
                order_id        INT NOT NULL,
                ticket_type_id  INT NOT NULL,
                qty             INT NOT NULL DEFAULT 0,
                unit_price_cents INT NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY idx_items_order (order_id)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$tickets} (
                id             INT          NOT NULL AUTO_INCREMENT,
                order_id       INT          NOT NULL,
                ticket_type_id INT          NOT NULL,
                code           VARCHAR(64)  NOT NULL DEFAULT '',
                attendee_name  VARCHAR(200) NULL DEFAULT NULL,
                status         VARCHAR(20)  NOT NULL DEFAULT 'valid',
                created        DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_tickets_code (code),
                KEY idx_tickets_order (order_id)
                ) {$charset};"
        );

        update_option( 'pcio_met_db_version', PCIO_VIS_TICKETS_VERSION );
    }

    // ── Event settings ────────────────────────────────────────────

    /**
     * Get the ticket settings for an event, or sane defaults when none exist.
     *
     * @return array{event_id:int,max_guests:int,is_soldout:int,hide_remaining:int,max_entrance_per_buyer:int}
     */
    public static function get_settings( int $event_id ): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d', self::t_settings(), $event_id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return [
                'event_id'               => $event_id,
                'max_guests'             => 0,
                'is_soldout'             => 0,
                'hide_remaining'         => 0,
                'max_entrance_per_buyer' => 0,
            ];
        }
        return [
            'event_id'               => (int) $row['event_id'],
            'max_guests'             => (int) $row['max_guests'],
            'is_soldout'             => (int) $row['is_soldout'],
            'hide_remaining'         => (int) $row['hide_remaining'],
            'max_entrance_per_buyer' => (int) $row['max_entrance_per_buyer'],
        ];
    }

    /**
     * Insert or update the ticket settings for an event (upsert by event_id).
     *
     * @param array $data Any subset of max_guests, is_soldout, hide_remaining,
     *                    max_entrance_per_buyer.
     */
    public static function save_settings( int $event_id, array $data ): void {
        global $wpdb;
        $current = self::get_settings( $event_id );
        $merged  = [
            'max_guests'             => isset( $data['max_guests'] ) ? max( 0, (int) $data['max_guests'] ) : $current['max_guests'],
            'is_soldout'             => isset( $data['is_soldout'] ) ? (int) (bool) $data['is_soldout'] : $current['is_soldout'],
            'hide_remaining'         => isset( $data['hide_remaining'] ) ? (int) (bool) $data['hide_remaining'] : $current['hide_remaining'],
            'max_entrance_per_buyer' => isset( $data['max_entrance_per_buyer'] ) ? max( 0, (int) $data['max_entrance_per_buyer'] ) : $current['max_entrance_per_buyer'],
        ];

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', self::t_settings(), $event_id )
        );

        if ( $exists ) {
            $wpdb->update(
                self::t_settings(),
                $merged + [ 'updated' => current_time( 'mysql' ) ],
                [ 'event_id' => $event_id ],
                [ '%d', '%d', '%d', '%d', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                self::t_settings(),
                $merged + [ 'event_id' => $event_id ],
                [ '%d', '%d', '%d', '%d', '%d' ]
            );
        }
    }

    // ── Ticket types ──────────────────────────────────────────────

    /**
     * All ticket types for an event, ordered by the manual sort then id.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_types( int $event_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_id = %d ORDER BY sort ASC, id ASC',
                self::t_types(),
                $event_id
            ),
            ARRAY_A
        );
        return array_map( [ self::class, 'shape_type' ], $rows ?: [] );
    }

    public static function get_type( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_types(), $id ),
            ARRAY_A
        );
        return $row ? self::shape_type( $row ) : null;
    }

    /**
     * Create a ticket type for an event. Returns the new id.
     */
    public static function create_type( int $event_id, array $data ): int {
        global $wpdb;
        $next_sort = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COALESCE(MAX(sort),0)+1 FROM %i WHERE event_id = %d', self::t_types(), $event_id )
        );
        $clean = self::sanitize_type( $data );
        $wpdb->insert(
            self::t_types(),
            [
                'event_id'    => $event_id,
                'name'        => $clean['name'] ?? '',
                'is_entrance' => $clean['is_entrance'] ?? 1,
                'price_cents' => $clean['price_cents'] ?? 0,
                'currency'    => $clean['currency'] ?? 'DKK',
                'qty_offered' => $clean['qty_offered'] ?? 0,
                'active'      => $clean['active'] ?? 1,
                'sales_start' => $clean['sales_start'] ?? null,
                'sales_stop'  => $clean['sales_stop'] ?? null,
                'sort'        => $next_sort,
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update a subset of fields on a ticket type. qty_sold is never writable here.
     */
    public static function update_type( int $id, array $data ): bool {
        global $wpdb;
        $clean = self::sanitize_type( $data );
        if ( ! $clean ) {
            return false;
        }
        $formats = [];
        foreach ( $clean as $field => $value ) {
            $formats[] = in_array( $field, [ 'name', 'currency', 'sales_start', 'sales_stop' ], true ) ? '%s' : '%d';
        }
        $clean['updated'] = current_time( 'mysql' );
        $formats[]        = '%s';
        return false !== $wpdb->update( self::t_types(), $clean, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    /**
     * Delete a ticket type. Refuses when tickets have already been sold for it.
     */
    public static function delete_type( int $id ): bool {
        global $wpdb;
        $sold = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT qty_sold FROM %i WHERE id = %d', self::t_types(), $id )
        );
        if ( $sold > 0 ) {
            return false;
        }
        return false !== $wpdb->delete( self::t_types(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Adjust qty_sold by a (possibly negative) delta, clamped at 0.
     */
    public static function adjust_sold( int $type_id, int $delta ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET qty_sold = GREATEST(0, qty_sold + %d) WHERE id = %d',
                self::t_types(),
                $delta,
                $type_id
            )
        );
    }

    // ── Capacity / mode helpers ───────────────────────────────────

    /**
     * 'tickets' when the event sells tickets (max_guests > 0), else 'member'.
     */
    public static function event_mode( int $event_id ): string {
        return self::get_settings( $event_id )['max_guests'] > 0 ? 'tickets' : 'member';
    }

    /**
     * Total entrance tickets sold for an event (only is_entrance types count).
     */
    public static function entrance_sold( int $event_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(qty_sold),0) FROM %i WHERE event_id = %d AND is_entrance = 1',
                self::t_types(),
                $event_id
            )
        );
    }

    /**
     * Entrance places still available against max_guests (>= 0).
     * Returns PHP_INT_MAX when the event is not in ticket mode.
     */
    public static function entrance_available( int $event_id ): int {
        $settings = self::get_settings( $event_id );
        if ( $settings['max_guests'] <= 0 ) {
            return PHP_INT_MAX;
        }
        return max( 0, $settings['max_guests'] - self::entrance_sold( $event_id ) );
    }

    /**
     * Whether a given ticket type can currently be sold: active, within its
     * sales window, not exhausted, and (for entrance types) capacity remaining.
     */
    public static function type_on_sale( array $type ): bool {
        if ( empty( $type['active'] ) ) {
            return false;
        }
        $event_id = (int) $type['event_id'];
        $settings = self::get_settings( $event_id );
        if ( $settings['is_soldout'] ) {
            return false;
        }
        $now = time();
        if ( ! empty( $type['sales_start'] ) && $now < strtotime( $type['sales_start'] ) ) {
            return false;
        }
        if ( ! empty( $type['sales_stop'] ) && $now > strtotime( $type['sales_stop'] ) ) {
            return false;
        }
        if ( $type['qty_offered'] > 0 && $type['qty_sold'] >= $type['qty_offered'] ) {
            return false;
        }
        if ( ! empty( $type['is_entrance'] ) && self::entrance_available( $event_id ) < 1 ) {
            return false;
        }
        return true;
    }

    /**
     * How many of this type a buyer may still purchase right now, taking into
     * account the per-type offered quantity and (for entrance) the event cap.
     */
    public static function type_purchasable_qty( array $type ): int {
        if ( ! self::type_on_sale( $type ) ) {
            return 0;
        }
        $limit = PHP_INT_MAX;
        if ( $type['qty_offered'] > 0 ) {
            $limit = min( $limit, $type['qty_offered'] - $type['qty_sold'] );
        }
        if ( ! empty( $type['is_entrance'] ) ) {
            $limit = min( $limit, self::entrance_available( (int) $type['event_id'] ) );
        }
        return max( 0, $limit );
    }

    // ── Customers ─────────────────────────────────────────────────

    /**
     * Get a customer row by id.
     *
     * @return array<string,mixed>|null
     */
    public static function get_customer( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_customers(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Find a customer by email or create one. Returns the customer id.
     */
    public static function upsert_customer( string $email, string $name = '', string $phone = '' ): int {
        global $wpdb;
        $email = sanitize_email( $email );
        $id    = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', self::t_customers(), $email )
        );
        if ( $id ) {
            if ( $name !== '' || $phone !== '' ) {
                $wpdb->update(
                    self::t_customers(),
                    array_filter( [
                        'name'  => sanitize_text_field( $name ),
                        'phone' => sanitize_text_field( $phone ),
                    ], static fn( $v ) => $v !== '' ),
                    [ 'id' => $id ]
                );
            }
            return $id;
        }
        $wpdb->insert(
            self::t_customers(),
            [
                'email' => $email,
                'name'  => sanitize_text_field( $name ),
                'phone' => sanitize_text_field( $phone ),
            ],
            [ '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    // ── Orders ────────────────────────────────────────────────────

    public static function create_order( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            self::t_orders(),
            [
                'order_no'     => self::generate_order_no(),
                'token'        => self::generate_token(),
                'event_id'     => (int) ( $data['event_id'] ?? 0 ),
                'customer_id'  => (int) ( $data['customer_id'] ?? 0 ),
                'email'        => sanitize_email( $data['email'] ?? '' ),
                'status'       => in_array( $data['status'] ?? 'pending', self::ORDER_STATUSES, true ) ? $data['status'] : 'pending',
                'summary'      => wp_kses_post( $data['summary'] ?? '' ),
                'amount_cents' => (int) ( $data['amount_cents'] ?? 0 ),
                'currency'     => substr( strtoupper( $data['currency'] ?? 'DKK' ), 0, 3 ),
            ],
            [ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function get_order( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_orders(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_order_by_token( string $token ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', self::t_orders(), $token ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_order_by_session( string $session_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE stripe_session_id = %s', self::t_orders(), $session_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function update_order( int $id, array $data, array $formats ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::t_orders(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    /**
     * List orders for the admin screen, newest first.
     *
     * @param array $args {
     *   @type int    $event_id Restrict to one event (0 = all).
     *   @type string $status   Restrict to one status ('' = all).
     *   @type string $search   Match order_no or email (LIKE).
     *   @type int    $limit    Page size (default 30).
     *   @type int    $offset   Offset.
     * }
     * @return array<int,array<string,mixed>>
     */
    public static function list_orders( array $args = [] ): array {
        global $wpdb;
        $f      = self::orders_filters( $args );
        $limit  = max( 1, (int) ( $args['limit'] ?? 30 ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE ( %d = 0 OR event_id = %d )
                   AND ( %s = '' OR status = %s )
                   AND ( %s = '' OR order_no LIKE %s OR email LIKE %s )
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                self::t_orders(),
                $f['event_id'],
                $f['event_id'],
                $f['status'],
                $f['status'],
                $f['search'],
                $f['like'],
                $f['like'],
                $limit,
                $offset
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    /**
     * Count orders matching the same filters as {@see list_orders()}.
     */
    public static function count_orders( array $args = [] ): int {
        global $wpdb;
        $f = self::orders_filters( $args );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                 WHERE ( %d = 0 OR event_id = %d )
                   AND ( %s = '' OR status = %s )
                   AND ( %s = '' OR order_no LIKE %s OR email LIKE %s )",
                self::t_orders(),
                $f['event_id'],
                $f['event_id'],
                $f['status'],
                $f['status'],
                $f['search'],
                $f['like'],
                $f['like']
            )
        );
    }

    /**
     * Normalise the shared list/count filters into scalar bind values.
     *
     * The queries use no-op placeholder guards (e.g. "%d = 0 OR event_id = %d")
     * so the SQL text is a fixed literal with a fixed parameter list — fully
     * covered by $wpdb->prepare() with no dynamic string building.
     *
     * @return array{event_id:int,status:string,search:string,like:string}
     */
    private static function orders_filters( array $args ): array {
        global $wpdb;
        $status = ( ! empty( $args['status'] ) && in_array( $args['status'], self::ORDER_STATUSES, true ) )
            ? (string) $args['status']
            : '';
        $search = ! empty( $args['search'] ) ? (string) $args['search'] : '';

        return [
            'event_id' => (int) ( $args['event_id'] ?? 0 ),
            'status'   => $status,
            'search'   => $search,
            'like'     => '%' . $wpdb->esc_like( $search ) . '%',
        ];
    }

    /**
     * Distinct event ids that have at least one order (for the filter dropdown).
     *
     * @return int[]
     */
    public static function order_event_ids(): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_id FROM %i ORDER BY event_id DESC', self::t_orders() ) );
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Mark every still-valid ticket of an order as refunded.
     *
     * @return int Number of tickets transitioned.
     */
    public static function refund_all_tickets( int $order_id ): int {
        global $wpdb;
        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i' . " SET status = 'refunded' WHERE order_id = %d AND status <> 'refunded'",
                self::t_tickets(),
                $order_id
            )
        );
    }

    /**
     * Return the sold-quantity each ticket type contributed to an order back to
     * the pool (reverses {@see mint_tickets()}), freeing entrance capacity.
     */
    public static function restore_order_capacity( int $order_id ): void {
        foreach ( self::get_order_items( $order_id ) as $item ) {
            $qty = (int) $item['qty'];
            if ( $qty > 0 ) {
                self::adjust_sold( (int) $item['ticket_type_id'], -$qty );
            }
        }
    }

    /**
     * Void a just-minted order that lost a capacity race: release its tickets,
     * return its capacity and mark it cancelled. Used as a safety rollback when
     * concurrent confirmations oversell an event.
     */
    public static function void_order( int $order_id ): void {
        self::refund_all_tickets( $order_id );
        self::restore_order_capacity( $order_id );
        self::update_order(
            $order_id,
            [ 'status' => 'cancelled' ],
            [ '%s' ]
        );
    }

    /**
     * Cancel pending orders left abandoned for longer than $minutes. Pending
     * orders never minted tickets, so no capacity needs restoring.
     *
     * @return int Number of orders cancelled.
     */
    public static function cancel_stale_pending( int $minutes = 120 ): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS ) );
        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i' . " SET status = 'cancelled' WHERE status = 'pending' AND created < %s",
                self::t_orders(),
                $cutoff
            )
        );
    }

    /**
     * Atomically move an order from one status to another. Only one caller can
     * win the transition, which makes confirmation idempotent across the free
     * path (Phase 4) and the Stripe webhook (Phase 5) — no double minting.
     *
     * @return bool True when this call performed the transition.
     */
    public static function claim_order( int $order_id, string $from_status, string $to_status ): bool {
        global $wpdb;
        if ( ! in_array( $to_status, self::ORDER_STATUSES, true ) ) {
            return false;
        }
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s WHERE id = %d AND status = %s',
                self::t_orders(),
                $to_status,
                $order_id,
                $from_status
            )
        );
        return 1 === (int) $updated;
    }

    /**
     * Create the tickets for a confirmed order (one per purchased unit) and
     * bump each ticket type's qty_sold. Idempotent: does nothing if tickets
     * already exist for the order.
     *
     * @return int[] Newly created ticket ids (empty if already minted).
     */
    public static function mint_tickets( int $order_id ): array {
        if ( self::get_tickets( $order_id ) ) {
            return []; // already minted
        }
        $ticket_ids = [];
        foreach ( self::get_order_items( $order_id ) as $item ) {
            $type_id = (int) $item['ticket_type_id'];
            $qty     = (int) $item['qty'];
            for ( $i = 0; $i < $qty; $i++ ) {
                $ticket_ids[] = self::create_ticket( $order_id, $type_id );
            }
            if ( $qty > 0 ) {
                self::adjust_sold( $type_id, $qty );
            }
        }
        return $ticket_ids;
    }

    // ── Order items ───────────────────────────────────────────────

    public static function add_order_item( int $order_id, int $type_id, int $qty, int $unit_price_cents ): void {
        global $wpdb;
        $wpdb->insert(
            self::t_order_items(),
            [
                'order_id'         => $order_id,
                'ticket_type_id'   => $type_id,
                'qty'              => $qty,
                'unit_price_cents' => $unit_price_cents,
            ],
            [ '%d', '%d', '%d', '%d' ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function get_order_items( int $order_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', self::t_order_items(), $order_id ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    // ── Tickets ───────────────────────────────────────────────────

    public static function create_ticket( int $order_id, int $type_id, ?string $attendee_name = null ): int {
        global $wpdb;
        $wpdb->insert(
            self::t_tickets(),
            [
                'order_id'       => $order_id,
                'ticket_type_id' => $type_id,
                'code'           => self::generate_token(),
                'attendee_name'  => $attendee_name !== null ? sanitize_text_field( $attendee_name ) : null,
                'status'         => 'valid',
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function get_tickets( int $order_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', self::t_tickets(), $order_id ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    // ── Internal helpers ──────────────────────────────────────────

    /**
     * Normalise a ticket type row for output (ints/bools, never exposes raw).
     */
    private static function shape_type( array $row ): array {
        return [
            'id'          => (int) $row['id'],
            'event_id'    => (int) $row['event_id'],
            'name'        => (string) $row['name'],
            'is_entrance' => (int) $row['is_entrance'],
            'price_cents' => (int) $row['price_cents'],
            'currency'    => (string) $row['currency'],
            'qty_offered' => (int) $row['qty_offered'],
            'qty_sold'    => (int) $row['qty_sold'],
            'sort'        => (int) $row['sort'],
            'active'      => (int) $row['active'],
            'sales_start' => $row['sales_start'],
            'sales_stop'  => $row['sales_stop'],
        ];
    }

    /**
     * Sanitize an incoming ticket-type payload to only the writable fields.
     * qty_sold, id, event_id are intentionally excluded.
     *
     * @return array<string,mixed>
     */
    private static function sanitize_type( array $data ): array {
        $out = [];
        if ( array_key_exists( 'name', $data ) ) {
            $out['name'] = sanitize_text_field( $data['name'] );
        }
        if ( array_key_exists( 'is_entrance', $data ) ) {
            $out['is_entrance'] = (int) (bool) $data['is_entrance'];
        }
        if ( array_key_exists( 'price_cents', $data ) ) {
            $out['price_cents'] = max( 0, (int) $data['price_cents'] );
        }
        if ( array_key_exists( 'currency', $data ) ) {
            $out['currency'] = substr( strtoupper( sanitize_text_field( $data['currency'] ) ), 0, 3 );
        }
        if ( array_key_exists( 'qty_offered', $data ) ) {
            $out['qty_offered'] = max( 0, (int) $data['qty_offered'] );
        }
        if ( array_key_exists( 'active', $data ) ) {
            $out['active'] = (int) (bool) $data['active'];
        }
        if ( array_key_exists( 'sales_start', $data ) ) {
            $out['sales_start'] = empty( $data['sales_start'] ) ? null : sanitize_text_field( $data['sales_start'] );
        }
        if ( array_key_exists( 'sales_stop', $data ) ) {
            $out['sales_stop'] = empty( $data['sales_stop'] ) ? null : sanitize_text_field( $data['sales_stop'] );
        }
        return $out;
    }

    private static function generate_token(): string {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Human-friendly, unique order number (e.g. "M7K3Q2P9").
     */
    private static function generate_order_no(): string {
        global $wpdb;
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ( $i = 0; $i < 8; $i++ ) {
                $code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
            }
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_no = %s', self::t_orders(), $code )
            );
        } while ( $exists );
        return $code;
    }
}
