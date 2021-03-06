<?php
/**
 * Displays a cart item in a payment form
 *
 * This template can be overridden by copying it to yourtheme/invoicing/payment-forms/cart-item.php.
 *
 * @version 1.0.19
 */

defined( 'ABSPATH' ) || exit;

do_action( 'getpaid_before_payment_form_cart_item', $form, $item );

$currency = $form->get_currency();

?>
<div class='getpaid-payment-form-items-cart-item px-3 py-2 getpaid-<?php echo $item->is_required() ? 'required'  : 'selectable'; ?> item-<?php echo $item->get_id(); ?>'>
    <div class="form-row">
        <?php foreach ( $columns as $key => $label ) : ?>
            <div class="<?php echo 'name' == $key ? 'col-12 col-sm-5' : 'col-12 col-sm' ?> getpaid-form-cart-item-<?php echo esc_attr( $key ); ?> getpaid-form-cart-item-<?php echo esc_attr( $key ); ?>-<?php echo $item->get_id(); ?>">
                <?php

                    // Item name.
                    if ( 'name' == $key ) {
                        echo sanitize_text_field( $item->get_name() );
                        $description = $item->get_description();

                        if ( ! empty( $description ) ) {
                            $description = wp_kses_post( $description );
                            echo "<small class='form-text text-muted pr-2 m-0'>$description</small>";
                        }

                        $description = getpaid_item_recurring_price_help_text( $item, $currency );

                        if ( $description ) {
                            echo "<small class='form-text text-muted pr-2 m-0'>$description</small>";
                        }
                    }

                    // Item price.
                    if ( 'price' == $key ) {

                        // Set the currency position.
                        $position = wpinv_currency_position();

                        if ( $position == 'left_space' ) {
                            $position = 'left';
                        }

                        if ( $position == 'right_space' ) {
                            $position = 'right';
                        }

                        if ( $item->user_can_set_their_price() ) {
                            $price = max( (float) $item->get_price(), (float) $item->get_minimum_price() );
                            ?>
                                <div class="input-group input-group-sm">
                                    <?php if( 'left' == $position ) : ?>
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><?php echo wpinv_currency_symbol( $currency ); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <input type="text" name="getpaid-items[<?php echo (int) $item->get_id(); ?>][price]" value="<?php echo $price; ?>" placeholder="<?php echo esc_attr( $item->get_minimum_price() ); ?>" class="getpaid-item-price-input p-1 align-middle font-weight-normal shadow-none m-0 rounded-0 text-center border border">

                                    <?php if( 'left' != $position ) : ?>
                                        <div class="input-group-append">
                                            <span class="input-group-text"><?php echo wpinv_currency_symbol( $currency ); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php
                        } else {
                            echo wpinv_price( wpinv_format_amount( $item->get_price() ), $currency );
                            ?>
                                <input name='getpaid-items[<?php echo (int) $item->get_id(); ?>][price]' type='hidden' class='getpaid-item-price-input' value='<?php echo esc_attr( $item->get_price() ); ?>'>
                            <?php
                        }
                    }

                    // Item quantity.
                    if ( 'quantity' == $key ) {

                        if ( $item->allows_quantities() ) {
                            ?>
                                <input name='getpaid-items[<?php echo (int) $item->get_id(); ?>][quantity]' type='number' class='getpaid-item-quantity-input p-1 align-middle font-weight-normal shadow-none m-0 rounded-0 text-center border' value='<?php echo (int) $item->get_qantity(); ?>' min='1' required>
                            <?php
                        } else {
                            echo (int) $item->get_qantity();
                            echo '&nbsp;&nbsp;&nbsp;';
                            ?>
                                <input type='hidden' name='getpaid-items[<?php echo (int) $item->get_id(); ?>][quantity]' class='getpaid-item-quantity-input' value='<?php echo (int) $item->get_qantity(); ?>'>
                            <?php
                        }
                    }

                    // Item sub total.
                    if ( 'subtotal' == $key ) {
                        echo wpinv_price( wpinv_format_amount( $item->get_sub_total() ), $currency );
                    }

                    do_action( "getpaid_payment_form_cart_item_$key", $item, $form );
                ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
do_action(  'getpaid_payment_form_cart_item', $form, $item );
