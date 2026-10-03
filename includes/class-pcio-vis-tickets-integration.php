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
        // Surface our capabilities in the Vis Roles matrix.
        add_filter( 'pcio_me_all_caps',   [ $this, 'register_caps' ] );
        add_filter( 'pcio_me_cap_labels', [ $this, 'register_cap_labels' ] );

        // Default-cap sync (the "Sync capabilities" button on Vis → Roles).
        add_filter( 'pcio_me_default_role_caps', [ $this, 'register_default_role_caps' ] );
        add_action( 'pcio_me_sync_caps',         [ $this, 'grant_native_role_caps' ] );

        // "Tickets" tab on the event editor.
        add_filter( 'pcio_me_event_tabs', [ $this, 'add_event_tab' ], 10, 2 );

        // Suppress the core member-signup UI when an event sells tickets.
        add_filter( 'pcio_me_event_signup_enabled', [ $this, 'maybe_disable_signup' ], 10, 2 );

        // Public event page: render the "Buy tickets" action inside the right-bar
        // signup box (replacing the member signup controls) for ticket events.
        add_filter( 'pcio_me_event_signup_heading', [ $this, 'signup_heading' ], 10, 2 );
        add_filter( 'pcio_me_event_signup_box',     [ $this, 'signup_box' ],     10, 3 );

        // Finance section: register a read-only "Orders" list. Orders are a live
        // mirror of Stripe; the core journal is a separate, manual-only ledger.
        add_filter( 'pcio_me_finance_lists', [ $this, 'register_finance_list' ] );
    }

    // ── Capabilities ──────────────────────────────────────────────

    /**
     * @param string[] $caps
     * @return string[]
     */
    public function register_caps( array $caps ): array {
        return array_merge( $caps, array_keys( PCIO_VIS_Tickets_Plugin::CAP_ROLE_MAP ) );
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function register_cap_labels( array $labels ): array {
        $labels['vis_tickets_manage'] = __( 'Manage tickets & ticket sales', 'pcio-vis-tickets' );
        $labels['vis_tickets_refund'] = __( 'Refund ticket orders', 'pcio-vis-tickets' );
        return $labels;
    }

    /**
     * Add the ticket caps to the default "System Admin" event-system role so the
     * core "Sync capabilities" action grants them on existing installs.
     *
     * @param array<string,string[]> $defaults Map of vis-role slug => cap slugs.
     * @return array<string,string[]>
     */
    public function register_default_role_caps( array $defaults ): array {
        $slug = 'system-admin';
        $defaults[ $slug ] = array_values( array_unique( array_merge(
            $defaults[ $slug ] ?? [],
            array_keys( PCIO_VIS_Tickets_Plugin::CAP_ROLE_MAP )
        ) ) );
        return $defaults;
    }

    /**
     * Re-grant the ticket caps to their native WP roles when the core "Sync
     * capabilities" action runs (mirrors plugin-activation behaviour).
     */
    public function grant_native_role_caps(): void {
        foreach ( PCIO_VIS_Tickets_Plugin::CAP_ROLE_MAP as $cap => $roles ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }

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
        if ( ! $event_id || ! current_user_can( 'vis_tickets_manage' ) ) {
            return $tabs;
        }

        $this->enqueue_editor_assets( $event_id );

        $tabs[] = [
            'id'    => 'tickets',
            'label' => __( 'Tickets', 'pcio-vis-tickets' ),
            'html'  => '<div id="pcio-met-editor" class="pcio-met-editor" data-event="' . esc_attr( (string) $event_id ) . '">'
                     . '<p class="pcio-met-loading">' . esc_html__( 'Loading ticket settings…', 'pcio-vis-tickets' ) . '</p>'
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

        wp_enqueue_style(
            'pcio-met-ticket-tab',
            plugins_url( 'assets/ticket-tab.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION
        );
        wp_enqueue_script(
            'pcio-met-ticket-tab',
            plugins_url( 'assets/ticket-tab.js', PCIO_VIS_TICKETS_FILE ),
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
                'currency' => PCIO_VIS_Tickets_Admin::currency(),
                'settings' => $settings,
                'types'    => $types,
                'i18n'     => $this->editor_i18n(),
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

    // ── Mode wiring ───────────────────────────────────────────────

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

        $settings = PCIO_VIS_Tickets_DB::get_settings( $event_id );

        // Are any types currently on sale, and is any priced above 0?
        $on_sale  = false;
        $has_paid = false;
        foreach ( PCIO_VIS_Tickets_DB::get_types( $event_id ) as $type ) {
            if ( PCIO_VIS_Tickets_DB::type_on_sale( $type ) ) {
                $on_sale = true;
                if ( (int) $type['price_cents'] > 0 ) {
                    $has_paid = true;
                }
            }
        }
        $soldout = (int) $settings['is_soldout'] === 1 || ! $on_sale;

        // Late-enqueued styles are flushed in the footer by WordPress.
        wp_enqueue_style(
            'pcio-met-checkout',
            plugins_url( 'assets/checkout.css', PCIO_VIS_TICKETS_FILE ),
            [],
            PCIO_VIS_TICKETS_VERSION
        );

        $checkout_url = add_query_arg(
            'event',
            rawurlencode( $event['slug'] ?? (string) $event_id ),
            home_url( 'tickets/checkout/' )
        );

        ob_start();
        ?>
        <div class="met-event-signup">
            <?php if ( $soldout ) : ?>
                <span class="me-btn met-event-signup__btn met-event-signup__btn--soldout" aria-disabled="true">
                    <?php esc_html_e( 'Sold out', 'pcio-vis-tickets' ); ?>
                </span>
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
        $can_refund = current_user_can( 'vis_tickets_refund' );
        $can_manage = current_user_can( 'vis_tickets_manage' );

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
            'label'      => __( 'Ticket Orders', 'pcio-vis-tickets' ),
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
        return $lists;
    }
}
