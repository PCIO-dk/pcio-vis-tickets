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
            default:
                $this->not_found();
        }
    }

    // ── Checkout ──────────────────────────────────────────────────

    private function serve_checkout(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET param, read-only.
        $event_ref = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '';
        $event     = $this->resolve_event( $event_ref );

        if ( ! $event || PCIO_VIS_Tickets_DB::event_mode( (int) $event['id'] ) !== 'tickets' ) {
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
