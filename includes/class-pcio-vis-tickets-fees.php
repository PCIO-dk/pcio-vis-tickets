<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stripe fee pass-through for the Tickets and Shop checkouts.
 *
 * A single figure is added to each order (never per line item) when the
 * administrator enables "Pass fee to buyers" under Vis → Tickets. The fee is a
 * neutral service fee computed from two dynamic inputs — a basis amount per
 * order and a percentage of the order subtotal — mirroring how Stripe's own
 * processing cost is built. It applies to every order regardless of payment
 * method (a payment-specific surcharge would violate Stripe's EEA agreement
 * and the Danish/EU rules on payment surcharges).
 *
 * Accounting: the fee is part of `amount_cents` (what Stripe charges) and is
 * stored separately in `fee_cents` so the event report can split
 * revenue / fee income without changing the net-payout formula:
 *
 *   net = (amount − refunded) − Stripe cost   (unchanged)
 *       = (revenue + fee income) − refunded − Stripe cost
 *
 * Refunds return ticket/product money only — the fee is never refunded, so
 * `refundable_cents()` caps refunds below the fee.
 */
class PCIO_VIS_Tickets_Fees {

    const OPT_PASS_ON = 'pcio_met_fee_pass_on';

    /**
     * Whether buyers pay the service fee (admin toggle, default off).
     */
    public static function pass_on(): bool {
        return (bool) get_option( self::OPT_PASS_ON, false );
    }

    public static function fee_base_cents(): int {
        return PCIO_VIS_Tickets_Reports::fee_base_cents();
    }

    public static function fee_percent(): float {
        return PCIO_VIS_Tickets_Reports::fee_percent();
    }

    /**
     * Fee (øre) for one order with the given subtotal in minor units.
     * Orders without value (free tickets / empty basket) never gain a fee.
     * The result is filtered so site owners can tweak it via
     * `pcio_met_order_fee_cents`.
     *
     * @param int $subtotal_cents Sum of the value-bearing items (ør).
     * @return int Fee in øre; 0 when disabled, free or misconfigured.
     */
    public static function order_fee_cents( int $subtotal_cents ): int {
        if ( $subtotal_cents <= 0 || ! self::pass_on() ) {
            return 0;
        }
        $base    = self::fee_base_cents();
        $percent = self::fee_percent();
        if ( $base <= 0 && $percent <= 0.0 ) {
            return 0;
        }
        // The percentage part is rounded up to whole øre so the collected fee
        // never under-covers the estimate for small orders.
        $fee = $base + (int) ceil( $subtotal_cents * $percent / 100 );
        $fee = max( 0, $fee );
        return (int) apply_filters( 'pcio_met_order_fee_cents', $fee, $subtotal_cents );
    }

    /**
     * Money (øre) that may be refunded from one order: ticket/product value
     * only — the buyer-paid fee stays with the organisation because Stripe
     * keeps its processing fee on refunds.
     *
     * @param int $amount_cents Stripe-charged total for the order.
     * @param int $fee_cents    Fee share of that total.
     * @param int $refunded_cents Already refunded amount (øn).
     * @return int
     */
    public static function refundable_cents( int $amount_cents, int $fee_cents, int $refunded_cents ): int {
        return max( 0, min( $amount_cents, $amount_cents - absint( $fee_cents ) ) - absint( $refunded_cents ) );
    }
}
