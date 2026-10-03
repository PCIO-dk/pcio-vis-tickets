<?php
/*
Plugin Name:  PCIO VIS Tickets
Plugin URI:   https://www.pcio.dk/
Description:  Adds paid (Stripe) and free ticket sales to PCIO VIS Member Event. Events with a guest limit sell tickets to the public; per-event ticket types, a secure checkout, PDF tickets with QR codes, and refunds. Events without a limit keep the member join/interested/not-joining signup.
Version:      1.0.0
Author:       PCIO
Author URI:   https://www.pcio.dk
Requires at least: 6.2
Requires PHP: 8.1
Requires Plugins: pcio-vis-member-event
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  pcio-vis-tickets
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PCIO_VIS_TICKETS_FILE', __FILE__ );
define( 'PCIO_VIS_TICKETS_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PCIO_VIS_TICKETS_URL',  plugin_dir_url( __FILE__ ) );
define( 'PCIO_VIS_TICKETS_VERSION', '1.0.0' );

// PDF (Dompdf) + QR libraries are provided by the base Member Event plugin
// (member-event/vendor, loaded via PCIO_VIS_PDF). Tickets requires member-event.

require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-db.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-stripe.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-pdf.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-fulfillment.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-rest.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-admin.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-frontend.php';
require_once PCIO_VIS_TICKETS_DIR . 'includes/class-pcio-vis-tickets-integration.php';

class PCIO_VIS_Tickets_Plugin {

    /**
     * Custom capabilities and the WP roles they are granted to on activation.
     */
    const CAP_ROLE_MAP = [
        'vis_tickets_manage' => [ 'editor', 'administrator' ],
        'vis_tickets_refund' => [ 'editor', 'administrator' ],
    ];

    const CRON_CLEANUP = 'pcio_met_cleanup_pending';

    public static function register(): void {
        add_action( 'admin_init',    [ 'PCIO_VIS_Tickets_DB', 'install' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest'   ] );
        add_action( self::CRON_CLEANUP, [ __CLASS__, 'cleanup_pending' ] );

        ( new PCIO_VIS_Tickets_Admin() )->register();
        ( new PCIO_VIS_Tickets_Frontend() )->register();
        ( new PCIO_VIS_Tickets_Integration() )->register();
        ( new PCIO_VIS_Tickets_Fulfillment() )->register();
    }

    /**
     * Daily housekeeping: cancel checkout sessions that were never paid so the
     * orders list stays meaningful. Pending orders hold no capacity.
     */
    public static function cleanup_pending(): void {
        $minutes = (int) apply_filters( 'pcio_met_pending_ttl_minutes', 120 );
        PCIO_VIS_Tickets_DB::cancel_stale_pending( $minutes );
    }

    // ── Activation / deactivation ─────────────────────────────────

    public static function activate(): void {
        PCIO_VIS_Tickets_DB::install();
        foreach ( self::CAP_ROLE_MAP as $cap => $roles ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $cap );
                }
            }
        }
        // Public checkout pages are served from rewrite rules registered on
        // 'init'; flush so /tickets/checkout etc. resolve.
        ( new PCIO_VIS_Tickets_Frontend() )->register_rewrites();
        flush_rewrite_rules();

        if ( ! wp_next_scheduled( self::CRON_CLEANUP ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP );
        }
    }

    public static function deactivate(): void {
        foreach ( array_keys( self::CAP_ROLE_MAP ) as $cap ) {
            foreach ( wp_roles()->role_objects as $role ) {
                $role->remove_cap( $cap );
            }
        }
        wp_clear_scheduled_hook( self::CRON_CLEANUP );
        flush_rewrite_rules();
    }

    // ── REST ──────────────────────────────────────────────────────

    public static function register_rest(): void {
        ( new PCIO_VIS_Tickets_Rest() )->register();
    }
}

register_activation_hook( __FILE__, [ 'PCIO_VIS_Tickets_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PCIO_VIS_Tickets_Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    // Hard dependency: this extension does nothing without the base PCIO VIS
    // Member Event plugin (which also provides the shared PDF/QR libraries). The
    // "Requires Plugins: pcio-vis-member-event" header blocks activation on WP 6.5+;
    // this guard + notice covers older cores and the case where the base is
    // deactivated while Tickets stays active.
    if ( ! class_exists( 'PCIO_VIS_Plugin' ) ) {
        add_action( 'admin_notices', static function (): void {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }
            echo '<div class="notice notice-error"><p>'
                . esc_html__(
                    'Member Event – Tickets requires the Member Event plugin to be installed and active. Tickets functionality is disabled until then.',
                    'pcio-vis-tickets'
                )
                . '</p></div>';
        } );
        return;
    }
    PCIO_VIS_Tickets_Plugin::register();
} );
