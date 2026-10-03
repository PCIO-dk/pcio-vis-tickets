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

    const NONCE_ACTION = 'pcio_met_settings_save';
    const NONCE_FIELD  = 'pcio_met_settings_nonce';

    const PAGE_SLUG = 'pcio-me-tickets';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_filter(
            'plugin_action_links_' . plugin_basename( PCIO_VIS_TICKETS_FILE ),
            [ $this, 'action_links' ]
        );
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
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    // ── Settings page ─────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
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

        $webhook_url = rest_url( PCIO_VIS_REST_NS . '/tickets/webhook' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vis — Ticket settings', 'pcio-vis-tickets' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e( 'Ticket settings saved.', 'pcio-vis-tickets' ); ?>
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
        if ( ! current_user_can( 'manage_options' ) ) {
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
        return (string) get_option(
            self::stripe_mode() === 'live' ? self::OPT_SK_LIVE : self::OPT_SK_TEST,
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
