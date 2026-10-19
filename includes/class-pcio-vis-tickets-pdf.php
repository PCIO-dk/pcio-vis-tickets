<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PDF ticket generation for the Tickets extension.
 *
 * One PDF per order; one page block per issued ticket, each carrying a QR code
 * that encodes a check-in URL (the ticket's unique code). Files are written to
 * a protected sub-directory of the uploads folder and re-served through the
 * tokenised /tickets/download route.
 *
 * PDF rendering + QR generation are provided by the base plugin's shared
 * {@see PCIO_VIS_PDF} helper (Dompdf + php-qrcode live in member-event/vendor).
 */
class PCIO_VIS_Tickets_PDF {

    const SUBDIR = 'pcio-met-tickets';

    /**
     * Absolute path to the PDF for an order token (the file may not exist yet).
     */
    public static function file_for( string $token ): string {
        $dir = self::storage_dir();
        return trailingslashit( $dir['path'] ) . 'order-' . sanitize_file_name( $token ) . '.pdf';
    }

    /**
     * Generate (or return the cached) PDF for a confirmed order.
     *
     * @return string|null Absolute file path, or null on failure.
     */
    public static function generate( int $order_id, bool $force = false ) {
        $order = PCIO_VIS_Tickets_DB::get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $path = self::file_for( (string) $order['token'] );
        if ( ! $force && is_readable( $path ) ) {
            return $path;
        }

        $tickets = PCIO_VIS_Tickets_DB::get_tickets( $order_id );
        if ( empty( $tickets ) ) {
            return null;
        }

        $event = PCIO_VIS_Events_DB::get( (int) $order['event_id'] );

        // Map ticket_type_id → full type row (name + entrance flag).
        $types = [];
        foreach ( PCIO_VIS_Tickets_DB::get_types( (int) $order['event_id'] ) as $type ) {
            $types[ (int) $type['id'] ] = $type;
        }

        // Order items for per-ticket pricing.
        $unit_price = [];
        foreach ( PCIO_VIS_Tickets_DB::get_order_items( $order_id ) as $item ) {
            $unit_price[ (int) $item['ticket_type_id'] ] = (int) $item['unit_price_cents'];
        }

        $customer = ! empty( $order['customer_id'] )
            ? PCIO_VIS_Tickets_DB::get_customer( (int) $order['customer_id'] )
            : null;

        $html = self::render_html( [
            'order'      => $order,
            'event'      => $event,
            'tickets'    => $tickets,
            'types'      => $types,
            'unit_price' => $unit_price,
            'customer'   => $customer,
        ] );

        if ( ! class_exists( 'PCIO_VIS_PDF' ) || ! PCIO_VIS_PDF::available() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for a missing server-side PDF renderer.
            error_log( 'PCIO_VIS_Tickets_PDF: renderer unavailable (Dompdf missing?)' );
            return null;
        }

        $pdf = PCIO_VIS_PDF::render( $html, 'A4', 'portrait' );
        if ( ! is_string( $pdf ) || '' === $pdf ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for a failed PDF render.
            error_log( 'PCIO_VIS_Tickets_PDF: render failed for order ' . $order_id );
            return null;
        }

        // Ensure the protected storage directory exists before writing.
        self::storage_dir();

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! WP_Filesystem() || ! $wp_filesystem->put_contents( $path, $pdf ) ) {
            return null;
        }
        return $path;
    }

    // ── HTML template ─────────────────────────────────────────────

    private static function render_html( array $ctx ): string {
        $order      = $ctx['order'];
        $event      = $ctx['event'];
        $tickets    = $ctx['tickets'];
        $types      = $ctx['types'];
        $unit_price = $ctx['unit_price'];
        $customer   = $ctx['customer'];

        $event_title = $event ? (string) $event['title'] : __( 'Event', 'pcio-vis-tickets' );
        // Event time interval, legacy style: "fredag d. 20. juni 2026 kl. 19:30-22:00".
        $event_when = '';
        $door_line  = '';
        if ( $event && ! empty( $event['start_datetime'] ) ) {
            $event_when = self::nice_interval( (string) $event['start_datetime'], (string) ( $event['end_datetime'] ?? '' ) );
            if ( ! empty( $event['door_opening_datetime'] ) && '0000-00-00 00:00:00' !== $event['door_opening_datetime'] ) {
                $ts = strtotime( (string) $event['door_opening_datetime'] );
                if ( $ts ) {
                    $door_line = sprintf(
                        /* translators: %s: door opening time */
                        __( '(Doors open at %s)', 'pcio-vis-tickets' ),
                        wp_date( get_option( 'time_format' ), $ts )
                    );
                }
            }
        }
        $event_where = ( $event && ! empty( $event['location'] ) ) ? (string) $event['location'] : '';

        // Event thumb image as data URI (Dompdf cannot reach protected files reliably).
        $thumb_tag = '';
        $thumb_id  = $event ? (int) ( $event['thumb_image_id'] ?? 0 ) : 0;
        if ( ! $thumb_id && $event ) {
            $thumb_id = (int) ( $event['image_id'] ?? 0 );
        }
        if ( $thumb_id ) {
            $file = get_attached_file( $thumb_id );
            if ( $file && is_readable( $file ) ) {
                $mime = wp_get_image_mime( $file );
                if ( $mime ) {
                    $data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file for PDF embedding.
                    if ( false !== $data ) {
                        $thumb_tag = '<img src="data:' . esc_attr( $mime ) . ';base64,' . base64_encode( $data ) . '" style="width:100%; height:auto;">';
                    }
                }
            }
        }

        // Order data shared by every ticket in the order.
        $buyer_name = '';
        if ( $customer && ! empty( $customer['name'] ) ) {
            $buyer_name = (string) $customer['name'];
        } elseif ( ! empty( $order['email'] ) ) {
            $buyer_name = (string) $order['email'];
        }
        $purchase_when = '';
        $paid_ts = ! empty( $order['paid_at'] ) ? strtotime( (string) $order['paid_at'] ) : false;
        $made_ts = ! empty( $order['created'] ) ? strtotime( (string) $order['created'] ) : false;
        if ( $paid_ts ) {
            $purchase_when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $paid_ts );
        } elseif ( $made_ts ) {
            $purchase_when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $made_ts );
        }

        $site     = get_bloginfo( 'name' );
        $currency = (string) ( $order['currency'] ?? 'DKK' );

        ob_start();
        ?>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                * { font-family: "DejaVu Sans", sans-serif; }
                body { color: #1a1a1a; font-size: 12px; margin: 0; }
                .ticket { border: 2px dotted black; border-collapse: collapse; width: 100%; margin: 0 0 18px 0; page-break-inside: avoid; }
                .ticket td { border: 2px dotted black; padding: 8px; text-align: left; vertical-align: top; }
                .ticket__inner { border: none; border-collapse: collapse; width: 100%; }
                .ticket__inner td { border: none; padding: 8px; }
                h1 { font-size: 24px; margin: 0; }
                h2 { font-size: 18px; margin: 0; }
                .ticket__code { color: #666; font-size: 10px; margin-top: 6px; word-break: break-all; }
                .ticket__qr { width: 130px; text-align: center; }
                .ticket__qr img { width: 120px; height: 120px; }
                .ticket__activate { text-align: center; margin-top: 10px; }
                .ticket__activate a { font-size: 18px; color: #1a73e8; text-decoration: none; font-weight: 700; display: inline-block; padding: 6px 10px; border: 2px solid #1a73e8; border-radius: 6px; }
            </style>
        </head>
        <body>
            <?php foreach ( $tickets as $i => $ticket ) : ?>
                <?php
                $type      = $types[ (int) $ticket['ticket_type_id'] ] ?? null;
                $type_name = $type ? (string) $type['name'] : __( 'Ticket', 'pcio-vis-tickets' );
                $is_entre  = $type && ! empty( $type['is_entrance'] );
                $price     = self::format_price( $unit_price[ (int) $ticket['ticket_type_id'] ] ?? 0, $currency );
                $qr        = self::qr_data_uri( self::checkin_url( (string) $ticket['code'] ) );
                ?>
                <table class="ticket">
                    <tr>
                        <td style="width: 30%;">
                            <?php echo $thumb_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built data-URI img above. ?>
                            <?php if ( $is_entre ) : ?>
                                <br><i><?php esc_html_e( 'Entrance ticket', 'pcio-vis-tickets' ); ?></i>
                            <?php endif; ?>
                        </td>
                        <td style="width: 70%;">
                            <table class="ticket__inner">
                                <tr><td><h1><?php echo esc_html( $site ); ?> <?php esc_html_e( 'Ticket', 'pcio-vis-tickets' ); ?></h1></td></tr>
                                <tr><td><h2><?php echo esc_html( $event_title ); ?></h2></td></tr>
                                <tr>
                                    <td>
                                        <table class="ticket__inner">
                                            <tr>
                                                <td style="width: 70%;">
                                                    <table class="ticket__inner">
                                                        <tr>
                                                            <td>
                                                                <?php esc_html_e( 'Time:', 'pcio-vis-tickets' ); ?> <?php echo esc_html( trim( $event_when . ' ' . $door_line ) ); ?><br>
                                                                <?php esc_html_e( 'Place:', 'pcio-vis-tickets' ); ?> <?php echo esc_html( $event_where ); ?>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td>
                                                                <?php esc_html_e( 'Order number:', 'pcio-vis-tickets' ); ?> <?php echo esc_html( (string) $order['order_no'] ); ?><br>
                                                                <?php esc_html_e( 'Purchase date:', 'pcio-vis-tickets' ); ?> <?php echo esc_html( $purchase_when ); ?><br>
                                                                <?php esc_html_e( 'Purchased by:', 'pcio-vis-tickets' ); ?> <?php echo esc_html( $buyer_name ); ?>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td>
                                                                1 <?php esc_html_e( 'pc.', 'pcio-vis-tickets' ); ?> <?php echo esc_html( $type_name ); ?> <?php esc_html_e( 'at', 'pcio-vis-tickets' ); ?> <?php echo esc_html( $price ); ?>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td class="ticket__qr">
                                                    <img src="<?php echo esc_attr( $qr ); ?>" alt="QR">
                                                    <div class="ticket__activate">
                                                        <a href="<?php echo esc_url( self::checkin_url( (string) $ticket['code'] ) ); ?>"><?php esc_html_e( 'Use ticket', 'pcio-vis-tickets' ); ?></a>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <div class="ticket__code"><?php echo esc_html( (string) $ticket['code'] ); ?></div>
            <?php endforeach; ?>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Legacy-style Danish time interval, e.g. "fredag d. 20. juni 2026 kl. 19:30-22:00".
     */
    private static function nice_interval( string $start, string $end ): string {
        $ts = strtotime( $start );
        if ( ! $ts ) {
            return $start;
        }
        $out = wp_date( 'l \d. j. F Y \k\l. H:i', $ts );
        $te = $end ? strtotime( $end ) : false;
        if ( $te && wp_date( 'Y-m-d', $te ) === wp_date( 'Y-m-d', $ts ) ) {
            $out .= '-' . wp_date( 'H:i', $te );
        } elseif ( $te ) {
            $out .= ' – ' . wp_date( 'l \d. j. F Y \k\l. H:i', $te );
        }
        return $out;
    }

    /** Format øre as "75,00 kr". */
    private static function format_price( int $cents, string $currency ): string {
        $amount = number_format_i18n( $cents / 100, 2 );
        return 'DKK' === $currency ? $amount . ' kr' : $amount . ' ' . $currency;
    }

    // ── QR + check-in URL ─────────────────────────────────────────

    /**
     * Build the check-in URL a scanner would open for a ticket.
     *
     * Legacy-style payload: base64("ticket_id, event_id, order_no, customer_id").
     */
    public static function checkin_url( string $code ): string {
        return add_query_arg( 'data', rawurlencode( self::activation_payload( $code ) ), home_url( 'tickets/checkin/' ) );
    }

    /**
     * Encode the activation payload for a ticket code. Returns '' when the
     * ticket cannot be resolved (callers then fall back to the raw code).
     */
    public static function activation_payload( string $code ): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-match lookup by ticket code; is_cached false for correctness.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT t.id, t.order_id, o.event_id, o.order_no, o.customer_id FROM %i t JOIN %i o ON o.id = t.order_id WHERE t.code = %s LIMIT 1',
                PCIO_VIS_Tickets_DB::t_tickets(), PCIO_VIS_Tickets_DB::t_orders(), $code
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return $code;
        }
        return base64_encode( implode( ', ', [ (int) $row['id'], (int) $row['event_id'], (string) $row['order_no'], (int) $row['customer_id'] ] ) );
    }

    /**
     * Decode an activation payload. Returns [ticket_id, event_id] or null.
     *
     * @return array{int,int}|null
     */
    public static function parse_activation_payload( string $raw ): ?array {
        $decoded = base64_decode( $raw, true );
        if ( false === $decoded ) {
            return null;
        }
        $parts = array_map( 'trim', explode( ',', $decoded ) );
        if ( count( $parts ) < 2 || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
            return null;
        }
        return [ (int) $parts[0], (int) $parts[1] ];
    }

    /**
     * Render a QR code as a PNG data URI (embeddable in the PDF HTML).
     */
    private static function qr_data_uri( string $text ): string {
        return class_exists( 'PCIO_VIS_PDF' ) ? PCIO_VIS_PDF::qr_data_uri( $text ) : '';
    }

    // ── Storage ───────────────────────────────────────────────────

    /**
     * Return (and lazily create + protect) the PDF storage directory.
     *
     * @return array{path:string,url:string}
     */
    private static function storage_dir(): array {
        $uploads = wp_upload_dir();
        $path    = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
        $url     = trailingslashit( $uploads['baseurl'] ) . self::SUBDIR;

        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
            // Block direct web access; PDFs are only served via the tokenised route.
            global $wp_filesystem;
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if ( WP_Filesystem() ) {
                $wp_filesystem->put_contents( trailingslashit( $path ) . '.htaccess', "Require all denied\n" );
                $wp_filesystem->put_contents( trailingslashit( $path ) . 'index.html', '' );
            }
        }
        return [ 'path' => $path, 'url' => $url ];
    }
}
