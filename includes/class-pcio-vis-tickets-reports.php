<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data-access layer for the plugin's own ticket-report tables; figures are calculated live and must not be cached.

/**
 * Event reports + automatic payouts for the Tickets extension.
 *
 * Port of the legacy Fjordglimt event-report system
 * (event_rapporter.php / event_rapport_bizz.php / event_rapport_payout.php /
 * event_rapport_send.php / event_rapport_print.php):
 *
 *   • One report row per (event, currency) with confirmed ticket orders.
 *     Amounts are calculated live from `me_t_orders` on every read, mirroring
 *     the legacy `calculate_event_totals()` — revenue, refunds, fee income
 *     (currently always 0; per-order fees are a separate work package), Stripe
 *     cost and the net amount.
 *   • A daily cron job creates/refreshes reports, pays out finished events
 *     through Stripe (optional toggle + configurable payout delay in days),
 *     flags reports that wait for the Stripe balance, and mails the report
 *     PDF to the configured receivers.
 *   • Reports are visible on the Finance tab (GET /tickets/reports) for
 *     everyone holding the `vis_manage_finance` capability.
 *
 * Stripe cost uses the exact fees from the payment intents' balance
 * transactions when the API call succeeds, and falls back to configurable
 * constants (base fee per transaction + percent of gross) otherwise.
 */
class PCIO_VIS_Tickets_Reports {

    // ── Options (managed on the Vis → Tickets settings page) ─────
    const OPT_AUTO_PAYOUT = 'pcio_met_auto_payout';
    const OPT_DELAY_DAYS  = 'pcio_met_report_delay_days';
    const OPT_FEE_BASE    = 'pcio_met_fee_base_cents';
    const OPT_FEE_PERCENT = 'pcio_met_fee_percent';
    const OPT_RECEIVERS   = 'pcio_met_report_receivers';

    const DEFAULT_DELAY_DAYS    = 3;
    const MIN_PAYOUT_CENTS      = 2000; // 20 kr — Stripe minimum payout used by the legacy system.
    const DEFAULT_FEE_BASE_CENT = 250;  // 2.50 kr per transaction.
    const DEFAULT_FEE_PERCENT   = 1.7;  // percent of gross.

    // ── Report statuses ───────────────────────────────────────────
    const STATUS_FREE     = 'free';           // only free (0 cost) orders
    const STATUS_PENDING  = 'pending';        // payout delay not passed yet
    const STATUS_READY    = 'ready';          // due to be paid out
    const STATUS_AWAITING = 'awaiting-funds'; // due, but Stripe balance is short
    const STATUS_CLAIMED  = 'payouting';      // transient state while talking to Stripe
    const STATUS_PAID     = 'paid';           // everything netted out
    const STATUS_FAILED   = 'failed';         // last Stripe attempt failed (retried next run)

    const CLAIM_TIMEOUT_MINUTES = 120;          // stale claim recovery
    private static bool $bulk_refresh = false;  // bulk mode skips Stripe network lookups
    private static array $pi_fee_cache = [];    // payment intent => fee cents (per request)

    // ── Settings accessors ────────────────────────────────────────

    /**
     * Active Stripe mode ('test' | 'live'). Event reports, payouts and the
     * orders they aggregate are strictly separated per mode, so a test run can
     * never move real money and switching modes never re-pays old events.
     */
    public static function mode(): string {
        return PCIO_VIS_Tickets_Admin::stripe_mode();
    }

    /** Whether Stripe calls currently use test keys. Data is never mode-filtered. */
    public static function is_test_mode(): bool {
        return 'live' !== self::mode();
    }

    /**
     * Whether cron emails are silenced for testing. Uses the existing shop cron
     * test flag so there is one test switch for all outgoing ticket/shop mail.
     */
    public static function mail_test_mode(): bool {
        return (bool) apply_filters( 'pcio_met_report_mail_test_mode', (bool) get_option( 'pcio_mep_cron_test_mode', false ) );
    }

    public static function auto_payout_enabled(): bool {
        return (bool) get_option( self::OPT_AUTO_PAYOUT, true );
    }

    public static function delay_days(): int {
        $days = absint( get_option( self::OPT_DELAY_DAYS, self::DEFAULT_DELAY_DAYS ) );
        return max( 0, min( 365, $days ) );
    }

    public static function fee_base_cents(): int {
        return max( 0, (int) get_option( self::OPT_FEE_BASE, self::DEFAULT_FEE_BASE_CENT ) );
    }

    public static function fee_percent(): float {
        $p = (float) get_option( self::OPT_FEE_PERCENT, self::DEFAULT_FEE_PERCENT );
        return max( 0.0, min( 25.0, $p ) );
    }

    /** @return string[] sanitized receiver addresses. */
    public static function receivers(): array {
        return array_values( array_filter( (array) get_option( self::OPT_RECEIVERS, [] ), 'is_string' ) );
    }

    /** Replace the receiver list with the given sanitized addresses. */
    public static function set_receivers( array $emails ): void {
        $clean = [];
        foreach ( $emails as $email ) {
            $email = sanitize_email( (string) $email );
            if ( is_email( $email ) && ! in_array( $email, $clean, true ) ) {
                $clean[] = $email;
            }
        }
        update_option( self::OPT_RECEIVERS, $clean, false );
    }

    /**
     * Remaining amount for a report (net minus everything already paid out).
     */
    public static function remaining_cents( array $totals, int $paid_cents ): int {
        return max( 0, (int) $totals['net_cents'] - $paid_cents );
    }

    // ── Order queries ─────────────────────────────────────────────

    /**
     * Self-heal Stripe modes + stale report rows. Imported/legacy orders may
     * predate the mode column (empty mode = invisible to both modes), and
     * reports created by the pre-mode implementation can end up empty. Both are
     * reconciled here; payout rows are audit data and are never deleted.
     */
    public static function reconcile_modes(): void {
        global $wpdb;
        $orders  = PCIO_VIS_Tickets_DB::t_orders();
        $reports = PCIO_VIS_Tickets_DB::t_event_reports();
        $payouts = PCIO_VIS_Tickets_DB::t_event_payouts();

        $legacy = (string) apply_filters( 'pcio_met_legacy_order_mode', 'live' );
        $legacy = 'test' === $legacy ? 'test' : 'live';

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
        $wpdb->query( $wpdb->prepare( "UPDATE %i SET stripe_mode = %s WHERE stripe_mode = ''", $orders, $legacy ) );

        // Remove reports whose event no longer has any confirmed order and that
        // never had a payout (they render as 0,00 rows from an old scan).
        $wpdb->query(
            $wpdb->prepare(
                "DELETE r FROM %i r
                 LEFT JOIN (
                     SELECT DISTINCT event_id FROM %i
                     WHERE status IN ('paid','partly_refunded','refunded','free_confirmed')
                 ) o ON o.event_id = r.event_id
                 WHERE o.event_id IS NULL
                   AND NOT EXISTS ( SELECT 1 FROM %i p WHERE p.event_report_id = r.id )",
                $reports,
                $orders,
                $payouts
            )
        );
    }

    /** Event IDs that have at least one confirmed ticket order (all Stripe modes). */
    public static function event_ids_with_orders(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT event_id FROM %i WHERE status IN ( %s, %s, %s, %s )',
                PCIO_VIS_Tickets_DB::t_orders(),
                'paid',
                'partly_refunded',
                'refunded',
                'free_confirmed'
            )
        );
        return array_map( 'intval', $rows );
    }

    private static function query_orders( int $event_id ): array {
        return self::query_orders_all( $event_id );
    }

    /** All confirmed orders for one event, regardless of Stripe mode. */
    private static function query_orders_all( int $event_id ): array {
        global $wpdb;
        if ( $event_id < 1 ) {
            return [];
        }
        // SELECT * keeps report calculation alive even if a schema patch
        // (fee_cents / stripe_mode) has not been applied yet; missing columns
        // read as empty and are handled in the calculation.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_id = %d AND status IN ( %s, %s, %s, %s ) ORDER BY id ASC',
                PCIO_VIS_Tickets_DB::t_orders(),
                $event_id,
                'paid',
                'partly_refunded',
                'refunded',
                'free_confirmed'
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Keep only the orders that belong to one Stripe mode.
     *
     * Orders store the mode they were created in; legacy rows are resolved from
     * the checkout-session prefix (`cs_test_` / `cs_live_`). Free orders (zero
     * value) carry no mode risk and fall back to the current mode.
     *
     * @param array[] $orders Rows with stripe_mode / stripe_session_id / status.
     * @param string  $mode   'test' | 'live'.
     * @return array[]
     */
    public static function filter_orders_for_mode( array $orders, string $mode ): array {
        $mode = 'live' === $mode ? 'live' : 'test';
        $out  = [];
        foreach ( $orders as $order ) {
            if ( self::order_mode( $order ) !== $mode ) {
                continue;
            }
            $out[] = $order;
        }
        return $out;
    }

    /** Resolve the Stripe mode an order was created in. */
    public static function order_mode( array $order ): string {
        $stored = strtolower( (string) ( $order['stripe_mode'] ?? '' ) );
        if ( 'test' === $stored || 'live' === $stored ) {
            return $stored;
        }
        $session = (string) ( $order['stripe_session_id'] ?? '' );
        if ( str_starts_with( $session, 'cs_live_' ) ) {
            return 'live';
        }
        if ( str_starts_with( $session, 'cs_test_' ) ) {
            return 'test';
        }
        // Free or legacy rows without a Stripe reference.
        return 'free_confirmed' === (string) ( $order['status'] ?? '' ) ? self::mode() : '';
    }

    // ── Calculations (port of legacy calculate_event_totals) ──────

    /**
     * Pure calculation over confirmed order rows.
     *
     * Buyer-paid service fees are part of `amount_cents` (what Stripe charged)
     * and are split out via `fee_cents`: revenue is the ticket/product share,
     * fee income is the buyer-paid fee (fees are never refunded), and the net
     * formula stays invariant:
     *   net = revenue + fee income − refunded − Stripe cost
     *       = amount − refunded − Stripe cost.
     *
     * @param array[] $order_rows Rows with status, amount_cents, fee_cents,
     *                            refunded_cents, currency, stripe_payment_intent.
     * @return array{currency:string,has_paid:bool,has_free:bool,paid_orders:int,free_orders:int,transactions:int,
     *               revenue_cents:int,refunded_cents:int,fee_income_cents:int,stripe_fee_cents:int,net_cents:int}
     */
    public static function calculate_totals( array $order_rows ): array {
        $revenue    = 0;
        $fee_income = 0;
        $refunded   = 0;
        $has_paid   = false;
        $has_free   = false;
        $currency   = PCIO_VIS_Tickets_Admin::currency();
        $intents    = [];

        foreach ( $order_rows as $order ) {
            $status  = (string) ( $order['status'] ?? '' );
            $amount  = (int) ( $order['amount_cents'] ?? 0 );
            $fee     = max( 0, min( $amount, (int) ( $order['fee_cents'] ?? 0 ) ) );
            $is_free = 'free_confirmed' === $status;
            if ( $is_free ) {
                $has_free = true;
            } else {
                $has_paid    = true;
                $revenue    += $amount - $fee;
                $fee_income += $fee;
                $refunded   += (int) ( $order['refunded_cents'] ?? 0 );
                $intent      = (string) ( $order['stripe_payment_intent'] ?? '' );
                if ( $intent && ! isset( $intents[ $intent ] ) ) {
                    $intents[ $intent ] = self::order_mode( $order );
                }
                if ( ! empty( $order['currency'] ) ) {
                    $currency = strtoupper( (string) $order['currency'] );
                }
            }
        }

        $stripe_fee = self::stripe_fee_for( $intents, $revenue + $fee_income );
        $paid_rows  = array_values( array_filter( $order_rows, static fn( $o ) => 'free_confirmed' !== (string) ( $o['status'] ?? '' ) ) );
        $free_rows  = array_values( array_filter( $order_rows, static fn( $o ) => 'free_confirmed' === (string) ( $o['status'] ?? '' ) ) );

        return [
            'currency'          => $currency,
            'has_paid'          => $has_paid,
            'has_free'          => $has_free,
            'paid_orders'       => count( $paid_rows ),
            'free_orders'       => count( $free_rows ),
            'transactions'      => count( $intents ),
            'revenue_cents'     => $revenue,
            'refunded_cents'    => $refunded,
            'fee_income_cents'  => $fee_income,
            'stripe_fee_cents'  => $stripe_fee,
            'net_cents'         => $revenue + $fee_income - $refunded - $stripe_fee,
        ];
    }

    public static function calculate_for_event( int $event_id ): array {
        return self::calculate_totals( self::query_orders( $event_id ) );
    }

    /**
     * Net amount using the configured fee estimate (no Stripe round-trips).
     * Used by the legacy payout migration to top up historically settled
     * reports without hitting the API.
     */
    public static function net_estimate_for_event( int $event_id ): int {
        self::$bulk_refresh = true;
        try {
            return (int) ( self::calculate_for_event( $event_id )['net_cents'] ?? 0 );
        } finally {
            self::$bulk_refresh = false;
        }
    }

    /**
     * Live totals for a report row. Reports always aggregate every order for
     * the event; the Stripe mode only decides which keys pay it out.
     */
    public static function calculate_for_report( array $report ): array {
        return self::calculate_for_event( (int) ( $report['event_id'] ?? 0 ) );
    }

    /**
     * Stripe mode a report's money belongs to: the unique mode of its paid
     * orders, the active mode for free-only data, or '' when it mixes modes.
     *
     * @param array[] $order_rows
     */
    public static function report_data_mode( array $order_rows ): string {
        $modes = [];
        foreach ( $order_rows as $order ) {
            if ( 'free_confirmed' === (string) ( $order['status'] ?? '' ) ) {
                continue; // zero value, no Stripe money attached
            }
            $mode = self::order_mode( $order );
            if ( 'test' === $mode || 'live' === $mode ) {
                $modes[ $mode ] = true;
            }
        }
        if ( 1 === count( $modes ) ) {
            return (string) array_key_first( $modes );
        }
        return $modes ? '' : self::mode();
    }

    /**
     * Stripe processing fee for an event. Exact fees via balance transactions
     * when possible, configurable-constant estimate otherwise.
     *
     * @param array<string,string> $payment_intents Map payment intent => its Stripe mode.
     *                                              The mode's own key is used so the fee
     *                                              does not change with the active mode.
     */
    public static function stripe_fee_for( array $payment_intents, int $revenue_cents ): int {
        $intents = array_filter( $payment_intents, static fn( $pi ) => '' !== (string) $pi, ARRAY_FILTER_USE_KEY );
        if ( ! $intents ) {
            return 0;
        }

        // Bulk report creation (upgrade/backfill) uses the configured estimate:
        // no Stripe round-trips while creating dozens of reports.
        if ( ! self::$bulk_refresh ) {
            $exact = 0;
            try {
                foreach ( $intents as $intent => $mode ) {
                    $transient = 'pcio_met_fee_' . md5( ( $mode ?: 'auto' ) . '|' . $intent );
                    $cached    = get_transient( $transient );
                    if ( false !== $cached ) {
                        $exact += (int) $cached;
                        continue;
                    }
                    $cache_key = ( $mode ?: 'auto' ) . '|' . $intent;
                    if ( isset( self::$pi_fee_cache[ $cache_key ] ) ) {
                        $exact += (int) self::$pi_fee_cache[ $cache_key ];
                        continue;
                    }
                    $resp = PCIO_VIS_Tickets_Stripe::balance_transactions( (string) $intent, $mode ?: null );
                    if ( is_wp_error( $resp ) || empty( $resp['data'] ) || ! is_array( $resp['data'] ) ) {
                        throw new RuntimeException( 'balance transactions unavailable' );
                    }
                    $fee = 0;
                    foreach ( $resp['data'] as $tx ) {
                        $fee += (int) ( $tx['fee'] ?? 0 );
                    }
                    self::$pi_fee_cache[ $cache_key ] = $fee;
                    set_transient( $transient, $fee, 12 * HOUR_IN_SECONDS );
                    $exact += $fee;
                }
                return $exact;
            } catch ( RuntimeException $e ) {
                // Fall through to the estimate.
            }
        }

        $base    = self::fee_base_cents();
        $percent = self::fee_percent();
        return count( $intents ) * $base + (int) round( $revenue_cents * $percent / 100 );
    }

    /**
     * Create/refresh a report for every event with confirmed orders (upgrade
     * backfill and "Run now"). Uses the fee estimate, never moves money and
     * never sends mail.
     *
     * @return int Number of reports present afterwards.
     */
    public static function refresh_all_reports(): int {
        self::$bulk_refresh = true;
        $count = 0;
        try {
            foreach ( self::event_ids_with_orders() as $event_id ) {
                if ( self::refresh_report( $event_id ) ) {
                    $count++;
                }
            }
        } finally {
            self::$bulk_refresh = false;
        }
        return $count;
    }

    /** Cheap coverage diagnostic for the admin screen. */
    public static function coverage(): array {
        global $wpdb;
        $orders_table  = PCIO_VIS_Tickets_DB::t_orders();
        $reports_table = PCIO_VIS_Tickets_DB::t_event_reports();

        $events = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT event_id) FROM %i WHERE status IN ( %s, %s, %s, %s )',
                $orders_table,
                'paid',
                'partly_refunded',
                'refunded',
                'free_confirmed'
            )
        );
        $reports = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $reports_table ) );

        $by_status = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i GROUP BY status', $orders_table ), ARRAY_A ) as $row ) {
            $by_status[ (string) $row['status'] ] = (int) $row['c'];
        }

        $columns = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $orders_table ) );
        $missing = array_values( array_diff( [ 'stripe_mode', 'fee_cents' ], $columns ) );

        return [
            'events'          => $events,
            'reports'         => $reports,
            'orders'          => array_sum( $by_status ),
            'by_status'       => $by_status,
            'missing_columns' => $missing,
            'sql_error'       => (string) $wpdb->last_error,
        ];
    }

    /**
     * Per-ticket-type breakdown for the report PDF.
     *
     * @return array[] [ name, qty, unit_price_cents, revenue_cents ]
     */
public static function ticket_type_breakdown( int $event_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.name AS type_name, oi.qty, oi.unit_price_cents
                 FROM %i oi
                 INNER JOIN %i o ON o.id  = oi.order_id
                 INNER JOIN %i tt ON tt.id = oi.ticket_type_id
                 WHERE o.event_id = %d AND o.status IN ( %s, %s, %s )
                 ORDER BY tt.sort ASC, tt.name ASC",
                PCIO_VIS_Tickets_DB::t_order_items(),
                PCIO_VIS_Tickets_DB::t_orders(),
                PCIO_VIS_Tickets_DB::t_types(),
                $event_id,
                'paid',
                'partly_refunded',
                'free_confirmed'
            ),
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ( $rows as $row ) {
            $key   = (string) $row['type_name'];
            $price = (int) $row['unit_price_cents'];
            if ( ! isset( $out[ $key ] ) ) {
                $out[ $key ] = [
                    'name'          => $key,
                    'qty'           => 0,
                    'unit_price'    => $price,
                    'revenue_cents' => 0,
                ];
            }
            $out[ $key ]['qty']           += (int) $row['qty'];
            $out[ $key ]['revenue_cents'] += (int) $row['qty'] * $price;
        }
        return array_values( $out );
    }

    // ── Status machine (pure) ─────────────────────────────────────

    /**
     * Derive the stored status from live data. Claims and Stripe failures are
     * recovered by the caller (stale claims become ready again; failures retry
     * once the delay is still in the window).
     *
     * @param array $args {
     *   @type bool   $has_paid_orders
     *   @type int    $net_cents
     *   @type int    $paid_cents
     *   @type string $event_end        'Y-m-d H:i:s', may be ''.
     *   @type int    $delay_days
     *   @type string $now              'Y-m-d H:i:s'.
     *   @type string $previous_status
     * }
     */
    public static function compute_status( array $args ): string {
        $has_paid = (bool) ( $args['has_paid_orders'] ?? false );
        $net      = (int) ( $args['net_cents'] ?? 0 );
        $paid     = (int) ( $args['paid_cents'] ?? 0 );
        $previous = (string) ( $args['previous_status'] ?? '' );

        if ( ! $has_paid || $net <= 0 ) {
            return self::STATUS_FREE;
        }
        if ( $paid >= $net ) {
            return self::STATUS_PAID;
        }
        $due = self::is_due( (string) ( $args['event_end'] ?? '' ), (int) ( $args['delay_days'] ?? 0 ), (string) ( $args['now'] ?? '' ) );
        if ( ! $due ) {
            return self::STATUS_PENDING;
        }
        return self::STATUS_AWAITING === $previous ? self::STATUS_AWAITING : self::STATUS_READY;
    }

    /** Whether event_end + delay days is in the past (null end = not due). */
    public static function is_due( string $event_end, int $delay_days, string $now ): bool {
        if ( '' === $event_end ) {
            return false;
        }
        $end = strtotime( $event_end );
        $ref = strtotime( $now );
        if ( ! $end || ! $ref ) {
            return false;
        }
        return $end + $delay_days * DAY_IN_SECONDS <= $ref;
    }

    /**
     * Events without an explicit end (all-day, open-ended) count as finished
     * the day after the start, so their reports can mature like any other.
     */
    public static function effective_event_end( array $event ): string {
        $end = (string) ( $event['end_datetime'] ?? '' );
        if ( '' !== $end && '0000-00-00 00:00:00' !== $end ) {
            return $end;
        }
        $start = (string) ( $event['start_datetime'] ?? '' );
        if ( '' === $start ) {
            return '';
        }
        $ts = strtotime( $start );
        return $ts ? gmdate( 'Y-m-d H:i:s', $ts + DAY_IN_SECONDS ) : '';
    }

    // ── Report CRUD ───────────────────────────────────────────────

    public static function get_report( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', PCIO_VIS_Tickets_DB::t_event_reports(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function find_report( int $event_id, string $currency ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_id = %d AND currency = %s ORDER BY id ASC LIMIT 1',
                PCIO_VIS_Tickets_DB::t_event_reports(),
                $event_id,
                $currency
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Create or refresh the report for one event (live recalculation).
     *
     * @return array|null Report row, or null when the event has no confirmed orders.
     */
    public static function refresh_report( int $event_id, ?int $delay_days = null ): ?array {
        global $wpdb;

        $orders = self::query_orders( $event_id );
        if ( ! $orders ) {
            return null;
        }
        $totals = self::calculate_totals( $orders );
        $event  = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return null;
        }

        $currency = $totals['currency'];
        $data_mode = self::report_data_mode( $orders );
        $report   = self::find_report( $event_id, $currency );
        $now      = current_time( 'mysql' );
        $delay    = null !== $delay_days ? $delay_days : self::delay_days();

        // Recover from claims that never finished (timeout), before status math.
        $previous = '';
        if ( $report ) {
            $previous = (string) $report['status'];
            if ( self::STATUS_CLAIMED === $previous && self::claim_is_stale( $report, $now ) ) {
                $previous = self::STATUS_READY;
            }
        }

        $paid      = (int) self::sum_paid_cents( $report ? (int) $report['id'] : 0 );
        $event_end = self::effective_event_end( $event );
        $status    = self::compute_status( [
            'has_paid_orders' => $totals['has_paid'],
            'net_cents'       => $totals['net_cents'],
            'paid_cents'      => $paid,
            'event_end'       => $event_end,
            'delay_days'      => $delay,
            'now'             => $now,
            'previous_status' => $previous,
        ] );

        if ( ! $report ) {
            $wpdb->insert(
                PCIO_VIS_Tickets_DB::t_event_reports(),
                [
                    'event_id' => $event_id,
                    'currency' => $currency,
                    'mode'     => $data_mode,
                    'event_end'=> $event_end,
                    'status'   => $status,
                ],
                [ '%d', '%s', '%s', '%s', '%s' ]
            );
            $report = self::find_report( $event_id, $currency );
            if ( ! $report ) {
                return null;
            }
        } else {
            $wpdb->update(
                PCIO_VIS_Tickets_DB::t_event_reports(),
                [
                    'event_end' => $event_end,
                    'status'    => $status,
                    'mode'      => $data_mode,
                ],
                [ 'id' => (int) $report['id'] ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            $report['event_end'] = $event_end;
            $report['status']    = $status;
            $report['mode']      = $data_mode;
        }

        $report['paid_cents']    = $paid;
        $report['remaining_cents'] = self::remaining_cents( $totals, $paid );
        $report['totals']        = $totals;
        $report['event_title']   = (string) $event['title'];
        return $report;
    }

    private static function claim_is_stale( array $report, string $now ): bool {
        $updated = strtotime( (string) ( $report['updated'] ?? '' ) );
        $ref     = strtotime( $now );
        if ( ! $updated || ! $ref ) {
            return true;
        }
        return ( $ref - $updated ) > self::CLAIM_TIMEOUT_MINUTES * MINUTE_IN_SECONDS;
    }

    public static function delete_report( int $report_id ): bool {
        global $wpdb;
        $payouts = self::get_payouts( $report_id );
        if ( $payouts ) {
            return false; // an audited payout must never be deleted silently.
        }
        $wpdb->delete( PCIO_VIS_Tickets_DB::t_event_reports(), [ 'id' => $report_id ], [ '%d' ] );
        return true;
    }

    // ── Payout rows ───────────────────────────────────────────────

    public static function get_payouts( int $report_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_report_id = %d ORDER BY id ASC',
                PCIO_VIS_Tickets_DB::t_event_payouts(),
                $report_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function sum_paid_cents( int $report_id ): int {
        global $wpdb;
        if ( $report_id < 1 ) {
            return 0;
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COALESCE(SUM(payout_amount),0) FROM %i WHERE event_report_id = %d', PCIO_VIS_Tickets_DB::t_event_payouts(), $report_id )
        );
    }

    /**
     * Create a Stripe payout for a report (automatic full payout or a manual
     * partial amount). Guards:
     *   - remaining amount must be ≥ the configured minimum,
     *   - Stripe available balance (same currency) must cover the amount,
     *   - status claim prevents concurrent double payouts.
     *
     * @return true|WP_Error True on success (report mail is sent afterwards).
     */
    public static function attempt_payout( array $report, ?int $amount_cents = null, ?string $description = null ) {
        global $wpdb;

        $report_id = (int) $report['id'];
        if ( ! $report_id ) {
            return new WP_Error( 'pcio_met_report_missing', __( 'Report not found.', 'pcio-vis-tickets' ) );
        }
        if ( ! PCIO_VIS_Tickets_Admin::is_configured() ) {
            return new WP_Error( 'pcio_met_no_key', __( 'Stripe is not configured.', 'pcio-vis-tickets' ) );
        }

        // Money can only leave the account whose keys are active. Reports still
        // show data from both modes; paying one out requires the matching mode.
        $data_mode = (string) ( $report['mode'] ?? '' );
        if ( '' === $data_mode ) {
            return new WP_Error( 'pcio_met_payout_mode', __( 'This report mixes Stripe test and live payments and cannot be paid out automatically.', 'pcio-vis-tickets' ) );
        }
        if ( $data_mode !== self::mode() ) {
            return new WP_Error(
                'pcio_met_payout_mode',
                sprintf(
                    /* translators: 1: mode the money belongs to, 2: currently active mode. */
                    __( 'This report contains %1$s payments, but Vis Tickets is using %2$s Stripe keys. Switch the mode under Vis → Tickets to pay it out.', 'pcio-vis-tickets' ),
                    $data_mode,
                    self::mode()
                )
            );
        }

        $totals    = $report['totals'] ?? self::calculate_for_report( $report );
        $paid      = self::sum_paid_cents( $report_id );
        $remaining = self::remaining_cents( $totals, $paid );
        $currency  = (string) ( $report['currency'] ?: $totals['currency'] );

        if ( $remaining <= 0 ) {
            return new WP_Error( 'pcio_met_payout_none', __( 'There is nothing left to pay out for this event.', 'pcio-vis-tickets' ) );
        }

        $amount    = $amount_cents ?: $remaining;
        $amount    = (int) $amount;
        $minimum   = (int) apply_filters( 'pcio_met_min_payout_cents', self::MIN_PAYOUT_CENTS );
        if ( $amount < $minimum ) {
            $message = sprintf(
                /* translators: 1: remaining amount, 2: minimum payout amount. */
                __( 'The remaining amount (%1$s) is below the minimum payout amount (%2$s). No payout is created.', 'pcio-vis-tickets' ),
                PCIO_VIS_Tickets_Admin::format_money( $remaining, $currency ),
                PCIO_VIS_Tickets_Admin::format_money( $minimum, $currency )
            );
            $wpdb->update(
                PCIO_VIS_Tickets_DB::t_event_reports(),
                [ 'status' => self::STATUS_READY, 'last_error' => $message ],
                [ 'id' => $report_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            return new WP_Error( 'pcio_met_payout_min', $message );
        }
        if ( $amount > $remaining ) {
            return new WP_Error( 'pcio_met_payout_cap', __( 'The payout amount cannot exceed the remaining net amount.', 'pcio-vis-tickets' ) );
        }

        // Flag short Stripe balances and leave the money for the next daily run.
        $available = PCIO_VIS_Tickets_Stripe::available_balance_for( $currency );
        if ( null === $available ) {
            return new WP_Error( 'pcio_met_payout_balance', __( 'The Stripe balance could not be read. No payout is created.', 'pcio-vis-tickets' ) );
        }
        if ( $available < $amount ) {
            $message = sprintf(
                /* translators: 1: available balance, 2: payout amount. */
                __( 'Awaiting Stripe balance: the account holds %1$s of the due %2$s. The payout is postponed.', 'pcio-vis-tickets' ),
                PCIO_VIS_Tickets_Admin::format_money( max( 0, $available ), $currency ),
                PCIO_VIS_Tickets_Admin::format_money( $amount, $currency )
            );
            $wpdb->update(
                PCIO_VIS_Tickets_DB::t_event_reports(),
                [ 'status' => self::STATUS_AWAITING, 'last_error' => $message ],
                [ 'id' => $report_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            return new WP_Error( 'pcio_met_payout_funds', $message );
        }

        // Claim the report; only one process can hold the claim.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, last_error = NULL WHERE id = %d AND status IN ( %s, %s )',
                PCIO_VIS_Tickets_DB::t_event_reports(),
                self::STATUS_CLAIMED,
                $report_id,
                self::STATUS_READY,
                self::STATUS_AWAITING
            )
        );
        if ( ! $claimed ) {
            return new WP_Error( 'pcio_met_payout_busy', __( 'A payout for this event is already being processed.', 'pcio-vis-tickets' ) );
        }

        $description = $description ?: sprintf(
            /* translators: %s: event title */
            __( 'Ticket payout — %s', 'pcio-vis-tickets' ),
            (string) ( $report['event_title'] ?? '' )
        );
        $description = mb_substr( sanitize_text_field( wp_unslash( $description ) ), 0, 255 );

        $payout = PCIO_VIS_Tickets_Stripe::create_payout(
            $amount,
            $currency,
            [ 'internal_reference' => 'PCIO-MET-REPORT-ID-' . $report_id ]
        );

        if ( is_wp_error( $payout ) ) {
            $message = $payout->get_error_message();
            // Flagged failed; the next daily scan recomputes ready and retries,
            // so transient Stripe problems self-heal.
            $wpdb->update(
                PCIO_VIS_Tickets_DB::t_event_reports(),
                [ 'status' => self::STATUS_FAILED, 'last_error' => mb_substr( $message, 0, 190 ) ],
                [ 'id' => $report_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            self::maybe_mail_failure( $report, $message );
            return $payout;
        }

        $wpdb->insert(
            PCIO_VIS_Tickets_DB::t_event_payouts(),
            [
                'event_report_id'               => $report_id,
                'event_id'                      => (int) $report['event_id'],
                'mode'                          => self::mode(),
                'description'                   => $description ?: __( 'Ticket payout', 'pcio-vis-tickets' ),
                'payout_amount'                 => (int) ( $payout['amount'] ?? $amount ),
                'stripe_payout_id'              => (string) ( $payout['id'] ?? '' ),
                'stripe_balance_transaction_id' => (string) ( $payout['balance_transaction'] ?? '' ),
                'stripe_status'                 => (string) ( $payout['status'] ?? '' ),
                'payout_date'                   => self::payout_arrival( $payout ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        // Mark paid when fully netted; a manual partial payout leaves the
        // report ready for another payout tomorrow.
        $paid_now = self::sum_paid_cents( $report_id );
        $final    = $paid_now >= (int) $totals['net_cents'] ? self::STATUS_PAID : self::STATUS_READY;
        $wpdb->update(
            PCIO_VIS_Tickets_DB::t_event_reports(),
            [ 'status' => $final, 'last_error' => null ],
            [ 'id' => $report_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        $mail = self::send_report_mail( $report_id );
        if ( is_wp_error( $mail ) ) {
            do_action( 'pcio_met_report_mail_failed', $report_id, $mail );
        }

        return true;
    }

    /** Map a Stripe payout object to a MySQL arrival datetime. */
    private static function payout_arrival( array $payout ): ?string {
        $ts = (int) ( $payout['arrival_date'] ?? 0 );
        if ( $ts <= 0 ) {
            return null;
        }
        $gmt = gmdate( 'Y-m-d H:i:s', $ts );
        return get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );
    }

    /**
     * Ask Stripe for the current state of unsettled payouts and update the
     * stored rows (so the Finance tab shows arrivals without webhooks).
     */
    public static function refresh_pending_payouts( array $report ): void {
        global $wpdb;
        foreach ( self::get_payouts( (int) $report['id'] ) as $payout ) {
            if ( ! in_array( (string) ( $payout['stripe_status'] ?? '' ), [ 'pending', 'in_transit' ], true ) ) {
                continue;
            }
            // Read with the key mode of the payout's own Stripe account.
            $payout_mode = (string) ( $payout['mode'] ?? '' );
            $fresh = PCIO_VIS_Tickets_Stripe::get_payout( (string) $payout['stripe_payout_id'], $payout_mode ?: null );
            if ( is_wp_error( $fresh ) || ! is_array( $fresh ) ) {
                continue;
            }
            $wpdb->update(
                PCIO_VIS_Tickets_DB::t_event_payouts(),
                [
                    'stripe_status' => (string) ( $fresh['status'] ?? $payout['stripe_status'] ),
                    'payout_date'   => self::payout_arrival( $fresh ),
                ],
                [ 'id' => (int) $payout['id'] ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * Failure mail for Stripe errors; repeated identical failures are silent so
     * the treasurer is not spammed by the daily retry.
     */
    private static function maybe_mail_failure( array $report, string $message ): void {
        if ( $message === (string) ( $report['last_error'] ?? '' ) ) {
            return; // same failure as yesterday → already alerted.
        }
        $title = (string) ( $report['event_title'] ?? '' );
        wp_mail(
            (string) get_bloginfo( 'admin_email' ),
            sprintf( /* translators: %s: event title */ __( 'Ticket payout failed for %s', 'pcio-vis-tickets' ), $title ),
            sprintf(
                /* translators: 1: event title, 2: error message. */
                __( 'The automatic ticket payout for "%1$s" failed. The report is flagged in the Finance tab and the payout is retried tomorrow. Error: %2$s', 'pcio-vis-tickets' ),
                $title,
                $message
            )
        );
    }

    // ── Daily cron ────────────────────────────────────────────────

    /**
     * Why a payout action is unavailable for a report ('' = payable).
     * Pure helper so the Finance list can show a disabled button with a reason
     * instead of silently hiding the action.
     */
    public static function payout_block_reason( int $remaining_cents, string $data_mode, string $active_mode, bool $configured ): string {
        if ( ! $configured ) {
            return __( 'Stripe is not configured.', 'pcio-vis-tickets' );
        }
        if ( $remaining_cents <= 0 ) {
            return __( 'Already paid out.', 'pcio-vis-tickets' );
        }
        if ( '' === $data_mode ) {
            return __( 'This report mixes test and live payments and cannot be paid out automatically.', 'pcio-vis-tickets' );
        }
        if ( $data_mode !== $active_mode ) {
            return sprintf(
                /* translators: %s: Stripe mode the report's money belongs to. */
                __( 'Switch Vis Tickets to %s Stripe mode to pay out this report.', 'pcio-vis-tickets' ),
                $data_mode
            );
        }
        return '';
    }

    /**
     * Rows for the Finance "Event reports" table, joined with live totals and
     * aggregate payout info.
     */
    public static function list_report_rows(): array {
        global $wpdb;
        $report_table = PCIO_VIS_Tickets_DB::t_event_reports();
        $rows         = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i ORDER BY event_end DESC, id ASC", $report_table ),
            ARRAY_A
        ) ?: [];
        $min = (int) apply_filters( 'pcio_met_min_payout_cents', self::MIN_PAYOUT_CENTS );
        $active_mode = self::mode();

        $rows_out = [];
        foreach ( $rows as $row ) {
            $report = self::enriched_report( (int) $row['id'] );
            if ( ! $report ) {
                continue;
            }
            $totals     = (array) $report['totals'];
            $payouts    = (array) $report['payouts'];
            $paid       = (int) $report['paid_cents'];
            $remaining  = (int) $report['remaining_cents'];
            $data_mode  = self::report_data_mode( self::query_orders_all( (int) $row['event_id'] ) );
            $last_date  = '';
            foreach ( $payouts as $p) {
                $date = (string) ( $p['payout_date'] ?: $p['created'] );
                if ( $date > $last_date ) $last_date = $date;
            }

            $rows_out[] = [
                'id'               => (int) $row['id'],
                'event_id'         => (int) $row['event_id'],
                'event_title'      => (string) $report['event_title'],
                'event_end'        => (string) $row['event_end'],
                'currency'         => (string) $row['currency'],
                'mode'             => $data_mode,
                'is_test'          => 'test' === $data_mode,
                'revenue_cents'    => (int) ( $totals['revenue_cents'] ?? 0 ),
                'refunded_cents'   => (int) ( $totals['refunded_cents'] ?? 0 ),
                'fee_income_cents' => (int) ( $totals['fee_income_cents'] ?? 0 ),
                'stripe_fee_cents' => (int) ( $totals['stripe_fee_cents'] ?? 0 ),
                'net_cents'        => (int) ( $totals['net_cents'] ?? 0 ),
                'paid_cents'       => $paid,
                'remaining_cents'  => $remaining,
                'status_key'       => $row['status'],
                'status'           => self::status_label( (string) $row['status'] ),
                'note'             => (string) ( $row['last_error'] ?? '' ),
                'last_error'       => (string) ( $row['last_error'] ?? '' ),
                'last_payout_date' => $last_date,
                'pdf_url'          => self::report_pdf_url( (int) $row['id'], true ),
                'stripe_url'       => self::latest_payout_dashboard_url( $payouts ),
                'can_payout'       => $remaining > 0
                    && $data_mode === $active_mode
                    && PCIO_VIS_Tickets_Admin::is_configured(),
                'payout_block_reason' => self::payout_block_reason(
                    $remaining,
                    $data_mode,
                    $active_mode,
                    PCIO_VIS_Tickets_Admin::is_configured()
                ),
                'can_mail'         => ! self::mail_test_mode(),
                'can_delete'       => count( $payouts ) === 0,
                'payout_count'     => count( $payouts ),
                'min_cents'        => $min,
            ];
        }
        return $rows_out;
    }

    /**
     * Stripe dashboard link for the most recent real payout of a report, so
     * treasurers can open the payout transaction in Stripe. Empty when the
     * report has no Stripe payout (e.g. free events or synthetic legacy rows).
     *
     * @param array[] $payouts Report payout rows (newest wins).
     */
    public static function latest_payout_dashboard_url( array $payouts ): string {
        $latest = null;
        foreach ( $payouts as $payout ) {
            if ( '' === (string) ( $payout['stripe_payout_id'] ?? '' ) ) {
                continue;
            }
            if ( null === $latest || (int) $payout['id'] > (int) $latest['id'] ) {
                $latest = $payout;
            }
        }
        if ( ! $latest ) {
            return '';
        }
        $mode = 'test' === (string) ( $latest['mode'] ?? '' ) ? 'test/' : '';
        return 'https://dashboard.stripe.com/' . $mode . 'payouts/' . rawurlencode( (string) $latest['stripe_payout_id'] );
    }

    public static function report_pdf_url( int $report_id, bool $download = false ): string {
        return add_query_arg(
            [
                'format'   => $download ? 'download' : 'view',
                '_wpnonce' => wp_create_nonce( 'wp_rest' ),
            ],
            rest_url( PCIO_VIS_REST_NS . '/tickets/reports/' . $report_id . '/pdf' )
        );
    }

    public static function status_label( string $status ): string {
        switch ( $status ) {
            case self::STATUS_FREE:
                return __( 'Free event', 'pcio-vis-tickets' );
            case self::STATUS_PENDING:
                return __( 'Awaiting delay', 'pcio-vis-tickets' );
            case self::STATUS_READY:
                return __( 'Due to be paid', 'pcio-vis-tickets' );
            case self::STATUS_AWAITING:
                return __( 'Awaiting Stripe balance', 'pcio-vis-tickets' );
            case self::STATUS_CLAIMED:
                return __( 'Paying out…', 'pcio-vis-tickets' );
            case self::STATUS_PAID:
                return __( 'Paid', 'pcio-vis-tickets' );
            case self::STATUS_FAILED:
                return __( 'Payout failed', 'pcio-vis-tickets' );
        }
        return $status;
    }

    /** Self-heal the schedule in case an option reset dropped it. */
    public static function maybe_schedule(): void {
        // Kept as a no-op for backwards compatibility: the scan is a core Sync
        // Job now and is scheduled by PCIO_VIS_Sync from the me_sync registry.
    }

    /**
     * Core Sync Jobs callable: refresh reports and pay out finished events.
     *
     * @param array $job Registry row supplied by PCIO_VIS_Sync::execute_job().
     * @return string Human-readable result shown in Vis → Sync Jobs.
     */
    public static function run_daily_scan( array $job = [] ): string {
        self::reconcile_modes();

        $delay = self::delay_days();
        $auto  = self::auto_payout_enabled();

        $refreshed = 0;
        $payouts   = 0;
        $awaiting  = 0;
        $errors    = 0;

        foreach ( self::event_ids_with_orders() as $event_id ) {
            $report = self::refresh_report( $event_id, $delay );
            if ( ! $report ) {
                continue;
            }
            $refreshed++;
            self::refresh_pending_payouts( $report );

            if ( ! $auto || ! in_array( (string) $report['status'], [ self::STATUS_READY, self::STATUS_AWAITING ], true ) ) {
                continue;
            }
            // Only the active mode's Stripe keys may move money. Reports built
            // from the other mode still refresh, they just wait for a mode switch.
            if ( (string) ( $report['mode'] ?? '' ) !== self::mode() ) {
                continue;
            }

            $result = self::attempt_payout( $report );
            if ( true === $result ) {
                $payouts++;
            } elseif ( is_wp_error( $result ) ) {
                if ( 'pcio_met_payout_funds' === $result->get_error_code() ) {
                    $awaiting++;
                } elseif ( 'pcio_met_payout_min' !== $result->get_error_code() && 'pcio_met_payout_none' !== $result->get_error_code() ) {
                    $errors++;
                }
            }
        }

        if ( ! $auto ) {
            return sprintf(
                /* translators: %d: number of refreshed reports. */
                __( 'Automatic payout is disabled. Reports refreshed: %d.', 'pcio-vis-tickets' ),
                $refreshed
            );
        }

        return sprintf(
            /* translators: 1: reports refreshed, 2: payouts created, 3: awaiting Stripe balance, 4: errors. */
            __( 'Reports refreshed: %1$d. Payouts created: %2$d. Awaiting Stripe balance: %3$d. Errors: %4$d.', 'pcio-vis-tickets' ),
            $refreshed,
            $payouts,
            $awaiting,
            $errors
        );
    }

    // ── PDF + mail (port of event_rapport_print / _send) ──────────

    /** Reports storage directory (uploads/pcio-vis-reports). */
    public static function report_dir(): string {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( (string) $uploads['basedir'] ) . 'pcio-vis-reports';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // No directory listing, consistent with the ticket storage.
            @file_put_contents( $dir . '/index.html', '' );
        }
        return $dir;
    }

    public static function pdf_path( array $report ): string {
        $mode = sanitize_key( (string) ( $report['mode'] ?? self::mode() ) );
        $slug = sanitize_file_name(
            'event-report-' . (int) $report['event_id'] . '-' . strtolower( (string) $report['currency'] ) . '-' . $mode . '.pdf'
        );
        return self::report_dir() . '/' . $slug;
    }

    /**
     * Generate (and cache) the report PDF; returns the absolute file path.
     *
     * @return string|WP_Error
     */
    public static function generate_pdf( int $report_id ) {
        $report = self::enriched_report( $report_id );
        if ( ! $report ) {
            return new WP_Error( 'pcio_met_report_missing', __( 'Report not found.', 'pcio-vis-tickets' ) );
        }
        $path = self::pdf_path( $report );

        $class = 'PCIO_VIS_PDF';
        if ( ! class_exists( $class ) || ! $class::available() ) {
            return new WP_Error( 'pcio_met_no_pdf', __( 'The PDF library is unavailable.', 'pcio-vis-tickets' ) );
        }
        $bytes = $class::render( self::report_html( $report ), 'A4', 'portrait' );
        if ( null === $bytes || '' === trim( (string) $bytes ) ) {
            return new WP_Error( 'pcio_met_pdf_failed', __( 'The PDF could not be generated.', 'pcio-vis-tickets' ) );
        }
        file_put_contents( $path, $bytes );
        return $path;
    }

    /**
     * Report row enriched with live totals, payouts and the event title.
     *
     * @return array|null { report row + totals, event_title, payouts, paid_cents, remaining_cents }
     */
    public static function enriched_report( int $report_id ): ?array {
        $report = self::get_report( $report_id );
        if ( ! $report ) {
            return null;
        }
        $totals = self::calculate_for_report( $report );
        $event  = PCIO_VIS_Events_DB::get( (int) $report['event_id'] );
        $paid   = self::sum_paid_cents( $report_id );

        $report['totals']          = $totals;
        $report['payouts']         = self::get_payouts( $report_id );
        $report['paid_cents']      = $paid;
        $report['remaining_cents'] = max( 0, (int) $totals['net_cents'] - $paid );
        $report['event_title']     = $event ? (string) $event['title'] : '';
        return $report;
    }

    /**
     * Mail the report PDF to the configured receivers. Legacy behaviour: no
     * receiver list configured → the report stays on the Finance tab only.
     *
     * @return true|WP_Error
     */
    public static function send_report_mail( int $report_id ) {
        $report = self::enriched_report( $report_id );
        if ( ! $report ) {
            return new WP_Error( 'pcio_met_report_missing', __( 'Report not found.', 'pcio-vis-tickets' ) );
        }

        // Report emails follow the separate cron test switch (the same one the
        // shop uses), never the Stripe key mode.
        if ( self::mail_test_mode() ) {
            return new WP_Error( 'pcio_met_mail_test_mode', __( 'Cron test mode is enabled. Report emails are not sent while testing.', 'pcio-vis-tickets' ) );
        }

        $receivers = self::receivers();
        if ( ! $receivers ) {
            return new WP_Error( 'pcio_met_no_receivers', __( 'No event report receivers are configured. Add email addresses under Vis → Tickets.', 'pcio-vis-tickets' ) );
        }

        $pdf = self::generate_pdf( $report_id );
        if ( is_wp_error( $pdf ) ) {
            return $pdf;
        }

        $title = (string) $report['event_title'];
        $url   = home_url( 'vis/finance/#event-reports' );
        $body  = '<p>'
            . sprintf(
                /* translators: 1: event title, 2: net amount */
                __( 'The automatic report for the event <strong>%1$s</strong> is ready. Net ticket sales: %2$s. The report is attached as a PDF.', 'pcio-vis-tickets' ),
                esc_html( $title ),
                esc_html( PCIO_VIS_Tickets_Admin::format_money( (int) $report['totals']['net_cents'], (string) $report['currency'] ) )
            )
            . '</p><p>'
            . wp_kses_post(
                sprintf(
                    /* translators: %s: Finance page URL */
                    __( 'Open the <a href="%s">Finance page</a> to manage payouts.', 'pcio-vis-tickets' ),
                    esc_url( $url )
                )
            )
            . '</p>';

        $headers = [
            'From: ' . PCIO_VIS_Tickets_Admin::from_name() . ' <' . PCIO_VIS_Tickets_Admin::from_email() . '>',
            'Content-Type: text/html; charset=UTF-8',
        ];

        $sent = wp_mail( $receivers, sprintf( /* translators: %s: event title */ __( 'Ticket report — %s', 'pcio-vis-tickets' ), $title ), $body, $headers, [ $pdf ] );
        if ( ! $sent ) {
            return new WP_Error( 'pcio_met_mail_failed', __( 'The report email could not be sent.', 'pcio-vis-tickets' ) );
        }

        global $wpdb;
        $wpdb->update(
            PCIO_VIS_Tickets_DB::t_event_reports(),
            [ 'mail_sent_at' => current_time( 'mysql' ) ],
            [ 'id' => $report_id ],
            [ '%s' ],
            [ '%d' ]
        );
        return true;
    }

    // ── HTML report (Dompdf-safe, legacy-inspired layout) ─────────

    /** Data URI of the configured report logo, or '' when none is set. */
    private static function report_logo_data_uri(): string {
        if ( ! class_exists( 'PCIO_VIS_Tickets_Admin' ) ) {
            return '';
        }
        $logo_id = (int) PCIO_VIS_Tickets_Admin::report_logo_id();
        if ( $logo_id < 1 ) {
            return '';
        }
        $path = get_attached_file( $logo_id );
        if ( ! $path || ! is_readable( $path ) ) {
            return '';
        }
        $info = wp_check_filetype( $path );
        $mime = $info['type'] ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload file.
    }

    private static function payout_status_label( string $status ): string {
        switch ( strtolower( $status ) ) {
            case 'paid':
                return __( 'Paid', 'pcio-vis-tickets' );
            case 'in_transit':
                return __( 'In transit', 'pcio-vis-tickets' );
            case 'pending':
                return __( 'Pending', 'pcio-vis-tickets' );
            case 'failed':
            case 'canceled':
            case 'cancelled':
                return __( 'Failed', 'pcio-vis-tickets' );
        }
        return $status;
    }

    public static function report_html( array $report ): string {
        $totals  = $report['totals'];
        $paid    = (int) $report['paid_cents'];
        $remain  = (int) $report['remaining_cents'];
        $cur     = (string) $report['currency'];
        $payouts = (array) $report['payouts'];
        $types   = (array) self::ticket_type_breakdown( (int) $report['event_id'] );
        $org     = (string) get_bloginfo( 'name' );
        $logo    = self::report_logo_data_uri();
        $money   = static fn( int $cents ): string => esc_html( PCIO_VIS_Tickets_Admin::format_money( $cents, $cur ) );

        $event_date = '';
        if ( ! empty( $report['event_end'] ) ) {
            $ts = strtotime( (string) $report['event_end'] );
            if ( $ts ) {
                $event_date = date_i18n( 'd/m/Y \k\l. H:i', $ts + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
            }
        }

        $paid_rows = '';
        foreach ( $payouts as $p ) {
            $date = ! empty( $p['payout_date'] ) ? date_i18n( 'd/m/Y', (int) strtotime( (string) $p['payout_date'] ) ) : '';
            $paid_rows .= '<tr>'
                . '<td>' . esc_html( (string) ( $p['description'] ?: __( 'Payout', 'pcio-vis-tickets' ) ) ) . '</td>'
                . '<td>' . esc_html( $date ) . '</td>'
                . '<td class="right">' . $money( (int) $p['payout_amount'] ) . '</td>'
                . '<td class="right">' . esc_html( self::payout_status_label( (string) $p['stripe_status'] ) ) . '</td>'
                . '</tr>';
        }
        if ( '' === $paid_rows ) {
            $paid_rows = '<tr><td colspan="4" class="muted">' . esc_html__( 'No payouts recorded yet.', 'pcio-vis-tickets' ) . '</td></tr>';
        }

        $type_rows = '';
        $type_qty  = 0;
        $type_sum  = 0;
        foreach ( $types as $t ) {
            $type_qty += (int) $t['qty'];
            $type_sum += (int) $t['revenue_cents'];
            $type_rows .= '<tr>'
                . '<td>' . esc_html( (string) $t['name'] ) . '</td>'
                . '<td class="right">' . (int) $t['qty'] . '</td>'
                . '<td class="right">' . $money( (int) $t['unit_price'] ) . '</td>'
                . '<td class="right">' . $money( (int) $t['revenue_cents'] ) . '</td>'
                . '</tr>';
        }
        if ( '' === $type_rows ) {
            $type_rows = '<tr><td colspan="4" class="muted">' . esc_html__( 'No ticket sales recorded.', 'pcio-vis-tickets' ) . '</td></tr>';
        }

        $net_ticket = (int) $totals['revenue_cents'] - (int) $totals['refunded_cents'];

        ob_start();
        ?>
<!doctype html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 42px 44px 54px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color:#0f172a; font-size:12px; margin:0; }
    table { width:100%; border-collapse:collapse; }
    .head td { vertical-align: top; }
    .kicker { font-size:19px; font-weight:700; letter-spacing:.2px; }
    .sub { color:#475569; font-size:12px; margin-top:3px; }
    .tiny { color:#64748b; font-size:10.5px; margin-top:2px; }
    .head-right { text-align:right; width:38%; }
    .logo { max-width:190px; max-height:72px; }
    .org-name { font-weight:700; font-size:12px; }
    .report-date { margin-top:14px; font-size:11px; color:#334155; }
    .event { margin-top:34px; }
    .event td { padding:1px 0; color:#0f172a; }
    .event .title { font-weight:700; font-size:12.5px; padding-top:2px; }
    .event .date { color:#334155; }
    .summary { margin-top:34px; }
    .summary td { width:20%; padding:0 10px; vertical-align:top; text-align:center; }
    .summary td:first-child { padding-left:0; text-align:left; }
    .summary td:last-child { padding-right:0; }
    .s-label { font-size:9.5px; font-weight:700; letter-spacing:.6px; color:#0f172a; text-transform:uppercase; }
    .s-value { font-size:14px; font-weight:700; margin-top:7px; }
    .red { color:#c0392b; }
    .total { margin-top:46px; text-align:center; }
    .total-label { font-size:14px; letter-spacing:.2px; }
    .total-amount { font-size:30px; font-weight:700; margin-top:10px; }
    .total-note { font-size:10.5px; color:#64748b; font-style:italic; margin-top:10px; }
    .remaining { margin-top:8px; font-size:11px; color:#b45309; }
    .subhead { font-size:10px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; margin:0 0 6px; }
    .box { background:#f1f5f9; border:1px solid #e2e8f0; }
    .postings { margin-top:42px; }
    .postings thead .section-title { background:#e8eef5; text-align:center; font-size:10.5px; letter-spacing:.6px; font-weight:700; text-transform:uppercase; padding:9px; }
    .postings th { font-size:10px; text-transform:uppercase; letter-spacing:.4px; background:#f8fafc; border:1px solid #dfe6ee; padding:7px 9px; text-align:left; }
    .postings td { border:1px solid #e8edf3; padding:7px 9px; font-size:11px; }
    .postings tfoot td { font-weight:700; background:#f8fafc; }
    .right { text-align:right; }
    .muted { color:#94a3b8; }
    .types { margin-top:34px; }
    .types .section-title { font-size:10.5px; letter-spacing:.6px; font-weight:700; text-transform:uppercase; margin-bottom:6px; }
    .types th { font-size:9.5px; text-transform:uppercase; letter-spacing:.3px; background:#f8fafc; border:1px solid #e2e8f0; padding:6px 8px; text-align:left; }
    .types td { border:1px solid #e8edf3; padding:6px 8px; font-size:11px; }
    .types tfoot td { font-weight:700; background:#f8fafc; }
    .footer { margin-top:40px; border-top:1px solid #cbd5e1; padding-top:10px; text-align:center; color:#64748b; font-size:10px; font-style:italic; }
</style></head>
<body>

<table class="head"><tr>
    <td>
        <div class="kicker"><?php esc_html_e( 'Payout report', 'pcio-vis-tickets' ); ?></div>
        <div class="sub"><?php
            /* translators: %s: site name. */
            echo esc_html( sprintf( __( 'Payouts from Stripe to %s', 'pcio-vis-tickets' ), $org ) );
        ?></div>
        <div class="tiny"><?php esc_html_e( 'Generated by the Vis ticket system', 'pcio-vis-tickets' ); ?></div>
    </td>
    <td class="head-right">
        <?php if ( '' !== $logo ) : ?>
            <img class="logo" src="<?php echo esc_attr( $logo ); ?>" alt="">
        <?php else : ?>
            <div class="org-name"><?php echo esc_html( $org ); ?></div>
        <?php endif; ?>
        <div class="report-date"><?php
            /* translators: %s: report generation date. */
            echo esc_html( sprintf( __( 'Report date: %s', 'pcio-vis-tickets' ), date_i18n( 'd/m/Y', current_time( 'timestamp' ) ) ) );
        ?></div>
    </td>
</tr></table>

<table class="event"><tbody>
    <tr><td class="org-name"><?php echo esc_html( $org ); ?></td></tr>
    <tr><td class="title"><?php echo esc_html( (string) $report['event_title'] ); ?></td></tr>
    <?php if ( '' !== $event_date ) : ?>
        <tr><td class="date"><?php echo esc_html( $event_date ); ?></td></tr>
    <?php endif; ?>
</tbody></table>

<table class="summary"><tbody><tr>
    <td>
        <div class="s-label"><?php esc_html_e( 'Total ticket revenue', 'pcio-vis-tickets' ); ?></div>
        <div class="s-value"><?php echo $money( (int) $totals['revenue_cents'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by $money. ?></div>
    </td>
    <td>
        <div class="s-label"><?php esc_html_e( 'Refunded', 'pcio-vis-tickets' ); ?></div>
        <div class="s-value red"><?php echo $money( (int) $totals['refunded_cents'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    </td>
    <td>
        <div class="s-label"><?php esc_html_e( 'Net ticket revenue', 'pcio-vis-tickets' ); ?></div>
        <div class="s-value"><?php echo $money( $net_ticket ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    </td>
    <td>
        <div class="s-label"><?php esc_html_e( 'Total fee income', 'pcio-vis-tickets' ); ?></div>
        <div class="s-value"><?php echo $money( (int) $totals['fee_income_cents'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    </td>
    <td>
        <div class="s-label"><?php esc_html_e( 'Stripe fee share', 'pcio-vis-tickets' ); ?></div>
        <div class="s-value"><?php echo $money( (int) $totals['stripe_fee_cents'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    </td>
</tr></tbody></table>

<div class="total">
    <div class="total-label"><?php
        /* translators: %s: organisation name. */
        echo esc_html( strtoupper( sprintf( __( 'Total payout to %s', 'pcio-vis-tickets' ), $org ) ) );
    ?></div>
    <div class="total-amount"><?php echo $money( (int) $totals['net_cents'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="total-note"><?php esc_html_e( 'All amounts include VAT.', 'pcio-vis-tickets' ); ?></div>
    <?php if ( $remain > 0 ) : ?>
        <div class="remaining"><?php
            /* translators: %s: remaining amount. */
            echo esc_html( sprintf( __( 'Remaining amount not yet paid out: %s', 'pcio-vis-tickets' ), PCIO_VIS_Tickets_Admin::format_money( $remain, $cur ) ) );
        ?></div>
    <?php endif; ?>
</div>

<table class="postings">
    <thead>
        <tr><th colspan="4" class="section-title"><?php esc_html_e( 'Payout postings', 'pcio-vis-tickets' ); ?></th></tr>
        <tr>
            <th><?php esc_html_e( 'Description', 'pcio-vis-tickets' ); ?></th>
            <th><?php esc_html_e( 'Payout date', 'pcio-vis-tickets' ); ?></th>
            <th class="right"><?php esc_html_e( 'Amount', 'pcio-vis-tickets' ); ?></th>
            <th class="right"><?php esc_html_e( 'Status', 'pcio-vis-tickets' ); ?></th>
        </tr>
    </thead>
    <tbody><?php echo $paid_rows; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the loop. ?></tbody>
    <tfoot><tr>
        <td colspan="2"><?php esc_html_e( 'Paid in total', 'pcio-vis-tickets' ); ?></td>
        <td class="right"><?php echo $money( $paid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
        <td></td>
    </tr></tfoot>
</table>

<div class="types">
    <div class="section-title"><?php esc_html_e( 'Ticket types', 'pcio-vis-tickets' ); ?></div>
    <table>
        <thead><tr>
            <th><?php esc_html_e( 'Ticket name', 'pcio-vis-tickets' ); ?></th>
            <th class="right"><?php esc_html_e( 'Sold', 'pcio-vis-tickets' ); ?></th>
            <th class="right"><?php esc_html_e( 'Unit price', 'pcio-vis-tickets' ); ?></th>
            <th class="right"><?php esc_html_e( 'Ticket revenue', 'pcio-vis-tickets' ); ?></th>
        </tr></thead>
        <tbody><?php echo $type_rows; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the loop. ?></tbody>
        <tfoot><tr>
            <td><?php esc_html_e( 'Total', 'pcio-vis-tickets' ); ?></td>
            <td class="right"><?php echo (int) $type_qty; ?></td>
            <td></td>
            <td class="right"><?php echo $money( $type_sum ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
        </tr></tfoot>
    </table>
</div>

<div class="footer"><?php esc_html_e( 'All amounts include VAT.', 'pcio-vis-tickets' ); ?></div>

</body></html>
        <?php
        return (string) ob_get_clean();
    }
}
