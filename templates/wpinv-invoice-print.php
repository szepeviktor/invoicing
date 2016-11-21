<?php 
if ( !defined('ABSPATH') ) {
    exit;
}
global $post;
$invoice_id = $post->ID;
$invoice = wpinv_get_invoice( $invoice_id );
if ( empty( $invoice ) ) {
    exit;
}
do_action( 'wpinv_invoice_print_before_display', $invoice ); ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <title><?php wp_title() ?></title>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">

    <?php do_action( 'wpinv_invoice_print_head', $invoice ); ?>
</head>
<body class="body wpinv wpinv-print">
    <?php do_action( 'wpinv_invoice_print_body_start', $invoice ); ?>
    <div class="container wpinv-wrap">
        <?php if ( $watermark = wpinv_watermark( $invoice_id ) ) { ?>
            <div class="watermark no-print"><p><?php echo esc_html( $watermark ) ?></p></div>
        <?php } ?>
        <!-- ///// Start PDF header -->
        <htmlpageheader name="wpinv-pdf-header">
            <?php do_action( 'wpinv_invoice_print_before_header', $invoice ); ?>
            <div class="row wpinv-header">
                <div class="col-xs-6 wpinv-business">
                    <a target="_blank" href="<?php echo esc_url( wpinv_get_business_website() ); ?>">
                        <?php if ( $logo = wpinv_get_business_logo() ) { ?>
                        <img class="logo" src="<?php echo esc_url( $logo ); ?>">
                        <?php } else { ?>
                        <h1><?php echo esc_html( wpinv_get_business_name() ); ?></h1>
                        <?php } ?>
                    </a>
                </div>

                <div class="col-xs-6 wpinv-title">
                    <h2><?php echo esc_html( _e( 'Invoice', 'invoicing' ) ); ?></h2>
                </div>
            </div>
            <?php do_action( 'wpinv_invoice_print_after_header', $invoice ); ?>
        </htmlpageheader>
        <!-- End PDF header ///// -->
        
        <?php do_action( 'wpinv_invoice_print_before_addresses', $invoice ); ?>
        <div class="row wpinv-addresses">
            <div class="col-xs-12 col-sm-6 wpinv-address wpinv-from-address">
                <?php wpinv_display_from_address(); ?>
            </div>
            <div class="col-xs-12 col-sm-6 wpinv-address wpinv-to-address">
                <?php wpinv_display_to_address( $invoice_id ); ?>
            </div>
        </div>
        <?php do_action( 'wpinv_invoice_print_after_addresses', $invoice ); ?>
        
        <?php do_action( 'wpinv_invoice_print_before_details', $invoice ); ?>
        <div class="row wpinv-details">
            <div class="col-xs-12 wpinv-line-details">
                <?php wpinv_display_invoice_details( $invoice ); ?>
            </div>
        </div>
        <?php do_action( 'wpinv_invoice_print_after_details', $invoice ); ?>

        <?php do_action( 'wpinv_invoice_print_middle', $invoice ); ?>
        
        <?php do_action( 'wpinv_invoice_print_before_line_items', $invoice ); ?>
        <div class="row wpinv-items">
            <div class="col-sm-12 wpinv-line-items">
                <?php wpinv_display_line_items( $invoice_id ); ?>
            </div>
        </div>
        <?php do_action( 'wpinv_invoice_print_after_line_items', $invoice ); ?>
        
        <!-- ///// Start PDF footer -->
        <htmlpagefooter name="wpinv-pdf-footer">
            <?php do_action( 'wpinv_invoice_print_before_footer', $invoice ); ?>
            <div class="row wpinv-footer">
                <div class="col-sm-12">
                    <?php if ( $term_text = wpinv_get_terms_text() ) { ?>
                    <div class="terms-text"><?php echo wpautop( $term_text ); ?></div>
                    <?php } ?>
                    <div class="footer-text"><?php echo wpinv_get_business_footer(); ?></div>
                    <div class="print-only"><?php _e( 'Page ', 'invoicing' ) ?> {PAGENO}/{nbpg}</div>
                </div>
            </div>
            <?php do_action( 'wpinv_invoice_print_after_footer', $invoice ); ?>
        </htmlpagefooter>
        <!-- End PDF footer ///// -->
    </div><!-- END wpinv-wrap -->
    <?php do_action( 'wpinv_invoice_print_body_end', $invoice ); ?>
</body>
</html>