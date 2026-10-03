<?php
/**
 * Front-end template: ticket checkout
 * Served at /tickets/checkout?event={slug|id} by PCIO_VIS_Tickets_Frontend.
 *
 * $pcio_met_view:
 *   event, event_id, title, types[], currency, soldout, event_url
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_vis_v = isset( $pcio_met_view ) && is_array( $pcio_met_view ) ? $pcio_met_view : [];
if ( empty( $pcio_vis_v ) ) {
    return;
}

get_header();
?>
<div class="met-checkout">
    <a class="met-checkout__back" href="<?php echo esc_url( $pcio_vis_v['event_url'] ); ?>">
        &larr; <?php esc_html_e( 'Back to event', 'pcio-vis-tickets' ); ?>
    </a>

    <h1 class="met-checkout__title"><?php echo esc_html( $pcio_vis_v['title'] ); ?></h1>
    <p class="met-checkout__sub"><?php esc_html_e( 'Buy tickets', 'pcio-vis-tickets' ); ?></p>

    <?php if ( $pcio_vis_v['soldout'] ) : ?>
        <div class="met-notice met-notice--warn">
            <?php esc_html_e( 'Sorry — there are no tickets available for this event right now.', 'pcio-vis-tickets' ); ?>
        </div>
    <?php else : ?>

    <form id="met-checkout-form" class="met-checkout__form" novalidate>
        <section class="met-card">
            <h2 class="met-card__title"><?php esc_html_e( 'Tickets', 'pcio-vis-tickets' ); ?></h2>
            <div class="met-types">
                <?php foreach ( $pcio_vis_v['types'] as $pcio_vis_t ) : ?>
                    <div class="met-type" data-type="<?php echo esc_attr( (string) $pcio_vis_t['id'] ); ?>"
                         data-price="<?php echo esc_attr( (string) $pcio_vis_t['price_cents'] ); ?>"
                         data-entrance="<?php echo esc_attr( (string) $pcio_vis_t['is_entrance'] ); ?>"
                         data-max="<?php echo esc_attr( (string) $pcio_vis_t['max'] ); ?>">
                        <div class="met-type__info">
                            <span class="met-type__name"><?php echo esc_html( $pcio_vis_t['name'] ); ?></span>
                            <span class="met-type__price">
                                <?php
                                if ( $pcio_vis_t['price_cents'] <= 0 ) {
                                    esc_html_e( 'Free', 'pcio-vis-tickets' );
                                } else {
                                    echo esc_html( number_format_i18n( $pcio_vis_t['price_cents'] / 100, 2 ) . ' ' . $pcio_vis_v['currency'] );
                                }
                                ?>
                            </span>
                        </div>
                        <div class="met-qty">
                            <button type="button" class="met-qty__btn" data-act="dec" aria-label="<?php esc_attr_e( 'Decrease', 'pcio-vis-tickets' ); ?>">&minus;</button>
                            <input type="number" class="met-qty__input" value="0" min="0" max="<?php echo esc_attr( (string) $pcio_vis_t['max'] ); ?>" inputmode="numeric" readonly>
                            <button type="button" class="met-qty__btn" data-act="inc" aria-label="<?php esc_attr_e( 'Increase', 'pcio-vis-tickets' ); ?>">&plus;</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="met-total">
                <span><?php esc_html_e( 'Total', 'pcio-vis-tickets' ); ?></span>
                <strong id="met-total-amount">0.00 <?php echo esc_html( $pcio_vis_v['currency'] ); ?></strong>
            </div>
        </section>

        <section class="met-card">
            <h2 class="met-card__title"><?php esc_html_e( 'Your details', 'pcio-vis-tickets' ); ?></h2>
            <div class="met-field">
                <label for="met-name"><?php esc_html_e( 'Full name', 'pcio-vis-tickets' ); ?></label>
                <input type="text" id="met-name" name="name" autocomplete="name" required>
            </div>
            <div class="met-field">
                <label for="met-email"><?php esc_html_e( 'Email', 'pcio-vis-tickets' ); ?></label>
                <input type="email" id="met-email" name="email" autocomplete="email" required>
                <p class="met-field__help"><?php esc_html_e( 'Your tickets will be emailed to this address.', 'pcio-vis-tickets' ); ?></p>
            </div>
            <div class="met-field">
                <label for="met-phone"><?php esc_html_e( 'Phone (optional)', 'pcio-vis-tickets' ); ?></label>
                <input type="tel" id="met-phone" name="phone" autocomplete="tel">
            </div>
        </section>

        <div id="met-checkout-error" class="met-notice met-notice--error" hidden></div>

        <button type="submit" id="met-checkout-submit" class="met-btn met-btn--primary" disabled>
            <?php esc_html_e( 'Continue to payment', 'pcio-vis-tickets' ); ?>
        </button>
        <p class="met-checkout__secure">
            <?php esc_html_e( 'Payments are processed securely by Stripe.', 'pcio-vis-tickets' ); ?>
        </p>
    </form>

    <?php endif; ?>
</div>
<?php
get_footer();
