<?php
/**
 * Plugin Name: WooCommerce Instant Win Reveal (Endpoint)
 * Description: Runs the Instant Win game at /checkout/instant-win/{order_id}/ before the real Thank You page, with guaranteed JS/CSS loading.
 * Version:     1.5.2
 * Author:      Your Name
 * Text Domain: wc-instant-win
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Instant_Win_Reveal {
    public function __construct() {
        // 1) Register endpoint and query var
        add_action( 'init',               [ $this, 'add_rewrite_endpoint' ] );
        add_filter( 'query_vars',         [ $this, 'add_query_vars' ] );

        // 2) Override Thank You URL
        add_filter( 'woocommerce_get_checkout_order_received_url', [ $this, 'filter_received_url' ], 10, 2 );

        // 3) Catch reveal page requests
        add_action( 'template_redirect',   [ $this, 'maybe_show_reveal_page' ] );

        // 4) Core logic hooks
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'mark_instantwin_order' ], 20, 2 );
        add_action( 'woocommerce_payment_complete',           [ $this, 'precompute_instant_win' ], 20 );
        add_action( 'wp_ajax_instantwin_reveal_auto',         [ $this, 'ajax_reveal_auto' ] );
        add_action( 'wp_ajax_nopriv_instantwin_reveal_auto',  [ $this, 'ajax_reveal_auto' ] );
        add_action( 'wp_ajax_instantwin_reveal_finalize',     [ $this, 'ajax_reveal_finalize' ] );
        add_action( 'wp_ajax_nopriv_instantwin_reveal_finalize',[ $this, 'ajax_reveal_finalize' ] );
        add_action( 'woocommerce_thankyou',                   [ $this, 'output_reveal_ui' ], 20 );

        // 5) Flush rewrite rules on activation/deactivation
        register_activation_hook(   __FILE__, [ $this, 'flush_rewrite_rules_on_activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'flush_rewrite_rules' ] );
    }

    /*** 1) Register endpoint ***/
    public function add_rewrite_endpoint() {
        add_rewrite_endpoint( 'instant-win', EP_ROOT | EP_PAGES );
    }
    public function add_query_vars( $vars ) {
        $vars[] = 'instant-win';
        return $vars;
    }

    /*** 2) Override the default Thank You redirect ***/
    public function filter_received_url( $url, $order ) {
        if ( get_post_meta( $order->get_id(), '_instantwin_enabled', true ) ) {
            $checkout = wc_get_page_permalink( 'checkout' );
            $endpoint = trailingslashit( $checkout ) . 'instant-win/' . $order->get_id() . '/';
            return add_query_arg( 'key', $order->get_order_key(), $endpoint );
        }
        return $url;
    }

    /*** 3) Display the reveal page (with JS/CSS) ***/
    public function maybe_show_reveal_page() {
        $order_id = get_query_var( 'instant-win' );
        if ( ! $order_id ) {
            return;
        }
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order ) {
            wp_die( 'Order not found.' );
        }
        if ( ! get_post_meta( $order_id, '_instantwin_enabled', true ) ) {
            return;
        }

        // Precompute if needed
        if ( ! get_post_meta( $order_id, '_instantwin_precomputed', true ) ) {
            $this->precompute_instant_win( $order_id );
        }

        // ===== REGISTER & ENQUEUE ASSETS DIRECTLY HERE =====
        wp_register_script(
            'wc-instantwin-js',
            plugin_dir_url( __FILE__ ) . 'assets/js/instantwin.js',
            [ 'jquery' ],
            '1.5.2',
            true
        );
        wp_register_style(
            'wc-instantwin-css',
            plugin_dir_url( __FILE__ ) . 'assets/css/instantwin.css',
            [],
            '1.5.2'
        );
        // Localize
        $raw     = get_post_meta( $order_id, '_instantwin_tickets', true );
        $tickets = $raw ? json_decode( $raw, true ) : [];
        $prizes  = [];
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( function_exists( 'have_rows' ) && have_rows( 'instant_tickets_prizes', $pid ) ) {
                foreach ( get_field( 'instant_tickets_prizes', $pid ) as $w ) {
                    $prizes[] = $w['instant_prize'];
                }
            }
        }
        $prizes = array_values( array_unique( $prizes ) );
        wp_localize_script( 'wc-instantwin-js', 'instantWin', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'order_id'     => $order_id,
            'tickets'      => $tickets,
            'prizes'       => $prizes,
            'thankyou_url' => wc_get_checkout_url() . "order-received/{$order_id}/?key={$order->get_order_key()}",
        ] );

        wp_enqueue_script( 'wc-instantwin-js' );
        wp_enqueue_style(  'wc-instantwin-css' );
        // ====================================================

        // Theme + WooCommerce wrappers
        get_header();
        do_action( 'woocommerce_before_main_content' );

        echo '<section class="woocommerce order-reveal"><div class="woocommerce-order-reveal__content">';
        $this->output_reveal_ui( $order_id );
        echo '</div></section>';

        do_action( 'woocommerce_after_main_content' );
        do_action( 'woocommerce_sidebar' );
        get_footer();

        exit;
    }

    /*** 4a) Mark orders with Instant Win ***/
    public function mark_instantwin_order( $order_id, $data ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        foreach ( $order->get_items() as $item ) {
            $p = wc_get_product( $item->get_product_id() );
            if ( function_exists('have_rows') && have_rows('instant_tickets_prizes',$p->get_id()) ) {
                update_post_meta( $order_id, '_instantwin_enabled', 1 );
                return;
            }
        }
    }

    /*** 4b) Precompute ticket array ***/
    public function precompute_instant_win( $order_id ) {
        if ( get_post_meta( $order_id, '_instantwin_precomputed', true ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
    
        // Build a map of winning tickets → prize
        $wins_map = [];
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( ! function_exists( 'have_rows' ) || ! have_rows( 'instant_tickets_prizes', $pid ) ) {
                continue;
            }
            foreach ( get_field( 'instant_tickets_prizes', $pid ) as $win ) {
                foreach ( explode( ',', $win['winning_ticket'] ) as $num ) {
                    $wins_map[ trim( $num ) ] = $win['instant_prize'];
                }
            }
        }
    
        // Group tickets by product into sessions
        $sessions = [];
        foreach ( $order->get_items() as $item ) {
            $pid      = $item->get_product_id();
            $product  = wc_get_product( $pid );
            $title    = $product ? $product->get_title() : '';
            $mode     = get_post_meta( $pid, 'instant_win_game_type', true ) ?: 'wheel';
            $mode     = in_array( $mode, ['wheel','slots','scratch'], true ) ? $mode : 'wheel';
    
            // collect ticket numbers
            foreach ( $item->get_formatted_meta_data() as $meta ) {
                if ( $meta->key !== 'Ticket number' ) {
                    continue;
                }
                $nums = array_map( 'trim', explode( ',', $meta->value ) );
                foreach ( $nums as $n ) {
                    if ( ! $n ) {
                        continue;
                    }
                    $sessions[ $pid ] = [
                        'product_id' => $pid,
                        'title'      => $title,
                        'mode'       => $mode,
                        'tickets'    => [],  // init
                    ];
                    $sessions[ $pid ]['tickets'][] = [
                        'number' => $n,
                        'status' => isset( $wins_map[ $n ] ) ? 'WIN' : 'LOSE',
                        'prize'  => $wins_map[ $n ] ?? '',
                    ];
                }
            }
        }
    
        // Re-index to numeric
        $sessions = array_values( $sessions );
    
        update_post_meta( $order_id, '_instantwin_sessions', wp_json_encode( $sessions ) );
        update_post_meta( $order_id, '_instantwin_precomputed', 1 );
    }
    

    /*** 4c) AJAX handlers ***/
    public function ajax_reveal_auto()     { $this->process_reveal( intval($_POST['order_id']), 'auto' ); }
    public function ajax_reveal_finalize(){ $this->process_reveal( intval($_POST['order_id']), 'interactive' ); }

    /*** 4e) Output the basic UI ***/
    public function output_reveal_ui( $order_id ) {
        echo '<div id="instantwin-choice" class="instantwin-wrap">';
          echo '<h3>Instant Win!</h3>';
          echo '<button id="btnRevealNow">Reveal Now</button> ';
          echo '<button id="btnPlayGame">Play Game</button>';
          echo '<div id="instantwin-area"></div>';
          echo '<div id="instantwin-wheel-container" class="hidden">';
            echo '<canvas id="instantwin-wheel" width="300" height="300"></canvas>';
            echo '<button id="btnSpin">Spin</button>';
          echo '</div>';
        echo '</div>';
    }

    /*** 4f) Process reveal & notify ***/
   /**
 * Process the reveal, notify & debug
 */
private function process_reveal( $order_id, $mode ) {
    $debug = [];

    // 1) Entry check
    if ( ! $order_id ) {
        wp_send_json_error([
            'msg'   => 'Invalid order.',
            'debug' => ['no order_id passed']
        ]);
    }
    $debug[] = "Received order_id={$order_id}, mode={$mode}";

    // 2) Load order
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error([
            'msg'   => 'Order not found.',
            'debug' => $debug
        ]);
    }
    $debug[] = 'Order loaded successfully.';

    // 3) Prevent double‐reveals
    if ( get_post_meta( $order_id, '_instantwin_revealed_at', true ) ) {
        $debug[] = 'Already revealed, sending already flag.';
        wp_send_json_success([
            'already' => true,
            'debug'   => $debug
        ]);
    }

    // 4) Flag the reveal
    update_post_meta( $order_id, '_instantwin_reveal_mode',  $mode );
    update_post_meta( $order_id, '_instantwin_revealed_at', current_time( 'mysql' ) );
    $debug[] = 'Meta _instantwin_reveal_mode and _instantwin_revealed_at updated.';

    // 5) Call your notification logic
    $debug[] = 'Calling send_win_notification()';
    $this->send_win_notification( $order_id );
    $debug[] = 'Returned from send_win_notification()';

    // 6) Load tickets
    $raw     = get_post_meta( $order_id, '_instantwin_tickets', true );
    $tickets = $raw ? json_decode( $raw, true ) : [];
    $debug[] = 'Loaded ' . count( $tickets ) . ' tickets from meta.';

    // 7) Final response
    $debug[] = 'Sending JSON success.';
    wp_send_json_success([
        'mode'        => $mode,
        'revealed_at' => get_post_meta( $order_id, '_instantwin_revealed_at', true ),
        'tickets'     => $tickets,
        'debug'       => $debug,
    ]);
}

    /**
 * Send Instant Win Notification, credit wallet, add order notes and emails.
 */
public function send_win_notification( $order_id ) {
    if ( ! $order_id ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Avoid running twice
    if ( get_post_meta( $order_id, '_thankyou_action_done', true ) ) {
        return;
    }
    $order->update_meta_data( '_thankyou_action_done', true );
    $order->save();

    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();
        $product    = wc_get_product( $product_id );

        // Only Instant Win products
        if ( ! $product || ! function_exists('have_rows') || ! have_rows( 'instant_tickets_prizes', $product_id ) ) {
            continue;
        }

        $instantWins = get_field( 'instant_tickets_prizes', $product_id );

        // Wrong-answer check
        $wrongAnswer = false;
        foreach ( $item->get_formatted_meta_data() as $meta ) {
            if ( $meta->key === __( 'Answer', 'wc-lottery-pn' )
              && get_option( 'lottery_remove_ticket_wrong_answer', 'no' ) === 'yes'
            ) {
                $correct = array_keys( wc_lottery_pn_get_true_answers( $product_id ) );
                if ( ! in_array( $meta->value, $correct ) ) {
                    $wrongAnswer = true;
                }
            }
        }
        if ( $wrongAnswer ) {
            $order->add_order_note( 'Instant Win skipped – wrong answer.' );
            continue;
        }

        // Loop ticket numbers
        foreach ( $item->get_formatted_meta_data() as $meta ) {
            if ( $meta->key !== 'Ticket number' ) {
                continue;
            }
            $ticket = $meta->value;

            foreach ( $instantWins as $win ) {
                $winning = array_map( 'trim', explode( ',', $win['winning_ticket'] ) );
                if ( in_array( $ticket, $winning ) ) {

                    // Build messages
                    $admin_message = '<p><strong>Instant Win won: ' . esc_html( $win['instant_prize'] ) . '</strong></p>';
                    $user_message  = '<p><strong>Congratulations! You have won: ' . esc_html( $win['instant_prize'] ) . '</strong></p>';

                    // Auto-credit wallet?
                    if ( function_exists( 'woo_wallet' ) && $win['credit_wallet_automatically'] ) {
                        $code = sprintf(
                            'Instant Win! %s, Order: %d, Ticket: %s',
                            $win['instant_prize'], $order_id, $ticket
                        );
                        $dup = false;
                        foreach ( get_wallet_transactions( [ 'user_id' => $order->get_user_id() ] ) as $txn ) {
                            if ( $txn->details === $code ) {
                                $dup = true;
                                break;
                            }
                        }
                        if ( ! $dup ) {
                            woo_wallet()->wallet->credit(
                                $order->get_user_id(),
                                (int) $win['win_credit_amount'],
                                $code
                            );
                        }
                        $amount = get_woocommerce_currency_symbol() . $win['win_credit_amount'];
                        $admin_message .= "<p><strong>Credited {$amount} to customer's wallet.</strong></p>";
                        $user_message  .= "<p><strong>We have credited {$amount} to your wallet.</strong></p>";
                    } else {
                        $user_message .= '<p>We will be in touch within 24 hours.</p>';
                    }

                    // Append order details
                    $admin_message .= '<ul>'
                        . '<li>Competition: ' . esc_html( $product->get_title() ) . '</li>'
                        . '<li>Ticket: '      . esc_html( $ticket )              . '</li>'
                        . '<li>Prize: '       . esc_html( $win['instant_prize'] )  . '</li>'
                        . '<li>Customer: '    . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . '</li>'
                        . '<li>Order #: '     . esc_html( $order_id )             . '</li>'
                        . '<li>Email: '       . esc_html( $order->get_billing_email() ) . '</li>'
                        . '</ul>';
                    $user_message .= '<ul>'
                        . '<li>Ticket: ' . esc_html( $ticket ) . '</li>'
                        . '<li>Prize: '  . esc_html( $win['instant_prize'] ) . '</li>'
                        . '</ul>';

                    // Add an order note for admin
                    $order->add_order_note( $admin_message );

                    // Send admin email
                    $mailer  = WC()->mailer();
                    $wrapped = $mailer->wrap_message( 'New Instant Winner', $admin_message );
                    $html    = ( new WC_Email() )->style_inline( $wrapped );
                    $to      = get_field( 'instant_wins_admin_email_notification', 'options' ) ?: get_bloginfo( 'admin_email' );
                    $mailer->send( $to, "New Instant Winner – Order #{$order_id}", $html, [ 'Content-Type' => 'text/html' ] );

                    // Send customer email
                    $wrapped_u = $mailer->wrap_message( "You're an Instant Winner!", $user_message );
                    $html_u    = ( new WC_Email() )->style_inline( $wrapped_u );
                    $mailer->send( $order->get_billing_email(), "You Won! – Order #{$order_id}", $html_u, [ 'Content-Type' => 'text/html' ] );
                }
            }
        }
    }
}

    /*** 5) Flush rewrite rules ***/
    public function flush_rewrite_rules_on_activate() {
        $this->add_rewrite_endpoint();
        flush_rewrite_rules();
    }
    public function flush_rewrite_rules() {
        flush_rewrite_rules();
    }
}

new WC_Instant_Win_Reveal();