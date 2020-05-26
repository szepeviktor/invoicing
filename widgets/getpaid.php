<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * getpaid button widget.
 *
 */
class WPInv_GetPaid_Widget extends WP_Super_Duper {

    /**
     * Register the widget with WordPress.
     *
     */
    public function __construct() {

        $options = array(
            'textdomain'    => 'invoicing',
            'block-icon'    => 'admin-site',
            'block-category'=> 'widgets',
            'block-keywords'=> "['invoicing','buy', 'buy item', 'form']",
            'class_name'     => __CLASS__,
            'base_id'       => 'getpaid',
            'name'          => __('GetPaid','invoicing'),
            'widget_ops'    => array(
                'classname'   => 'getpaid bsui',
                'description' => esc_html__('Show payment forms or buttons.','invoicing'),
            ),
            'arguments'     => array(

                'title'  => array(
                    'title'       => __( 'Widget title', 'invoicing' ),
                    'desc'        => __( 'Enter widget title.', 'invoicing' ),
                    'type'        => 'text',
                    'desc_tip'    => true,
                    'default'     => '',
                    'advanced'    => false
				),

                'form'  => array(
	                'title'       => __( 'Form', 'invoicing' ),
	                'desc'        => __( 'Enter a form id in case you want to display a specific payment form', 'invoicing' ),
	                'type'        => 'text',
	                'desc_tip'    => true,
	                'default'     => '',
	                'placeholder' => __('1','invoicing'),
	                'advanced'    => false
				),

				'item'  => array(
	                'title'       => __( 'Items', 'invoicing' ),
	                'desc'        => __( 'Enter comma separated list of invoicing item id and quantity (item_id|quantity). Ex. 101|2. This will be ignored in case you specify a form above. Enter 0 as the quantity to let users select their own quantities', 'invoicing' ),
	                'type'        => 'text',
	                'desc_tip'    => true,
	                'default'     => '',
	                'placeholder' => __('1','invoicing'),
	                'advanced'    => false
				),

                'button'  => array(
	                'title'       => __( 'Button', 'invoicing' ),
	                'desc'        => __( 'Enter button label in case you would like to display the forms in a popup.', 'invoicing' ),
	                'type'        => 'text',
	                'desc_tip'    => true,
	                'default'     => '',
	                'advanced'    => false
				)

            )

        );


        parent::__construct( $options );
    }

	/**
	 * The Super block output function.
	 *
	 * @param array $args
	 * @param array $widget_args
	 * @param string $content
	 *
	 * @return string
	 */
    public function output( $args = array(), $widget_args = array(), $content = '' ) {

	    // Is the shortcode set up correctly?
		if ( empty( $args['form'] ) && empty( $args['item'] ) ) {
			return aui()->alert(
				array(
					'type'    => 'warning',
					'content' => __( 'No payment form or item selected', 'invoicing' ),
				)
			);
		}

		// Payment form or button?
		if ( ! empty( $args['form'] ) ) {
			return $this->handle_payment_form(  $args );
		} else {
			return $this->handle_buy_item(  $args );
		}

	}

	/**
	 * Displaying a payment form
	 *
	 * @return string
	 */
    protected function handle_payment_form( $args = array() ) {

		if ( empty( $args['button'] ) ) {
			return getpaid_display_payment_form( $args['form'] );
		}

		return $this->payment_form_button( $args['form'], $args['button'] );
	}

	/**
	 * Displays a payment form button.
	 *
	 * @return string
	 */
    protected function payment_form_button( $form, $button ) {
		$label = sanitize_text_field( $button );
		$form  = esc_attr( $form );
		$nonce = wp_create_nonce('getpaid_ajax_form');
		return "<button class='btn btn-primary getpaid-payment-button' type='button' data-nonce='$nonce' data-form='$form'>$label</button>";
	}

	/**
	 * Selling an item
	 *
	 * @return string
	 */
    protected function handle_buy_item( $args = array() ) {

		if ( empty( $args['button'] ) ) {
			return $this->buy_item_form( $args['item'] );
		}

		return $this->buy_item_button( $args['item'], $args['button'] );

	}

	/**
	 * Displays a buy item form.
	 *
	 * @return string
	 */
    protected function buy_item_form( $item ) {
		$items = getpaid_convert_items_to_array( $item );
		return getpaid_display_item_payment_form( $items );
	}

	/**
	 * Displays a buy item button.
	 *
	 * @return string
	 */
    protected function buy_item_button( $item, $button ) {
		$label = sanitize_text_field( $button );
		$item  = esc_attr( $item );
		$nonce = wp_create_nonce('getpaid_ajax_form');
		return "<button class='btn btn-primary getpaid-payment-button' type='button' data-nonce='$nonce' data-item='$item'>$label</button>";
    }

}