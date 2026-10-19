<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public-facing pages for the Tickets extension.
 *
 * Rewrite rules (flushed on activation) map to a single query var `pcio_met`:
 *   /tickets/checkout?event={slug|id}  – choose ticket types + buyer details
 *   /tickets/accepted?order={token}    – payment accepted / free order confirmed
 *   /tickets/rejected?order={token}    – payment cancelled or failed
 *   /tickets/download?order={token}    – tokenised PDF re-download (Phase 5)
 */
class PCIO_VIS_Tickets_Frontend {

    public function register(): void {
        add_action( 'init',              [ $this, 'register_rewrites' ] );
        add_action( 'template_redirect', [ $this, 'serve_templates'   ] );
    }

    public function register_rewrites(): void {
        add_rewrite_rule( '^tickets/checkout/?$', 'index.php?pcio_met=checkout', 'top' );
        add_rewrite_rule( '^tickets/accepted/?$', 'index.php?pcio_met=accepted', 'top' );
        add_rewrite_rule( '^tickets/rejected/?$', 'index.php?pcio_met=rejected', 'top' );
        add_rewrite_rule( '^tickets/download/?$', 'index.php?pcio_met=download', 'top' );
        add_rewrite_rule( '^tickets/checkin/?$', 'index.php?pcio_met=checkin', 'top' );
        add_rewrite_tag( '%pcio_met%', '([^&]+)' );
    }

    public function serve_templates(): void {
        $view = get_query_var( 'pcio_met' );
        if ( ! $view ) {
            return;
        }
        switch ( $view ) {
            case 'checkout':
                $this->serve_checkout();
                break;
            case 'accepted':
                $this->serve_result( 'accepted' );
                break;
            case 'rejected':
                $this->serve_result( 'rejected' );
                break;
            case 'download':
                $this->serve_download();
                break;
            case 'checkin':
                $this->serve_checkin();
                break;
            default:
                $this->not_found();
        }
    }

    // ── Checkout ──────────────────────────────────────────────────

    private function serve_checkout(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET param, read-only.
        $event_ref = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '';
        $event     = $this->resolve_event( $event_ref );

        if ( ! $event || ! PCIO_VIS_Events_DB::is_published( $event ) || PCIO_VIS_Tickets_DB::event_mode( (int) $event['id'] ) !== 'tickets' ) {
            $this->not_found();
            return;
        }

        $event_id = (int) $event['id'];

        // Only types that can currently be sold are offered.
        $types = [];
        foreach ( PCIO_VIS_Tickets_DB::get_types( $event_id ) as $type ) {
            if ( ! PCIO_VIS_Tickets_DB::type_on_sale( $type ) ) {
                continue;
            }
            $types[] = [
                'id'          => (int) $type['id'],
                'name'        => (string) $type['name'],
                'price_cents' => (int) $type['price_cents'],
                'is_entrance' => (int) $type['is_entrance'],
                'max'         => PCIO_VIS_Tickets_DB::type_purchasable_qty( $type ),
            ];
        }

        $settings = PCIO_VIS_Tickets_DB::get_settings( $event_id );
        $currency = PCIO_VIS_Tickets_Admin::currency();

        $this->enqueue_checkout_assets( $event_id, $types, $settings, $currency );

        $pcio_met_view = [
            'event'     => $event,
            'event_id'  => $event_id,
            'title'     => (string) $event['title'],
            'types'     => $types,
            'currency'  => $currency,
            'soldout'   => (int) $settings['is_soldout'] === 1 || empty( $types ),
            'event_url' => home_url( 'events/' . rawurlencode( $event['slug'] ?? '' ) . '/' ),
        ];
        $this->render( __DIR__ . '/../templates/checkout.php', $pcio_met_view );
    }

    private function enqueue_checkout_assets( int $event_id, array $types, array $settings, string $currency ): void {
        wp_enqueue_style(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION
        );
        wp_enqueue_script(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.js', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION,
            true
        );
        // IMPORTANT: top-level `const` does NOT attach to window — assign explicitly.
        wp_add_inline_script(
            'pcio-met-checkout',
            'window.PCIO_VIS_Tickets_CHECKOUT = ' . wp_json_encode( [
                'rest'           => rest_url( PCIO_VIS_REST_NS . '/tickets/checkout' ),
                'nonce'          => wp_create_nonce( 'pcio_met_checkout' ),
                'restNonce'      => wp_create_nonce( 'wp_rest' ),
                'eventId'        => $event_id,
                'currency'       => $currency,
                'types'          => $types,
                'maxPerBuyer'    => (int) $settings['max_entrance_per_buyer'],
                'i18n'           => [
                    'free'        => __( 'Free', 'pcio-vis-tickets' ),
                    'total'       => __( 'Total', 'pcio-vis-tickets' ),
                    'selectOne'   => __( 'Please select at least one ticket.', 'pcio-vis-tickets' ),
                    'needName'    => __( 'Please enter your name.', 'pcio-vis-tickets' ),
                    'needEmail'   => __( 'Please enter a valid email address.', 'pcio-vis-tickets' ),
                    /* translators: %d is the maximum number of entrance tickets a single buyer may purchase. */
                    'maxPerBuyer' => __( 'You can buy at most %d entrance tickets.', 'pcio-vis-tickets' ),
                    'processing'  => __( 'Processing…', 'pcio-vis-tickets' ),
                    'payNow'      => __( 'Continue to payment', 'pcio-vis-tickets' ),
                    'getFree'     => __( 'Get tickets', 'pcio-vis-tickets' ),
                    'genericErr'  => __( 'Something went wrong. Please try again.', 'pcio-vis-tickets' ),
                ],
            ] ) . ';',
            'before'
        );
    }

    // ── Accepted / rejected result pages ──────────────────────────

    private function serve_result( string $view ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET token, read-only.
        $token = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order = $token ? PCIO_VIS_Tickets_DB::get_order_by_token( $token ) : null;

        wp_enqueue_style(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION
        );

        $event = $order ? PCIO_VIS_Events_DB::get( (int) $order['event_id'] ) : null;

        $pcio_met_view = [
            'view'      => $view,
            'order'     => $order,
            'event'     => $event,
            'event_url' => $event ? home_url( 'events/' . rawurlencode( $event['slug'] ?? '' ) . '/' ) : home_url( '/' ),
            'retry_url' => $event ? add_query_arg( 'event', rawurlencode( $event['slug'] ?? '' ), home_url( 'tickets/checkout/' ) ) : home_url( '/' ),
        ];
        $this->render( __DIR__ . '/../templates/result.php', $pcio_met_view );
    }

    // ── Tokenised PDF download ────────────────────────────────────

    private function serve_download(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET token, read-only.
        $token = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order = $token ? PCIO_VIS_Tickets_DB::get_order_by_token( $token ) : null;

        // Only confirmed orders have tickets to download.
        $confirmed = [ 'paid', 'free_confirmed', 'partly_refunded' ];
        if ( ! $order || ! in_array( (string) $order['status'], $confirmed, true ) ) {
            $this->not_found();
            return;
        }

        $path = PCIO_VIS_Tickets_PDF::generate( (int) $order['id'] );
        if ( ! $path || ! is_readable( $path ) ) {
            $this->not_found();
            return;
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="tickets-' . sanitize_file_name( (string) $order['order_no'] ) . '.pdf"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile( $path );
        exit;
    }

    // ── Ticket self-activation ("Use ticket") ────────────────────

    /**
     * Serve the guest self-activation page (GET ?data=<base64>) and handle
     * swipe activations (POST data + selections). Mirrors the legacy
     * activate_billet.php flow: QR-linked ticket resolves the order, the guest
     * picks quantities per ticket type, swipes, and the chosen tickets are
     * marked used. Only inside the door-open → event-end window.
     */
    private function serve_checkin(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET payload, read-only.
        $raw = isset( $_GET['data'] ) ? sanitize_text_field( wp_unslash( $_GET['data'] ) ) : '';
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
        if ( 'POST' === $request_method ) {
            $this->handle_activation_post();
            return;
        }
        $ctx = $this->activation_context( $raw );
        wp_enqueue_style(
            'pcio-met-activate',
            plugins_url( 'assets/activate.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION
        );
        wp_enqueue_script(
            'pcio-met-activate',
            plugins_url( 'assets/activate.js', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION,
            true
        );
        wp_add_inline_script(
            'pcio-met-activate',
            'window.PCIO_MET_ACTIVATE = ' . wp_json_encode( [
                'data' => $raw,
                'i18n' => [
                    'swipe'       => __( 'Swipe to activate', 'pcio-vis-tickets' ),
                    // translators: %d: number of tickets.
                    'swipeN'      => __( 'Swipe to activate %d tickets', 'pcio-vis-tickets' ),
                    'activating'  => __( 'Activating…', 'pcio-vis-tickets' ),
                    'failed'      => __( 'Activation failed.', 'pcio-vis-tickets' ),
                    'activatedIn' => __( 'Ticket active for', 'pcio-vis-tickets' ),
                    'expired'     => __( 'Ticket activation has expired.', 'pcio-vis-tickets' ),
                ],
            ] ) . ';',
            'before'
        );
        $this->render( __DIR__ . '/../templates/activate.php', [ 'ctx' => $ctx, 'raw' => $raw ] );
    }

    /** Shared state for the activation page: ticket, order, event, groups. */
    private function activation_context( string $raw ): array {
        $fail = static function ( string $msg ): array {
            return [ 'ok' => false, 'error' => $msg ];
        };
        if ( '' === $raw ) {
            return $fail( __( 'Missing ticket data.', 'pcio-vis-tickets' ) );
        }
        $parsed = PCIO_VIS_Tickets_PDF::parse_activation_payload( $raw );
        if ( null === $parsed ) {
            // Backward compatibility: PDFs printed before the activation
            // feature encode the bare ticket code instead of a payload.
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-match lookup on the ticket code; a stale cache would wrongly reject a valid ticket.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT t.id, o.event_id FROM %i t JOIN %i o ON o.id = t.order_id WHERE t.code = %s LIMIT 1',
                    PCIO_VIS_Tickets_DB::t_tickets(), PCIO_VIS_Tickets_DB::t_orders(), $raw
                ),
                ARRAY_A
            );
            if ( ! $row ) {
                return $fail( __( 'Invalid ticket format.', 'pcio-vis-tickets' ) );
            }
            $parsed = [ (int) $row['id'], (int) $row['event_id'] ];
        }
        [ $ticket_id, $event_id ] = $parsed;
        $ticket = PCIO_VIS_Tickets_DB::get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return $fail( __( 'Ticket not found.', 'pcio-vis-tickets' ) );
        }
        $order = PCIO_VIS_Tickets_DB::get_order( (int) $ticket['order_id'] );
        if ( ! $order ) {
            return $fail( __( 'Order not found.', 'pcio-vis-tickets' ) );
        }
        $event = PCIO_VIS_Events_DB::get( (int) $order['event_id'] );
        if ( ! $event || (int) $event['id'] !== $event_id ) {
            return $fail( __( 'Ticket does not match the event.', 'pcio-vis-tickets' ) );
        }
        if ( 'refunded' === (string) $ticket['status'] ) {
            return $fail( __( 'This ticket has been refunded and is no longer valid.', 'pcio-vis-tickets' ) );
        }
        if ( ! in_array( (string) $order['status'], [ 'paid', 'free_confirmed', 'partly_refunded' ], true ) ) {
            return $fail( __( 'The ticket is not valid — the order has not been paid.', 'pcio-vis-tickets' ) );
        }
        // Per-type groups for this order (non-refunded tickets only).
        $groups = [];
        foreach ( PCIO_VIS_Tickets_DB::get_tickets( (int) $order['id'] ) as $t ) {
            if ( 'refunded' === (string) $t['status'] ) {
                continue;
            }
            $tid = (int) $t['ticket_type_id'];
            if ( ! isset( $groups[ $tid ] ) ) {
                $type = PCIO_VIS_Tickets_DB::get_type( $tid );
                $groups[ $tid ] = [ 'name' => $type ? (string) $type['name'] : __( 'Ticket', 'pcio-vis-tickets' ), 'total' => 0, 'used' => 0, 'free' => 0 ];
            }
            ++$groups[ $tid ]['total'];
            if ( 'valid' === (string) $t['status'] ) {
                ++$groups[ $tid ]['free'];
            } else {
                ++$groups[ $tid ]['used'];
            }
        }
        $free_total = array_sum( array_column( $groups, 'free' ) );
        $now        = current_time( 'mysql' );
        $start      = ! empty( $event['door_opening_datetime'] ) && '0000-00-00 00:00:00' !== $event['door_opening_datetime']
            ? (string) $event['door_opening_datetime']
            : gmdate( 'Y-m-d H:i:s', strtotime( (string) $event['start_datetime'] . ' -1 hour' ) );
        $end        = (string) ( $event['end_datetime'] ?? '' );
        $in_window  = $now >= $start && $now <= $end;
        $customer   = ! empty( $order['customer_id'] ) ? PCIO_VIS_Tickets_DB::get_customer( (int) $order['customer_id'] ) : null;
        return [
            'ok'          => true,
            'ticket'      => $ticket,
            'order'       => $order,
            'event'       => $event,
            'groups'      => $groups,
            'free_total'  => $free_total,
            'in_window'   => $in_window,
            'window_from' => $start,
            'window_to'   => $end,
            'buyer'       => $customer && ! empty( $customer['name'] ) ? (string) $customer['name'] : (string) $order['email'],
        ];
    }

    /** POST handler for swipe activations. */
    private function handle_activation_post(): void {
        header( 'Content-Type: application/json; charset=UTF-8' );
        $body = json_decode( (string) file_get_contents( 'php://input' ), true );
        if ( ! is_array( $body ) ) {
            $body = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public activation, credential is the ticket payload.
        }
        $raw = isset( $body['data'] ) ? sanitize_text_field( (string) $body['data'] ) : '';
        $ctx = $this->activation_context( $raw );
        if ( empty( $ctx['ok'] ) ) {
            echo wp_json_encode( [ 'success' => false, 'error' => $ctx['error'] ?? '' ] );
            exit;
        }
        if ( ! $ctx['in_window'] ) {
            echo wp_json_encode( [ 'success' => false, 'error' => __( 'Activation is only possible during the event.', 'pcio-vis-tickets' ) ] );
            exit;
        }
        $selections = $body['selections'] ?? null;
        if ( ! is_array( $selections ) || empty( $selections ) ) {
            echo wp_json_encode( [ 'success' => false, 'error' => __( 'Select at least one ticket before swiping.', 'pcio-vis-tickets' ) ] );
            exit;
        }
        $activated = [];
        foreach ( $selections as $type_id_str => $count ) {
            $type_id = (int) $type_id_str;
            $count   = max( 0, (int) $count );
            if ( $count < 1 ) {
                continue;
            }
            $ids = PCIO_VIS_Tickets_DB::unused_ticket_ids( (int) $ctx['order']['id'], $type_id, $count );
            if ( empty( $ids ) ) {
                continue;
            }
            $marked = PCIO_VIS_Tickets_DB::mark_tickets_scanned( $ids );
            if ( $marked > 0 ) {
                $type = PCIO_VIS_Tickets_DB::get_type( $type_id );
                $activated[] = [ 'name' => $type ? (string) $type['name'] : __( 'Ticket', 'pcio-vis-tickets' ), 'count' => $marked ];
            }
        }
        if ( $activated ) {
            echo wp_json_encode( [ 'success' => true, 'event_title' => (string) $ctx['event']['title'], 'activated' => $activated ] );
        } else {
            echo wp_json_encode( [ 'success' => false, 'error' => __( 'No tickets activated.', 'pcio-vis-tickets' ) ] );
        }
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────


    /**
     * Resolve an event from a slug or a numeric id.
     */
    private function resolve_event( string $ref ): ?array {
        if ( '' === $ref ) {
            return null;
        }
        if ( ctype_digit( $ref ) ) {
            return PCIO_VIS_Events_DB::get( (int) $ref );
        }
        return PCIO_VIS_Events_DB::get_by_slug( sanitize_title( $ref ) );
    }

    /**
     * Render a front-end template with the active theme's header/footer.
     *
     * @param string $template       Absolute path.
     * @param array  $pcio_met_view  Data exposed to the template.
     */
    private function render( string $template, array $pcio_met_view ): void {
        if ( ! file_exists( $template ) ) {
            $this->not_found();
            return;
        }
        status_header( 200 );
        nocache_headers();
        // Templates either use $pcio_met_view or individual extracted variables.
        // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- used by template.
        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope only.
        extract( $pcio_met_view, EXTR_SKIP );
        // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- used by template.
        require $template;
        exit;
    }

    private function not_found(): void {
        global $wp_query;
        if ( $wp_query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
    }
}
