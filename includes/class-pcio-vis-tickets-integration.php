<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integration between the Tickets extension and the core Member Event system.
 *
 * Wiring (all hooks live in the core plugin):
 *   • pcio_me_all_caps / pcio_me_cap_labels   — caps in the Vis Roles matrix
 *   • pcio_me_event_tabs                       — "Tickets" tab on the event editor
 *   • pcio_me_event_signup_enabled             — hide core member-signup UI in
 *                                                ticket mode (max_guests > 0)
 *   • pcio_me_after_event_main (Phase 4)       — "Buy tickets" CTA on the public page
 *
 * Each hook degrades gracefully if the core plugin predates it.
 */
class PCIO_VIS_Tickets_Integration {

    public function register(): void {
        // "Tickets" tab on the event editor.
        add_filter( 'pcio_me_event_tabs', [ $this, 'add_event_tab' ], 10, 2 );

        // Suppress the core member-signup UI when an event sells tickets.
        add_filter( 'pcio_me_event_signup_enabled', [ $this, 'maybe_disable_signup' ], 10, 2 );

        // Add a "Ticket sales" option to the event editor's sign-up mode dropdown.
        add_filter( 'pcio_me_event_signup_modes', [ $this, 'register_signup_mode' ] );

        // Public event page: render the "Buy tickets" action inside the right-bar
        // signup box (replacing the member signup controls) for ticket events.
        add_filter( 'pcio_me_event_signup_heading', [ $this, 'signup_heading' ], 10, 2 );
        add_filter( 'pcio_me_event_signup_box',     [ $this, 'signup_box' ],     10, 3 );

        // [pcio_me_events] list: mark events flagged "Sold out" with a banner.
        add_filter( 'pcio_me_events_soldout_ids', [ $this, 'soldout_event_ids' ], 10, 2 );

        add_action( 'pcio_met_order_first_confirmed', [ $this, 'add_ticket_guest' ], 10, 1 );
        add_action( 'pcio_met_order_refunded', [ $this, 'update_refunded_ticket_guest' ], 10, 3 );

        // Finance section: register a read-only "Orders" list. Orders are a live
        // mirror of Stripe; the core journal is a separate, manual-only ledger.
        add_filter( 'pcio_me_finance_lists', [ $this, 'register_finance_list' ] );

        // Core Sync Jobs registry: seed and expose the daily event-report scan.
        add_action( 'pcio_me_sync_register_jobs', [ $this, 'register_sync_job' ] );
        add_filter( 'pcio_me_sync_callables', [ $this, 'register_sync_callable' ] );
    }

    // ── Core Sync Jobs registry ───────────────────────────────────

    const SYNC_CRON_NAME = 'ticket_event_reports';
    const SYNC_CALLABLE  = 'PCIO_VIS_Tickets_Reports::run_daily_scan';

    /** Registry hook fired by PCIO_VIS_Sync before it schedules jobs. */
    public function register_sync_job(): void {
        self::seed_sync_job();
    }

    /**
     * Create the "Ticket payouts & event reports" row in the core me_sync
     * registry the first time it is missing. On existing installations this
     * makes the job appear under Vis → Sync Jobs with Run Now / enable / edit.
     */
    public static function seed_sync_job(): void {
        if ( ! class_exists( 'PCIO_VIS_Sync_DB' ) ) {
            return;
        }
        if ( PCIO_VIS_Sync_DB::get_by_cron_name( self::SYNC_CRON_NAME ) ) {
            return;
        }
        PCIO_VIS_Sync_DB::create(
            [
                'name'         => __( 'Ticket payouts & event reports', 'pcio-vis-tickets' ),
                'description'  => __( 'Refreshes ticket event reports and pays out finished events through Stripe after the configured delay (Vis → Tickets → Event reports). Reports awaiting the Stripe balance are retried on the next run, and the report PDF is emailed to the configured receivers.', 'pcio-vis-tickets' ),
                'cron_name'    => self::SYNC_CRON_NAME,
                'php_function' => self::SYNC_CALLABLE,
                'sync_url'     => '',
                'frequence'    => 24,
                'enabled'      => 1,
            ]
        );

        // Remove the legacy direct WP-Cron hook from the first iteration.
        if ( wp_next_scheduled( 'pcio_met_reports_daily' ) ) {
            wp_clear_scheduled_hook( 'pcio_met_reports_daily' );
        }
    }

    /**
     * Allow the reports scan in the Sync Jobs callable allow-list (the core
     * only executes registered callables).
     *
     * @param array<string,string> $callables
     * @return array<string,string>
     */
    public function register_sync_callable( array $callables ): array {
        $callables[ self::SYNC_CALLABLE ] = __( 'Ticket payouts & event reports', 'pcio-vis-tickets' );
        return $callables;
    }

    // ── Capabilities ──────────────────────────────────────────────


    // ── Event editor "Tickets" tab ────────────────────────────────

    /**
     * Register a "Tickets" tab on the event editor via the core
     * `pcio_me_event_tabs` filter. Core renders the returned HTML as a static
     * container; the interactive UI is wired up by ticket-tab.js, which is
     * enqueued here (this filter only fires on the event editor page).
     *
     * @param array $tabs     Existing extra tab definitions.
     * @param int   $event_id Current event ID.
     */
    public function add_event_tab( array $tabs, int $event_id ): array {
        $event = $event_id ? PCIO_VIS_Events_DB::get( $event_id ) : null;
        if ( ! $event || ! PCIO_VIS_Events_DB::can_edit_event( $event ) ) {
            return $tabs;
        }

        $this->enqueue_editor_assets( $event_id );

        $tabs[] = [
            'id'            => 'tickets',
            'label'         => __( 'Tickets', 'pcio-vis-tickets' ),
            'signup_modes'  => [ 'tickets', 'external' ],
            'inactive_text' => __( 'Tickets are NOT sold through the Vis ticket system for this event. Choose "Ticket sales" or "External ticket sale" under Sign-up on the Basic tab.', 'pcio-vis-tickets' ),
            'html'          => '<div id="pcio-met-editor" class="pcio-met-editor" data-event="' . esc_attr( (string) $event_id ) . '">'
                             . '<p class="pcio-met-loading">' . esc_html__( 'Loading ticket settings…', 'pcio-vis-tickets' ) . '</p>'
                             . '</div>',
        ];
        $tabs[] = [
            'id'            => 'sales',
            'label'         => __( 'Sales', 'pcio-vis-tickets' ),
            'signup_modes'  => [ 'tickets' ],
            'inactive_text' => __( 'Ticket sales are only shown for events using "Ticket sales" under Sign-up on the Basic tab.', 'pcio-vis-tickets' ),
            'html'          => '<div id="pcio-met-sales" class="pcio-met-editor" data-event="' . esc_attr( (string) $event_id ) . '">'
                             . '<p class="pcio-met-loading">' . esc_html__( 'Loading sales…', 'pcio-vis-tickets' ) . '</p>'
                             . '</div>',
        ];
        return $tabs;
    }

    /**
     * Enqueue the editor tab script/style and hand it a config object.
     *
     * IMPORTANT: top-level `const` in an inline script does NOT attach to
     * `window`, so the config is assigned to `window.PCIO_MET` explicitly.
     */
    private function enqueue_editor_assets( int $event_id ): void {
        $settings = PCIO_VIS_Tickets_DB::get_settings( $event_id );
        $types    = PCIO_VIS_Tickets_DB::get_types( $event_id );
        $event    = PCIO_VIS_Events_DB::get( $event_id );
        $default_access = $event ? PCIO_VIS_Events_DB::default_access_for_event( $event ) : PCIO_VIS_Events_DB::ACCESS_MEMBERS;

        wp_enqueue_style(
            'pcio-met-ticket-tab',
            plugins_url( 'assets/ticket-tab.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION
        );
        wp_enqueue_style(
            'pcio-met-sales-tab',
            plugins_url( 'assets/sales-tab.css', PCIO_VIS_TICKETS_FILE ),
            [ 'pcio-me-app' ],
            PCIO_VIS_TICKETS_VERSION
        );
        wp_enqueue_script(
            'pcio-met-ticket-tab',
            plugins_url( 'assets/ticket-tab.js', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION . '.1',
            true
        );
        wp_enqueue_script(
            'pcio-met-sales-tab',
            plugins_url( 'assets/sales-tab.js', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION,
            true
        );

        wp_add_inline_script(
            'pcio-met-ticket-tab',
            'window.PCIO_MET = ' . wp_json_encode( [
                'rest'     => rest_url( PCIO_VIS_REST_NS . '/tickets' ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'eventId'  => $event_id,
                'eventRest' => rest_url( PCIO_VIS_REST_NS . '/events' ),
                'signupMode' => $event ? (string) $event['signup_mode'] : '',
                'externalUrl' => $event ? (string) $event['external_url'] : '',
                'currency' => PCIO_VIS_Tickets_Admin::currency(),
                'settings' => $settings,
                'types'    => $types,
                'defaultAccess' => $default_access,
                'accessLabels'  => PCIO_VIS_Events_DB::access_labels(),
                'i18n'     => $this->editor_i18n(),
            ] ) . ';',
            'before'
        );
        wp_add_inline_script(
            'pcio-met-sales-tab',
            'window.PCIO_MET_SALES = ' . wp_json_encode( [
                'rest'    => rest_url( PCIO_VIS_REST_NS . '/tickets' ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'eventId' => $event_id,
                'i18n'    => $this->sales_i18n(),
            ] ) . ';',
            'before'
        );
    }

    /**
     * Strings used by the editor tab JS.
     *
     * @return array<string,string>
     */
    private function editor_i18n(): array {
        return [
            'modeMember'      => __( 'Member sign-up event', 'pcio-vis-tickets' ),
            'modeTickets'     => __( 'Ticket sales event', 'pcio-vis-tickets' ),
            'modeHint'        => __( 'Set the maximum number of guests above 0 to sell tickets. Leave it at 0 for a member-only sign-up event.', 'pcio-vis-tickets' ),
            'externalHeading' => __( 'External ticket sale', 'pcio-vis-tickets' ),
            'externalHelp'    => __( 'Tickets for this event are sold on another site. Enter the link buyers should use.', 'pcio-vis-tickets' ),
            'externalUrlLabel'=> __( 'Ticket sale URL', 'pcio-vis-tickets' ),
            'externalSave'    => __( 'Save link', 'pcio-vis-tickets' ),
            'externalSaved'   => __( 'Link saved.', 'pcio-vis-tickets' ),
            'externalSaveError' => __( 'Could not save the link: ', 'pcio-vis-tickets' ),
            'externalOpen'    => __( 'Open link', 'pcio-vis-tickets' ),
            'maxGuests'       => __( 'Maximum number of guests', 'pcio-vis-tickets' ),
            'maxGuestsHelp'   => __( 'Only entrance tickets count towards this limit. Sales stop automatically when it is reached.', 'pcio-vis-tickets' ),
            'maxPerBuyer'     => __( 'Max entrance tickets per buyer', 'pcio-vis-tickets' ),
            'maxPerBuyerHelp' => __( 'Set to 0 for no limit.', 'pcio-vis-tickets' ),
            'isSoldout'       => __( 'Mark as sold out', 'pcio-vis-tickets' ),
            'hideRemaining'   => __( 'Hide remaining ticket count from buyers', 'pcio-vis-tickets' ),
            'ticketTypes'     => __( 'Ticket types', 'pcio-vis-tickets' ),
            'addType'         => __( 'Add ticket type', 'pcio-vis-tickets' ),
            'deleteType'      => __( 'Delete', 'pcio-vis-tickets' ),
            'name'            => __( 'Name', 'pcio-vis-tickets' ),
            'access'          => __( 'Who can purchase', 'pcio-vis-tickets' ),
            'accessDefault'   => __( 'Event type default', 'pcio-vis-tickets' ),
            'accessHelp'      => __( 'Defaults to the event type access. Only buyers matching this access level can select the ticket.', 'pcio-vis-tickets' ),
            'isEntrance'      => __( 'Entrance ticket (counts towards capacity)', 'pcio-vis-tickets' ),
            'price'           => __( 'Price (DKK)', 'pcio-vis-tickets' ),
            'qtyOffered'      => __( 'Quantity offered', 'pcio-vis-tickets' ),
            'qtySold'         => __( 'Sold', 'pcio-vis-tickets' ),
            'salesStart'      => __( 'Sales start', 'pcio-vis-tickets' ),
            'salesStop'       => __( 'Sales stop', 'pcio-vis-tickets' ),
            'active'          => __( 'Active', 'pcio-vis-tickets' ),
            'noTypes'         => __( 'No ticket types yet. Add one to start selling.', 'pcio-vis-tickets' ),
            'newTypeName'     => __( 'New ticket', 'pcio-vis-tickets' ),
            'capacityNote'    => __( 'Entrance tickets sold:', 'pcio-vis-tickets' ),
            'saved'           => __( 'Saved', 'pcio-vis-tickets' ),
            'saveError'       => __( 'Save failed', 'pcio-vis-tickets' ),
            'confirmDelete'   => __( 'Delete this ticket type?', 'pcio-vis-tickets' ),
            'cannotDelete'    => __( 'This ticket type cannot be deleted because tickets have already been sold.', 'pcio-vis-tickets' ),
        ];
    }

    /**
     * Strings used by the sales tab JS.
     *
     * @return array<string,string>
     */
    private function sales_i18n(): array {
        return [
            'loading'      => __( 'Loading sales…', 'pcio-vis-tickets' ),
            'loadError'    => __( 'Could not load sales: ', 'pcio-vis-tickets' ),
            'noOrders'     => __( 'No orders yet for this event.', 'pcio-vis-tickets' ),
            'summaryTitle' => __( 'Sales by ticket type', 'pcio-vis-tickets' ),
            'colType'      => __( 'Ticket type', 'pcio-vis-tickets' ),
            'colSold'      => __( 'Sold', 'pcio-vis-tickets' ),
            'colGross'     => __( 'Gross', 'pcio-vis-tickets' ),
            'colRefunded'  => __( 'Refunded', 'pcio-vis-tickets' ),
            'ordersTitle'  => __( 'Orders', 'pcio-vis-tickets' ),
            'colDate'      => __( 'Date', 'pcio-vis-tickets' ),
            'colOrder'     => __( 'Order', 'pcio-vis-tickets' ),
            'colBuyer'     => __( 'Buyer', 'pcio-vis-tickets' ),
            'colEmail'     => __( 'Email', 'pcio-vis-tickets' ),
            'colProducts'  => __( 'Tickets', 'pcio-vis-tickets' ),
            'colAmount'    => __( 'Amount', 'pcio-vis-tickets' ),
            'colStatus'    => __( 'Status', 'pcio-vis-tickets' ),
            'colStripe'    => __( 'Stripe', 'pcio-vis-tickets' ),
            'stripeView'   => __( 'View', 'pcio-vis-tickets' ),
            'refund'       => __( 'Refund', 'pcio-vis-tickets' ),
            'resend'       => __( 'Resend email', 'pcio-vis-tickets' ),
            'refundTitle'  => __( 'Refund order', 'pcio-vis-tickets' ),
            'refundDone'   => __( 'Order refunded.', 'pcio-vis-tickets' ),
            'refundFailed' => __( 'Refund failed.', 'pcio-vis-tickets' ),
            // translators: %s: buyer email address.
            'resendConfirm' => __( 'Send tickets again to %s?', 'pcio-vis-tickets' ),
            'resendDone'   => __( 'The tickets have been sent.', 'pcio-vis-tickets' ),
            'resendFailed' => __( 'Could not resend the email.', 'pcio-vis-tickets' ),
        ];
    }

    /**
     * Disable the built-in member signup UI when an event sells tickets.
     *
     * @param bool $enabled
     * @param int  $event_id
     */
    public function maybe_disable_signup( bool $enabled, int $event_id ): bool {
        if ( PCIO_VIS_Tickets_DB::event_mode( $event_id ) === 'tickets' ) {
            return false;
        }
        return $enabled;
    }

    /**
     * Add the "Ticket sales" option to the core event sign-up mode dropdown.
     * Hooked on `pcio_me_event_signup_modes`.
     *
     * @param array<string,string> $modes
     * @return array<string,string>
     */
    public function register_signup_mode( array $modes ): array {
        $modes['tickets'] = __( 'Ticket sales', 'pcio-vis-tickets' );
        return $modes;
    }

    /**
     * Add events flagged "Sold out" to the core list of sold-out event IDs.
     *
     * Hooked on `pcio_me_events_soldout_ids`.
     *
     * @param int[] $soldout   Sold-out IDs collected so far.
     * @param int[] $event_ids Event IDs being rendered.
     * @return int[]
     */
    public function soldout_event_ids( array $soldout, array $event_ids ): array {
        return array_merge( $soldout, PCIO_VIS_Tickets_DB::get_soldout_event_ids( $event_ids ) );
    }

    // ── Public ticket purchase (right-bar signup box) ─────────────

    /**
     * Heading for the public event right-bar box. "Tickets" for ticket-sales
     * events (paid or free), otherwise the default member "Sign up".
     * Hooked on `pcio_me_event_signup_heading`.
     *
     * @param string $heading
     * @param int    $event_id
     */
    public function signup_heading( string $heading, int $event_id ): string {
        if ( $event_id && PCIO_VIS_Tickets_DB::event_mode( $event_id ) === 'tickets' ) {
            return __( 'Tickets', 'pcio-vis-tickets' );
        }
        return $heading;
    }

    /**
     * Render the ticket purchase control inside the right-bar signup box for
     * ticket-sales events. Returning a non-empty string makes the core template
     * show this instead of the member signup buttons (either/or, same place).
     * Hooked on `pcio_me_event_signup_box`.
     *
     * @param string              $html     Default (empty) body.
     * @param int                 $event_id
     * @param array<string,mixed> $view     Prepared public-page view data.
     */
    public function signup_box( string $html, int $event_id, array $view ): string {
        if ( ! $event_id || PCIO_VIS_Tickets_DB::event_mode( $event_id ) !== 'tickets' ) {
            return $html;
        }

        $event = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return $html;
        }

        // Determine access, sale state and whether any purchasable type is paid.
        $purchase  = PCIO_VIS_Tickets_DB::purchase_state( $event );
        $notice    = $purchase['message'];
        $on_sale   = 'on_sale' === $purchase['state'];
        $has_paid  = false;
        $ticket_types = [];
        if ( $on_sale ) {
            foreach ( PCIO_VIS_Tickets_DB::get_types( $event_id ) as $type ) {
                if ( ! PCIO_VIS_Tickets_DB::type_on_sale( $type )
                    || ! PCIO_VIS_Tickets_DB::can_purchase_type( $type, $event ) ) {
                    continue;
                }
                $ticket_types[] = $type;
                if ( (int) $type['price_cents'] > 0 ) {
                    $has_paid = true;
                }
            }
        }

        // Late-enqueued styles are flushed in the footer by WordPress.
        wp_enqueue_style(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_CHECKOUT_ASSET_VERSION
        );

        $checkout_url = add_query_arg(
            'event',
            rawurlencode( $event['slug'] ?? (string) $event_id ),
            home_url( 'tickets/checkout/' )
        );

        ob_start();
        ?>
        <div class="met-event-signup">
            <?php if ( $ticket_types ) : ?>
                <div class="met-event-signup__types">
                    <?php foreach ( $ticket_types as $ticket_type ) : ?>
                        <div class="met-event-signup__type">
                            <span class="met-event-signup__name"><?php echo esc_html( (string) $ticket_type['name'] ); ?></span>
                            <span class="met-event-signup__price">
                                <?php if ( (int) $ticket_type['price_cents'] <= 0 ) : ?>
                                    <?php esc_html_e( 'Free', 'pcio-vis-tickets' ); ?>
                                <?php else : ?>
                                    <?php echo esc_html( PCIO_VIS_Tickets_Admin::format_money( (int) $ticket_type['price_cents'], (string) $ticket_type['currency'] ) ); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ( '' !== $notice ) : ?>
                <p class="me-pe-side-text"><?php echo esc_html( $notice ); ?></p>
            <?php else : ?>
                <a class="me-btn me-btn-primary met-event-signup__btn" href="<?php echo esc_url( $checkout_url ); ?>">
                    <?php
                    echo $has_paid
                        ? esc_html__( 'Buy tickets', 'pcio-vis-tickets' )
                        : esc_html__( 'Get tickets', 'pcio-vis-tickets' );
                    ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function add_ticket_guest( int $order_id ): void {
        $order = PCIO_VIS_Tickets_DB::get_order( $order_id );
        if ( ! $order || (int) ( $order['member_id'] ?? 0 ) < 1 || ! PCIO_VIS_Tickets_DB::order_has_entrance_item( $order_id ) ) {
            return;
        }
        $event_id  = (int) $order['event_id'];
        $member_id = (int) ( $order['member_id'] ?? 0 );
        if ( ! PCIO_VIS_Events_DB::get( $event_id ) || ! PCIO_VIS_DB::get( $member_id ) ) {
            return;
        }
        $signup = PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id );
        if ( $signup && in_array( (string) $signup['status'], [ 'joining', 'arrived' ], true ) ) {
            return;
        }
        PCIO_VIS_Signups_DB::upsert( $event_id, $member_id, 'joining' );
    }

    public function update_refunded_ticket_guest( int $order_id, int $amount, bool $is_full ): void {
        if ( ! $is_full ) {
            return;
        }
        $order = PCIO_VIS_Tickets_DB::get_order( $order_id );
        if ( ! $order || (int) ( $order['member_id'] ?? 0 ) < 1 || ! PCIO_VIS_Tickets_DB::order_has_entrance_item( $order_id ) ) {
            return;
        }
        $event_id  = (int) $order['event_id'];
        $member_id = (int) ( $order['member_id'] ?? 0 );
        if ( ! PCIO_VIS_Events_DB::get( $event_id ) || ! PCIO_VIS_DB::get( $member_id ) ) {
            return;
        }
        if ( PCIO_VIS_Tickets_DB::member_has_entrance_order( $event_id, $member_id ) ) {
            return;
        }
        $signup = PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id );
        if ( $signup && 'joining' === (string) $signup['status'] ) {
            PCIO_VIS_Signups_DB::upsert( $event_id, $member_id, 'not_joining' );
        }
    }

    // ── Finance integration ───────────────────────────────────────

    /**
     * Register a read-only "Orders" list on the Finance page via the core
     * `pcio_me_finance_lists` filter. The data is served by the REST route
     * GET /tickets/orders (see PCIO_VIS_Tickets_Rest::list_orders_finance()).
     *
     * @param array $lists Existing list descriptors.
     * @return array
     */
    public function register_finance_list( array $lists ): array {
        $can_refund = current_user_can( 'vis_manage_finance' );
        $can_manage = current_user_can( 'vis_manage_finance' );

        // Per-row management actions, mirrored from the (now removed) standalone
        // orders screen. Each action POSTs to a REST sub-route of the list and is
        // only offered to users holding the relevant ticket capability.
        $actions = [];
        if ( $can_refund ) {
            $actions[] = [
                'id'          => 'refund',
                'label'       => __( 'Refund', 'pcio-vis-tickets' ),
                'icon'        => 'refund',
                'kind'        => 'danger',
                'path'        => '/refund',
                'method'      => 'POST',
                'visibleField'=> 'can_refund',
                'amountParam' => 'amount_cents',   // body param sent to the endpoint
                'maxField'    => 'refundable_cents', // row field: max refundable (øre)
                'totalField'  => 'amount_cents',     // row field: order total (øre); <=0 means free
                'currencyField' => 'currency',
                'i18n'        => [
                    'title'       => __( 'Refund order', 'pcio-vis-tickets' ),
                    'amountLabel' => __( 'Refund amount', 'pcio-vis-tickets' ),
                    'amountHint'  => __( 'Up to the order total can be refunded. The maximum performs a full refund and releases the tickets.', 'pcio-vis-tickets' ),
                    'freeText'    => __( 'This is a free order. Cancelling it releases the tickets and frees capacity.', 'pcio-vis-tickets' ),
                    'submit'      => __( 'Refund', 'pcio-vis-tickets' ),
                    'submitFree'  => __( 'Cancel order', 'pcio-vis-tickets' ),
                    'working'     => __( 'Working…', 'pcio-vis-tickets' ),
                    'toast'       => __( 'Order refunded.', 'pcio-vis-tickets' ),
                    'error'       => __( 'Refund failed.', 'pcio-vis-tickets' ),
                ],
            ];
        }
        if ( $can_manage ) {
            $actions[] = [
                'id'          => 'resend',
                'label'       => __( 'Resend email', 'pcio-vis-tickets' ),
                'icon'        => 'mail',
                'kind'        => 'default',
                'path'        => '/resend',
                'method'      => 'POST',
                'visibleField'=> 'can_resend',
                'confirm'     => __( 'Resend the confirmation email with tickets?', 'pcio-vis-tickets' ),
                'i18n'        => [
                    'title'   => __( 'Resend confirmation', 'pcio-vis-tickets' ),
                    'submit'  => __( 'Resend', 'pcio-vis-tickets' ),
                    'working' => __( 'Working…', 'pcio-vis-tickets' ),
                    'toast'   => __( 'Confirmation email resent.', 'pcio-vis-tickets' ),
                    'error'   => __( 'Could not resend the email.', 'pcio-vis-tickets' ),
                ],
            ];
        }

        $lists[] = [
            'id'         => 'orders',
            'label'      => __( 'Ticket sales', 'pcio-vis-tickets' ),
            'rest'       => rest_url( PCIO_VIS_REST_NS . '/tickets/orders' ),
            'editable'   => false,
            'canEdit'    => false,
            'rowActions' => $actions,
            'columns'    => [
                [ 'key' => 'entry_date',   'label' => __( 'Date',     'pcio-vis-tickets' ), 'type' => 'datetime', 'sortable' => true ],
                [ 'key' => 'order_no',     'label' => __( 'Order',    'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'buyer',        'label' => __( 'Buyer',    'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'email',        'label' => __( 'Email',    'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'event_title',  'label' => __( 'Event',    'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'amount_cents', 'label' => __( 'Amount',   'pcio-vis-tickets' ), 'type' => 'money',    'sortable' => true ],
                [ 'key' => 'status',       'label' => __( 'Status',   'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true, 'align' => 'center' ],
                [ 'key' => 'stripe_url',   'label' => __( 'Stripe',   'pcio-vis-tickets' ), 'type' => 'extlink',  'linkLabel' => __( 'View', 'pcio-vis-tickets' ), 'align' => 'center' ],
            ],
        ];

        // ── Event reports overview (payout status per finished event) ─
        $report_actions = [
            [
                'id'        => 'stripe',
                'label'     => __( 'Stripe payout', 'pcio-vis-tickets' ),
                'icon'      => 'link',
                'hrefField' => 'stripe_url',
            ],
            [
                'id'        => 'pdf',
                'label'     => __( 'Report PDF', 'pcio-vis-tickets' ),
                'icon'      => 'pdf',
                'hrefField' => 'pdf_url',
            ],
        ];
        if ( $can_refund ) {
            $report_actions[] = [
                'id'            => 'payout',
                'label'         => __( 'Payout', 'pcio-vis-tickets' ),
                'icon'          => 'payout',
                'kind'          => 'default',
                'path'          => '/payout',
                'method'        => 'POST',
                'disabledField' => 'can_payout',
                'disabledReasonField' => 'payout_block_reason',
                'amountParam'   => 'amount_cents',    // body param sent to the endpoint
                'totalField'    => 'remaining_cents', // withAmount only when remaining > 0
                'maxField'      => 'remaining_cents', // row field: max payout (minor units)
                'currencyField' => 'currency',
                'i18n'          => [
                    'title'       => __( 'Payout', 'pcio-vis-tickets' ),
                    'amountLabel' => __( 'Payout amount', 'pcio-vis-tickets' ),
                    'amountHint'  => __( 'Full remaining amount is prefilled. The amount cannot exceed the remaining net amount and must be at least the Stripe minimum.', 'pcio-vis-tickets' ),
                    'submit'      => __( 'Payout', 'pcio-vis-tickets' ),
                    'working'     => __( 'Working…', 'pcio-vis-tickets' ),
                    'toast'       => __( 'Payout created.', 'pcio-vis-tickets' ),
                    'error'       => __( 'Payout failed.', 'pcio-vis-tickets' ),
                ],
            ];
            $report_actions[] = [
                'id'           => 'delete',
                'label'        => __( 'Delete report', 'pcio-vis-tickets' ),
                'icon'         => 'delete',
                'kind'         => 'danger',
                'path'         => '',
                'method'       => 'DELETE',
                'visibleField' => 'can_delete',
                'confirm'      => __( 'Delete this event report?', 'pcio-vis-tickets' ),
                'i18n'         => [
                    'title'   => __( 'Delete report', 'pcio-vis-tickets' ),
                    'submit'  => __( 'Delete', 'pcio-vis-tickets' ),
                    'working' => __( 'Working…', 'pcio-vis-tickets' ),
                    'toast'   => __( 'Report deleted.', 'pcio-vis-tickets' ),
                    'error'   => __( 'Could not delete the report — payout postings block deletion.', 'pcio-vis-tickets' ),
                ],
            ];
        }
        if ( $can_manage ) {
            $report_actions[] = [
                'id'           => 'mail',
                'label'        => __( 'Send report email', 'pcio-vis-tickets' ),
                'icon'         => 'mail',
                'kind'         => 'default',
                'path'         => '/mail',
                'method'       => 'POST',
                'visibleField' => 'can_mail',
                'confirm'      => __( 'Email the report PDF to the configured receivers?', 'pcio-vis-tickets' ),
                'i18n'         => [
                    'title'   => __( 'Send report email', 'pcio-vis-tickets' ),
                    'submit'  => __( 'Send', 'pcio-vis-tickets' ),
                    'working' => __( 'Working…', 'pcio-vis-tickets' ),
                    'toast'   => __( 'Report email sent.', 'pcio-vis-tickets' ),
                    'error'   => __( 'Could not send the report email.', 'pcio-vis-tickets' ),
                ],
            ];
        }

        $lists[] = [
            'id'         => 'event-reports',
            'label'      => __( 'Event reports', 'pcio-vis-tickets' ),
            'rest'       => rest_url( PCIO_VIS_REST_NS . '/tickets/reports' ),
            'editable'   => false,
            'canEdit'    => false,
            'rowActions' => $report_actions,
            'columns'    => [
                [ 'key' => 'event_end',        'label' => __( 'Finished',       'pcio-vis-tickets' ), 'type' => 'datetime', 'sortable' => true, 'width' => '118px', 'nowrap' => true, 'filterLabel' => __( 'date', 'pcio-vis-tickets' ) ],
                [ 'key' => 'event_title',      'label' => __( 'Event',          'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true, 'width' => '260px', 'wrap' => true,   'filterLabel' => __( 'event', 'pcio-vis-tickets' ) ],
                [ 'key' => 'revenue_cents',    'label' => __( 'Ticket revenue', 'pcio-vis-tickets' ), 'type' => 'money',    'sortable' => true, 'align' => 'right', 'width' => '112px', 'filterLabel' => __( 'revenue', 'pcio-vis-tickets' ) ],
                [ 'key' => 'refunded_cents',   'label' => __( 'Refunded',       'pcio-vis-tickets' ), 'type' => 'money',    'sortable' => true, 'align' => 'right', 'width' => '104px', 'filterLabel' => __( 'refunded', 'pcio-vis-tickets' ) ],
                [ 'key' => 'stripe_fee_cents', 'label' => __( 'Stripe fee',     'pcio-vis-tickets' ), 'type' => 'money',    'sortable' => true, 'align' => 'right', 'width' => '104px', 'filterLabel' => __( 'fee', 'pcio-vis-tickets' ) ],
                [ 'key' => 'net_cents',        'label' => __( 'Net',            'pcio-vis-tickets' ), 'type' => 'money',    'sortable' => true, 'align' => 'right', 'width' => '112px', 'filterLabel' => __( 'net', 'pcio-vis-tickets' ) ],
                [ 'key' => 'status',           'label' => __( 'Status',         'pcio-vis-tickets' ), 'type' => 'text',     'sortable' => true, 'align' => 'center', 'width' => '150px', 'nowrap' => true, 'filterLabel' => __( 'status', 'pcio-vis-tickets' ) ],
                [ 'key' => 'paid_cents',       'label' => __( 'Paid out',       'pcio-vis-tickets' ), 'type' => 'money',    'sortable' => true, 'align' => 'right', 'width' => '104px', 'filterLabel' => __( 'paid', 'pcio-vis-tickets' ) ],
                [ 'key' => 'last_payout_date', 'label' => __( 'Transferred',    'pcio-vis-tickets' ), 'type' => 'datetime', 'sortable' => true, 'width' => '118px', 'nowrap' => true, 'filterLabel' => __( 'transferred', 'pcio-vis-tickets' ) ],
            ],
        ];
        return $lists;
    }
}
