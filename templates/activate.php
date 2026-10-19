<?php
/**
 * Tickets extension – guest self-activation page ("Use ticket").
 *
 * Variables: $ctx (activation_context), $raw (the base64 payload).
 * Ported from the legacy activate_billet.php flow: state header, per-type
 * quantity selector, swipe-to-activate, 5-minute anti-screenshot countdown.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables supplied by the renderer, never real globals.

$ok = ! empty( $ctx['ok'] );
if ( $ok ) {
    $event      = $ctx['event'];
    $order      = $ctx['order'];
    $groups     = $ctx['groups'];
    $free_total = (int) $ctx['free_total'];
    $ticket_used = 'valid' !== (string) $ctx['ticket']['status'];

    if ( ! $ctx['in_window'] && $free_total > 0 ) {
        $before = current_time( 'mysql' ) < $ctx['window_from'];
        $when = $before ? $ctx['window_from'] : $ctx['window_to'];
        $ts = strtotime( $when );
        $nice = $ts ? wp_date( 'd-m-Y H:i', $ts ) : $when;
        $st = [ 'class' => $before ? 'st-wait' : 'st-error', 'icon' => $before ? '⏳' : '✕',
            'title' => $before ? __( 'Too early', 'pcio-vis-tickets' ) : __( 'Expired', 'pcio-vis-tickets' ),
            // translators: %s: date/time the activation window opens or closes.
            'msg' => $before ? sprintf( __( 'Activation opens %s.', 'pcio-vis-tickets' ), $nice ) : sprintf( __( 'Activation ended %s.', 'pcio-vis-tickets' ), $nice ) ];
        $show_swipe = false;
    } elseif ( $free_total < 1 ) {
        $st = [ 'class' => 'st-used', 'icon' => '✓', 'title' => __( 'All tickets activated', 'pcio-vis-tickets' ),
            'msg' => __( 'All tickets on this order have already been activated.', 'pcio-vis-tickets' ) ];
        $show_swipe = false;
    } else {
        $st = [ 'class' => 'st-ready', 'icon' => '🎫', 'title' => __( 'Ready to activate', 'pcio-vis-tickets' ),
            'msg' => __( 'Choose quantities and swipe to activate your tickets.', 'pcio-vis-tickets' ) ];
        $show_swipe = true;
    }
} else {
    $st = [ 'class' => 'st-error', 'icon' => '✕', 'title' => __( 'Invalid', 'pcio-vis-tickets' ), 'msg' => (string) ( $ctx['error'] ?? '' ) ];
    $show_swipe = false;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title><?php esc_html_e( 'Ticket', 'pcio-vis-tickets' ); ?><?php echo $ok ? ' – ' . esc_html( (string) $ctx['event']['title'] ) : ''; ?></title>
<?php wp_head(); ?>
</head>
<body class="pcio-act">
<div class="pcio-act-card">
    <div class="pcio-act-header <?php echo esc_attr( $st['class'] ); ?>">
        <div class="pcio-act-icon"><?php echo esc_html( $st['icon'] ); ?></div>
        <div class="pcio-act-title"><?php echo esc_html( $st['title'] ); ?></div>
        <?php if ( $ok ) : ?>
            <div class="pcio-act-event"><?php echo esc_html( (string) $ctx['event']['title'] ); ?></div>
            <div class="pcio-act-ticket"><?php esc_html_e( 'Order:', 'pcio-vis-tickets' ); ?> <?php echo esc_html( (string) $ctx['order']['order_no'] ); ?></div>
            <?php if ( '' !== (string) $ctx['buyer'] ) : ?>
                <div class="pcio-act-buyer"><?php echo esc_html( (string) $ctx['buyer'] ); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="pcio-act-body">
        <p class="pcio-act-msg"><?php echo esc_html( $st['msg'] ); ?></p>
        <?php if ( $ok && $show_swipe ) : ?>
            <div id="ticket-selector">
                <?php foreach ( $groups as $tid => $g ) : ?>
                    <div class="ticket-row<?php echo $g['free'] < 1 ? ' qty-zero' : ''; ?>" data-type-id="<?php echo (int) $tid; ?>" data-max="<?php echo (int) $g['free']; ?>">
                        <div class="ticket-row-info">
                            <div class="ticket-row-name"><?php echo esc_html( (string) $g['name'] ); ?></div>
                            <div class="ticket-row-avail">
                                <?php if ( $g['free'] > 0 ) : ?>
                                    <?php echo (int) $g['free']; ?> <?php esc_html_e( 'available', 'pcio-vis-tickets' ); ?>
                                    <?php if ( $g['used'] > 0 ) : ?> &bull; <?php echo (int) $g['used']; ?> <?php esc_html_e( 'already activated', 'pcio-vis-tickets' ); ?><?php endif; ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'All activated', 'pcio-vis-tickets' ); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ticket-qty">
                            <button type="button" class="qty-btn qty-minus" aria-label="−"<?php echo $g['free'] < 1 ? ' disabled' : ''; ?>>−</button>
                            <span class="qty-val"><?php echo $g['free'] > 0 ? 1 : 0; ?></span>
                            <button type="button" class="qty-btn qty-plus" aria-label="+"<?php echo $g['free'] <= 1 ? ' disabled' : ''; ?>>+</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="swipe-wrap" id="swipe-wrap">
                <div class="swipe-label" id="swipe-label">→ <?php esc_html_e( 'Swipe to activate', 'pcio-vis-tickets' ); ?> →</div>
                <div class="swipe-thumb" id="swipe-thumb">🎫</div>
            </div>
            <div class="error-box" id="error-box"></div>
        <?php endif; ?>
    </div>
</div>
<div id="cd-overlay">
    <canvas id="cd-canvas"></canvas>
    <div id="cd-body">
        <div id="cd-check">✓</div>
        <div id="cd-event"><?php echo $ok ? esc_html( (string) $ctx['event']['title'] ) : ''; ?></div>
        <div id="cd-ticket"><?php esc_html_e( 'Order:', 'pcio-vis-tickets' ); ?> <?php echo $ok ? esc_html( (string) $ctx['order']['order_no'] ) : ''; ?></div>
        <div id="cd-summary"></div>
        <div id="cd-timer">5:00</div>
        <div id="cd-label"><?php esc_html_e( 'Ticket active for', 'pcio-vis-tickets' ); ?></div>
        <div id="cd-clock">--:--:--</div>
        <div id="cd-expired"><?php esc_html_e( 'Ticket activation has expired.', 'pcio-vis-tickets' ); ?></div>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
