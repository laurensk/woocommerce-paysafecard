<?php

class Paysafecard_AddFees {
    /**
     * required actions & filters.
     */
    public static function init() {
        //hook function to add custom fee to cart
        add_action( 'woocommerce_cart_calculate_fees',  __CLASS__ . '::add_fee' );
    }

    public static function add_fee() {
        global $woocommerce;

        $payment_gateway_id = 'paysafecard';
        $payment_gateways   = WC_Payment_Gateways::instance();
        $payment_gateway    = $payment_gateways->payment_gateways()[$payment_gateway_id];


        $added_percent = $payment_gateway->settings['added_percent'];
        $order_total_amount = preg_replace("/([^0-9\\.])/i", "", $woocommerce->cart->get_cart_total() ) ;
        $added_amount = ($order_total_amount / 100) * $added_percent;

        $chosen_gateway = $woocommerce->session->get( 'chosen_payment_method' );
        if ( $chosen_gateway == 'paysafecard' ) {
            $woocommerce->cart->add_fee( "Paysafecard Bezahlungsgebüren", $added_amount);
        }
    }
}
Paysafecard_AddFees::init();