<?php
/**
 * Uninstall handler for PCIO VIS Tickets.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes all
 * configuration options (including the stored Stripe API keys and webhook
 * secrets) and clears the scheduled cleanup event.
 *
 * NOTE: the custom order/ticket tables (me_t_orders, me_t_tickets, etc.) are
 * intentionally NOT dropped. They hold financial records (paid orders, refunds
 * and issued tickets) that a site owner may be legally required to retain, so
 * we do not destroy them automatically on delete. Remove them manually if you
 * are certain the sales history is no longer needed.
 *
 * @package PCIO_VIS_Tickets
 */

// Bail if WordPress did not invoke this file via the uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$pcio_vis_tickets_options = [
    'pcio_met_stripe_mode',
    'pcio_met_stripe_pk_test',
    'pcio_met_stripe_sk_test',
    'pcio_met_stripe_wh_test',
    'pcio_met_stripe_pk_live',
    'pcio_met_stripe_sk_live',
    'pcio_met_stripe_wh_live',
    'pcio_met_currency',
    'pcio_met_from_email',
    'pcio_met_from_name',
    'pcio_met_db_version',
];

foreach ( $pcio_vis_tickets_options as $pcio_vis_tickets_option ) {
    delete_option( $pcio_vis_tickets_option );
}

// Clear the daily cleanup cron in case it survived deactivation.
wp_clear_scheduled_hook( 'pcio_met_cleanup_pending' );
