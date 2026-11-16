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
* me_t_orders         – one purchase. `status` tracks the lifecycle; free
*                         orders skip Stripe and go straight to free_confirmed.
*                         `fee_cents` is the service-fee share of
*                         `amount_cents` when fee pass-through is enabled.
 *   me_t_order_items    – the chosen ticket types + quantities for an order.
 *   me_t_tickets        – one row per issued ticket (one QR each).
 *
 * Amounts are stored in minor units (øre / cents) as integers.
 */
class PCIO_VIS_Tickets_DB {

    const TABLE_SETTINGS      = 'me_t_event_settings';
    const TABLE_TYPES         = 'me_t_ticket_types';
    const TABLE_CUSTOMERS     = 'me_t_customers';
    const TABLE_ORDERS        = 'me_t_orders';
    const TABLE_ORDER_ITEMS   = 'me_t_order_items';
    const TABLE_TICKETS       = 'me_t_tickets';
    const TABLE_REPORTS       = 'me_t_event_reports';
    const TABLE_PAYOUTS       = 'me_t_event_payouts';

    const ORDER_STATUSES = [
        'pending',          // awaiting payment (Stripe session open)
        'paid',             // Stripe payment completed
        'free_confirmed',   // 0-cost order confirmed without Stripe
        'refunded',         // fully refunded
        'partly_refunded',  // partially refunded
        'cancelled',        // abandoned / failed
    ];

    const TICKET_STATUSES = [ 'valid', 'scanned', 'refunded' ];

    /**
     * Register ticket tables with the core change-history service.
     *
     * @param array<string,array> $tables Existing table definitions.
     * @return array<string,array>
     */
    public static function register_history_tables( array $tables ): array {
        $ignore     = [ 'created', 'updated', 'created_at', 'updated_at' ];
        $event_link = static function ( array $row ): array {
            return [ 'event_id' => (int) ( $row['event_id'] ?? 0 ) ];
        };

        $tables['met_event_settings'] = [
            'table'  => self::TABLE_SETTINGS,
            'pk'     => [ 'event_id' ],
            'auto_increment' => false,
            'label'  => __( 'Ticket settings', 'pcio-vis-tickets' ),
            'title'  => static function ( array $row ): string {
                return sprintf(
                    /* translators: %d: event ID. */
                    __( 'Event #%d ticket settings', 'pcio-vis-tickets' ),
                    (int) ( $row['event_id'] ?? 0 )
                );
            },
            'weight' => 3,
            'ignore' => $ignore,
            'fields' => [
                'max_guests'             => __( 'Guest limit', 'pcio-vis-tickets' ),
                'is_soldout'             => __( 'Sold out', 'pcio-vis-tickets' ),
                'hide_remaining'         => __( 'Hide remaining', 'pcio-vis-tickets' ),
                'max_entrance_per_buyer' => __( 'Max entrance tickets per buyer', 'pcio-vis-tickets' ),
            ],
            'links'  => $event_link,
        ];

        $tables['met_ticket_types'] = [
            'table'     => self::TABLE_TYPES,
            'label'     => __( 'Ticket type', 'pcio-vis-tickets' ),
            'title_col' => 'name',
            'weight'    => 4,
            'ignore'    => $ignore,
            'fields'    => [
                'name'        => __( 'Name', 'pcio-vis-tickets' ),
                'access'      => __( 'Who can purchase', 'pcio-vis-tickets' ),
                'is_entrance' => __( 'Entrance ticket', 'pcio-vis-tickets' ),
                'price_cents' => __( 'Price (cents)', 'pcio-vis-tickets' ),
                'currency'    => __( 'Currency', 'pcio-vis-tickets' ),
                'qty_offered' => __( 'Quantity offered', 'pcio-vis-tickets' ),
                'qty_sold'    => __( 'Quantity sold', 'pcio-vis-tickets' ),
                'sort'        => __( 'Sort order', 'pcio-vis-tickets' ),
                'active'      => __( 'Active', 'pcio-vis-tickets' ),
                'sales_start' => __( 'Sales start', 'pcio-vis-tickets' ),
                'sales_stop'  => __( 'Sales stop', 'pcio-vis-tickets' ),
            ],
            'links'     => $event_link,
        ];

        $tables['met_customers'] = [
            'table'    => self::TABLE_CUSTOMERS,
            'label'    => __( 'Ticket customer', 'pcio-vis-tickets' ),
            'title_col' => 'email',
            'undoable' => false,
            'weight'   => 2,
            'ignore'   => $ignore,
            'fields'   => [
                'email' => __( 'Email', 'pcio-vis-tickets' ),
                'name'  => __( 'Name', 'pcio-vis-tickets' ),
                'phone' => __( 'Phone', 'pcio-vis-tickets' ),
            ],
        ];

        $tables['met_orders'] = [
            'table'     => self::TABLE_ORDERS,
            'label'     => __( 'Ticket order', 'pcio-vis-tickets' ),
            'title_col' => 'order_no',
            'undoable'  => false,
            'weight'    => 6,
            'ignore'    => $ignore,
            'large'     => [ 'summary' ],
            'fields'    => [
                'order_no'              => __( 'Order number', 'pcio-vis-tickets' ),
                'event_id'              => __( 'Event', 'pcio-vis-tickets' ),
                'customer_id'           => __( 'Customer', 'pcio-vis-tickets' ),
                'member_id'             => __( 'Member ID', 'pcio-vis-tickets' ),
                'email'                 => __( 'Email', 'pcio-vis-tickets' ),
                'status'                => __( 'Status', 'pcio-vis-tickets' ),
                'summary'               => __( 'Summary', 'pcio-vis-tickets' ),
                'amount_cents'          => __( 'Amount (cents)', 'pcio-vis-tickets' ),
                'fee_cents'             => __( 'Service fee (cents)', 'pcio-vis-tickets' ),
                'refunded_cents'        => __( 'Refunded (cents)', 'pcio-vis-tickets' ),
                'currency'              => __( 'Currency', 'pcio-vis-tickets' ),
                'stripe_session_id'     => __( 'Stripe session', 'pcio-vis-tickets' ),
                'stripe_payment_intent' => __( 'Stripe payment intent', 'pcio-vis-tickets' ),
                'paid_at'               => __( 'Paid at', 'pcio-vis-tickets' ),
                'refunded_at'           => __( 'Refunded at', 'pcio-vis-tickets' ),
            ],
            'links'     => $event_link,
        ];

        $tables['met_order_items'] = [
            'table'    => self::TABLE_ORDER_ITEMS,
            'label'    => __( 'Ticket order item', 'pcio-vis-tickets' ),
            'title'    => static function ( array $row ): string {
                return sprintf(
                    /* translators: 1: order ID, 2: ticket type ID. */
                    __( 'Order #%1$d ticket type #%2$d', 'pcio-vis-tickets' ),
                    (int) ( $row['order_id'] ?? 0 ),
                    (int) ( $row['ticket_type_id'] ?? 0 )
                );
            },
            'undoable' => false,
            'weight'   => 2,
            'ignore'   => $ignore,
            'fields'   => [
                'order_id'         => __( 'Order', 'pcio-vis-tickets' ),
                'ticket_type_id'   => __( 'Ticket type', 'pcio-vis-tickets' ),
                'qty'              => __( 'Quantity', 'pcio-vis-tickets' ),
                'unit_price_cents' => __( 'Unit price (cents)', 'pcio-vis-tickets' ),
            ],
            'links'    => static function ( array $row ): array {
                return self::event_link_for_order( (int) ( $row['order_id'] ?? 0 ) );
            },
        ];

        $tables['met_tickets'] = [
            'table'     => self::TABLE_TICKETS,
            'label'     => __( 'Ticket', 'pcio-vis-tickets' ),
            'title_col' => 'code',
            'undoable'  => false,
            'weight'    => 3,
            'ignore'    => $ignore,
            'fields'    => [
                'order_id'       => __( 'Order', 'pcio-vis-tickets' ),
                'ticket_type_id' => __( 'Ticket type', 'pcio-vis-tickets' ),
                'code'           => __( 'Code', 'pcio-vis-tickets' ),
                'attendee_name'  => __( 'Attendee name', 'pcio-vis-tickets' ),
                'status'         => __( 'Status', 'pcio-vis-tickets' ),
            ],
            'links'     => static function ( array $row ): array {
                return self::event_link_for_order( (int) ( $row['order_id'] ?? 0 ) );
            },
        ];

        $tables['met_event_reports'] = [
            'table'     => self::TABLE_REPORTS,
            'label'     => __( 'Event report', 'pcio-vis-tickets' ),
            'title_col' => 'event_id',
            'title'     => static function ( array $row ): string {
                return sprintf(
                    /* translators: 1: event ID, 2: currency. */
                    __( 'Event #%1$d report (%2$s)', 'pcio-vis-tickets' ),
                    (int) ( $row['event_id'] ?? 0 ),
                    (string) ( $row['currency'] ?? '' )
                );
            },
            'undoable'  => false,
            'weight'    => 5,
            'ignore'    => $ignore,
            'fields'    => [
                'event_id'    => __( 'Event', 'pcio-vis-tickets' ),
                'event_end'   => __( 'Event end', 'pcio-vis-tickets' ),
                'currency'    => __( 'Currency', 'pcio-vis-tickets' ),
                'mode'        => __( 'Stripe mode', 'pcio-vis-tickets' ),
                'status'      => __( 'Status', 'pcio-vis-tickets' ),
                'mail_sent_at'=> __( 'Report mailed at', 'pcio-vis-tickets' ),
                'last_error'  => __( 'Last error', 'pcio-vis-tickets' ),
            ],
            'links'     => $event_link,
        ];

        $tables['met_event_payouts'] = [
            'table'     => self::TABLE_PAYOUTS,
            'label'     => __( 'Event payout', 'pcio-vis-tickets' ),
            'title_col' => 'id',
            'title'     => static function ( array $row ): string {
                return sprintf(
                    /* translators: 1: event ID, 2: payout amount (minor units). */
                    __( 'Event #%1$d payout (%2$d)', 'pcio-vis-tickets' ),
                    (int) ( $row['event_id'] ?? 0 ),
                    (int) ( $row['payout_amount'] ?? 0 )
                );
            },
            'undoable'  => false,
            'weight'    => 5,
            'ignore'    => $ignore,
            'fields'    => [
                'event_report_id'                   => __( 'Report', 'pcio-vis-tickets' ),
                'event_id'                          => __( 'Event', 'pcio-vis-tickets' ),
                'mode'                              => __( 'Stripe mode', 'pcio-vis-tickets' ),
                'description'                       => __( 'Description', 'pcio-vis-tickets' ),
                'payout_amount'                     => __( 'Amount (minor units)', 'pcio-vis-tickets' ),
                'stripe_payout_id'                  => __( 'Stripe payout', 'pcio-vis-tickets' ),
                'stripe_balance_transaction_id'     => __( 'Stripe balance transaction', 'pcio-vis-tickets' ),
                'stripe_status'                     => __( 'Stripe status', 'pcio-vis-tickets' ),
                'payout_date'                       => __( 'Arrival date', 'pcio-vis-tickets' ),
            ],
            'links'     => $event_link,
        ];

        return $tables;
    }

    /**
     * Resolve an order row to its event link for history tabs.
     *
     * @return array{event_id?:int}
     */
    private static function event_link_for_order( int $order_id ): array {
        global $wpdb;
        if ( $order_id < 1 ) {
            return [];
        }
        $event_id = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT event_id FROM %i WHERE id = %d', self::t_orders(), $order_id )
        );
        return $event_id > 0 ? [ 'event_id' => $event_id ] : [];
    }

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
    public static function t_event_reports(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_REPORTS;
    }
    public static function t_event_payouts(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_PAYOUTS;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        if ( get_option( 'pcio_met_db_version' ) === PCIO_VIS_TICKETS_DB_VERSION ) {
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
        $reports  = self::t_event_reports();
        $payouts  = self::t_event_payouts();

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
                access       VARCHAR(20)  NOT NULL DEFAULT '',
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
                member_id             INT          NOT NULL DEFAULT 0,
                email                 VARCHAR(190) NOT NULL DEFAULT '',
                status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
                summary               TEXT,
                amount_cents          INT          NOT NULL DEFAULT 0,
                fee_cents             INT          NOT NULL DEFAULT 0,
                refunded_cents        INT          NOT NULL DEFAULT 0,
                currency              VARCHAR(3)   NOT NULL DEFAULT 'DKK',
                stripe_mode           VARCHAR(10)  NOT NULL DEFAULT '',
                stripe_session_id     VARCHAR(255) NULL DEFAULT NULL,
                stripe_payment_intent VARCHAR(255) NULL DEFAULT NULL,
                created               DATETIME     DEFAULT CURRENT_TIMESTAMP,
                paid_at               DATETIME     NULL DEFAULT NULL,
                refunded_at           DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_orders_token (token),
                UNIQUE KEY idx_orders_no (order_no),
                KEY idx_orders_event (event_id),
                KEY idx_orders_member_event (member_id, event_id),
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
            "CREATE TABLE IF NOT EXISTS {$reports} (
                id            INT          NOT NULL AUTO_INCREMENT,
                event_id      INT          NOT NULL,
                currency      VARCHAR(3)   NOT NULL DEFAULT 'DKK',
                mode          VARCHAR(10)  NOT NULL DEFAULT 'test',
                event_end     DATETIME     NULL DEFAULT NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
                mail_sent_at  DATETIME     NULL DEFAULT NULL,
                last_error    VARCHAR(190) NULL DEFAULT NULL,
                created       DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated       DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_reports_event (event_id),
                KEY idx_reports_status (status)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$payouts} (
                id                            INT          NOT NULL AUTO_INCREMENT,
                event_report_id               INT          NOT NULL,
                event_id                      INT          NOT NULL,
                mode                          VARCHAR(10)  NOT NULL DEFAULT 'test',
                description                   VARCHAR(255) NOT NULL DEFAULT '',
                payout_amount                 INT          NOT NULL DEFAULT 0,
                stripe_payout_id              VARCHAR(64)  NOT NULL DEFAULT '',
                stripe_balance_transaction_id VARCHAR(64)  NOT NULL DEFAULT '',
                stripe_status                 VARCHAR(20)  NOT NULL DEFAULT '',
                payout_date                   DATETIME     NULL DEFAULT NULL,
                created                       DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated                       DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_payouts_report (event_report_id),
                KEY idx_payouts_event (event_id)
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
                scanned_at     DATETIME     NULL DEFAULT NULL,
                created        DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_tickets_code (code),
                KEY idx_tickets_order (order_id)
                ) {$charset};"
        );

        // Column added after 1.0.0; dbDelta only adds tables, so patch up here.
        $tickets_table = self::t_tickets();
        $cols          = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $tickets_table ), 0 );
        if ( ! in_array( 'scanned_at', (array) $cols, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-off column back-fill for installs upgraded from 1.0.0.
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN scanned_at DATETIME NULL DEFAULT NULL', $tickets_table ) );
        }

        $types_table = self::t_types();
        $type_cols   = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $types_table ), 0 );
        if ( ! in_array( 'access', (array) $type_cols, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Column added after 1.2.0.
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN access VARCHAR(20) NOT NULL DEFAULT '' AFTER name", $types_table ) );
        }

        $order_cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $orders ), 0 );
        if ( ! in_array( 'member_id', (array) $order_cols, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Column added after 1.1.0.
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN member_id INT NOT NULL DEFAULT 0 AFTER customer_id', $orders ) );
            $order_cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $orders ), 0 );
        }
        if ( ! in_array( 'member_id', (array) $order_cols, true ) ) {
            return;
        }

        // Service-fee share of `amount_cents` (fee pass-through, 1.9.0). dbDelta
        // only adds columns to new tables, so patch existing ones directly.
        if ( ! in_array( 'fee_cents', (array) $order_cols, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Column added after 1.9.0.
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN fee_cents INT NOT NULL DEFAULT 0 AFTER amount_cents', $orders ) );
            $order_cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $orders ), 0 );
        }

        // Stripe test/live segregation (1.8.0). Orders record the mode they were
        // created in so event reports and payouts never mix the two accounts.
        if ( ! in_array( 'stripe_mode', (array) $order_cols, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Column added after 1.8.0.
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN stripe_mode VARCHAR(10) NOT NULL DEFAULT '' AFTER currency", $orders ) );
            // Back-fill legacy rows: checkout session ids carry the mode.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET stripe_mode = 'test' WHERE stripe_mode = '' AND stripe_session_id LIKE %s",
                    $orders,
                    'cs_test_%'
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET stripe_mode = 'live' WHERE stripe_mode = '' AND stripe_session_id LIKE %s",
                    $orders,
                    'cs_live_%'
                )
            );
            // Remaining rows are free orders (zero value) or unresolved; they are
            // attributed to the currently active mode so no money is ever moved
            // from an unknown mode.
            $current = PCIO_VIS_Tickets_Admin::stripe_mode();
            $wpdb->query(
                $wpdb->prepare( "UPDATE %i SET stripe_mode = %s WHERE stripe_mode = ''", $orders, $current )
            );
        }

        // Composite member-order index added after 1.1.0. dbDelta does not diff
        // indexes on existing tables, so add it explicitly for upgraded installs.
        $order_indexes = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $orders ), 2 );
        if ( ! in_array( 'idx_orders_member_event', (array) $order_indexes, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Index added after 1.1.0.
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY idx_orders_member_event (member_id, event_id)', $orders ) );
        }

        foreach ( [ $reports, $payouts ] as $table ) {
            $cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
            if ( ! in_array( 'mode', (array) $cols, true ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Column added after 1.8.0.
                $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT ''", $table ) );
                // Rows created before the split belong to the mode that was in
                // play when they were written; tag them with the active mode so
                // they stay visible and payable instead of turning into `test`.
                $wpdb->query(
                    $wpdb->prepare( "UPDATE %i SET mode = %s WHERE mode = ''", $table, PCIO_VIS_Tickets_Admin::stripe_mode() )
                );
            }
        }

        // 1.9.1: orders imported by the Fjordglimt migration script after the
        // 1.8.0 column patch keep an empty `stripe_mode`. Left alone they are
        // invisible to both modes, so their reports read 0. Infer the mode from
        // the checkout-session prefix; remaining rows are legacy production
        // sales (live) unless a site filters this to a different default.
        $legacy_mode = (string) apply_filters( 'pcio_met_legacy_order_mode', 'live' );
        $legacy_mode = 'test' === $legacy_mode ? 'test' : 'live';
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- internal table names, no user input.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET stripe_mode = 'test' WHERE stripe_mode = '' AND stripe_session_id LIKE %s",
                $orders,
                'cs_test_%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET stripe_mode = 'live' WHERE stripe_mode = '' AND stripe_session_id LIKE %s",
                $orders,
                'cs_live_%'
            )
        );
        $wpdb->query( $wpdb->prepare( "UPDATE %i SET stripe_mode = %s WHERE stripe_mode = ''", $orders, $legacy_mode ) );

        // 1.9.2: one report per (event, currency). Earlier versions created one
        // per Stripe mode even though the report data is mode-neutral; merge the
        // duplicates and keep their payout postings attached to the keeper.
        $report_rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, event_id, currency FROM %i ORDER BY event_id ASC, currency ASC, id ASC", $reports ),
            ARRAY_A
        ) ?: [];
        $keeper_for = [];
        foreach ( $report_rows as $report_row ) {
            $key = (int) $report_row['event_id'] . '|' . (string) $report_row['currency'];
            if ( ! isset( $keeper_for[ $key ] ) ) {
                $keeper_for[ $key ] = (int) $report_row['id'];
                continue;
            }
            $keeper = $keeper_for[ $key ];
            $extra  = (int) $report_row['id'];
            $wpdb->update( $payouts, [ 'event_report_id' => $keeper ], [ 'event_report_id' => $extra ], [ '%d' ], [ '%d' ] );
            $wpdb->delete( $reports, [ 'id' => $extra ], [ '%d' ] );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        // Materialise reports for every event that already has confirmed orders
        // (the old per-mode implementation only created rows for some events,
        // and its cleanup removed others). Estimate-based and side-effect free.
        if ( class_exists( 'PCIO_VIS_Tickets_Reports' ) ) {
            PCIO_VIS_Tickets_Reports::refresh_all_reports();
        }

        // Verify the critical columns before claiming the schema is current: a
        // failed ALTER must not pin the version and permanently skip the patch.
        $final_cols = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $orders ), 0 );
        foreach ( [ 'member_id', 'stripe_mode', 'fee_cents' ] as $required ) {
            if ( ! in_array( $required, $final_cols, true ) ) {
                return; // retry on the next admin request
            }
        }

        $final_type_cols = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::t_types() ), 0 );
        if ( ! in_array( 'access', $final_type_cols, true ) ) {
            return; // retry on the next admin request
        }

        update_option( 'pcio_met_db_version', PCIO_VIS_TICKETS_DB_VERSION );
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
     * Return the subset of the given event IDs flagged as sold out (one query).
     *
     * @param int[] $event_ids Event IDs to check.
     * @return int[]
     */
    public static function get_soldout_event_ids( array $event_ids ): array {
        global $wpdb;
        $event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
        if ( ! $event_ids ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d, one argument per event id.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT event_id FROM %i WHERE is_soldout = 1 AND event_id IN ($placeholders)",
                self::t_settings(),
                ...$event_ids
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( 'intval', $ids );
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
            PCIO_VIS_History::update(
                'met_event_settings',
                $merged + [ 'updated' => current_time( 'mysql' ) ],
                [ 'event_id' => $event_id ],
                [ '%d', '%d', '%d', '%d', '%s' ],
                [ '%d' ]
            );
        } else {
            PCIO_VIS_History::insert(
                'met_event_settings',
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
        if ( empty( $clean['access'] ) ) {
            $event = PCIO_VIS_Events_DB::get( $event_id );
            $clean['access'] = $event
                ? PCIO_VIS_Events_DB::default_access_for_event( $event )
                : PCIO_VIS_Events_DB::ACCESS_MEMBERS;
        }
        PCIO_VIS_History::insert(
            'met_ticket_types',
            [
                'event_id'    => $event_id,
                'name'        => $clean['name'] ?? '',
                'access'      => $clean['access'] ?? '',
                'is_entrance' => $clean['is_entrance'] ?? 1,
                'price_cents' => $clean['price_cents'] ?? 0,
                'currency'    => $clean['currency'] ?? 'DKK',
                'qty_offered' => $clean['qty_offered'] ?? 0,
                'active'      => $clean['active'] ?? 1,
                'sales_start' => $clean['sales_start'] ?? null,
                'sales_stop'  => $clean['sales_stop'] ?? null,
                'sort'        => $next_sort,
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d' ]
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
            $formats[] = in_array( $field, [ 'name', 'access', 'currency', 'sales_start', 'sales_stop' ], true ) ? '%s' : '%d';
        }
        $clean['updated'] = current_time( 'mysql' );
        $formats[]        = '%s';
        return false !== PCIO_VIS_History::update( 'met_ticket_types', $clean, [ 'id' => $id ], $formats, [ '%d' ] );
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
        return false !== PCIO_VIS_History::delete( 'met_ticket_types', [ 'id' => $id ], [ '%d' ] );
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

    /** Resolve a ticket type's access, falling back to the event type default. */
    public static function type_access( array $type, array $event ): string {
        return PCIO_VIS_Events_DB::ticket_access( $type, $event );
    }

    /** Whether the current user may purchase this ticket type. */
    public static function can_purchase_type( array $type, array $event ): bool {
        return PCIO_VIS_Events_DB::can_access_level( self::type_access( $type, $event ), $event );
    }

    public static function requires_registered_customer( array $event ): bool {
        if ( self::is_registered_access( PCIO_VIS_Events_DB::event_access( $event ) ) ) {
            return true;
        }
        $event_id = (int) ( $event['id'] ?? 0 );
        if ( $event_id < 1 ) {
            return false;
        }
        foreach ( self::get_types( $event_id ) as $type ) {
            if ( empty( $type['active'] ) || ! self::can_purchase_type( $type, $event ) ) {
                continue;
            }
            if ( self::is_registered_access( self::type_access( $type, $event ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function current_registered_customer(): ?array {
        if ( ! is_user_logged_in() || ! class_exists( 'PCIO_VIS_Signups_DB' ) || ! class_exists( 'PCIO_VIS_DB' ) ) {
            return null;
        }
        $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();
        if ( ! $member_id ) {
            return null;
        }
        $member = PCIO_VIS_DB::get( (int) $member_id );
        if ( ! $member ) {
            return null;
        }
        $name  = sanitize_text_field( (string) ( $member['name'] ?? '' ) );
        $email = sanitize_email( (string) ( $member['email'] ?? '' ) );
        if ( '' === $name || ! is_email( $email ) ) {
            return null;
        }
        return [
            'member_id' => (int) $member_id,
            'name'      => $name,
            'email'     => $email,
            'phone'     => sanitize_text_field( (string) ( $member['phone'] ?? '' ) ),
        ];
    }

    public static function member_has_active_guest_signup( int $event_id, int $member_id ): bool {
        if ( ! class_exists( 'PCIO_VIS_Signups_DB' ) ) {
            return false;
        }
        $signup = PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id );
        return $signup && in_array( (string) $signup['status'], [ 'joining', 'arrived' ], true );
    }

    public static function member_has_entrance_order( int $event_id, int $member_id ): bool {
        if ( $event_id < 1 || $member_id < 1 ) {
            return false;
        }
        global $wpdb;
        $pending = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1
                 FROM %i o
                 INNER JOIN %i oi ON oi.order_id = o.id
                 INNER JOIN %i tt ON tt.id = oi.ticket_type_id
                 WHERE o.event_id = %d
                   AND o.member_id = %d
                   AND o.status = %s
                   AND tt.is_entrance = 1
                   AND oi.qty > 0
                 LIMIT 1',
                self::t_orders(),
                self::t_order_items(),
                self::t_types(),
                $event_id,
                $member_id,
                'pending'
            )
        );
        if ( $pending ) {
            return true;
        }
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                 FROM %i o
                 INNER JOIN %i t ON t.order_id = o.id
                 INNER JOIN %i tt ON tt.id = t.ticket_type_id
                 WHERE o.event_id = %d
                   AND o.member_id = %d
                   AND t.status IN ('valid', 'scanned')
                   AND tt.is_entrance = 1
                 LIMIT 1",
                self::t_orders(),
                self::t_tickets(),
                self::t_types(),
                $event_id,
                $member_id
            )
        );
    }

    public static function order_has_entrance_item( int $order_id ): bool {
        if ( $order_id < 1 ) {
            return false;
        }
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1
                 FROM %i oi
                 INNER JOIN %i tt ON tt.id = oi.ticket_type_id
                 WHERE oi.order_id = %d
                   AND oi.qty > 0
                   AND tt.is_entrance = 1
                 LIMIT 1',
                self::t_order_items(),
                self::t_types(),
                $order_id
            )
        );
    }

    private static function is_registered_access( string $access ): bool {
        return in_array(
            $access,
            [
                PCIO_VIS_Events_DB::ACCESS_MEMBERS,
                PCIO_VIS_Events_DB::ACCESS_VOLUNTEERS,
                PCIO_VIS_Events_DB::ACCESS_ACTIVE_VOLUNTEERS,
                PCIO_VIS_Events_DB::ACCESS_INVITED,
            ],
            true
        );
    }

    /**
     * Why a ticket type is or is not sellable right now.
     *
     * @return string on_sale|upcoming|ended|soldout|inactive
     */
    public static function type_sale_state( array $type ): string {
        if ( empty( $type['active'] ) ) {
            return 'inactive';
        }
        $event_id = (int) $type['event_id'];
        $settings = self::get_settings( $event_id );
        if ( $settings['is_soldout'] ) {
            return 'soldout';
        }
        $now = time();
        if ( ! empty( $type['sales_start'] ) && $now < strtotime( $type['sales_start'] ) ) {
            return 'upcoming';
        }
        if ( ! empty( $type['sales_stop'] ) && $now > strtotime( $type['sales_stop'] ) ) {
            return 'ended';
        }
        if ( $type['qty_offered'] > 0 && $type['qty_sold'] >= $type['qty_offered'] ) {
            return 'soldout';
        }
        if ( ! empty( $type['is_entrance'] ) && self::entrance_available( $event_id ) < 1 ) {
            return 'soldout';
        }
        return 'on_sale';
    }

    /** Whether a given ticket type can currently be sold. */
    public static function type_on_sale( array $type ): bool {
        return 'on_sale' === self::type_sale_state( $type );
    }

    /**
     * Purchase state for the current visitor, used by the event ticket box and
     * the checkout page. Distinguishes access restrictions from a sale that has
     * not started, ended or sold out.
     *
     * @return array{state:string,access:string,message:string}
     */
    public static function purchase_state( array $event ): array {
        $event_id = (int) $event['id'];
        $types    = self::get_types( $event_id );
        if ( ! $types ) {
            return [
                'state'   => 'none',
                'access'  => '',
                'message' => __( 'Tickets are not available yet.', 'pcio-vis-tickets' ),
            ];
        }

        $allowed_states = [];
        $denied_access  = '';
        foreach ( $types as $type ) {
            if ( self::can_purchase_type( $type, $event ) ) {
                $allowed_states[] = self::type_sale_state( $type );
            } elseif ( '' === $denied_access ) {
                $denied_access = self::type_access( $type, $event );
            }
        }

        if ( ! $allowed_states ) {
            return [
                'state'   => 'restricted',
                'access'  => $denied_access,
                'message' => PCIO_VIS_Events_DB::access_denied_message( $denied_access ),
            ];
        }
        if ( in_array( 'on_sale', $allowed_states, true ) ) {
            return [ 'state' => 'on_sale', 'access' => '', 'message' => '' ];
        }
        if ( in_array( 'upcoming', $allowed_states, true ) ) {
            return [
                'state'   => 'upcoming',
                'access'  => '',
                'message' => __( 'Ticket sales have not started yet.', 'pcio-vis-tickets' ),
            ];
        }
        if ( in_array( 'soldout', $allowed_states, true ) ) {
            return [ 'state' => 'soldout', 'access' => '', 'message' => __( 'Sold out', 'pcio-vis-tickets' ) ];
        }
        if ( in_array( 'ended', $allowed_states, true ) ) {
            return [
                'state'   => 'ended',
                'access'  => '',
                'message' => __( 'Ticket sales have ended.', 'pcio-vis-tickets' ),
            ];
        }
        return [
            'state'   => 'unavailable',
            'access'  => '',
            'message' => __( 'Tickets are not available right now.', 'pcio-vis-tickets' ),
        ];
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
                PCIO_VIS_History::update(
                    'met_customers',
                    array_filter( [
                        'name'  => sanitize_text_field( $name ),
                        'phone' => sanitize_text_field( $phone ),
                    ], static fn( $v ) => $v !== '' ),
                    [ 'id' => $id ]
                );
            }
            return $id;
        }
        PCIO_VIS_History::insert(
            'met_customers',
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
        PCIO_VIS_History::insert(
            'met_orders',
            [
                'order_no'     => self::generate_order_no(),
                'token'        => self::generate_token(),
                'event_id'     => (int) ( $data['event_id'] ?? 0 ),
                'customer_id'  => (int) ( $data['customer_id'] ?? 0 ),
                'member_id'    => absint( $data['member_id'] ?? 0 ),
                'email'        => sanitize_email( $data['email'] ?? '' ),
                'status'       => in_array( $data['status'] ?? 'pending', self::ORDER_STATUSES, true ) ? $data['status'] : 'pending',
                'summary'      => wp_kses_post( $data['summary'] ?? '' ),
                'amount_cents' => (int) ( $data['amount_cents'] ?? 0 ),
                'fee_cents'    => max( 0, (int) ( $data['fee_cents'] ?? 0 ) ),
                'currency'     => substr( strtoupper( $data['currency'] ?? 'DKK' ), 0, 3 ),
                'stripe_mode'  => PCIO_VIS_Tickets_Admin::stripe_mode(),
            ],
            [ '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
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
        return false !== PCIO_VIS_History::update( 'met_orders', $data, [ 'id' => $id ], $formats, [ '%d' ] );
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
        PCIO_VIS_History::insert(
            'met_order_items',
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
        PCIO_VIS_History::insert(
            'met_tickets',
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

    public static function get_ticket( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::t_tickets(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Unused (valid) ticket ids for one order + type, oldest first. */
    public static function unused_ticket_ids( int $order_id, int $type_id, int $limit ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $limit is intvalled below.
        $rows = $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM %i WHERE order_id = %d AND ticket_type_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
            self::t_tickets(), $order_id, $type_id, 'valid', max( 0, $limit )
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /** Mark tickets as scanned (self-activated at the door). */
    public static function mark_tickets_scanned( array $ids ): int {
        if ( empty( $ids ) ) {
            return 0;
        }
        $ids = array_map( 'intval', $ids );
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %d and prepare() receives the values as an array.
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET status = 'scanned', scanned_at = %s WHERE id IN ($placeholders) AND status = 'valid'",
            array_merge( [ self::t_tickets(), current_time( 'mysql' ) ], $ids )
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
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
            'access'      => (string) ( $row['access'] ?? '' ),
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
        if ( array_key_exists( 'access', $data ) ) {
            $access      = sanitize_key( (string) $data['access'] );
            $out['access'] = in_array( $access, PCIO_VIS_Events_DB::ACCESS_LEVELS, true ) ? $access : '';
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
