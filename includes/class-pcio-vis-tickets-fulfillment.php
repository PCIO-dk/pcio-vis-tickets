<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Order fulfilment for the Tickets extension.
 *
 * Listens for `pcio_met_order_confirmed` (fired by both the free checkout path
 * and the Stripe webhook) and, for each confirmed order, generates the PDF and
 * emails it to the buyer. Sending is routed through the core `pcio_me_mailer_send`
 * filter (so a Postmark/SendGrid extension handles it when present) with a
 * wp_mail() fallback.
 */
class PCIO_VIS_Tickets_Fulfillment {

    public function register(): void {
        add_action( 'pcio_met_order_confirmed', [ $this, 'fulfil' ], 10, 1 );
    }

    /**
     * Generate the PDF and email the buyer for a confirmed order.
     */
    public function fulfil( int $order_id ): void {
        $order = PCIO_VIS_Tickets_DB::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $pdf_path = PCIO_VIS_Tickets_PDF::generate( $order_id );
        $event    = PCIO_VIS_Events_DB::get( (int) $order['event_id'] );

        $this->email_order( $order, $event, $pdf_path );
    }

    /**
     * Send the confirmation email with the PDF attached.
     *
     * @param array       $order
     * @param array|null  $event
     * @param string|null $pdf_path Absolute path to the generated PDF (or null).
     */
    private function email_order( array $order, ?array $event, ?string $pdf_path ): void {
        $to = sanitize_email( (string) $order['email'] );
        if ( ! is_email( $to ) ) {
            return;
        }

        $event_title = $event ? (string) $event['title'] : __( 'your event', 'pcio-vis-tickets' );
        $site        = get_bloginfo( 'name' );

        $subject = sprintf(
            /* translators: %s: event title */
            __( 'Your tickets for %s', 'pcio-vis-tickets' ),
            $event_title
        );

        $download_url = add_query_arg(
            'order',
            rawurlencode( (string) $order['token'] ),
            home_url( 'tickets/download/' )
        );

        $name = '';
        if ( ! empty( $order['customer_id'] ) ) {
            $customer = PCIO_VIS_Tickets_DB::get_customer( (int) $order['customer_id'] );
            $name     = $customer ? (string) $customer['name'] : '';
        }

        $greeting = $name !== ''
            ? sprintf( /* translators: %s: buyer name */ __( 'Hi %s,', 'pcio-vis-tickets' ), $name )
            : __( 'Hi,', 'pcio-vis-tickets' );

        $lines = [
            '<p>' . esc_html( $greeting ) . '</p>',
            '<p>' . sprintf(
                /* translators: %s: event title */
                esc_html__( 'Thank you for your order. Your tickets for %s are attached as a PDF.', 'pcio-vis-tickets' ),
                '<strong>' . esc_html( $event_title ) . '</strong>'
            ) . '</p>',
            '<p>' . sprintf(
                /* translators: %s: order number */
                esc_html__( 'Order number: %s', 'pcio-vis-tickets' ),
                '<strong>' . esc_html( (string) $order['order_no'] ) . '</strong>'
            ) . '</p>',
            '<p>' . sprintf(
                /* translators: %s: download link */
                esc_html__( 'If the attachment is missing, you can download your tickets here: %s', 'pcio-vis-tickets' ),
                '<a href="' . esc_url( $download_url ) . '">' . esc_html( $download_url ) . '</a>'
            ) . '</p>',
            '<p>' . esc_html( $site ) . '</p>',
        ];
        $content = implode( "\n", $lines );

        $attachments = [];
        if ( $pdf_path && is_readable( $pdf_path ) ) {
            $attachments[] = [
                'name' => 'tickets-' . sanitize_file_name( (string) $order['order_no'] ) . '.pdf',
                'path' => $pdf_path,
                'mime' => 'application/pdf',
            ];
        }

        $mail = [
            'subject'     => $subject,
            'content'     => $content,
            'attachments' => [],
        ];
        $recipients = [ [ 'email' => $to ] ];

        /**
         * Let a mailer extension handle delivery. Mirrors the core Mails flow:
         * returns ['sent'=>int,'failed'=>int] / WP_Error / null (defer).
         */
        $result = apply_filters( 'pcio_me_mailer_send', null, $mail, $recipients, $attachments );

        if ( null === $result || is_wp_error( $result ) ) {
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . PCIO_VIS_Tickets_Admin::from_name() . ' <' . PCIO_VIS_Tickets_Admin::from_email() . '>',
            ];
            $files = $pdf_path && is_readable( $pdf_path ) ? [ $pdf_path ] : [];
            wp_mail( $to, $subject, $content, $headers, $files );
        }
    }
}
