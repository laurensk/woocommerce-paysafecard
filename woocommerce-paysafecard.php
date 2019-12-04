<?php
/*
 * Plugin Name: WooCommerce paysafecard Gateway
 * Plugin URI: https://www.laurensk.at
 * Description: Use paysafecard as payment method.
 * Author: Laurens Kropf
 * Author URI: https://www.laurensk.at
 * Version: 1.0.0
 *
*/
include( plugin_dir_path( __FILE__ ) . '/PaymentClass.php' );

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	add_filter( 'woocommerce_payment_gateways', 'paysafecard_add_gateway_class' );
	add_action( 'plugins_loaded', 'paysafecard_init_gateway_class' );
}

function paysafecard_add_gateway_class( $methods ) {
	$methods[] = 'WC_Paysafecard_Gateway';

	return $methods;
}

function paysafecard_country_restriction( $available_gateways ) {
	global $woocommerce;

	$options    = get_option( 'woocommerce_paysafecard_settings' );
	$is_diabled = true;

	foreach ( $options["country"] AS $country ) {
		if ( WC()->customer != null ) {
			if ( WC()->customer->get_shipping_country() == $country ) {
				$is_diabled = false;
			}
		}else{
			$is_diabled = false;
		}
	}

	if ( $is_diabled == true ) {
		unset( $available_gateways["paysafecard"] );
	}

	return $available_gateways;

}

function paysafecard_init_gateway_class() {

	class WC_Paysafecard_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = 'paysafecard';
			$this->icon               = plugins_url( 'Logo2.png', __FILE__ );
			$this->has_fields         = true;
			$this->method_title       = 'paysafecard';
			$this->method_description = __( 'Use paysafecard as payment method.', 'paysafecard' );
			$this->description        = $this->method_description;
			$this->version            = "1.0.3";
			$this->supports           = array(
				'products',
				'refunds'
			);

			$this->init_form_fields();
			$this->init_settings();
			$this->title           = "paysafecard";
			$this->description     = __( 'Mit paysafecard bezahlen. Es fallen 20 % Bezahlungsgebüren an.', 'paysafecard' );
			$this->enabled         = $this->get_option( 'enabled' );
			$this->testmode        = 'yes' === $this->get_option( 'testmode' );
			$this->private_key     = $this->testmode ? $this->get_option( 'api_test_key' ) : $this->get_option( 'api_test_key' );
			$this->publishable_key = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );

			add_action( 'plugins_loaded', 'paysafecard_textdomain' );

			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'callback_handler' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			add_action( 'woocommerce_thankyou_paysafecard', array( $this, 'check_response' ) );

			add_filter( 'woocommerce_available_payment_gateways', 'paysafecard_country_restriction' );

		}

		/**
		 * Plugin options, we deal with it in Step 3 too
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'        => array(
					'title'   => 'Enable/Disable',
					'label'   => 'Enable paysafecard',
					'type'    => 'checkbox',
					'default' => 'no'
				),
				'testmode'       => array(
					'title'       => 'Test mode',
					'label'       => 'Enable Test Mode',
					'type'        => 'checkbox',
					'description' => __( 'If the test mode is enabled you are making transactions against paysafecard test environment. Therefore the test environment API key is necessary to be set.', 'paysafecard' ),
					'default'     => 'yes',
					'desc_tip'    => false,
				),
				'added_percent'    => array(
					'title'       => 'Extra charge amount (percent)',
					'description' => __( 'Specify percentage amount. Decimals must be seperated by a dot (.)', 'paysafecard' ),
					'type'        => 'number'
				),
				'api_key'        => array(
					'title'       => 'API Key',
					'description' => __( 'This key is provided by the paysafecard support team. There is one key for the test- and one for production environment.', 'paysafecard' ),
					'type'        => 'password'
				),
				'submerchant_id' => array(
					'title'       => 'Submerchant ID',
					'description' => __( 'This field specifies the used Reporting Criteria. You can use this parameter to distinguish your transactions per brand/URL. Use this field only if agreed beforehand with the paysafecard support team. The value has to be configured in both systems.', 'paysafecard' ),
					'type'        => 'text'
				),
			);
		}

		public function admin_options() {
			echo '<h3>' . __( 'paysafecard', 'paysafecard' ) . '</h3>';
			echo '<p>' . __( 'This plugin adds support for paysafecard. This plugin is working as a gateway for the paysafecard PHP RESI API.', 'paysafecard' ) . '</p>';
			echo '<p>' . __( 'Please set up the plugin in order to work.', 'paysafecard' ) . '</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';

		}

		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
			global $woocommerce;
			if ( $this->description ) {
				if ( $this->testmode ) {
					$this->description;
					$this->description = trim( $this->description );
				}
				echo wpautop( wp_kses_post( $this->description ) );
			}
		}


		public function process_payment( $order_id ) {
			global $woocommerce;

			$this->init_settings();
			$this->api_key        = $this->settings['api_key'];
			$this->added_percent  = $this->settings['added_percent'];
			$this->submerchant_id = $this->settings['submerchant_id'];
			$this->testmode       = $this->settings['testmode'];

			$order = wc_get_order( $order_id );

			if ( $this->testmode == "yes") {
				$env = "TEST";
			} else {
				$env = "PRODUCTION";
			}

			$order_total_amount = $order->get_total();
			$added_amount = ($order_total_amount / 100) * $this->added_percent;
			$total_to_pay = $order_total_amount + $added_amount;

			$pscpayment 	  = new PaysafecardPaymentController($this->api_key, $env);
			$success_url      = $order->get_checkout_order_received_url() . "&paysafecard=true&success=true&order_id=" . $order->get_order_number() . "&payment_id={payment_id}";
			$failure_url      = $order->get_checkout_payment_url() . "&paysafecard=false&failed=true&payment_id={payment_id}";
			$notification_url = $this->get_return_url( $order ) . "&wc-api=wc_paysafecard_gateway&order_id=" . $order->get_order_number() . "&payment_id={payment_id}";

			$customerhash = "";

			if ( empty( $order->get_customer_id() ) ) {
				$customerhash = md5( $order->get_billing_email() );
			} else {
				$customerhash = md5( $order->get_customer_id() );
			}

			$response = $pscpayment->createPayment($total_to_pay, $order->get_currency(), $customerhash, $order->get_customer_ip_address(), $success_url, $failure_url, $notification_url, $correlation_id = "");

			if ( isset( $response["object"] ) ) {
				return array(
					'result'   => 'success',
					'redirect' => $response["redirect"]['auth_url']
				);

			}
		}

		public function check_response() {
			global $woocommerce;

			if ( isset( $_GET['paysafecard'] ) ) {

				$payment_id = $_GET['payment_id'];
				$order_id   = $_GET['order_id'];

				$order = wc_get_order( $order_id );


				if ( $order_id == 0 || $order_id == '' ) {
					return;
				}

				if ( $this->testmode ) {
					$env = "TEST";
				} else {
					$env = "PRODUCTION";
				}

				$this->init_settings();
				$this->api_key = $this->settings['api_key'];
				$pscpayment    = new PaysafecardPaymentController( $this->api_key, $env );
				$response      = $pscpayment->retrievePayment( $payment_id );

				if ( $response == false ) {
					wc_add_notice( 'Error Request' . var_dump( $response ), 'error' );

					return array(
						'result'   => 'failed',
						'redirect' => ''
					);

				} else if ( isset( $response["object"] ) ) {
					if ( $response["status"] == "SUCCESS" ) {
						$order->payment_complete( $payment_id );
						$order->add_order_note( sprintf( __( '%s payment approved! Trnsaction ID: %s', 'paysafecard' ), $this->title, $payment_id ) );
						$woocommerce->cart->empty_cart();

						return array(
							'result'   => 'failed',
							'redirect' => ''
						);
					} else if ( $response["status"] == "INITIATED" ) {
						wc_add_notice( __( 'Thank you, please go to the Point of Sales and pay the transaction', 'paysafecard' ), 'info' );
					} else if ( $response["status"] == "REDIRECTED" ) {
						wc_add_notice( __( 'Thank you, please go to the Point of Sales and pay the transaction', 'paysafecard' ), 'info' );
					} else if ( $response["status"] == "EXPIRED" ) {
						wc_add_notice( __( 'Unfortunately, your payment failed. Please try again', 'paysafecard' ), 'error' );
					} else if ( $response["status"] == "AUTHORIZED" ) {
						$response = $pscpayment->capturePayment( $payment_id );
						if ( $response == true ) {
							if ( isset( $response["object"] ) ) {
								if ( $response["status"] == "SUCCESS" ) {
									$order->payment_complete( $payment_id );
									$order->add_order_note( sprintf( __( '%s payment approved! Trnsaction ID: %s', 'paysafecard' ), $this->title, $payment_id ) );
									$order->set_status( 'pending', 'Payment Approved.' );
									return;
							}
						}
					}


					}
				}

				if ( $_GET["failed"] ) {
					$order = new WC_Order( $order_id );
					$order->update_status( 'cancelled', sprintf( __( '%s payment cancelled! Transaction ID: %d', 'paysafecard' ), $this->title, $payment_id ) );
				}

			}
		}

		public function callback_handler() {
			global $woocommerce;
			global $wp;

			$payment_id = $_GET['payment_id'];
			$order_id   = $wp->query_vars['order-received'];

			$this->init_settings();
			$this->api_key        = $this->settings['api_key'];
			$this->submerchant_id = $this->settings['submerchant_id'];

			if ( $this->testmode ) {
				$env = "TEST";
			} else {
				$env = "PRODUCTION";
			}

			$pscpayment = new PaysafecardPaymentController( $this->api_key, $env );
			$response   = $pscpayment->retrievePayment( $payment_id );

			$order = new WC_Order($order_id );

			if ( $response == false ) {

			} else if ( isset( $response["object"] ) ) {


				if ( $response["status"] == "SUCCESS" ) {
					if ( 'processing' == $order->status ) {
						$order->payment_complete( $payment_id );
						$order->add_order_note( sprintf( __( '%s payment approved! Trnsaction ID: %s', 'paysafecard' ), $this->title, $payment_id ) );
						$order->set_status( 'pending', 'Payment Approved.' );
					}
				} else if ( $response["status"] == "INITIATED" ) {
				} else if ( $response["status"] == "REDIRECTED" ) {
				} else if ( $response["status"] == "EXPIRED" ) {
				} else if ( $response["status"] == "AUTHORIZED" ) {
					$response = $pscpayment->capturePayment( $payment_id );
					if ( $response == true ) {
						if ( isset( $response["object"] ) ) {
							if ( $response["status"] == "SUCCESS" ) {
								$order->payment_complete( $payment_id );
								$order->add_order_note( sprintf( __( '%s payment approved! Trnsaction ID: %s', 'paysafecard' ), $this->title, $payment_id ) );
								$order->set_status( 'pending', 'Payment Approved.' );
								return;
							}
						}
					}
				}
			}

			update_option( 'webhook_debug', $_GET );
		}
	}
}