<?php
/**
 * Main Subscriptions class.
 *
 */

defined( 'ABSPATH' ) || exit;
/**
 * Main Subscriptions class.
 *
 */
class WPInv_Subscriptions {

    /**
	 * Class constructor.
	 */
    public function __construct(){

        // Fire gateway specific hooks when a subscription changes.
        add_action( 'getpaid_subscription_status_changed', array( $this, 'process_subscription_status_change' ), 10, 3 );

        // De-activate a subscription whenever the invoice changes payment statuses.
        add_action( 'getpaid_invoice_status_wpi-refunded', array( $this, 'maybe_deactivate_invoice_subscription' ), 20 );
        add_action( 'getpaid_invoice_status_wpi-failed', array( $this, 'maybe_deactivate_invoice_subscription' ), 20 );
        add_action( 'getpaid_invoice_status_wpi-cancelled', array( $this, 'maybe_deactivate_invoice_subscription' ), 20 );
        add_action( 'getpaid_invoice_status_wpi-pending', array( $this, 'maybe_deactivate_invoice_subscription' ), 20 );

        // Handles subscription cancelations.
        add_action( 'getpaid_authenticated_action_subscription_cancel', array( $this, 'user_cancel_single_subscription' ) );

        // Create a subscription whenever an invoice is created, (and update it when it is updated).
        add_action( 'getpaid_new_invoice', array( $this, 'maybe_create_invoice_subscription' ) );
        add_action( 'getpaid_update_invoice', array( $this, 'maybe_update_invoice_subscription' ) );

        // Handles admin subscription update actions.
        add_action( 'getpaid_authenticated_admin_action_update_single_subscription', array( $this, 'admin_update_single_subscription' ) );
        add_action( 'getpaid_authenticated_admin_action_subscription_manual_renew', array( $this, 'admin_renew_single_subscription' ) );
        add_action( 'getpaid_authenticated_admin_action_subscription_manual_delete', array( $this, 'admin_delete_single_subscription' ) );

        // Filter invoice item row actions.
        add_action( 'getpaid-invoice-page-line-item-actions', array( $this, 'filter_invoice_line_item_actions' ), 10, 3 );
    }

    /**
     * Returns an invoice's subscription.
     *
     * @param WPInv_Invoice $invoice
     * @return WPInv_Subscription|bool
     */
    public function get_invoice_subscription( $invoice ) {
        $subscription_id = $invoice->get_subscription_id();

        // Fallback to the parent invoice if the child invoice has no subscription id.
        if ( empty( $subscription_id && $invoice->is_renewal() ) ) {
            $subscription_id = $invoice->get_parent_payment()->get_subscription_id();
        }

        // Fetch the subscription.
        $subscription = new WPInv_Subscription( $subscription_id );

        // Return subscription or use a fallback for backwards compatibility.
        return $subscription->get_id() ? $subscription : wpinv_get_subscription( $invoice );
    }

    /**
     * Deactivates the invoice subscription whenever an invoice status changes.
     * 
     * @param WPInv_Invoice $invoice
     */
    public function maybe_deactivate_invoice_subscription( $invoice ) {

        $subscription = $this->get_invoice_subscription( $invoice );

        // Abort if the subscription is missing or not active.
        if ( empty( $subscription ) || ! $subscription->is_active() ) {
            return;
        }

        $subscription->set_status( 'pending' );
        $subscription->save();

    }

    /**
	 * Processes subscription status changes.
     * 
     * @param WPInv_Subscription $subscription
     * @param string $from
     * @param string $to
	 */
    public function process_subscription_status_change( $subscription, $from, $to ) {

        $gateway = $subscription->get_gateway();

        if ( ! empty( $gateway ) ) {
            $gateway = sanitize_key( $gateway );
            $from    = sanitize_key( $from );
            $to      = sanitize_key( $to );
            do_action( "getpaid_{$gateway}_subscription_$to", $subscription, $from );
        }

    }

    /**
     * Get pretty subscription frequency
     *
     * @param $period
     * @param int $frequency_count The frequency of the period.
     * @deprecated
     * @return mixed|string|void
     */
    public static function wpinv_get_pretty_subscription_frequency( $period, $frequency_count = 1 ) {
        return getpaid_get_subscription_period_label( $period, $frequency_count );
    }

    /**
     * Handles cancellation requests for a subscription
     *
     * @access      public
     * @since       1.0.0
     * @return      void
     */
    public function user_cancel_single_subscription( $data ) {

        // Ensure there is a subscription to cancel.
        if ( empty( $data['subscription'] ) ) {
            return;
        }

        $subscription = new WPInv_Subscription( (int) $data['subscription'] );

        // Ensure that it exists and that it belongs to the current user.
        if ( ! $subscription->get_id() || $subscription->get_customer_id() != get_current_user_id() ) {
            wpinv_set_error( 'invalid_subscription', __( 'You do not have permission to cancel this subscription', 'invoicing' ) );

        // Can it be cancelled.
        } else if ( ! $subscription->can_cancel() ) {
            wpinv_set_error( 'cannot_cancel', __( 'This subscription cannot be cancelled as it is not active.', 'invoicing' ) );
            

        // Cancel it.
        } else {

            $subscription->cancel();
            wpinv_set_error( 'cancelled', __( 'This subscription has been cancelled.', 'invoicing' ), 'info' );
        }

        $redirect = add_query_arg(
            array(
                'getpaid-action' => false,
                'getpaid-nonce'  => false,
            )
        );

        wp_safe_redirect( esc_url( $redirect ) );
        exit;

    }

    /**
     * Creates a subscription for an invoice.
     *
     * @access      public
     * @param       WPInv_Invoice $invoice
     * @since       1.0.0
     */
    public function maybe_create_invoice_subscription( $invoice ) {

        // Abort if it is not recurring.
        if ( $invoice->is_free() || ! $invoice->is_recurring() || $invoice->is_renewal() ) {
            return;
        }

        $subscription = new WPInv_Subscription();
        return $this->update_invoice_subscription( $subscription, $invoice );

    }

    /**
     * (Maybe) Updates a subscription for an invoice.
     *
     * @access      public
     * @param       WPInv_Invoice $invoice
     * @since       1.0.19
     */
    public function maybe_update_invoice_subscription( $invoice ) {

        // Do not process renewals.
        if ( $invoice->is_renewal() ) {
            return;
        }

        // (Maybe) create a new subscription.
        $subscription = $this->get_invoice_subscription( $invoice );
        if ( empty( $subscription ) ) {
            return $this->maybe_create_invoice_subscription( $invoice );
        }

        // Abort if an invoice is paid and already has a subscription.
        if ( $invoice->is_paid() || $invoice->is_refunded() ) {
            return;
        }

        return $this->update_invoice_subscription( $subscription, $invoice );

    }

    /**
     * Updates a subscription for an invoice.
     *
     * @access      public
     * @param       WPInv_Subscription $subscription
     * @param       WPInv_Invoice $invoice
     * @since       1.0.19
     */
    public function update_invoice_subscription( $subscription, $invoice ) {

        // Delete the subscription if an invoice is free or nolonger recurring.
        if ( ! $invoice->is_type( 'invoice' ) || $invoice->is_free() || ! $invoice->is_recurring() ) {
            return $subscription->delete();
        }

        $subscription->set_customer_id( $invoice->get_customer_id() );
        $subscription->set_parent_invoice_id( $invoice->get_id() );
        $subscription->set_initial_amount( $invoice->get_initial_total() );
        $subscription->set_recurring_amount( $invoice->get_recurring_total() );
        $subscription->set_date_created( current_time( 'mysql' ) );
        $subscription->set_status( $invoice->is_paid() ? 'active' : 'pending' );

        // Get the recurring item and abort if it does not exist.
        $subscription_item = $invoice->get_recurring( true );
        if ( ! $subscription_item->get_id() ) {
            $invoice->set_subscription_id(0);
            $invoice->save();
            return $subscription->delete();
        }

        $subscription->set_product_id( $subscription_item->get_id() );
        $subscription->set_period( $subscription_item->get_recurring_period( true ) );
        $subscription->set_frequency( $subscription_item->get_recurring_interval() );
        $subscription->set_bill_times( $subscription_item->get_recurring_limit() );

        // Calculate the next renewal date.
        $period       = $subscription_item->get_recurring_period( true );
        $interval     = $subscription_item->get_recurring_interval();

        // If the subscription item has a trial period...
        if ( $subscription_item->has_free_trial() ) {
            $period   = $subscription_item->get_trial_period( true );
            $interval = $subscription_item->get_trial_interval();
            $subscription->set_trial_period( $interval . ' ' . $period );
            $subscription->set_status( 'trialling' );
        }

        // If initial amount is free, treat it as a free trial even if the subscription item does not have a free trial.
        if ( $invoice->has_free_trial() ) {
            $subscription->set_trial_period( $interval . ' ' . $period );
            $subscription->set_status( 'trialling' );
        }

        // Calculate the next renewal date.
        $expiration = date( 'Y-m-d H:i:s', strtotime( "+$interval $period", strtotime( $subscription->get_date_created() ) ) );

        $subscription->set_next_renewal_date( $expiration );
        return $subscription->save();

    }

    /**
     * Fired when an admin updates a subscription via the single subscription single page.
     *
     * @param       array $data
     * @since       1.0.19
     */
    public function admin_update_single_subscription( $args ) {

        // Ensure the subscription exists and that a status has been given.
        if ( empty( $args['subscription_id'] ) || empty( $args['subscription_status'] ) ) {
            return;
        }

        // Retrieve the subscriptions.
        $subscription = new WPInv_Subscription( $args['subscription_id'] );

        if ( $subscription->get_id() ) {

            $subscription->set_status( $args['subscription_status'] );
            $subscription->save();
            getpaid_admin()->show_info( __( 'Your changes have been saved', 'invoicing' ) );

        }

    }

    /**
     * Fired when an admin manually renews a subscription.
     *
     * @param       array $data
     * @since       1.0.19
     */
    public function admin_renew_single_subscription( $args ) {

        // Ensure the subscription exists and that a status has been given.
        if ( empty( $args['id'] ) ) {
            return;
        }

        // Retrieve the subscriptions.
        $subscription = new WPInv_Subscription( $args['id'] );

        if ( $subscription->get_id() ) {

            do_action( 'getpaid_admin_renew_subscription', $subscription );

            $args = array( 'transaction_id', $subscription->get_parent_invoice()->generate_key( 'renewal_' ) );

            if ( ! $subscription->add_payment( $args ) ) {
                getpaid_admin()->show_error( __( 'We are unable to renew this subscription as the parent invoice does not exist.', 'invoicing' ) );
            } else {
                $subscription->renew();
                getpaid_admin()->show_info( __( 'This subscription has been renewed and extended.', 'invoicing' ) );
            } 

            wp_safe_redirect(
                add_query_arg(
                    array(
                        'getpaid-admin-action' => false,
                        'getpaid-nonce'        => false,
                    )
                )
            );
            exit;

        }

    }

    /**
     * Fired when an admin manually deletes a subscription.
     *
     * @param       array $data
     * @since       1.0.19
     */
    public function admin_delete_single_subscription( $args ) {

        // Ensure the subscription exists and that a status has been given.
        if ( empty( $args['id'] ) ) {
            return;
        }

        // Retrieve the subscriptions.
        $subscription = new WPInv_Subscription( $args['id'] );

        if ( $subscription->delete() ) {
            getpaid_admin()->show_info( __( 'This subscription has been deleted.', 'invoicing' ) );
        } else {
            getpaid_admin()->show_error( __( 'We are unable to delete this subscription. Please try again.', 'invoicing' ) );
        }
    
        $redirected = wp_safe_redirect(
            add_query_arg(
                array(
                    'getpaid-admin-action' => false,
                    'getpaid-nonce'        => false,
                    'id'                   => false,
                )
            )
        );

        if ( $redirected ) {
            exit;
        }

    }

    /**
     * Filters the invoice line items actions.
     *
     * @param array actions
     * @param WPInv_Item $item
     * @param WPInv_Invoice $invoice
     */
    public function filter_invoice_line_item_actions( $actions, $item, $invoice ) {

        // Fetch item subscription.
        $args  = array(
            'invoice_in'  => $invoice->is_parent() ? $invoice->get_id() : $invoice->get_parent_id(),
            'product_in'  => $item->get_id(),
            'number'      => 1,
            'count_total' => false,
            'fields'      => 'id',
        );

        $subscription = new GetPaid_Subscriptions_Query( $args );
        $subscription = $subscription->get_results();

        // In case we found a match...
        if ( ! empty( $subscription ) ) {
            $url                     = esc_url( add_query_arg( 'subscription', (int) $subscription[0], get_permalink( (int) wpinv_get_option( 'invoice_subscription_page' ) ) ) );
            $actions['subscription'] = "<a href='$url' class='text-decoration-none'>" . __( 'Manage Subscription', 'invoicing' ) . '</a>';
        }

        return $actions;

    }

}
