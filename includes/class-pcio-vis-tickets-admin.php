<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-admin surface for the Tickets extension.
 *
 *   • Phase 3 – Stripe settings page (keys, currency, mode, sender)
 *   • Phase 6 – Orders list + refund actions
 *
 * Settings live as individual options (test/live key sets are kept separate so
 * switching mode never discards keys). Static accessors expose the active
 * configuration to the checkout (Phase 4) and webhook (Phase 5) code.
 *
 * SECURITY: the secret key and webhook signing secret must NEVER reach any
 * client. Only the publishable key may be sent to the browser.
 */
class PCIO_VIS_Tickets_Admin {

    const OPT_MODE        = 'pcio_met_stripe_mode';        // 'test' | 'live'
    const OPT_PK_TEST     = 'pcio_met_stripe_pk_test';
    const OPT_SK_TEST     = 'pcio_met_stripe_sk_test';
    const OPT_WH_TEST     = 'pcio_met_stripe_wh_test';
    const OPT_PK_LIVE     = 'pcio_met_stripe_pk_live';
    const OPT_SK_LIVE     = 'pcio_met_stripe_sk_live';
    const OPT_WH_LIVE     = 'pcio_met_stripe_wh_live';
    const OPT_CURRENCY    = 'pcio_met_currency';           // ISO-4217, e.g. DKK
    const OPT_FROM_EMAIL  = 'pcio_met_from_email';
    const OPT_FROM_NAME   = 'pcio_met_from_name';
    const OPT_REPORT_LOGO = 'pcio_met_report_logo_id';

    const NONCE_ACTION = 'pcio_met_settings_save';
    const NONCE_FIELD  = 'pcio_met_settings_nonce';

    const PAGE_SLUG = 'pcio-me-tickets';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
        add_action( 'admin_post_pcio_met_rebuild_reports', [ $this, 'handle_rebuild_reports' ] );
        add_filter(
            'plugin_action_links_' . plugin_basename( PCIO_VIS_TICKETS_FILE ),
            [ $this, 'action_links' ]
        );
    }

    /** Media picker used by the report logo field, settings page only. */
    public function enqueue_settings_assets( string $hook ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::PAGE_SLUG !== $page ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'pcio-me-tickets-admin',
            plugins_url( 'assets/admin-settings.js', PCIO_VIS_TICKETS_FILE ),
            [ 'jquery' ],
            '1.0.0',
            true
        );
    }

    public static function report_logo_id(): int {
        return absint( get_option( self::OPT_REPORT_LOGO, 0 ) );
    }

    /**
     * Recreate/refresh one report row per event with confirmed orders. Estimate
     * based (no Stripe calls), never moves money, never sends mail.
     */
    public function handle_rebuild_reports(): void {
        if ( ! current_user_can( 'vis_manage_settings' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-tickets' ) );
        }
        check_admin_referer( 'pcio_met_rebuild_reports' );
        $count = PCIO_VIS_Tickets_Reports::refresh_all_reports();
        wp_safe_redirect(
            add_query_arg(
                [ 'page' => self::PAGE_SLUG, 'pcio_met_rebuilt' => $count ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    // ── Plugin list action links ──────────────────────────────────

    public function action_links( array $links ): array {
        $extra = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">'
                . esc_html__( 'Settings', 'pcio-vis-tickets' ) . '</a>',
        ];
        return array_merge( $extra, $links );
    }

    // ── Admin submenu ─────────────────────────────────────────────

    public function add_menu(): void {
        add_submenu_page(
            'pcio-me-settings',
            __( 'Tickets', 'pcio-vis-tickets' ),
            __( 'Tickets', 'pcio-vis-tickets' ),
            'vis_manage_settings',
            self::PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    // ── Settings page ─────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( 'vis_manage_settings' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-tickets' ) );
        }

        $saved = $this->maybe_save();

        $mode       = self::stripe_mode();
        $pk_test    = (string) get_option( self::OPT_PK_TEST, '' );
        $sk_test    = (string) get_option( self::OPT_SK_TEST, '' );
        $wh_test    = (string) get_option( self::OPT_WH_TEST, '' );
        $pk_live    = (string) get_option( self::OPT_PK_LIVE, '' );
        $sk_live    = (string) get_option( self::OPT_SK_LIVE, '' );
        $wh_live    = (string) get_option( self::OPT_WH_LIVE, '' );
        $currency   = self::currency();
        $from_email = self::from_email();
        $from_name  = self::from_name();

        $pass_on   = PCIO_VIS_Tickets_Fees::pass_on();
        $auto_payout = PCIO_VIS_Tickets_Reports::auto_payout_enabled();
        $delay_days  = PCIO_VIS_Tickets_Reports::delay_days();
        $fee_base    = PCIO_VIS_Tickets_Reports::fee_base_cents() / 100;
        $fee_percent = PCIO_VIS_Tickets_Reports::fee_percent();
        $receivers   = PCIO_VIS_Tickets_Reports::receivers();
        $sync_job    = class_exists( 'PCIO_VIS_Sync_DB' )
            ? PCIO_VIS_Sync_DB::get_by_cron_name( PCIO_VIS_Tickets_Integration::SYNC_CRON_NAME )
            : null;
        $next_scan   = $sync_job ? (int) wp_next_scheduled( 'pcio_me_cron_' . $sync_job['cron_name'] ) : 0;
        $sync_on     = $sync_job && (int) $sync_job['enabled'];
        $coverage    = PCIO_VIS_Tickets_Reports::coverage();
        $logo_id     = self::report_logo_id();
        $logo_url    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

        $webhook_url = rest_url( PCIO_VIS_REST_NS . '/tickets/webhook' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vis — Ticket settings', 'pcio-vis-tickets' ); ?></h1>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pcio_met_rebuild_reports' ), 'pcio_met_rebuild_reports' ) ); ?>">
                    <?php esc_html_e( 'Recreate event reports now', 'pcio-vis-tickets' ); ?>
                </a>
                <span class="description">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: events with confirmed orders, 2: report rows. */
                        __( 'Data coverage: %1$d events with confirmed orders, %2$d report rows.', 'pcio-vis-tickets' ),
                        (int) $coverage['events'],
                        (int) $coverage['reports']
                    ) );
                    ?>
                </span>
            </p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e( 'Ticket settings saved.', 'pcio-vis-tickets' ); ?>
                </p></div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success counter from a redirect.
            $rebuilt = isset( $_GET['pcio_met_rebuilt'] ) ? absint( wp_unslash( $_GET['pcio_met_rebuilt'] ) ) : null;
            ?>
            <?php if ( null !== $rebuilt ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: number of report rows after the rebuild. */
                        __( 'Event reports rebuilt from orders: %d report rows.', 'pcio-vis-tickets' ),
                        $rebuilt
                    ) );
                    ?>
                </p></div>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e( 'Stripe keys are used to take card payments for paid tickets. Free tickets (price 0) skip Stripe entirely. Keys are stored in the database and never sent to visitors — only the publishable key is exposed to the checkout page.', 'pcio-vis-tickets' ); ?>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: Stripe dashboard URL */
                        esc_html__( 'Find your keys in the %s.', 'pcio-vis-tickets' ),
                        '<a href="' . esc_url( 'https://dashboard.stripe.com/apikeys' ) . '" target="_blank" rel="noopener">Stripe dashboard</a>'
                    ),
                    [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
                );
                ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

                <h2 class="title"><?php esc_html_e( 'Mode', 'pcio-vis-tickets' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Active mode', 'pcio-vis-tickets' ); ?></th>
                        <td>
                            <fieldset>
                                <label style="margin-right:18px;">
                                    <input type="radio" name="stripe_mode" value="test" <?php checked( $mode, 'test' ); ?>>
                                    <?php esc_html_e( 'Test', 'pcio-vis-tickets' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="stripe_mode" value="live" <?php checked( $mode, 'live' ); ?>>
                                    <?php esc_html_e( 'Live', 'pcio-vis-tickets' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Choose which key set is used for checkout. Both sets are stored independently, so you can switch without re-entering keys.', 'pcio-vis-tickets' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Test keys', 'pcio-vis-tickets' ); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->key_row( 'stripe_pk_test', __( 'Publishable key', 'pcio-vis-tickets' ), $pk_test, 'pk_test_…', 'text' );
                    $this->key_row( 'stripe_sk_test', __( 'Secret key', 'pcio-vis-tickets' ), $sk_test, 'sk_test_…', 'password' );
                    $this->key_row( 'stripe_wh_test', __( 'Webhook signing secret', 'pcio-vis-tickets' ), $wh_test, 'whsec_…', 'password' );
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e( 'Live keys', 'pcio-vis-tickets' ); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->key_row( 'stripe_pk_live', __( 'Publishable key', 'pcio-vis-tickets' ), $pk_live, 'pk_live_…', 'text' );
                    $this->key_row( 'stripe_sk_live', __( 'Secret key', 'pcio-vis-tickets' ), $sk_live, 'sk_live_…', 'password' );
                    $this->key_row( 'stripe_wh_live', __( 'Webhook signing secret', 'pcio-vis-tickets' ), $wh_live, 'whsec_…', 'password' );
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e( 'Checkout', 'pcio-vis-tickets' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="currency"><?php esc_html_e( 'Currency', 'pcio-vis-tickets' ); ?></label>
                        </th>
                        <td>
                            <input name="currency" id="currency" type="text"
                                   class="small-text" maxlength="3"
                                   style="text-transform:uppercase;"
                                   value="<?php echo esc_attr( $currency ); ?>">
                            <p class="description"><?php esc_html_e( 'Three-letter ISO currency code (e.g. DKK, EUR, USD).', 'pcio-vis-tickets' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="from_email"><?php esc_html_e( 'Ticket sender email', 'pcio-vis-tickets' ); ?></label>
                        </th>
                        <td>
                            <input name="from_email" id="from_email" type="email"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $from_email ); ?>"
                                   placeholder="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Address that ticket confirmation emails are sent from. Leave blank to use the site admin email.', 'pcio-vis-tickets' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="from_name"><?php esc_html_e( 'Ticket sender name', 'pcio-vis-tickets' ); ?></label>
                        </th>
                        <td>
                            <input name="from_name" id="from_name" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $from_name ); ?>"
                                   placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Ticket fees', 'pcio-vis-tickets' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Pass fee to buyers', 'pcio-vis-tickets' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="fee_pass_on" value="1" <?php checked( $pass_on ); ?>>
                                    <?php esc_html_e( 'Add a service fee to every checkout order (tickets, products and renewals)', 'pcio-vis-tickets' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'A single fee per order — never per item — paid by the buyer on top of the stated prices. It applies to all payment methods alike: a payment-specific surcharge would violate the Stripe rules in the EU. When disabled, the organisation covers the Stripe costs itself.', 'pcio-vis-tickets' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fee_base"><?php esc_html_e( 'Fee basis per order (kr)', 'pcio-vis-tickets' ); ?></label>
                        </th>
                        <td>
                            <input type="text" class="small-text" id="fee_base" name="fee_base"
                                   value="<?php echo esc_attr( number_format( $fee_base, 2, '.', '' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Flat amount added to every order. These figures are also used to estimate the Stripe fee in event reports when the exact fees cannot be read from Stripe.', 'pcio-vis-tickets' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fee_percent"><?php esc_html_e( 'Fee percent of order ( % )', 'pcio-vis-tickets' ); ?></label>
                        </th>
                        <td>
                            <input type="text" class="small-text" id="fee_percent" name="fee_percent"
                                   value="<?php echo esc_attr( number_format( $fee_percent, 2, '.', '' ) ); ?>"> %
                            <p class="description"><?php esc_html_e( 'Percentage of the order subtotal, rounded up to whole øre, added together with the base amount.', 'pcio-vis-tickets' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Event reports', 'pcio-vis-tickets' ); ?></h2>
                <p class="description">
                    <?php
                    echo esc_html(
                        'live' === PCIO_VIS_Tickets_Reports::mode()
                            ? __( 'Stripe mode: Live. Stripe actions use the live keys. Event reports show all orders regardless of mode; payouts are made from the live balance.', 'pcio-vis-tickets' )
                            : __( 'Stripe mode: Test. Stripe actions use test keys only. Event reports and amounts are unaffected; a report containing live payments can only be paid out in Live mode. Report emails follow the cron test setting.', 'pcio-vis-tickets' )
                    );
                    ?>
                </p>
                <p class="description">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: events with confirmed orders, 2: report rows, 3: order rows, 4: paid orders, 5: refunded orders, 6: free orders. */
                        __( 'Data coverage: %1$d events with confirmed orders, %2$d report rows. Order rows: %3$d (paid %4$d, refunded %5$d, free %6$d). Reports are built from these orders, not copied from Fjordglimt.', 'pcio-vis-tickets' ),
                        (int) $coverage['events'],
                        (int) $coverage['reports'],
                        (int) $coverage['orders'],
                        (int) ( $coverage['by_status']['paid'] ?? 0 ),
                        (int) ( ( $coverage['by_status']['refunded'] ?? 0 ) + ( $coverage['by_status']['partly_refunded'] ?? 0 ) ),
                        (int) ( $coverage['by_status']['free_confirmed'] ?? 0 )
                    ) );
                    ?>
                </p>
                <?php if ( $coverage['missing_columns'] ) : ?>
                    <div class="notice notice-error inline"><p>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %s: comma-separated database column names. */
                            __( 'Database columns missing on the ticket orders table: %s. Load this page again once to apply the upgrade; if the message stays, the database user cannot alter tables.', 'pcio-vis-tickets' ),
                            implode( ', ', $coverage['missing_columns'] )
                        ) );
                        ?>
                    </p></div>
                <?php endif; ?>
                <?php if ( '' !== $coverage['sql_error'] ) : ?>
                    <div class="notice notice-warning inline"><p>
                        <?php echo esc_html( sprintf( /* translators: %s: database error message. */ __( 'Last database error: %s', 'pcio-vis-tickets' ), $coverage['sql_error'] ) ); ?>
                    </p></div>
                <?php endif; ?>
                <p>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pcio_met_rebuild_reports' ), 'pcio_met_rebuild_reports' ) ); ?>">
                        <?php esc_html_e( 'Recreate event reports now', 'pcio-vis-tickets' ); ?>
                    </a>
                    <span class="description"><?php esc_html_e( 'Creates one report row per event with confirmed orders. No payouts are made and no emails are sent.', 'pcio-vis-tickets' ); ?></span>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Automatic payout', 'pcio-vis-tickets' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="auto_payout" value="1" <?php checked( $auto_payout ); ?>>
                                    <?php esc_html_e( 'Pay out finished events automatically', 'pcio-vis-tickets' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'A daily check pays out finished events that have waited the configured delay. The check only pays out when the Stripe balance is sufficient; otherwise the report is flagged “awaiting Stripe balance” on the Finance page and retried the next day.', 'pcio-vis-tickets' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="payout_delay_days"><?php esc_html_e( 'Payout delay (days)', 'pcio-vis-tickets' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="0" max="365" step="1" class="small-text" id="payout_delay_days"
                                   name="payout_delay_days" value="<?php echo esc_attr( (string) $delay_days ); ?>">
                            <p class="description"><?php esc_html_e( 'Number of days after the event end before the report becomes payable (and, with automatic payout enabled, is paid out). Default 3.', 'pcio-vis-tickets' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Report logo', 'pcio-vis-tickets' ); ?></th>
                        <td>
                            <input type="hidden" id="report_logo_id" name="report_logo_id" value="<?php echo esc_attr( (string) $logo_id ); ?>">
                            <div id="report_logo_preview" style="min-height:36px;margin-bottom:8px;">
                                <?php if ( $logo_url ) : ?>
                                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-width:220px;max-height:80px;">
                                <?php else : ?>
                                    <span class="description"><?php esc_html_e( 'No logo selected.', 'pcio-vis-tickets' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="button" data-media-pick
                                    data-target="report_logo_id" data-preview="report_logo_preview"
                                    data-title="<?php esc_attr_e( 'Select report logo', 'pcio-vis-tickets' ); ?>">
                                <?php esc_html_e( 'Select logo', 'pcio-vis-tickets' ); ?>
                            </button>
                            <button type="button" class="button-link" data-media-clear
                                    data-target="report_logo_id" data-preview="report_logo_preview"
                                    data-empty-text="<?php esc_attr_e( 'No logo selected.', 'pcio-vis-tickets' ); ?>">
                                <?php esc_html_e( 'Remove', 'pcio-vis-tickets' ); ?>
                            </button>
                            <p class="description"><?php esc_html_e( 'Shown in the header of the event report PDF. A transparent PNG works best (max ~190 × 72 px).', 'pcio-vis-tickets' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Report receivers', 'pcio-vis-tickets' ); ?></th>
                        <td>
                            <fieldset>
                                <?php if ( $receivers ) : ?>
                                    <table class="widefat striped" style="max-width:480px;margin-bottom:8px;">
                                        <tbody>
                                        <?php foreach ( $receivers as $receiver ) : ?>
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="receiver_keep[]" value="<?php echo esc_attr( $receiver ); ?>">
                                                    <?php echo esc_html( $receiver ); ?>
                                                </td>
                                                <td style="width:160px;text-align:right;">
                                                    <label><input type="checkbox" name="receiver_remove[]" value="<?php echo esc_attr( $receiver ); ?>"> <?php esc_html_e( 'Remove', 'pcio-vis-tickets' ); ?></label>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else : ?>
                                    <p class="description"><?php esc_html_e( 'No receivers yet — finished event reports are still shown on the Finance page, and nobody receives them as email. Add at least one email address below.', 'pcio-vis-tickets' ); ?></p>
                                <?php endif; ?>
                                <p>
                                    <input type="email" class="regular-text" name="receiver_add" id="receiver_add" value=""
                                           placeholder="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>"
                                           autocomplete="off">
                                </p>
                                <p class="description"><?php esc_html_e( 'Add an email address and press Save — the report PDF for finished events is sent to this list.', 'pcio-vis-tickets' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Next report scan', 'pcio-vis-tickets' ); ?></th>
                        <td>
                            <p class="description">
                                <?php
                                if ( ! $sync_job ) {
                                    esc_html_e( 'The report scan is not registered yet. Open Vis → Sync Jobs once to register it.', 'pcio-vis-tickets' );
                                } elseif ( ! $sync_on ) {
                                    esc_html_e( 'The report scan job is disabled. Enable “Ticket payouts & event reports” under Vis → Sync Jobs.', 'pcio-vis-tickets' );
                                } elseif ( $next_scan > 0 ) {
                                    /* translators: %s: date and time of the next automatic scan. */
                                    echo esc_html( sprintf( __( 'The next automatic report and payout scan runs %s.', 'pcio-vis-tickets' ), date_i18n( 'Y-m-d H:i', $next_scan + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) );
                                } else {
                                    esc_html_e( 'The scan job is enabled and will be scheduled on the next admin request.', 'pcio-vis-tickets' );
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save ticket settings', 'pcio-vis-tickets' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Status', 'pcio-vis-tickets' ); ?></h2>
            <?php if ( self::is_configured() ) : ?>
                <p>
                    <span style="color:#00a32a;font-weight:600;">&#10003;</span>
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: active mode (test/live) */
                            esc_html__( 'Stripe is configured in %s mode. Paid tickets can be sold.', 'pcio-vis-tickets' ),
                            '<strong>' . esc_html( $mode ) . '</strong>'
                        ),
                        [ 'strong' => [] ]
                    );
                    ?>
                </p>
            <?php else : ?>
                <p>
                    <span style="color:#d63638;font-weight:600;">&#9888;</span>
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: active mode (test/live) */
                            esc_html__( 'Stripe is not fully configured for %s mode. Paid tickets cannot be sold until the publishable and secret keys are set. Free tickets still work.', 'pcio-vis-tickets' ),
                            '<strong>' . esc_html( $mode ) . '</strong>'
                        ),
                        [ 'strong' => [] ]
                    );
                    ?>
                </p>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Webhook endpoint', 'pcio-vis-tickets' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Add this URL as a webhook in your Stripe dashboard (event: checkout.session.completed) and paste the resulting signing secret above.', 'pcio-vis-tickets' ); ?>
            </p>
            <p><code><?php echo esc_html( $webhook_url ); ?></code></p>
        </div>
        <?php
    }

    /**
     * Render one Stripe key row.
     */
    private function key_row( string $name, string $label, string $value, string $placeholder, string $type ): void {
        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
            </th>
            <td>
                <input name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>"
                       type="<?php echo esc_attr( $type ); ?>" class="regular-text" autocomplete="off"
                       value="<?php echo esc_attr( $value ); ?>"
                       placeholder="<?php echo esc_attr( $placeholder ); ?>">
            </td>
        </tr>
        <?php
    }

    /**
     * Handle the settings POST. Returns true when a save happened.
     */
    private function maybe_save(): bool {
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return false;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
            return false;
        }
        if ( ! current_user_can( 'vis_manage_settings' ) ) {
            return false;
        }

        $mode = ( sanitize_text_field( wp_unslash( $_POST['stripe_mode'] ?? 'test' ) ) === 'live' ) ? 'live' : 'test';
        update_option( self::OPT_MODE, $mode );

        update_option( self::OPT_PK_TEST, $this->clean_key( sanitize_text_field( wp_unslash( $_POST['stripe_pk_test'] ?? '' ) ) ) );
        update_option( self::OPT_SK_TEST, $this->clean_key( sanitize_text_field( wp_unslash( $_POST['stripe_sk_test'] ?? '' ) ) ) );
        update_option( self::OPT_WH_TEST, $this->clean_key( sanitize_text_field( wp_unslash( $_POST['stripe_wh_test'] ?? '' ) ) ) );
        update_option( self::OPT_PK_LIVE, $this->clean_key( sanitize_text_field( wp_unslash( $_POST['stripe_pk_live'] ?? '' ) ) ) );
        update_option( self::OPT_SK_LIVE, $this->clean_key( sanitize_text_field( wp_unslash( $_POST['stripe_sk_live'] ?? '' ) ) ) );
        update_option( self::OPT_WH_LIVE, $this->clean_key( sanitize_text_field( wp_unslash( $_POST['stripe_wh_live'] ?? '' ) ) ) );

        $currency = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'DKK' ) ) );
        $currency = preg_replace( '/[^A-Z]/', '', $currency );
        update_option( self::OPT_CURRENCY, substr( $currency, 0, 3 ) ?: 'DKK' );

        update_option( self::OPT_FROM_EMAIL, sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ) );
        update_option( self::OPT_FROM_NAME,  sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ) );
        update_option( self::OPT_REPORT_LOGO, absint( $_POST['report_logo_id'] ?? 0 ) );

        // Event reports + automatic payouts.
        update_option(
            PCIO_VIS_Tickets_Reports::OPT_AUTO_PAYOUT,
            ! empty( $_POST['auto_payout'] ) ? 1 : 0
        );
        update_option(
            PCIO_VIS_Tickets_Fees::OPT_PASS_ON,
            ! empty( $_POST['fee_pass_on'] ) ? 1 : 0
        );
        update_option(
            PCIO_VIS_Tickets_Reports::OPT_DELAY_DAYS,
            max( 0, min( 365, absint( $_POST['payout_delay_days'] ?? PCIO_VIS_Tickets_Reports::DEFAULT_DELAY_DAYS ) ) )
        );
        $fee_base_raw = isset( $_POST['fee_base'] ) && is_scalar( $_POST['fee_base'] )
            ? sanitize_text_field( wp_unslash( $_POST['fee_base'] ) )
            : '';
        $fee_base = floatval( str_replace( ',', '.', $fee_base_raw ) );
        update_option( PCIO_VIS_Tickets_Reports::OPT_FEE_BASE, max( 0, (int) round( $fee_base * 100 ) ) );
        $fee_percent_raw = isset( $_POST['fee_percent'] ) && is_scalar( $_POST['fee_percent'] )
            ? sanitize_text_field( wp_unslash( $_POST['fee_percent'] ) )
            : '';
        $fee_percent = floatval( str_replace( ',', '.', $fee_percent_raw ) );
        update_option( PCIO_VIS_Tickets_Reports::OPT_FEE_PERCENT, max( 0.0, min( 25.0, $fee_percent ) ) );

        // Receivers: kept rows minus removed ones plus the newly added address.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Entries are sanitized with sanitize_email() before use.
        $keep_raw   = isset( $_POST['receiver_keep'] ) ? (array) wp_unslash( $_POST['receiver_keep'] ) : [];
        $remove_raw = isset( $_POST['receiver_remove'] ) ? (array) wp_unslash( $_POST['receiver_remove'] ) : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $keep   = array_filter( array_map( 'sanitize_email', array_map( 'strval', array_filter( $keep_raw, 'is_scalar' ) ) ) );
        $remove = array_filter( array_map( 'sanitize_email', array_map( 'strval', array_filter( $remove_raw, 'is_scalar' ) ) ) );
        $add = sanitize_email( wp_unslash( $_POST['receiver_add'] ?? '' ) );
        $final = [];
        foreach ( $keep as $email ) {
            $email = sanitize_email( (string) $email );
            if ( ! is_email( $email ) || in_array( $email, $remove, true ) || in_array( $email, $final, true ) ) {
                continue;
            }
            $final[] = $email;
        }
        if ( is_email( $add ) && ! in_array( $add, $final, true ) ) {
            $final[] = $add;
        }
        PCIO_VIS_Tickets_Reports::set_receivers( $final );

        return true;
    }

    /**
     * Stripe keys are ASCII tokens; strip whitespace and disallowed characters.
     */
    private function clean_key( $raw ): string {
        $raw = sanitize_text_field( wp_unslash( (string) $raw ) );
        return trim( $raw );
    }

    // ── Static accessors (used by checkout & webhook) ─────────────

    public static function stripe_mode(): string {
        return get_option( self::OPT_MODE, 'test' ) === 'live' ? 'live' : 'test';
    }

    public static function stripe_publishable_key(): string {
        return (string) get_option(
            self::stripe_mode() === 'live' ? self::OPT_PK_LIVE : self::OPT_PK_TEST,
            ''
        );
    }

    public static function stripe_secret_key(): string {
        return self::stripe_secret_key_for( self::stripe_mode() );
    }

    /**
     * Secret key for a specific mode ('test' | 'live'). Read-only Stripe calls
     * for historic data use the mode the payment actually belongs to, so the
     * numbers do not change when the administrator switches the active mode.
     */
    public static function stripe_secret_key_for( string $mode ): string {
        return (string) get_option(
            'live' === $mode ? self::OPT_SK_LIVE : self::OPT_SK_TEST,
            ''
        );
    }

    public static function stripe_webhook_secret(): string {
        return (string) get_option(
            self::stripe_mode() === 'live' ? self::OPT_WH_LIVE : self::OPT_WH_TEST,
            ''
        );
    }

    public static function currency(): string {
        $c = strtoupper( (string) get_option( self::OPT_CURRENCY, 'DKK' ) );
        return $c !== '' ? $c : 'DKK';
    }

    public static function from_email(): string {
        $e = (string) get_option( self::OPT_FROM_EMAIL, '' );
        return $e !== '' ? $e : (string) get_bloginfo( 'admin_email' );
    }

    public static function from_name(): string {
        $n = (string) get_option( self::OPT_FROM_NAME, '' );
        return $n !== '' ? $n : (string) get_bloginfo( 'name' );
    }

    /**
     * Whether paid checkout is possible in the active mode (pk + sk present).
     */
    public static function is_configured(): bool {
        return self::stripe_publishable_key() !== '' && self::stripe_secret_key() !== '';
    }

    /**
     * Format an amount in minor units (øre/cents) as a display string.
     */
    public static function format_money( int $cents, string $currency = '' ): string {
        $currency = $currency !== '' ? strtoupper( $currency ) : self::currency();
        return number_format_i18n( $cents / 100, 2 ) . ' ' . $currency;
    }
}
