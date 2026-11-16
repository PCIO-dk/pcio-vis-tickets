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

    const CHECKOUT_PAGE_OPTION = 'pcio_met_checkout_page_id';
    const ACCEPTED_PAGE_OPTION = 'pcio_met_accepted_page_id';
    const REJECTED_PAGE_OPTION = 'pcio_met_rejected_page_id';
    const CHECKOUT_REWRITE_OPTION = 'pcio_met_checkout_rewrite_version';
    const CHECKOUT_SHORTCODE = 'pcio_met_checkout';
    const RESULT_SHORTCODE = 'pcio_met_result';

    private bool $checkout_prepared = false;
    private ?array $checkout_view = null;
    private bool $result_prepared = false;
    private ?array $result_view = null;

    public function register(): void {
        add_action( 'init',              [ $this, 'register_rewrites' ] );
        add_action( 'admin_init',        [ $this, 'maybe_upgrade_checkout_page' ] );
        add_action( 'template_redirect', [ $this, 'serve_templates'   ] );
        add_shortcode( self::CHECKOUT_SHORTCODE, [ $this, 'render_checkout_shortcode' ] );
        add_shortcode( self::RESULT_SHORTCODE, [ $this, 'render_result_shortcode' ] );
    }

    public function register_rewrites(): void {
        $page_id = (int) get_option( self::CHECKOUT_PAGE_OPTION, 0 );
        $checkout_query = $page_id > 0
            ? 'index.php?page_id=' . $page_id . '&pcio_met=checkout'
            : 'index.php?pcio_met=checkout';
        add_rewrite_rule( '^tickets/checkout/?$', $checkout_query, 'top' );
        $accepted_id = (int) get_option( self::ACCEPTED_PAGE_OPTION, 0 );
        $rejected_id = (int) get_option( self::REJECTED_PAGE_OPTION, 0 );
        $accepted_query = $accepted_id > 0
            ? 'index.php?page_id=' . $accepted_id . '&pcio_met=accepted'
            : 'index.php?pcio_met=accepted';
        $rejected_query = $rejected_id > 0
            ? 'index.php?page_id=' . $rejected_id . '&pcio_met=rejected'
            : 'index.php?pcio_met=rejected';
        add_rewrite_rule( '^tickets/accepted/?$', $accepted_query, 'top' );
        add_rewrite_rule( '^tickets/rejected/?$', $rejected_query, 'top' );
        add_rewrite_rule( '^tickets/download/?$', 'index.php?pcio_met=download', 'top' );
        add_rewrite_rule( '^tickets/checkin/?$', 'index.php?pcio_met=checkin', 'top' );
        add_rewrite_tag( '%pcio_met%', '([^&]+)' );
    }

    public function ensure_checkout_page(): int {
        return $this->ensure_ticket_page(
            'checkout',
            __( 'Ticket checkout', 'pcio-vis-tickets' ),
            self::CHECKOUT_SHORTCODE,
            self::CHECKOUT_PAGE_OPTION
        );
    }

    public function ensure_result_pages(): array {
        return [
            'accepted' => $this->ensure_ticket_page(
                'accepted',
                __( 'Ticket order accepted', 'pcio-vis-tickets' ),
                self::RESULT_SHORTCODE,
                self::ACCEPTED_PAGE_OPTION
            ),
            'rejected' => $this->ensure_ticket_page(
                'rejected',
                __( 'Ticket order rejected', 'pcio-vis-tickets' ),
                self::RESULT_SHORTCODE,
                self::REJECTED_PAGE_OPTION
            ),
        ];
    }

    public function ensure_public_pages(): bool {
        $checkout = $this->ensure_checkout_page();
        $results  = $this->ensure_result_pages();
        return $checkout > 0 && $results['accepted'] > 0 && $results['rejected'] > 0;
    }

    private function ensure_ticket_page( string $slug, string $title, string $shortcode, string $option ): int {
        $page_id = (int) get_option( $option, 0 );
        $page    = $page_id > 0 ? get_post( $page_id ) : null;
        if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
            $page = get_page_by_path( 'tickets/' . $slug );
        }

        if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
            $parent_id = $this->ensure_tickets_parent();
            if ( $parent_id < 1 ) {
                return 0;
            }
            $page_id = wp_insert_post( [
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'post_title'     => $title,
                'post_name'      => $slug,
                'post_parent'    => $parent_id,
                'post_content'   => '[' . $shortcode . ']',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ], true );
            if ( is_wp_error( $page_id ) || ! $page_id ) {
                return 0;
            }
            $page = get_post( (int) $page_id );
        }

        if ( ! $page || 'page' !== $page->post_type ) {
            return 0;
        }

        $page_id = (int) $page->ID;
        if ( false === strpos( (string) $page->post_content, '[' . $shortcode ) ) {
            $content = trim( (string) $page->post_content );
            $content = '' !== $content
                ? $content . "\n\n" . '[' . $shortcode . ']'
                : '[' . $shortcode . ']';
            wp_update_post( [ 'ID' => $page_id, 'post_content' => $content ] );
        }

        update_option( $option, $page_id );
        return $page_id;
    }

    private function ensure_tickets_parent(): int {
        $parent = get_page_by_path( 'tickets' );
        if ( $parent && 'page' === $parent->post_type && 'trash' !== $parent->post_status ) {
            return (int) $parent->ID;
        }
        $parent_id = wp_insert_post( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => __( 'Tickets', 'pcio-vis-tickets' ),
            'post_name'      => 'tickets',
            'post_content'   => '',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true );
        return is_wp_error( $parent_id ) || ! $parent_id ? 0 : (int) $parent_id;
    }

    public function maybe_upgrade_checkout_page(): void {
        $previous_ids = [
            (int) get_option( self::CHECKOUT_PAGE_OPTION, 0 ),
            (int) get_option( self::ACCEPTED_PAGE_OPTION, 0 ),
            (int) get_option( self::REJECTED_PAGE_OPTION, 0 ),
        ];
        if ( ! $this->ensure_public_pages() ) {
            return;
        }
        $current_ids = [
            (int) get_option( self::CHECKOUT_PAGE_OPTION, 0 ),
            (int) get_option( self::ACCEPTED_PAGE_OPTION, 0 ),
            (int) get_option( self::REJECTED_PAGE_OPTION, 0 ),
        ];
        if ( $previous_ids === $current_ids
            && PCIO_VIS_TICKETS_VERSION === get_option( self::CHECKOUT_REWRITE_OPTION, '' ) ) {
            return;
        }
        $this->register_rewrites();
        flush_rewrite_rules( false );
        update_option( self::CHECKOUT_REWRITE_OPTION, PCIO_VIS_TICKETS_VERSION );
    }

    private function is_ticket_page_request( string $view ): bool {
        switch ( $view ) {
            case 'checkout':
                $page_id = (int) get_option( self::CHECKOUT_PAGE_OPTION, 0 );
                break;
            case 'accepted':
                $page_id = (int) get_option( self::ACCEPTED_PAGE_OPTION, 0 );
                break;
            case 'rejected':
                $page_id = (int) get_option( self::REJECTED_PAGE_OPTION, 0 );
                break;
            default:
                return false;
        }
        return $page_id > 0 && (
            ( function_exists( 'is_page' ) && is_page( $page_id ) )
            || (int) get_query_var( 'page_id' ) === $page_id
        );
    }

    public function serve_templates(): void {
        $view = get_query_var( 'pcio_met' );
        if ( ! $view ) {
            return;
        }
        switch ( $view ) {
            case 'checkout':
                if ( $this->is_ticket_page_request( 'checkout' ) ) {
                    $this->prepare_checkout();
                    return;
                }
                $this->serve_checkout();
                return;
            case 'accepted':
            case 'rejected':
                if ( $this->is_ticket_page_request( (string) $view ) ) {
                    $this->prepare_result( (string) $view );
                    return;
                }
                $this->serve_result( (string) $view );
                return;
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

    public function render_checkout_shortcode(): string {
        if ( ! $this->is_ticket_page_request( 'checkout' ) ) {
            return '';
        }
        $this->prepare_checkout();
        return $this->checkout_view ? $this->checkout_content() : '';
    }

    private function prepare_checkout(): void {
        if ( $this->checkout_prepared ) {
            return;
        }
        $this->checkout_prepared = true;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET param, read-only.
        $event_ref = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '';
        $event     = $this->resolve_event( $event_ref );

        if ( ! $event || ! PCIO_VIS_Events_DB::is_published( $event ) || PCIO_VIS_Tickets_DB::event_mode( (int) $event['id'] ) !== 'tickets' || ! PCIO_VIS_Events_DB::can_view_event( $event ) ) {
            $this->not_found();
            return;
        }

        $event_id            = (int) $event['id'];
        $registered_required = PCIO_VIS_Tickets_DB::requires_registered_customer( $event );
        $registered_customer = $registered_required ? PCIO_VIS_Tickets_DB::current_registered_customer() : null;
        $types                = [];
        foreach ( PCIO_VIS_Tickets_DB::get_types( $event_id ) as $type ) {
            if ( ! PCIO_VIS_Tickets_DB::type_on_sale( $type ) || ! PCIO_VIS_Tickets_DB::can_purchase_type( $type, $event ) ) {
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
        $purchase = PCIO_VIS_Tickets_DB::purchase_state( $event );
        $currency = PCIO_VIS_Tickets_Admin::currency();
        $notice   = 'on_sale' === $purchase['state'] ? '' : $purchase['message'];
        $already_registered = false;

        if ( $registered_required && ! $registered_customer ) {
            $types  = [];
            $notice = __( 'Please sign in with the member account required for this event.', 'pcio-vis-tickets' );
        } elseif ( $registered_customer ) {
            $already_registered = PCIO_VIS_Tickets_DB::member_has_active_guest_signup( $event_id, (int) $registered_customer['member_id'] )
                || PCIO_VIS_Tickets_DB::member_has_entrance_order( $event_id, (int) $registered_customer['member_id'] );
            $settings['max_entrance_per_buyer'] = 1;
            if ( $already_registered ) {
                $types = array_values(
                    array_filter( $types, static fn( $type ) => empty( $type['is_entrance'] ) )
                );
                if ( ! $types ) {
                    $notice = __( 'This account is already registered for this event.', 'pcio-vis-tickets' );
                }
            }
        }

        $this->checkout_view = [
            'event'               => $event,
            'event_id'            => $event_id,
            'title'               => (string) $event['title'],
            'types'               => $types,
            'currency'            => $currency,
            'soldout'             => empty( $types ),
            'restricted'          => 'restricted' === $purchase['state'],
            'notice'              => $notice,
            'event_url'           => home_url( 'events/' . rawurlencode( $event['slug'] ?? '' ) . '/' ),
            'registered_customer' => $registered_customer,
            'already_registered'  => $already_registered,
        ];

        status_header( 200 );
        nocache_headers();
        $this->enqueue_checkout_assets( $event_id, $types, $settings, $currency );
    }

    private function checkout_content(): string {
        if ( ! $this->checkout_view ) {
            return '';
        }
        $pcio_met_view = $this->checkout_view;
        ob_start();
        require __DIR__ . '/../templates/checkout.php';
        return (string) ob_get_clean();
    }

    private function serve_checkout(): void {
        $this->prepare_checkout();
        if ( ! $this->checkout_view ) {
            return;
        }
        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/checkout.php.
        echo $this->checkout_content();
        get_footer();
        exit;
    }

    private function enqueue_checkout_assets( int $event_id, array $types, array $settings, string $currency ): void {
        wp_enqueue_style(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_CHECKOUT_ASSET_VERSION
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

    public function render_result_shortcode(): string {
        $view = (string) get_query_var( 'pcio_met' );
        if ( ! in_array( $view, [ 'accepted', 'rejected' ], true ) || ! $this->is_ticket_page_request( $view ) ) {
            return '';
        }
        $this->prepare_result( $view );
        return $this->result_view ? $this->result_content() : '';
    }

    private function prepare_result( string $view ): void {
        if ( $this->result_prepared ) {
            return;
        }
        $this->result_prepared = true;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET token, read-only.
        $token = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order = $token ? PCIO_VIS_Tickets_DB::get_order_by_token( $token ) : null;
        $event = $order ? PCIO_VIS_Events_DB::get( (int) $order['event_id'] ) : null;

        $this->result_view = [
            'view'      => $view,
            'order'     => $order,
            'event'     => $event,
            'event_url' => $event ? home_url( 'events/' . rawurlencode( $event['slug'] ?? '' ) . '/' ) : home_url( '/' ),
            'retry_url' => $event ? add_query_arg( 'event', rawurlencode( $event['slug'] ?? '' ), home_url( 'tickets/checkout/' ) ) : home_url( '/' ),
        ];

        status_header( 200 );
        nocache_headers();
        wp_enqueue_style(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_CHECKOUT_ASSET_VERSION
        );
    }

    private function result_content(): string {
        if ( ! $this->result_view ) {
            return '';
        }
        $pcio_met_view = $this->result_view;
        ob_start();
        require __DIR__ . '/../templates/result.php';
        return (string) ob_get_clean();
    }

    private function serve_result( string $view ): void {
        $this->prepare_result( $view );
        if ( ! $this->result_view ) {
            return;
        }
        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/result.php.
        echo $this->result_content();
        get_footer();
        exit;
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
        $buyer      = '';
        if ( $in_window ) {
            $buyer = $customer && ! empty( $customer['name'] ) ? (string) $customer['name'] : (string) $order['email'];
        }
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
            'buyer'       => $buyer,
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
