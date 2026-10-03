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

        // Map ticket_type_id → display name.
        $type_names = [];
        foreach ( PCIO_VIS_Tickets_DB::get_types( (int) $order['event_id'] ) as $type ) {
            $type_names[ (int) $type['id'] ] = (string) $type['name'];
        }

        $html = self::render_html( [
            'order'      => $order,
            'event'      => $event,
            'tickets'    => $tickets,
            'type_names' => $type_names,
        ] );

        if ( ! class_exists( 'PCIO_VIS_PDF' ) || ! PCIO_VIS_PDF::available() ) {
            return null;
        }

        $pdf = PCIO_VIS_PDF::render( $html, 'A4', 'portrait' );
        if ( ! is_string( $pdf ) || '' === $pdf ) {
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
        $type_names = $ctx['type_names'];

        $event_title = $event ? (string) $event['title'] : __( 'Event', 'pcio-vis-tickets' );
        $event_when  = '';
        if ( $event && ! empty( $event['start_datetime'] ) ) {
            $ts = strtotime( (string) $event['start_datetime'] );
            if ( $ts ) {
                $event_when = wp_date(
                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                    $ts
                );
            }
        }
        $event_where = ( $event && ! empty( $event['location'] ) ) ? (string) $event['location'] : '';

        $site = get_bloginfo( 'name' );

        ob_start();
        ?>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                * { font-family: "DejaVu Sans", sans-serif; }
                body { color: #1a1a1a; font-size: 12px; margin: 0; }
                .ticket {
                    border: 2px solid #222;
                    border-radius: 8px;
                    padding: 18px 20px;
                    margin: 0 0 18px 0;
                    page-break-inside: avoid;
                }
                .ticket__head { font-size: 18px; font-weight: bold; margin: 0 0 4px; }
                .ticket__meta { color: #444; margin: 0 0 12px; }
                .ticket__meta div { margin: 2px 0; }
                .ticket__body { width: 100%; }
                .ticket__body td { vertical-align: top; }
                .ticket__type { font-size: 14px; font-weight: bold; }
                .ticket__code { color: #666; font-size: 10px; margin-top: 6px; word-break: break-all; }
                .ticket__qr { width: 130px; text-align: right; }
                .ticket__qr img { width: 120px; height: 120px; }
                .order { color: #666; font-size: 10px; margin: 0 0 14px; }
            </style>
        </head>
        <body>
            <div class="order">
                <?php
                printf(
                    /* translators: 1: site name, 2: order number */
                    esc_html__( '%1$s — Order %2$s', 'pcio-vis-tickets' ),
                    esc_html( $site ),
                    esc_html( (string) $order['order_no'] )
                );
                ?>
            </div>
            <?php foreach ( $tickets as $i => $ticket ) : ?>
                <?php
                $type_name = $type_names[ (int) $ticket['ticket_type_id'] ] ?? __( 'Ticket', 'pcio-vis-tickets' );
                $qr        = self::qr_data_uri( self::checkin_url( (string) $ticket['code'] ) );
                ?>
                <div class="ticket">
                    <div class="ticket__head"><?php echo esc_html( $event_title ); ?></div>
                    <div class="ticket__meta">
                        <?php if ( $event_when ) : ?>
                            <div><?php echo esc_html( $event_when ); ?></div>
                        <?php endif; ?>
                        <?php if ( $event_where ) : ?>
                            <div><?php echo esc_html( $event_where ); ?></div>
                        <?php endif; ?>
                    </div>
                    <table class="ticket__body">
                        <tr>
                            <td>
                                <div class="ticket__type"><?php echo esc_html( $type_name ); ?></div>
                                <div>
                                    <?php
                                    printf(
                                        /* translators: %d: ticket number within the order */
                                        esc_html__( 'Ticket %d', 'pcio-vis-tickets' ),
                                        (int) $i + 1
                                    );
                                    ?>
                                </div>
                                <div class="ticket__code"><?php echo esc_html( (string) $ticket['code'] ); ?></div>
                            </td>
                            <td class="ticket__qr">
                                <img src="<?php echo esc_attr( $qr ); ?>" alt="QR">
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endforeach; ?>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    // ── QR + check-in URL ─────────────────────────────────────────

    /**
     * Build the check-in URL a scanner would open for a ticket code.
     */
    public static function checkin_url( string $code ): string {
        return add_query_arg( 'code', rawurlencode( $code ), home_url( 'tickets/checkin/' ) );
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
