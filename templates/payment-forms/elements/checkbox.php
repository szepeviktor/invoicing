<?php
/**
 * Displays a checkbox in payment form
 *
 * This template can be overridden by copying it to yourtheme/invoicing/payment-forms/elements/checkbox.php.
 *
 * @version 1.0.19
 */

defined( 'ABSPATH' ) || exit;

echo aui()->input(
    array(
        'type'       => 'checkbox',
        'name'       => esc_attr( $id ),
        'id'         => esc_attr( $id ) . uniqid( '_' ),
        'required'   => ! empty( $required ),
        'label'      => empty( $label ) ? '' : wp_kses_post( $label ),
        'value'      => esc_attr__( 'Yes', 'invoicing' ),
        'help_text'  => empty( $description ) ? '' : wp_kses_post( $description ),
    )
);
