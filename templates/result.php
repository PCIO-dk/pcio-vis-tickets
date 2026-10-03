<?php
/**
 * Front-end template: checkout result (accepted / rejected)
 * Served at /tickets/accepted?order={token} and /tickets/rejected?order={token}.
 *
 * $pcio_met_view:
 *   view ('accepted'|'rejected'), order|null, event|null, event_url, retry_url
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_vis_v = isset( $pcio_met_view ) && is_array( $pcio_met_view ) ? $pcio_met_view : [];
if ( empty( $pcio_vis_v ) ) {
    return;
}

$pcio_vis_order    = $pcio_vis_v['order'];
$pcio_vis_accepted = ( $pcio_vis_v['view'] === 'accepted' );
$pcio_vis_paid     = $pcio_vis_order && in_array( $pcio_vis_order['status'], [ 'paid', 'free_confirmed' ], true );

get_header();
?>
<div class="met-result">
    <?php if ( $pcio_vis_accepted ) : ?>

        <div class="met-result__icon met-result__icon--ok">&#10003;</div>
        <h1 class="met-result__title"><?php esc_html_e( 'Thank you!', 'pcio-vis-tickets' ); ?></h1>

        <?php if ( ! $pcio_vis_order ) : ?>
            <p class="met-result__text"><?php esc_html_e( 'We could not find your order, but if your payment went through your tickets are on their way by email.', 'pcio-vis-tickets' ); ?></p>
        <?php elseif ( $pcio_vis_paid ) : ?>
            <p class="met-result__text">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: order number */
                        esc_html__( 'Your order %s is confirmed. Your tickets have been sent to your email address.', 'pcio-vis-tickets' ),
                        '<strong>' . esc_html( $pcio_vis_order['order_no'] ) . '</strong>'
                    ),
                    [ 'strong' => [] ]
                );
                ?>
            </p>
        <?php else : ?>
            <p class="met-result__text">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: order number */
                        esc_html__( 'We are confirming your payment for order %s. Your tickets will be emailed to you shortly.', 'pcio-vis-tickets' ),
                        '<strong>' . esc_html( $pcio_vis_order['order_no'] ) . '</strong>'
                    ),
                    [ 'strong' => [] ]
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if ( $pcio_vis_order && ! empty( $pcio_vis_order['summary'] ) ) : ?>
            <p class="met-result__summary"><?php echo esc_html( $pcio_vis_order['summary'] ); ?></p>
        <?php endif; ?>

        <?php if ( $pcio_vis_paid ) : ?>
            <a class="met-btn met-btn--primary" href="<?php echo esc_url( add_query_arg( 'order', rawurlencode( (string) $pcio_vis_order['token'] ), home_url( 'tickets/download/' ) ) ); ?>">
                <?php esc_html_e( 'Download tickets (PDF)', 'pcio-vis-tickets' ); ?>
            </a>
        <?php endif; ?>

        <a class="met-btn met-btn--secondary" href="<?php echo esc_url( $pcio_vis_v['event_url'] ); ?>">
            <?php esc_html_e( 'Back to event', 'pcio-vis-tickets' ); ?>
        </a>

    <?php else : ?>

        <div class="met-result__icon met-result__icon--warn">&#10005;</div>
        <h1 class="met-result__title"><?php esc_html_e( 'Payment not completed', 'pcio-vis-tickets' ); ?></h1>
        <p class="met-result__text">
            <?php esc_html_e( 'Your payment was cancelled or did not go through, so no tickets were issued and you have not been charged.', 'pcio-vis-tickets' ); ?>
        </p>

        <a class="met-btn met-btn--primary" href="<?php echo esc_url( $pcio_vis_v['retry_url'] ); ?>">
            <?php esc_html_e( 'Try again', 'pcio-vis-tickets' ); ?>
        </a>
        <a class="met-result__link" href="<?php echo esc_url( $pcio_vis_v['event_url'] ); ?>">
            <?php esc_html_e( 'Back to event', 'pcio-vis-tickets' ); ?>
        </a>

    <?php endif; ?>
</div>
<?php
get_footer();
