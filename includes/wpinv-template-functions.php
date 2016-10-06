<?php
/**
 * Contains functions related to Invoicing plugin.
 *
 * @since 1.0.0
 * @package Invoicing
 */
 
// MUST have WordPress.
if ( !defined( 'WPINC' ) ) {
    exit( 'Do NOT access this file directly: ' . basename( __FILE__ ) );
}

if ( !is_admin() ) {
    add_filter( 'single_template', 'wpinv_template', 10, 1 );
    add_action( 'wpinv_invoice_after_body', 'wpinv_display_invoice_top_bar' );
    add_action( 'wpinv_invoice_top_bar_left', 'wpinv_invoice_display_left_actions' );
    add_action( 'wpinv_invoice_top_bar_right', 'wpinv_invoice_display_right_actions' );
}

function wpinv_template_path() {
    return apply_filters( 'wpinv_template_path', 'invoicing/' );
}

function wpinv_display_invoice_top_bar( $invoice ) {
    if ( empty( $invoice ) ) {
        return;
    }
    ?>
    <div class="row wpinv-top-bar no-print">
        <div class="container">
            <div class="col-xs-6">
                <?php do_action( 'wpinv_invoice_top_bar_left', $invoice );?>
            </div>
            <div class="col-xs-6 text-right">
                <?php do_action( 'wpinv_invoice_top_bar_right', $invoice );?>
            </div>
        </div>
    </div>
    <?php
}

function wpinv_invoice_display_left_actions( $invoice ) {
    if ( empty( $invoice ) ) {
        return;
    }
    
    $user_id = (int)$invoice->get_user_id();
    $current_user_id = (int)get_current_user_id();
    
    if ( $user_id > 0 && $user_id == $current_user_id && $invoice->needs_payment() ) {
    ?>
    <a class="btn btn-success btn-sm" title="<?php esc_attr_e( 'Pay This Invoice', 'invoicing' ); ?>" href="<?php echo esc_url( $invoice->get_checkout_payment_url() ); ?>"><?php _e( 'Pay For Invoice', 'invoicing' ); ?></a>
    <?php
    }
}

function wpinv_invoice_display_right_actions( $invoice ) {
    if ( empty( $invoice ) ) {
        return;
    }
    
    $user_id = (int)$invoice->get_user_id();
    $current_user_id = (int)get_current_user_id();
    
    if ( $user_id > 0 && $user_id == $current_user_id ) {
    ?>
    <a class="btn btn-primary btn-sm" href="<?php echo esc_url( $invoice->get_view_invoice_url() ); ?>"><?php _e( 'View Invoice', 'invoicing' ); ?></a>
    <a class="btn btn-primary btn-sm" href="<?php echo esc_url( wpinv_get_history_page_uri() ); ?>"><?php _e( 'Invoice History', 'invoicing' ); ?></a>
    <?php
    }
}

function wpinv_before_invoice_content( $content ) {
    global $post;

    if ( $post && $post->post_type == 'wpi_invoice' && is_singular( 'wpi_invoice' ) && is_main_query() ) {
        ob_start();
        do_action( 'wpinv_before_invoice_content', $post->ID );
        $content = ob_get_clean() . $content;
    }

    return $content;
}
add_filter( 'the_content', 'wpinv_before_invoice_content' );

function wpinv_after_invoice_content( $content ) {
    global $post;

    if ( $post && $post->post_type == 'wpi_invoice' && is_singular( 'wpi_invoice' ) && is_main_query() ) {
        ob_start();
        do_action( 'wpinv_after_invoice_content', $post->ID );
        $content .= ob_get_clean();
    }

    return $content;
}
add_filter( 'the_content', 'wpinv_after_invoice_content' );

function wpinv_get_templates_dir() {
    return WPINV_PLUGIN_DIR . 'templates';
}

function wpinv_get_templates_url() {
    return WPINV_PLUGIN_URL . 'templates';
}

function wpinv_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
    if ( ! empty( $args ) && is_array( $args ) ) {
		extract( $args );
	}

	$located = wpinv_locate_template( $template_name, $template_path, $default_path );
	if ( ! file_exists( $located ) ) {
        _doing_it_wrong( __FUNCTION__, sprintf( '<code>%s</code> does not exist.', $located ), '2.1' );
		return;
	}

	// Allow 3rd party plugin filter template file from their plugin.
	$located = apply_filters( 'wpinv_get_template', $located, $template_name, $args, $template_path, $default_path );

	do_action( 'wpinv_before_template_part', $template_name, $template_path, $located, $args );

	include( $located );

	do_action( 'wpinv_after_template_part', $template_name, $template_path, $located, $args );
}

function wpinv_get_template_html( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
	ob_start();
	wpinv_get_template( $template_name, $args, $template_path, $default_path );
	return ob_get_clean();
}

function wpinv_locate_template( $template_name, $template_path = '', $default_path = '' ) {
    if ( ! $template_path ) {
        $template_path = wpinv_template_path();
    }

    if ( ! $default_path ) {
        $default_path = WPINV_PLUGIN_DIR . 'templates/';
    }

    // Look within passed path within the theme - this is priority.
    $template = locate_template(
        array(
            trailingslashit( $template_path ) . $template_name,
            $template_name
        )
    );

    // Get default templates/
    if ( !$template && $default_path ) {
        $template = trailingslashit( $default_path ) . $template_name;
    }

    // Return what we found.
    return apply_filters( 'wpinv_locate_template', $template, $template_name, $template_path );
}

function wpinv_get_template_part( $slug, $name = null, $load = true ) {
	do_action( 'get_template_part_' . $slug, $slug, $name );

	// Setup possible parts
	$templates = array();
	if ( isset( $name ) )
		$templates[] = $slug . '-' . $name . '.php';
	$templates[] = $slug . '.php';

	// Allow template parts to be filtered
	$templates = apply_filters( 'wpinv_get_template_part', $templates, $slug, $name );

	// Return the part that is found
	return wpinv_locate_tmpl( $templates, $load, false );
}

function wpinv_locate_tmpl( $template_names, $load = false, $require_once = true ) {
	// No file found yet
	$located = false;

	// Try to find a template file
	foreach ( (array)$template_names as $template_name ) {

		// Continue if template is empty
		if ( empty( $template_name ) )
			continue;

		// Trim off any slashes from the template name
		$template_name = ltrim( $template_name, '/' );

		// try locating this template file by looping through the template paths
		foreach( wpinv_get_theme_template_paths() as $template_path ) {

			if( file_exists( $template_path . $template_name ) ) {
				$located = $template_path . $template_name;
				break;
			}
		}

		if( $located ) {
			break;
		}
	}

	if ( ( true == $load ) && ! empty( $located ) )
		load_template( $located, $require_once );

	return $located;
}

function wpinv_get_theme_template_paths() {
	$template_dir = wpinv_get_theme_template_dir_name();

	$file_paths = array(
		1 => trailingslashit( get_stylesheet_directory() ) . $template_dir,
		10 => trailingslashit( get_template_directory() ) . $template_dir,
		100 => wpinv_get_templates_dir()
	);

	$file_paths = apply_filters( 'wpinv_template_paths', $file_paths );

	// sort the file paths based on priority
	ksort( $file_paths, SORT_NUMERIC );

	return array_map( 'trailingslashit', $file_paths );
}

function wpinv_get_theme_template_dir_name() {
	return trailingslashit( apply_filters( 'wpinv_templates_dir', 'wpinv_templates' ) );
}

function wpinv_checkout_meta_tags() {

	$pages   = array();
	$pages[] = wpinv_get_option( 'success_page' );
	$pages[] = wpinv_get_option( 'failure_page' );
	$pages[] = wpinv_get_option( 'invoice_history_page' );

	if( !wpinv_is_checkout() && !is_page( $pages ) ) {
		return;
	}

	echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
}
add_action( 'wp_head', 'wpinv_checkout_meta_tags' );

function wpinv_add_body_classes( $class ) {
	$classes = (array)$class;

	if( wpinv_is_checkout() ) {
		$classes[] = 'wpinv-checkout';
		$classes[] = 'wpinv-page';
	}

	if( wpinv_is_success_page() ) {
		$classes[] = 'wpinv-success';
		$classes[] = 'wpinv-page';
	}

	if( wpinv_is_failed_transaction_page() ) {
		$classes[] = 'wpinv-failed-transaction';
		$classes[] = 'wpinv-page';
	}

	if( wpinv_is_invoice_history_page() ) {
		$classes[] = 'wpinv-history';
		$classes[] = 'wpinv-page';
	}

	if( wpinv_is_test_mode() ) {
		$classes[] = 'wpinv-test-mode';
		$classes[] = 'wpinv-page';
	}

	return array_unique( $classes );
}
add_filter( 'body_class', 'wpinv_add_body_classes' );

function wpinv_html_dropdown( $name = 'wpinv_discounts', $selected = 0, $status = '' ) {
    $args = array( 'nopaging' => true );

    if ( ! empty( $status ) )
        $args['post_status'] = $status;

    $discounts = wpinv_get_discounts( $args );
    $options   = array();

    if ( $discounts ) {
        foreach ( $discounts as $discount ) {
            $options[ absint( $discount->ID ) ] = esc_html( get_the_title( $discount->ID ) );
        }
    } else {
        $options[0] = __( 'No discounts found', 'invoicing' );
    }

    $output = $this->select( array(
        'name'             => $name,
        'selected'         => $selected,
        'options'          => $options,
        'show_option_all'  => false,
        'show_option_none' => false,
    ) );

    return $output;
}

function wpinv_html_year_dropdown( $name = 'year', $selected = 0, $years_before = 5, $years_after = 0 ) {
    $current     = date( 'Y' );
    $start_year  = $current - absint( $years_before );
    $end_year    = $current + absint( $years_after );
    $selected    = empty( $selected ) ? date( 'Y' ) : $selected;
    $options     = array();

    while ( $start_year <= $end_year ) {
        $options[ absint( $start_year ) ] = $start_year;
        $start_year++;
    }

    $output = $this->select( array(
        'name'             => $name,
        'selected'         => $selected,
        'options'          => $options,
        'show_option_all'  => false,
        'show_option_none' => false
    ) );

    return $output;
}

function wpinv_html_month_dropdown( $name = 'month', $selected = 0 ) {
    $month   = 1;
    $options = array();
    $selected = empty( $selected ) ? date( 'n' ) : $selected;

    while ( $month <= 12 ) {
        $options[ absint( $month ) ] = wpinv_month_num_to_name( $month );
        $month++;
    }

    $output = $this->select( array(
        'name'             => $name,
        'selected'         => $selected,
        'options'          => $options,
        'show_option_all'  => false,
        'show_option_none' => false
    ) );

    return $output;
}

function wpinv_html_select( $args = array() ) {
    $defaults = array(
        'options'          => array(),
        'name'             => null,
        'class'            => '',
        'id'               => '',
        'selected'         => 0,
        'chosen'           => false,
        'placeholder'      => null,
        'multiple'         => false,
        'show_option_all'  => _x( 'All', 'all dropdown items', 'invoicing' ),
        'show_option_none' => _x( 'None', 'no dropdown items', 'invoicing' ),
        'data'             => array(),
        'onchange'         => null,
        'required'         => false,
    );

    $args = wp_parse_args( $args, $defaults );

    $data_elements = '';
    foreach ( $args['data'] as $key => $value ) {
        $data_elements .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
    }

    if( $args['multiple'] ) {
        $multiple = ' MULTIPLE';
    } else {
        $multiple = '';
    }

    if( $args['chosen'] ) {
        $args['class'] .= ' wpinv-select-chosen';
    }

    if( $args['placeholder'] ) {
        $placeholder = $args['placeholder'];
    } else {
        $placeholder = '';
    }
    
    $options = '';
    if( !empty( $args['onchange'] ) ) {
        $options .= ' onchange="' . esc_attr( $args['onchange'] ) . '"';
    }
    
    if( !empty( $args['required'] ) ) {
        $options .= ' required="required"';
    }

    $class  = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $args['class'] ) ) );
    $output = '<select name="' . esc_attr( $args['name'] ) . '" id="' . esc_attr( $args['id'] ) . '" class="wpinv-select ' . $class . '"' . $multiple . ' data-placeholder="' . $placeholder . '" ' . trim( $options ) . $data_elements . '>';

    if ( $args['show_option_all'] ) {
        if( $args['multiple'] ) {
            $selected = selected( true, in_array( 0, $args['selected'] ), false );
        } else {
            $selected = selected( $args['selected'], 0, false );
        }
        $output .= '<option value="all"' . $selected . '>' . esc_html( $args['show_option_all'] ) . '</option>';
    }

    if ( !empty( $args['options'] ) ) {

        if ( $args['show_option_none'] ) {
            if( $args['multiple'] ) {
                $selected = selected( true, in_array( "", $args['selected'] ), false );
            } else {
                $selected = selected( $args['selected'] === "", true, false );
            }
            $output .= '<option value=""' . $selected . '>' . esc_html( $args['show_option_none'] ) . '</option>';
        }

        foreach( $args['options'] as $key => $option ) {

            if( $args['multiple'] && is_array( $args['selected'] ) ) {
                $selected = selected( true, (bool)in_array( $key, $args['selected'] ), false );
            } else {
                $selected = selected( $args['selected'], $key, false );
            }

            $output .= '<option value="' . esc_attr( $key ) . '"' . $selected . '>' . esc_html( $option ) . '</option>';
        }
    }

    $output .= '</select>';

    return $output;
}

function wpinv_item_dropdown( $args = array() ) {
    $defaults = array(
        'name'        => 'wpi_item',
        'id'          => 'wpi_item',
        'class'       => '',
        'multiple'    => false,
        'selected'    => 0,
        'chosen'      => false,
        'number'      => 100,
        'placeholder' => __( 'Choose a item', 'invoicing' ),
        'data'        => array( 'search-type' => 'item' ),
        'show_option_all'  => false,
        'show_option_none' => false,
        'with_packages' => true,
    );

    $args = wp_parse_args( $args, $defaults );

    $item_args = array(
        'post_type'      => 'wpi_item',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'posts_per_page' => $args['number']
    );
    
    if ( !$args['with_packages'] ) {
        $item_args['meta_query'] = array(
            array(
                'key'       => '_wpinv_type',
                'compare'   => '!=',
                'value'     => 'package'
            ),
        );
    }

    $items      = get_posts( $item_args );
    $options    = array();
    //$options[0] = '';
    if ( $items ) {
        foreach ( $items as $item ) {
            $options[ absint( $item->ID ) ] = esc_html( $item->post_title );
        }
    }

    // This ensures that any selected items are included in the drop down
    if( is_array( $args['selected'] ) ) {
        foreach( $args['selected'] as $item ) {
            if( ! in_array( $item, $options ) ) {
                $options[$item] = get_the_title( $item );
            }
        }
    } elseif ( is_numeric( $args['selected'] ) && $args['selected'] !== 0 ) {
        if ( ! in_array( $args['selected'], $options ) ) {
            $options[$args['selected']] = get_the_title( $args['selected'] );
        }
    }

    $output = wpinv_html_select( array(
        'name'             => $args['name'],
        'selected'         => $args['selected'],
        'id'               => $args['id'],
        'class'            => $args['class'],
        'options'          => $options,
        'chosen'           => $args['chosen'],
        'multiple'         => $args['multiple'],
        'placeholder'      => $args['placeholder'],
        'show_option_all'  => $args['show_option_all'],
        'show_option_none' => $args['show_option_none'],
        'data'             => $args['data'],
    ) );

    return $output;
}

function wpinv_html_checkbox( $args = array() ) {
    $defaults = array(
        'name'     => null,
        'current'  => null,
        'class'    => 'wpinv-checkbox',
        'options'  => array(
            'disabled' => false,
            'readonly' => false
        )
    );

    $args = wp_parse_args( $args, $defaults );

    $class = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $args['class'] ) ) );
    $options = '';
    if ( ! empty( $args['options']['disabled'] ) ) {
        $options .= ' disabled="disabled"';
    } elseif ( ! empty( $args['options']['readonly'] ) ) {
        $options .= ' readonly';
    }

    $output = '<input type="checkbox"' . $options . ' name="' . esc_attr( $args['name'] ) . '" id="' . esc_attr( $args['name'] ) . '" class="' . $class . ' ' . esc_attr( $args['name'] ) . '" ' . checked( 1, $args['current'], false ) . ' />';

    return $output;
}

function wpinv_html_text( $args = array() ) {
    // Backwards compatibility
    if ( func_num_args() > 1 ) {
        $args = func_get_args();

        $name  = $args[0];
        $value = isset( $args[1] ) ? $args[1] : '';
        $label = isset( $args[2] ) ? $args[2] : '';
        $desc  = isset( $args[3] ) ? $args[3] : '';
    }

    $defaults = array(
        'id'           => '',
        'name'         => isset( $name )  ? $name  : 'text',
        'value'        => isset( $value ) ? $value : null,
        'label'        => isset( $label ) ? $label : null,
        'desc'         => isset( $desc )  ? $desc  : null,
        'placeholder'  => '',
        'class'        => 'regular-text',
        'disabled'     => false,
        'readonly'     => false,
        'required'     => false,
        'autocomplete' => '',
        'data'         => false
    );

    $args = wp_parse_args( $args, $defaults );

    $class = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $args['class'] ) ) );
    $options = '';
    if( $args['required'] ) {
        $options .= ' required="required"';
    }
    if( $args['readonly'] ) {
        $options .= ' readonly';
    }
    if( $args['readonly'] ) {
        $options .= ' readonly';
    }

    $data = '';
    if ( !empty( $args['data'] ) ) {
        foreach ( $args['data'] as $key => $value ) {
            $data .= 'data-' . wpinv_sanitize_key( $key ) . '="' . esc_attr( $value ) . '" ';
        }
    }

    $output = '<span id="wpinv-' . wpinv_sanitize_key( $args['name'] ) . '-wrap">';
    $output .= '<label class="wpinv-label" for="' . wpinv_sanitize_key( $args['id'] ) . '">' . esc_html( $args['label'] ) . '</label>';
    if ( ! empty( $args['desc'] ) ) {
        $output .= '<span class="wpinv-description">' . esc_html( $args['desc'] ) . '</span>';
    }

    $output .= '<input type="text" name="' . esc_attr( $args['name'] ) . '" id="' . esc_attr( $args['id'] )  . '" autocomplete="' . esc_attr( $args['autocomplete'] )  . '" value="' . esc_attr( $args['value'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '" class="' . $class . '" ' . $data . ' ' . trim( $options ) . '/>';

    $output .= '</span>';

    return $output;
}

function wpinv_html_date_field( $args = array() ) {
    if( empty( $args['class'] ) ) {
        $args['class'] = 'wpinv_datepicker';
    } elseif( ! strpos( $args['class'], 'wpinv_datepicker' ) ) {
        $args['class'] .= ' wpinv_datepicker';
    }

    return $this->text( $args );
}

function wpinv_html_textarea( $args = array() ) {
    $defaults = array(
        'name'        => 'textarea',
        'value'       => null,
        'label'       => null,
        'desc'        => null,
        'class'       => 'large-text',
        'disabled'    => false
    );

    $args = wp_parse_args( $args, $defaults );

    $class = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $args['class'] ) ) );
    $disabled = '';
    if( $args['disabled'] ) {
        $disabled = ' disabled="disabled"';
    }

    $output = '<span id="wpinv-' . wpinv_sanitize_key( $args['name'] ) . '-wrap">';
    $output .= '<label class="wpinv-label" for="' . wpinv_sanitize_key( $args['name'] ) . '">' . esc_html( $args['label'] ) . '</label>';
    $output .= '<textarea name="' . esc_attr( $args['name'] ) . '" id="' . wpinv_sanitize_key( $args['name'] ) . '" class="' . $class . '"' . $disabled . '>' . esc_attr( $args['value'] ) . '</textarea>';

    if ( ! empty( $args['desc'] ) ) {
        $output .= '<span class="wpinv-description">' . esc_html( $args['desc'] ) . '</span>';
    }
    $output .= '</span>';

    return $output;
}

function wpinv_html_ajax_user_search( $args = array() ) {
    $defaults = array(
        'name'        => 'user_id',
        'value'       => null,
        'placeholder' => __( 'Enter username', 'invoicing' ),
        'label'       => null,
        'desc'        => null,
        'class'       => '',
        'disabled'    => false,
        'autocomplete'=> 'off',
        'data'        => false
    );

    $args = wp_parse_args( $args, $defaults );

    $args['class'] = 'wpinv-ajax-user-search ' . $args['class'];

    $output  = '<span class="wpinv_user_search_wrap">';
        $output .= $this->text( $args );
        $output .= '<span class="wpinv_user_search_results hidden"><a class="wpinv-ajax-user-cancel" title="' . __( 'Cancel', 'invoicing' ) . '" aria-label="' . __( 'Cancel', 'invoicing' ) . '" href="#">x</a><span></span></span>';
    $output .= '</span>';

    return $output;
}

function wpinv_ip_map_location() {
    global $wpinv_options;

    $ip = !empty( $_GET['ip'] ) ? sanitize_text_field( $_GET['ip'] ) : '';

    $output = '';
    $latitude = '';
    $longitude = '';
    $address = '';
    try {
        $xml = simplexml_load_file( "http://www.geoplugin.net/xml.gp?ip=" . $ip );
            
        if ( !empty( $xml ) && isset( $xml->geoplugin_countryCode ) && !empty( $xml->geoplugin_latitude ) && !empty( $xml->geoplugin_longitude ) ) {
            $latitude = $xml->geoplugin_latitude;
            $longitude = $xml->geoplugin_longitude;
            $geoplugin_credit = $xml->geoplugin_credit;
            $address = $xml->geoplugin_city. ', ' . $xml->geoplugin_regionName. ', ' . $xml->geoplugin_countryName. ' (' . $xml->geoplugin_countryCode . ')';
            $output = "<p>Location: $address, Currency: $xml->geoplugin_currencyCode ($xml->geoplugin_currencySymbol)</p>";
            $output .= "<p>Produced using information from <a href=\"http://www.geoplugin.net\" target=\"_blank\">geoplugin.net</a>";
            $output .= "<br/>$xml->geoplugin_credit</p>";
        } else {
            $output =  "Unable to find information for '$ip'";
        }
    } catch( Exception $e ) {
        wpinv_error_log( "AddressNotFoundException: " . $e->getMessage() ); 
        $output = "Unable to find information for IP address: $ip";
    }
    ?>
<!DOCTYPE html>
<html><head><title>IP : <?php echo $ip;?></title><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"><link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/leaflet/1.0.0-rc.1/leaflet.css" /><style>html,body{height:100%;margin:0;padding:0;width:100%}body{text-align:center;background:#fff;color:#222;font-size:small;}body,p{font-family: arial,sans-serif}#map{margin:auto;width:100%;height:calc(100% - 120px);min-height:240px}</style></head>
<body>
    <?php if ( $latitude && $latitude ) { ?>
    <div id="map"></div>
        <script src="//cdnjs.cloudflare.com/ajax/libs/leaflet/1.0.0-rc.1/leaflet.js"></script>
        <script type="text/javascript">
        var osmUrl = 'http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            osmAttrib = '&copy; <a href="http://openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            osm = L.tileLayer(osmUrl, {maxZoom: 18, attribution: osmAttrib}),
            latlng = new L.LatLng(<?php echo $latitude;?>, <?php echo $longitude;?>);

        var map = new L.Map('map', {center: latlng, zoom: 12, layers: [osm]});

        var marker = new L.Marker(latlng);
        map.addLayer(marker);

        marker.bindPopup("<p><?php esc_attr_e( $address );?></p>");
    </script>
    <?php } ?>
    <div style="height:100px"><?php echo $output; ?></div>
</body></html>
<?php
    exit;
}
add_action( 'wp_ajax_wpinv_ip_map_location', 'wpinv_ip_map_location' );
add_action( 'wp_ajax_nopriv_wpinv_ip_map_location', 'wpinv_ip_map_location' );

// Set up the template for the invoice.
function wpinv_template( $template ) {
    global $post, $wp_query;
    
    if ( is_single() && !empty( $post ) && get_post_type() == 'wpi_invoice' ) {
        if ( current_user_can( 'edit_post', $post->ID ) || current_user_can( 'manage_options' ) ) {
            $template = wpinv_get_template_part( 'wpinv-invoice-print', false, false );
        } else {
            $wp_query->set_404();
            $template = get_404_template();
        }
    }

    return $template;
}

function wpinv_get_business_address() {
    $business_address   = wpinv_store_address();
    $business_address   = !empty( $business_address ) ? wpautop( wp_kses_post( $business_address ) ) : '';
    
    /*
    $default_country    = wpinv_get_default_country();
    $default_state      = wpinv_get_default_state();
    
    $address_fields = array();
    if ( !empty( $default_state ) ) {
        $address_fields[] = wpinv_state_name( $default_state, $default_country );
    }
    
    if ( !empty( $default_country ) ) {
        $address_fields[] = wpinv_country_name( $default_country );
    }
    
    if ( !empty( $address_fields ) ) {
        $address_fields = implode( ", ", $address_fields );
                
        $business_address .= wpautop( wp_kses_post( $address_fields ) );
    }
    */
    
    $business_address = $business_address ? '<div class="address">' . $business_address . '</div>' : '';
    
    return apply_filters( 'wpinv_get_business_address', $business_address );
}

function wpinv_display_from_address() {
    $from_name = wpinv_owner_vat_company_name();
    if (empty($from_name)) {
        $from_name = wpinv_get_business_name();
    }
    ?><div class="from col-xs-2"><strong><?php _e( 'From:', 'invoicing' ) ?></strong></div>
    <div class="wrapper col-xs-10">
        <div class="name"><?php echo esc_html( $from_name ); ?></div>
        <?php if ( $address = wpinv_get_business_address() ) { ?>
        <div class="address"><?php echo wpautop( wp_kses_post( $address ) );?></div>
        <?php } ?>
        <?php if ( $email_from = wpinv_mail_get_from_address() ) { ?>
        <div class="email_from"><?php echo $email_from;?></div>
        <?php } ?>
    </div>
    <?php
}

function wpinv_watermark( $id = 0 ) {
    $output = wpinv_get_watermark( $id );
    
    return apply_filters( 'wpinv_get_watermark', $output, $id );
}

function wpinv_get_watermark( $id ) {
    if ( !$id > 0 ) {
        return NULL;
    }
    $invoice = wpinv_get_invoice( $id );
    
    if ( !empty( $invoice ) ) {
        if ( $invoice->is_complete() ) {
            return __( 'Paid', 'invoicing' );
        }
        if ( $invoice->has_status( array( 'cancelled' ) ) ) {
            return __( 'Cancelled', 'invoicing' );
        }
    }
    
    return NULL;
}

function wpinv_display_invoice_details( $invoice_id = 0 ) {
    $invoice_status = wpinv_get_invoice_status( $invoice_id );
    ?>
    <table class="table table-bordered table-sm">
        <?php if ( $invoice_number = wpinv_get_invoice_number( $invoice_id ) ) { ?>
            <tr>
                <td><?php _e( 'Invoice Number', 'invoicing' ); ?></td>
                <td><?php echo esc_html( $invoice_number ); ?></td>
            </tr>
        <?php } ?>
        <tr>
            <td><?php _e( 'Invoice Status', 'invoicing' ); ?></td>
            <td><?php echo wpinv_invoice_status_label( $invoice_status, wpinv_get_invoice_status( $invoice_id, true ) ); ?></td>
        </tr>
        <tr>
            <td><?php _e( 'Payment Method', 'invoicing' ); ?></td>
            <td><?php echo wpinv_get_payment_gateway_name( $invoice_id ); ?></td>
        </tr>
        <?php if ( $invoice_date = wpinv_get_invoice_date( $invoice_id ) ) { ?>
            <tr>
                <td><?php _e( 'Invoice Date', 'invoicing' ); ?></td>
                <td><?php echo $invoice_date; ?></td>
            </tr>
        <?php } ?>
        <?php if ( $owner_vat_number = wpinv_owner_vat_number() ) { ?>
            <tr>
                <td><?php _e( 'Owner VAT Number', 'invoicing' ); ?></td>
                <td><?php echo $owner_vat_number; ?></td>
            </tr>
        <?php } ?>
        <?php if ( $user_vat_number = wpinv_get_invoice_vat_number( $invoice_id ) ) { ?>
            <tr>
                <td><?php _e( 'Your VAT Number', 'invoicing' ); ?></td>
                <td><?php echo $user_vat_number; ?></td>
            </tr>
        <?php } ?>
        <tr class="table-active tr-total">
            <td><strong><?php _e( 'Total Amount', 'invoicing' ) ?></strong></td>
            <td><strong><?php echo wpinv_payment_total( $invoice_id, true ); ?></strong></td>
        </tr>
    </table>
<?php
}

function wpinv_display_to_address( $invoice_id = 0 ) {
    $invoice = wpinv_get_invoice( $invoice_id );
    
    if ( empty( $invoice ) ) {
        return NULL;
    }
    
    $billing_details = $invoice->get_user_info();
    $output = '<div class="to col-xs-2"><strong>' . __( 'To:', 'invoicing' ) . '</strong></div>';
    $output .= '<div class="wrapper col-xs-10">';
    $output .= '<div class="name">' . esc_html( trim( $billing_details['first_name'] . ' ' . $billing_details['last_name'] ) ) . '</div>';
    if ( $company = $billing_details['company'] ) {
        $output .= '<div class="company">' . wpautop( wp_kses_post( $company ) ) . '</div>';
    }
    $address_row = '';
    if ( $address = $billing_details['address'] ) {
        $address_row .= wpautop( wp_kses_post( $address ) );
    }
    
    $address_fields = array();
    if ( !empty( $billing_details['city'] ) ) {
        $address_fields[] = $billing_details['city'];
    }
    
    $billing_country = !empty( $billing_details['country'] ) ? $billing_details['country'] : '';
    if ( !empty( $billing_details['state'] ) ) {
        $address_fields[] = wpinv_state_name( $billing_details['state'], $billing_country );
    }
    
    if ( !empty( $billing_country ) ) {
        $address_fields[] = wpinv_country_name( $billing_country );
    }
    
    if ( !empty( $address_fields ) ) {
        $address_fields = implode( ", ", $address_fields );
        
        if ( !empty( $billing_details['zip'] ) ) {
            $address_fields .= ' ' . $billing_details['zip'];
        }
        
        $address_row .= wpautop( wp_kses_post( $address_fields ) );
    }
    
    if ( $address_row ) {
        $output .= '<div class="address">' . $address_row . '</div>';
    }
    
    if ( $email = $invoice->get_email() ) {
        $output .= '<div class="email">' . esc_html( $email ) . '</div>';
    }
    $output .= '</div>';
    $output = apply_filters( 'wpinv_display_to_address', $output, $invoice_id );

    echo $output;
}

function wpinv_display_line_items( $invoice_id = 0 ) {
    global $ajax_cart_details;
    $invoice            = wpinv_get_invoice( $invoice_id );
    $quantities_enabled = wpinv_item_quantities_enabled();
    $use_taxes          = wpinv_use_taxes();
    $zero_tax           = !(float)$invoice->get_tax() > 0 ? true : false;
    
    $cart_details       = $invoice->get_cart_details();
    $ajax_cart_details  = $cart_details;
    ob_start();
    ?>
    <table class="table table-sm table-bordered table-striped">
        <thead>
            <tr>
                <th class="name"><strong><?php _e( "Item Name", "invoicing" );?></strong></th>
                <th class="rate"><strong><?php _e( "Price", "invoicing" );?></strong></th>
                <?php if ($quantities_enabled) { ?>
                    <th class="qty"><strong><?php _e( "Qty", "invoicing" );?></strong></th>
                <?php } ?>
                <th class="total"><strong><?php _e( "Item Total", "invoicing" );?></strong></th>
                <?php if ($use_taxes && !$zero_tax) { ?>
                    <th class="tax"><strong><?php _e( "Tax (%)", "invoicing" );?></strong></th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
        <?php 
            if ( !empty( $cart_details ) ) {
                do_action( 'wpinv_display_before_line_items', $cart_details, $invoice );
                
                $count = 0;
                foreach ( $cart_details as $key => $cart_item ) {
                    $item_id    = !empty($cart_item['id']) ? absint( $cart_item['id'] ) : '';
                    $item_price = isset($cart_item["item_price"]) ? wpinv_format_amount( $cart_item["item_price"] ) : 0;
                    $line_total = isset($cart_item["subtotal"]) ? wpinv_format_amount( $cart_item["subtotal"] ) : 0;
                    $quantity   = !empty($cart_item['quantity']) && (int)$cart_item['quantity'] > 0 ? absint( $cart_item['quantity'] ) : 1;
                    
                    $item       = $item_id ? new WPInv_Item( $item_id ) : NULL;
                    $summary    = '';
                    if ( !empty($item) ) {
                        $item_name  = $item->get_name();
                        $summary    = $item->get_summary();
                    }
                    $item_name  = !empty($cart_item['name']) ? $cart_item['name'] : $item_name;
                    
                    if (!empty($item) && $item->is_package() && !empty($cart_item['meta']['post_id'])) {
                        $post_link = '<a href="' . get_edit_post_link( $cart_item['meta']['post_id'] ) .'" target="_blank">' . (!empty($cart_item['meta']['invoice_title']) ? $cart_item['meta']['invoice_title'] : get_the_title( $cart_item['meta']['post_id']) ) . '</a>';
                        $summary = wp_sprintf( __( '%s: %s', 'invoicing' ), $item->get_cpt_singular_name(), $post_link );
                    }
                    
                    $item_tax       = '';
                    $tax_rate       = '';
                    if ( $use_taxes && $cart_item['tax'] > 0 && $cart_item['subtotal'] > 0 ) {
                        $item_tax = wpinv_price( wpinv_format_amount( $cart_item['tax'] ) );
                        $tax_rate = !empty( $cart_item['vat_rate'] ) ? $cart_item['vat_rate'] : ( $cart_item['tax'] / $cart_item['subtotal'] ) * 100;
                        $tax_rate = $tax_rate > 0 ? (float)wpinv_format_amount( $tax_rate, 2 ) : '';
                        $tax_rate = $tax_rate != '' ? ' <small class="tax-rate">(' . $tax_rate . '%)</small>' : '';
                    }
                    
                    $line_item = '<tr class="row_' . ( ($count % 2 == 0) ? 'even' : 'odd' ) . ' wpinv-item">';
                        $line_item .= '<td class="name">' . esc_html__( $item_name, 'invoicing' );
                        if ( $summary !== '' ) {
                            $line_item .= '<br/><small class="meta">' . wpautop( wp_kses_post( $summary ) ) . '</small>';
                        }
                        $line_item .= '</td>';
                        
                        $line_item .= '<td class="rate">' . esc_html__( wpinv_price( $item_price, $invoice->get_currency() ) ) . '</td>';
                        if ($quantities_enabled) {
                            $line_item .= '<td class="qty">' . $quantity . '</td>';
                        }
                        $line_item .= '<td class="total">' . esc_html__( wpinv_price( $line_total, $invoice->get_currency() ) ) . '</td>';
                        
                        if ($use_taxes && !$zero_tax) {
                            $line_item .= '<td class="tax">' . $item_tax . $tax_rate . '</td>';
                        }
                    $line_item .= '</tr>';
                    
                    echo apply_filters( 'wpinv_display_line_item', $line_item, $cart_item, $invoice );

                    $count++;
                }
                
                do_action( 'wpinv_display_after_line_items', $cart_details, $invoice );
            }
        ?>
        </tbody>
    </table>
    <?php
    echo ob_get_clean();
}

function wpinv_display_invoice_totals( $invoice_id = 0 ) {    
    $use_taxes = wpinv_use_taxes();
    
    do_action( 'wpinv_before_display_totals_table', $invoice_id ); 
    ?>
    <table class="table table-sm table-bordered">
        <tbody>
            <?php do_action( 'wpinv_before_display_totals' ); ?>
            <tr class="row-sub-total">
                <td class="rate"><strong><?php _e( 'Sub Total', 'invoicing' ); ?></strong></td>
                <td class="total"><strong><?php _e( wpinv_subtotal( $invoice_id, true ) ) ?></strong></td>
            </tr>
            <?php do_action( 'wpinv_after_display_totals' ); ?>
            <?php if ( wpinv_discount( $invoice_id, false ) > 0 ) { ?>
                <tr class="row-discount">
                    <td class="rate"><?php wpinv_get_discount_label( wpinv_discount_code( $invoice_id ) ); ?></td>
                    <td class="total"><?php echo wpinv_discount( $invoice_id, true, true ); ?></td>
                </tr>
            <?php do_action( 'wpinv_after_display_discount' ); ?>
            <?php } ?>
            <?php if ( $use_taxes ) { ?>
            <tr class="row-tax">
                <td class="rate"><?php _e( 'Tax', 'invoicing' ); ?></td>
                <td class="total"><?php _e( wpinv_tax( $invoice_id, true ) ) ?></td>
            </tr>
            <?php do_action( 'wpinv_after_display_tax' ); ?>
            <?php } ?>
            <?php if ( $fees = wpinv_get_fees( $invoice_id ) ) { ?>
                <?php foreach ( $fees as $fee ) { ?>
                    <tr class="row-fee">
                        <td class="rate"><?php echo $fee['label']; ?></td>
                        <td class="total"><?php echo $fee['amount_display']; ?></td>
                    </tr>
                <?php } ?>
            <?php } ?>
            <tr class="table-active row-total">
                <td class="rate"><strong><?php _e( 'Total', 'invoicing' ) ?></strong></td>
                <td class="total"><strong><?php _e( wpinv_payment_total( $invoice_id, true ) ) ?></strong></td>
            </tr>
            <?php do_action( 'wpinv_after_totals' ); ?>
        </tbody>

    </table>

    <?php do_action( 'wpinv_after_totals_table' );
}

function wpinv_display_payments_info( $invoice_id = 0, $echo = true ) {
    $invoice = wpinv_get_invoice( $invoice_id );
    
    ob_start();
    do_action( 'wpinv_before_display_payments_info', $invoice_id );
    if ( ( $gateway_title = $invoice->get_gateway_title() ) || $invoice->is_complete() ) {
        ?>
        <div class="wpi-payment-info">
            <p class="wpi-payment-gateway"><?php echo wp_sprintf( __( 'Payment via %s', 'invoicing' ), $gateway_title ? $gateway_title : __( 'Manually', 'invoicing' ) ); ?></p>
            <?php if ( $gateway_title ) { ?>
            <p class="wpi-payment-transid"><?php echo wp_sprintf( __( 'Transaction ID: %s', 'invoicing' ), $invoice->get_transaction_id() ); ?></p>
            <?php } ?>
        </div>
        <?php
    }
    do_action( 'wpinv_after_display_payments_info', $invoice_id );
    $outout = ob_get_clean();
    
    if ( $echo ) {
        echo $outout;
    } else {
        return $outout;
    }
}

function wpinv_display_style( $invoice ) {
    wp_register_style( 'wpinv-single-style', WPINV_PLUGIN_URL . 'assets/css/invoice.css', array(), WPINV_VERSION );
    
    wp_print_styles( 'open-sans' );
    wp_print_styles( 'wpinv-single-style' );
}
add_action( 'wpinv_invoice_head', 'wpinv_display_style' );

function wpinv_checkout_billing_details() {  
    $invoice_id = (int)wpinv_get_invoice_cart_id();
    if (empty($invoice_id)) {
        wpinv_error_log( 'Invoice id not found', 'ERROR', __FILE__, __LINE__ );
        return null;
    }
    
    $invoice = wpinv_get_invoice_cart( $invoice_id );
    if (empty($invoice)) {
        wpinv_error_log( 'Invoice not found', 'ERROR', __FILE__, __LINE__ );
        return null;
    }
    $user_id        = $invoice->get_user_id();
    $user_info      = $invoice->get_user_info();
    $address_info   = wpinv_get_user_address( $user_id );
    
    if ( empty( $user_info['first_name'] ) && !empty( $user_info['first_name'] ) ) {
        $user_info['first_name'] = $user_info['first_name'];
        $user_info['last_name'] = $user_info['last_name'];
    }
    
    if ( ( ( empty( $user_info['country'] ) && !empty( $address_info['country'] ) ) || ( empty( $user_info['state'] ) && !empty( $address_info['state'] ) && $user_info['country'] == $address_info['country'] ) ) ) {
        $user_info['country']   = $address_info['country'];
        $user_info['state']     = $address_info['state'];
        $user_info['city']      = $address_info['city'];
        $user_info['zip']       = $address_info['zip'];
    }
    
    $address_fields = array(
        'user_id',
        'company',
        'vat_number',
        'email',
        'phone',
        'address'
    );
    
    foreach ( $address_fields as $field ) {
        if ( empty( $user_info[$field] ) ) {
            $user_info[$field] = $address_info[$field];
        }
    }
    
    return $user_info;
}

function wpinv_checkout_vat_fields( $billing_details ) {
    global $wpi_session, $wpinv_options;
    
    // Only display this field if VAT is required.
    // The company name will be collected with the VAT
    // number if VAT is being collected.
    $requires_vat = apply_filters( 'wpinv_requires_vat', 0, false );
    
    $total = 1;
    if ( $total == 0 ) {
        $requires_vat = false;
    }
    
    $company    = is_user_logged_in() ? wpinv_user_company() : '';
    $vat_number = wpinv_get_vat_number();
    
    $valid = wpinv_get_vat_number( '', 0, true ) ? true : false; // True
    $vat_info = $wpi_session->get( 'user_vat_info' );
    
    if ( is_array( $vat_info ) ) {
        $company = isset( $vat_info['company'] ) ? $vat_info['company'] : "";
        $vat_number = isset( $vat_info['number'] ) ? $vat_info['number'] : "";
        $valid = isset( $vat_info['valid'] ) ? $vat_info['valid'] : false;
    }
    
    $empty = empty( $vat_number );

    $validate_button_css    = "style='display:none;'";
    $reset_button_css       = "style='display:none;'";
    if ( !$empty && $valid ) {
        $vat_vailidated_text    = __( 'VAT number validated', 'invoicing' );
        $vat_vailidated_class   = 'wpinv-vat-stat-1';
        $reset_button_css       = "";
    } else if ( !$empty && !$valid ) {
        $vat_vailidated_text    = __( 'VAT number not validated', 'invoicing' );
        $vat_vailidated_class   = 'wpinv-vat-stat-0';
        $validate_button_css    = "";
    } else {
        $vat_vailidated_text    = __( 'VAT number not given', 'invoicing' );
        $vat_vailidated_class   = 'wpinv-vat-stat-0';
        $validate_button_css    = "";
    }

    $disable_vat_fields = wpinv_disable_vat_fields();

    $ignore_style   = $requires_vat && !$disable_vat_fields ? "" : "display:none";

    $ip_country_code = wpinv_get_ip_country();
    
    $selected_country = apply_filters( 'wpinv-get-country', !empty( $wpinv_options['vat_ip_country_default'] ) ? '' : wpinv_get_default_country() );

    if ( $ip_country_code == "UK" ) {
        $ip_country_code = "GB";
    }
    
    if ( $selected_country == "UK" ) {
        $selected_country = "GB";
    }
    
    if ( wpinv_disable_vat_for_same_country() && wpinv_is_base_country( $selected_country ) ) {
        $ignore_style       = "display:none";
        $disable_vat_fields = true;
        $requires_vat       = false;
    }
    
    $ip_address = wpinv_get_ip();

    $vat_fields = compact( 'company', 'vat_number', 'valid', 'requires_vat', 'ignore_style', 'validate_button_css', 'reset_button_css', 'validated_text_css', 'not_validated_text_css', 'not_given_text_css', 'ip_country_code', 'selected_country', 'ip_address', 'disable_vat_fields' );
    
    $show_self_cert = "none";
    
    $cart_total = wpinv_payment_total();
    
    $is_digital = wpinv_vat_rule_is_digital();

    // If there's no VAT number
    if ( $is_digital && empty( $vat_fields['vat_number'] ) || !$vat_fields['requires_vat'] ) {
        if ( $vat_fields['ip_country_code'] != $vat_fields['selected_country'] ) {
            $show_self_cert = "block";
        }
    }
    
    $page = '';
    if ( empty( $wpinv_options['vat_disable_ip_address_field'] ) ) {
        $page = admin_url( 'admin-ajax.php?action=wpinv_ip_map_location&ip=' . $vat_fields['ip_address'] );
    }
    
    ?>
    <div id="wpi-vat-details" class="wpi-vat-details clearfix panel panel-default" style="<?php echo $vat_fields['ignore_style']; ?>">
        <div class="panel-heading"><h3 class="panel-title"><?php _e( 'VAT Details', 'invoicing' );?></h3></div>
        <div id="wpinv-fields-box" class="panel-body">
            <p class="wpi-cart-field wpi-col2 wpi-colf">
                <label for="wpinv_company" class="wpi-label"><?php _e( 'Company Name', 'invoicing' );?></label>
                <?php
                echo wpinv_html_text( array(
                        'id'            => 'wpinv_company',
                        'name'          => 'wpinv_company',
                        'value'         => $vat_fields['company'],
                        'class'         => 'wpi-input form-control',
                        'placeholder'   => __( 'Company name', 'invoicing' ),
                    ) );
                ?>
                <input type="hidden" id="wpinv_company_original" name="wpinv_company_original" value="<?php echo esc_attr( $vat_fields['company'] ); ?>" />
            </p>
            <p class="wpi-cart-field wpi-col wpi-colf wpi-cart-field-vat">
                <label for="wpinv_vat_number" class="wpi-label"><?php _e( 'Vat Number', 'invoicing' );?></label>
                <span id="wpinv_vat_number-wrap">
                    <label for="wpinv_vat_number" class="wpinv-label"></label>
                    <input type="text" class="wpi-input form-control" placeholder="<?php echo esc_attr__( 'Vat number', 'invoicing' );?>" value="<?php esc_attr_e( $vat_fields['vat_number'] );?>" id="wpinv_vat_number" name="wpinv_vat_number">
                    <span class="wpinv-vat-stat <?php echo $vat_vailidated_class;?>"><i class="fa"></i>&nbsp;<font><?php echo $vat_vailidated_text;?></font></span>
                </span>
            </p>
            <p class="wpi-cart-field wpi-col wpi-colf wpi-cart-field-actions">
                <input type="button" id="wpinv_vat_validate" class="<?php echo apply_filters('wpinv_button_style','button wpinv-vat-validate'); ?> btn btn-success" <?php echo $vat_fields['validate_button_css']; ?> value="<?php echo __("Validate VAT Number", 'invoicing'); ?>"/>
                <input type="button" id="wpinv_vat_reset" class="<?php echo apply_filters('wpinv_button_style','button wpinv-vat-reset'); ?> btn btn-secondary" <?php echo $vat_fields['reset_button_css']; ?> value="<?php echo __("Reset", 'invoicing'); ?>"/>
                <span class="wpi-vat-box wpi-vat-box-info"><span id="text"></span></span>
                <span class="wpi-vat-box wpi-vat-box-error"><span id="text"></span></span>
                <input type="hidden" id="wpinv_vat_number_valid" name="wpinv_vat_number_valid" value="<?php echo $vat_fields['valid'];?>" />
                <input type="hidden" id="wpinv_vat_number_original" name="wpinv_vat_number_original" value="<?php echo $vat_fields['vat_number'];?>" />
                <input type="hidden" id="wpinv_vat_ignore" name="wpinv_vat_ignore" value="<?php echo $vat_fields['requires_vat'] ? "0" : "1"; ?>" />
                <input type="hidden" name="wpinv_wp_nonce" value="<?php echo wp_create_nonce( 'validate_vat_number' ) ?>" />
            </p>
        </div>
    </fieldset>
    <fieldset id="wpi-ip-country" class="wpi-vat-info clearfix panel panel-info" value="<?php echo $vat_fields['ip_country_code']; ?>" style="display: <?php echo $show_self_cert; ?>;">
        <div id="wpinv-fields-box" class="panel-body">
            <span id="wpinv_vat_self_cert-wrap">
                <input type="checkbox" id="wpinv_vat_self_cert" name="wpinv_vat_self_cert">
                <label for="wpinv_vat_self_cert"><?php _e('The country of your current location must be the same as the country of your billing location or you must confirm the billing address is your home country.', 'invoicing'); ?></label>
            </span>
        </div>
    </div>
    <?php 
    if ( empty( $wpinv_options['vat_disable_ip_address_field'] ) ) { 
        $ip_link = '<a target="_blank" href="' . esc_url( $page ) . '" class="wpi-ip-address-link">' . $vat_fields['ip_address'] . '</a>';
    ?>
    <div class="wpi-ip-info clearfix panel panel-info">
        <div id="wpinv-fields-box" class="panel-body">
            <span><?php echo wp_sprintf( __( "Your IP address is: %s", 'invoicing' ), $ip_link ); ?>&nbsp;<?php echo __( '(Click for more details)', 'invoicing' ); ?></span>
        </div>
    </div>
    <?php } ?>
    <?php
}
add_action( 'wpinv_after_billing_fields', 'wpinv_checkout_vat_fields' );

function wpinv_admin_get_line_items($invoice = array()) {
    $item_quantities    = wpinv_item_quantities_enabled();
    $use_taxes          = wpinv_use_taxes();
    
    if ( empty( $invoice ) ) {
        return NULL;
    }
    
    $cart_items = $invoice->get_cart_details();
    if ( empty( $cart_items ) ) {
        return NULL;
    }
    ob_start();
    
    do_action( 'wpinv_admin_before_line_items', $cart_items, $invoice );
    
    $count = 0;
    foreach ( $cart_items as $key => $cart_item ) {
        $item_id    = $cart_item['id'];
        $wpi_item   = $item_id > 0 ? new WPInv_Item( $item_id ) : NULL;
        
        if (empty($wpi_item)) {
            continue;
        }
        
        $item_price     = wpinv_price( wpinv_format_amount( $cart_item['item_price'] ) );
        $quantity       = !empty( $cart_item['quantity'] ) && $cart_item['quantity'] > 0 ? $cart_item['quantity'] : 1;
        $item_subtotal  = wpinv_price( wpinv_format_amount( $cart_item['subtotal'] ) );
        
        $summary = '';
        if ($wpi_item->is_package() && !empty($cart_item['meta']['post_id'])) {
            $post_link = '<a href="' . get_edit_post_link( $cart_item['meta']['post_id'] ) .'" target="_blank">' . (!empty($cart_item['meta']['invoice_title']) ? $cart_item['meta']['invoice_title'] : get_the_title( $cart_item['meta']['post_id']) ) . '</a>';
            $summary = wp_sprintf( __( '%s: %s', 'invoicing' ), $wpi_item->get_cpt_singular_name(), $post_link );
        }
        
        $item_tax       = '';
        $tax_rate       = '';
        if ( $cart_item['tax'] > 0 && $cart_item['subtotal'] > 0 ) {
            $item_tax = wpinv_price( wpinv_format_amount( $cart_item['tax'] ) );
            $tax_rate = !empty( $cart_item['vat_rate'] ) ? $cart_item['vat_rate'] : ( $cart_item['tax'] / $cart_item['subtotal'] ) * 100;
            $tax_rate = $tax_rate > 0 ? (float)wpinv_format_amount( $tax_rate, 2 ) : '';
            $tax_rate = $tax_rate != '' ? ' <span class="tax-rate">(' . $tax_rate . '%)</span>' : '';
        }

        $line_item = '<tr class="item item-' . ( ($count % 2 == 0) ? 'even' : 'odd' ) . '" data-item-id="' . $item_id . '">';
            $line_item .= '<td class="id">' . $item_id . '</td>';
            $line_item .= '<td class="title"><a href="' . get_edit_post_link( $item_id ) . '" target="_blank">' . $cart_item['name'] . '</a>';
            if ( $summary !== '' ) {
                $line_item .= '<span class="meta">' . wpautop( wp_kses_post( $summary ) ) . '</span>';
            }
            $line_item .= '</td>';
            $line_item .= '<td class="price">' . $item_price . '</td>';
            
            if ( $item_quantities ) {
                $line_item .= '<td class="qty">&nbsp;&times;&nbsp;' . $quantity . '</td>';
            }
            $line_item .= '<td class="total">' . $item_subtotal . '</td>';
            
            if ( $use_taxes ) {
                $line_item .= '<td class="tax">' . $item_tax . $tax_rate . '</td>';
            }
            $line_item .= '<td class="action"><i class="fa fa-remove wpinv-item-remove"></i></td>';
        $line_item .= '</tr>';
        
        echo apply_filters( 'wpinv_admin_line_item', $line_item, $cart_item, $invoice );
        
        $count++;
    } 
    
    do_action( 'wpinv_admin_after_line_items', $cart_items, $invoice );
    
    return ob_get_clean();
}

function wpinv_checkout_form() {
    $form_action  = esc_url( wpinv_get_checkout_uri() );

    ob_start();
        echo '<div id="wpinv_checkout_wrap">';
        
        if ( wpinv_get_cart_contents() || wpinv_cart_has_fees() ) {
            ?>
            <div id="wpinv_checkout_form_wrap" class="wpinv_clearfix table-responsive">
                <?php do_action( 'wpinv_before_checkout_form' ); ?>
                <form id="wpinv_checkout_form" class="wpi-form" action="<?php echo $form_action; ?>" method="POST">
                    <?php
                    do_action( 'wpinv_checkout_form_top' );
                    do_action( 'wpinv_checkout_billing_info' );
                    do_action( 'wpinv_checkout_cart' );
                    do_action( 'wpinv_payment_mode_select'  );
                    do_action( 'wpinv_checkout_form_bottom' )
                    ?>
                </form>
                <?php do_action( 'wpinv_after_purchase_form' ); ?>
            </div><!--end #wpinv_checkout_form_wrap-->
        <?php
        } else {
            do_action( 'wpinv_cart_empty' );
        }
        echo '</div><!--end #wpinv_checkout_wrap-->';
    return ob_get_clean();
}

function wpinv_checkout_cart( $cart_details = array(), $echo = true ) {
    global $ajax_cart_details;
    $ajax_cart_details = $cart_details;
    /*
    // Check if the Update cart button should be shown
    if( wpinv_item_quantities_enabled() ) {
        add_action( 'wpinv_cart_footer_buttons', 'wpinv_update_cart_button' );
    }
    
    // Check if the Save Cart button should be shown
    if( !wpinv_is_cart_saving_disabled() ) {
        add_action( 'wpinv_cart_footer_buttons', 'wpinv_save_cart_button' );
    }
    */
    ob_start();
    do_action( 'wpinv_before_checkout_cart' );
    echo '<div id="wpinv_checkout_cart_form" method="post">';
        echo '<div id="wpinv_checkout_cart_wrap">';
            wpinv_get_template_part( 'wpinv-checkout-cart' );
        echo '</div>';
    echo '</div>';
    do_action( 'wpinv_after_checkout_cart' );
    $content = ob_get_clean();
    
    if ( $echo ) {
        echo $content;
    } else {
        return $content;
    }
}
add_action( 'wpinv_checkout_cart', 'wpinv_checkout_cart', 10 );

function wpinv_empty_cart_message() {
	return apply_filters( 'wpinv_empty_cart_message', '<span class="wpinv_empty_cart">' . __( 'Your cart is empty.', 'invoicing' ) . '</span>' );
}

/**
 * Echoes the Empty Cart Message
 *
 * @since 1.0
 * @return void
 */
function wpinv_empty_checkout_cart() {
	echo wpinv_empty_cart_message();
}
add_action( 'wpinv_cart_empty', 'wpinv_empty_checkout_cart' );

function wpinv_save_cart_button() {
    if ( wpinv_is_cart_saving_disabled() )
        return;
?>
    <a class="wpinv-cart-saving-button wpinv-submit button" id="wpinv-save-cart-button" href="<?php echo esc_url( add_query_arg( 'wpi_action', 'save_cart' ) ); ?>"><?php _e( 'Save Cart', 'invoicing' ); ?></a>
<?php
}

function wpinv_update_cart_button() {
    if ( !wpinv_item_quantities_enabled() )
        return;
?>
    <input type="submit" name="wpinv_update_cart_submit" class="wpinv-submit wpinv-no-js button" value="<?php _e( 'Update Cart', 'invoicing' ); ?>"/>
    <input type="hidden" name="wpi_action" value="update_cart"/>
<?php
}

function wpinv_checkout_cart_columns() {
    $default = 3;
    if ( wpinv_item_quantities_enabled() ) {
        $default++;
    }
    
    if ( wpinv_use_taxes() ) {
        $default++;
    }

    return apply_filters( 'wpinv_checkout_cart_columns', $default );
}

function wpinv_display_cart_messages() {
    global $wpi_session;

    $messages = $wpi_session->get( 'wpinv_cart_messages' );

    if ( $messages ) {
        foreach ( $messages as $message_id => $message ) {
            // Try and detect what type of message this is
            if ( strpos( strtolower( $message ), 'error' ) ) {
                $type = 'error';
            } elseif ( strpos( strtolower( $message ), 'success' ) ) {
                $type = 'success';
            } else {
                $type = 'info';
            }

            $classes = apply_filters( 'wpinv_' . $type . '_class', array( 'wpinv_errors', 'wpinv-alert', 'wpinv-alert-' . $type ) );

            echo '<div class="' . implode( ' ', $classes ) . '">';
                // Loop message codes and display messages
                    echo '<p class="wpinv_error" id="wpinv_msg_' . $message_id . '">' . $message . '</p>';
            echo '</div>';
        }

        // Remove all of the cart saving messages
        $wpi_session->set( 'wpinv_cart_messages', null );
    }
}
add_action( 'wpinv_before_checkout_cart', 'wpinv_display_cart_messages' );

function wpinv_discount_field() {
    if ( isset( $_GET['wpi-gateway'] ) && wpinv_is_ajax_disabled() ) {
        return; // Only show before a payment method has been selected if ajax is disabled
    }

    if ( !wpinv_is_checkout() ) {
        return;
    }

    if ( wpinv_has_active_discounts() && wpinv_get_cart_total() ) {
    ?>
    <div id="wpinv-discount-field" class="panel panel-default">
        <div class="panel-body">
            <p>
                <label class="wpinv-label" for="wpinv_discount_code"><strong><?php _e( 'Discount', 'invoicing' ); ?></strong></label>
                <span class="wpinv-description"><?php _e( 'Enter a discount code if you have one.', 'invoicing' ); ?></span>
            </p>
            <div class="form-group row">
                <div class="col-sm-4">
                    <input class="wpinv-input form-control" type="text" id="wpinv_discount_code" name="wpinv_discount_code" placeholder="<?php _e( 'Enter discount code', 'invoicing' ); ?>"/>
                </div>
                <div class="col-sm-3">
                    <button id="wpi-apply-discount" type="button" class="btn btn-success btn-sm"><?php _e( 'Apply Discount', 'invoicing' ); ?></button>
                </div>
                <div class="col-sm-12 wpinv-discount-msg">
                    <div class="alert alert-success"><i class="fa fa-check-circle"></i><span class="wpi-msg"></span></div>
                    <div class="alert alert-error"><i class="fa fa-warning"></i><span class="wpi-msg"></span></div>
                </div>
            </div>
        </div>
    </div>
<?php
    }
}
add_action( 'wpinv_after_checkout_cart', 'wpinv_discount_field', -10 );

function wpinv_agree_to_terms_js() {
    if ( wpinv_get_option( 'show_agree_to_terms', false ) ) {
?>
<script type="text/javascript">
    jQuery(document).ready(function($){
        $( document.body ).on('click', '.wpinv_terms_links', function(e) {
            //e.preventDefault();
            $('#wpinv_terms').slideToggle();
            $('.wpinv_terms_links').toggle();
            return false;
        });
    });
</script>
<?php
    }
}
add_action( 'wpinv_checkout_form_top', 'wpinv_agree_to_terms_js' );

function wpinv_payment_mode_select() {
    $gateways = wpinv_get_enabled_payment_gateways( true );
    $gateways = apply_filters( 'wpinv_payment_gateways_on_cart', $gateways );
    $page_URL = wpinv_get_current_page_url();
    
    do_action('wpinv_payment_mode_top');
    $invoice_id = (int)wpinv_get_invoice_cart_id();
    ?>
    <div id="wpinv_payment_mode_select">
            <?php do_action( 'wpinv_payment_mode_before_gateways_wrap' ); ?>
            <div id="wpinv-payment-mode-wrap" class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title"><?php _e( 'Select Payment Method', 'invoicing' ); ?></h3></div>
                <div class="panel-body list-group wpi-payment_methods">
                    <?php
                    do_action( 'wpinv_payment_mode_before_gateways' );
                    $chosen_gateway = wpinv_get_chosen_gateway( $invoice_id );

                    foreach ( $gateways as $gateway_id => $gateway ) {
                        $checked = checked( $gateway_id, $chosen_gateway, false );
                        $button_label = $gateway_id == 'paypal' ? __( 'Proceed to PayPal', 'invoicing' ) : '';
                        $description = wpinv_get_gateway_description( $gateway_id );
                        ?>
                        <div class="list-group-item">
                            <div class="radio">
                                <label><input type="radio" data-payment-text="<?php echo esc_attr( $button_label );?>" value="<?php echo esc_attr( $gateway_id ) ;?>" <?php echo $checked ;?> id="wpi_gateway_<?php echo esc_attr( $gateway_id );?>" name="wpi-gateway" class="wpi-pmethod"><?php echo esc_html( $gateway['checkout_label'] ); ?></label>
                            </div>
                            <div style="display:none;" class="payment_box wpi_gateway_<?php echo esc_attr( $gateway_id );?>" role="alert">
                            <?php if ( !empty( $description ) ) { ?>
                            <div class="wpi-gateway-desc alert alert-info"><?php echo $description;?></div>
                            <?php } ?> 
                            <?php do_action( 'wpinv_' . $gateway_id . '_cc_form', $invoice_id ) ;?>
                        </div>
                        </div>
                    <?php 
                    }

                    do_action( 'wpinv_payment_mode_after_gateways' );
                    ?>
                </div>
            </div>
            <?php do_action( 'wpinv_payment_mode_after_gateways_wrap' ); ?>
    </div>
    <?php
    do_action('wpinv_payment_mode_bottom');
}
add_action( 'wpinv_payment_mode_select', 'wpinv_payment_mode_select' );

function wpinv_checkout_billing_info() {    
    if ( wpinv_is_checkout() ) {
        $logged_in          = is_user_logged_in();
        $billing_details    = wpinv_checkout_billing_details();

        if ( !empty( $billing_details['country'] ) ) {
            $selected_country = $billing_details['country'];
        } else {
            $selected_country = apply_filters( 'wpinv-get-country', '' );
            
            if ( empty( $selected_country ) ) {
                $selected_country = wpinv_get_default_country();
            }
        }
        ?>
        <div id="wpinv-fields" class="clearfix">
            <div id="wpi-billing" class="wpi-billing clearfix panel panel-default">
                <div class="panel-heading"><h3 class="panel-title"><?php _e( 'Billing Details', 'invoicing' );?></h3></div>
                <div id="wpinv-fields-box" class="panel-body">
                    <p class="wpi-cart-field wpi-col2 wpi-colf">
                        <label for="wpinv_first_name" class="wpi-label"><?php _e( 'First Name', 'invoicing' );?><span class="wpi-required">*</span></label>
                        <?php
                        echo wpinv_html_text( array(
                                'id'            => 'wpinv_first_name',
                                'name'          => 'wpinv_first_name',
                                'value'         => $billing_details['first_name'],
                                'class'         => 'wpi-input form-control required',
                                'placeholder'   => __( 'First name', 'invoicing' ),
                                'required'      => true,
                            ) );
                        ?>
                    </p>
                    <p class="wpi-cart-field wpi-col2 wpi-coll">
                        <label for="wpinv_last_name" class="wpi-label"><?php _e( 'Last Name', 'invoicing' );?></label>
                        <?php
                        echo wpinv_html_text( array(
                                'id'            => 'wpinv_last_name',
                                'name'          => 'wpinv_last_name',
                                'value'         => $billing_details['last_name'],
                                'class'         => 'wpi-input form-control',
                                'placeholder'   => __( 'Last name', 'invoicing' ),
                            ) );
                        ?>
                    </p>
                    <p class="wpi-cart-field wpi-col2 wpi-colf">
                        <label for="wpinv_address" class="wpi-label"><?php _e( 'Address', 'invoicing' );?><span class="wpi-required">*</span></label>
                        <?php
                        echo wpinv_html_text( array(
                                'id'            => 'wpinv_address',
                                'name'          => 'wpinv_address',
                                'value'         => $billing_details['address'],
                                'class'         => 'wpi-input form-control required',
                                'placeholder'   => __( 'Address', 'invoicing' ),
                                'required'      => true,
                            ) );
                        ?>
                    </p>
                    <p class="wpi-cart-field wpi-col2 wpi-coll">
                        <label for="wpinv_city" class="wpi-label"><?php _e( 'City', 'invoicing' );?><span class="wpi-required">*</span></label>
                        <?php
                        echo wpinv_html_text( array(
                                'id'            => 'wpinv_city',
                                'name'          => 'wpinv_city',
                                'value'         => $billing_details['city'],
                                'class'         => 'wpi-input form-control required',
                                'placeholder'   => __( 'City', 'invoicing' ),
                                'required'      => true,
                            ) );
                        ?>
                    </p>
                    <p id="wpinv_country_box" class="wpi-cart-field wpi-col2 wpi-colf">
                        <label for="wpinv_country" class="wpi-label"><?php _e( 'Country', 'invoicing' );?><span class="wpi-required">*</span></label>
                        <?php echo wpinv_html_select( array(
                            'options'          => wpinv_get_country_list(),
                            'name'             => 'wpinv_country',
                            'id'               => 'wpinv_country',
                            'selected'         => $selected_country,
                            'show_option_all'  => false,
                            'show_option_none' => false,
                            'class'            => 'wpi-input form-control required',
                            'placeholder'      => __( 'Choose a country', 'invoicing' ),
                            'required'          => true,
                        ) ); ?>
                    </p>
                    <p id="wpinv_state_box" class="wpi-cart-field wpi-col2 wpi-coll">
                        <label for="wpinv_state" class="wpi-label"><?php _e( 'State / Province', 'invoicing' );?><span class="wpi-required">*</span></label>
                        <?php
                        $states = wpinv_get_country_states( $selected_country );
                        if( !empty( $states ) ) {
                            echo wpinv_html_select( array(
                                'options'          => $states,
                                'name'             => 'wpinv_state',
                                'id'               => 'wpinv_state',
                                'selected'         => $billing_details['state'],
                                'show_option_all'  => false,
                                'show_option_none' => false,
                                'class'            => 'wpi-input form-control required',
                                'placeholder'      => __( 'Choose a state', 'invoicing' ),
                                'required'         => true,
                            ) );
                        } else {
                            echo wpinv_html_text( array(
                                'name'          => 'wpinv_state',
                                'value'         => $billing_details['state'],
                                'id'            => 'wpinv_state',
                                'class'         => 'wpi-input form-control required',
                                'placeholder'   => __( 'State / Province', 'invoicing' ),
                                'required'      => true,
                            ) );
                        }
                        ?>
                    </p>
                    <p class="wpi-cart-field wpi-col2 wpi-colf">
                        <label for="wpinv_zip" class="wpi-label"><?php _e( 'ZIP / Postcode', 'invoicing' );?></label>
                        <?php
                        echo wpinv_html_text( array(
                                'name'          => 'wpinv_zip',
                                'value'         => $billing_details['zip'],
                                'id'            => 'wpinv_zip',
                                'class'         => 'wpi-input form-control',
                                'placeholder'   => __( 'ZIP / Postcode', 'invoicing' ),
                            ) );
                        ?>
                    </p>
                    <p class="wpi-cart-field wpi-col2 wpi-coll">
                        <label for="wpinv_phone" class="wpi-label"><?php _e( 'Phone', 'invoicing' );?></label>
                        <?php
                        echo wpinv_html_text( array(
                                'id'            => 'wpinv_phone',
                                'name'          => 'wpinv_phone',
                                'value'         => $billing_details['phone'],
                                'class'         => 'wpi-input form-control',
                                'placeholder'   => __( 'Phone', 'invoicing' ),
                            ) );
                        ?>
                    </p>
                    <div class="clearfix"></div>
                </div>
            </div>
            <?php do_action( 'wpinv_after_billing_fields', $billing_details ); ?>
        </div>
        <?php
    }
}
add_action( 'wpinv_checkout_billing_info', 'wpinv_checkout_billing_info' );

function wpinv_checkout_hidden_fields() {
?>
    <?php if ( is_user_logged_in() ) { ?>
    <input type="hidden" name="wpinv_user_id" value="<?php echo get_current_user_id(); ?>"/>
    <?php } ?>
    <input type="hidden" name="wpi_action" value="payment" />
<?php
}

function wpinv_checkout_button_purchase() {
    ob_start();
?>
    <input type="submit" class="btn btn-success wpinv-submit" id="wpinv-payment-button" data-value="<?php esc_attr_e( 'Proceed to Pay', 'invoicing' ) ?>" name="wpinv_payment" value="<?php esc_attr_e( 'Proceed to Pay', 'invoicing' ) ?>"/>
<?php
    return apply_filters( 'wpinv_checkout_button_purchase', ob_get_clean() );
}

function wpinv_checkout_total() {
    global $cart_total;
?>
<div id="wpinv_checkout_total" class="panel panel-info">
    <div class="panel-body">
    <?php
    do_action( 'wpinv_purchase_form_before_checkout_total' );
    ?>
    <strong><?php _e( 'Invoice Total:', 'invoicing' ) ?></strong> <span class="wpinv-chdeckout-total"><?php echo $cart_total;?></span>
    <?php
    do_action( 'wpinv_purchase_form_after_checkout_total' );
    ?>
    </div>
</div>
<?php
}
add_action( 'wpinv_checkout_form_bottom', 'wpinv_checkout_total', 9998 );

function wpinv_checkout_submit() {
?>
<div id="wpinv_purchase_submit" class="panel panel-success">
    <div class="panel-body text-center">
    <?php
    do_action( 'wpinv_purchase_form_before_submit' );
    wpinv_checkout_hidden_fields();
    echo wpinv_checkout_button_purchase();
    do_action( 'wpinv_purchase_form_after_submit' );
    ?>
    </div>
</div>
<?php
}
add_action( 'wpinv_checkout_form_bottom', 'wpinv_checkout_submit', 9999 );

function wpinv_receipt_billing_address( $invoice_id = 0 ) {
    $invoice = wpinv_get_invoice( $invoice_id );
    
    if ( empty( $invoice ) ) {
        return NULL;
    }
    
    $billing_details = $invoice->get_user_info();
    $address_row = '';
    if ( $address = $billing_details['address'] ) {
        $address_row .= wpautop( wp_kses_post( $address ) );
    }
    
    $address_fields = array();
    if ( !empty( $billing_details['city'] ) ) {
        $address_fields[] = $billing_details['city'];
    }
    
    $billing_country = !empty( $billing_details['country'] ) ? $billing_details['country'] : '';
    if ( !empty( $billing_details['state'] ) ) {
        $address_fields[] = wpinv_state_name( $billing_details['state'], $billing_country );
    }
    
    if ( !empty( $billing_country ) ) {
        $address_fields[] = wpinv_country_name( $billing_country );
    }
    
    if ( !empty( $address_fields ) ) {
        $address_fields = implode( ", ", $address_fields );
        
        if ( !empty( $billing_details['zip'] ) ) {
            $address_fields .= ' ' . $billing_details['zip'];
        }
        
        $address_row .= wpautop( wp_kses_post( $address_fields ) );
    }
    ob_start();
    ?>
    <table class="table table-bordered table-sm wpi-billing-details">
        <tbody>
            <tr class="wpi-receipt-name">
                <th class="text-left"><?php _e( 'Name', 'invoicing' ); ?></th>
                <td><?php echo esc_html( trim( $billing_details['first_name'] . ' ' . $billing_details['last_name'] ) ) ;?></td>
            </tr>
            <tr class="wpi-receipt-email">
                <th class="text-left"><?php _e( 'Email', 'invoicing' ); ?></th>
                <td><?php echo $billing_details['email'] ;?></td>
            </tr>
            <?php if ( $billing_details['company'] ) { ?>
            <tr class="wpi-receipt-company">
                <th class="text-left"><?php _e( 'Company', 'invoicing' ); ?></th>
                <td><?php echo esc_html( $billing_details['company'] ) ;?></td>
            </tr>
            <?php } ?>
            <tr class="wpi-receipt-address">
                <th class="text-left"><?php _e( 'Address', 'invoicing' ); ?></th>
                <td><?php echo $address_row ;?></td>
            </tr>
            <?php if ( $billing_details['phone'] ) { ?>
            <tr class="wpi-receipt-phone">
                <th class="text-left"><?php _e( 'Phone', 'invoicing' ); ?></th>
                <td><?php echo esc_html( $billing_details['phone'] ) ;?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php
    $output = ob_get_clean();
    
    $output = apply_filters( 'wpinv_receipt_billing_address', $output, $invoice_id );

    echo $output;
}

function wpinv_filter_success_page_content( $content ) {
    if ( isset( $_GET['payment-confirm'] ) && wpinv_is_success_page() ) {
        if ( has_filter( 'wpinv_payment_confirm_' . sanitize_text_field( $_GET['payment-confirm'] ) ) ) {
            $content = apply_filters( 'wpinv_payment_confirm_' . sanitize_text_field( $_GET['payment-confirm'] ), $content );
        }
    }

    return $content;
}
add_filter( 'the_content', 'wpinv_filter_success_page_content', 99999 );

function wpinv_receipt_actions( $invoice ) {
    if ( !empty( $invoice ) ) {
        $actions = array(
            'pay'    => array(
                'url'  => $invoice->get_checkout_payment_url(),
                'name' => __( 'Pay', 'invoicing' ),
                'class' => 'btn-success'
            ),
            'print'   => array(
                'url'  => $invoice->get_print_url(),
                'name' => __( 'Print', 'invoicing' ),
                'class' => 'btn-primary',
                'attrs' => 'target="_blank"'
            )
        );

        if ( ! $invoice->needs_payment() ) {
            unset( $actions['pay'] );
        }

        $actions = apply_filters( 'wpinv_invoice_receipt_actions', $actions, $invoice );
        
        if ( !empty( $actions ) ) {
        ?>
        <div class="wpinv-receipt-actions text-right">
            <?php foreach ( $actions as $key => $action ) { $class = !empty($action['class']) ? sanitize_html_class( $action['class'] ) : ''; ?>
            <a href="<?php echo esc_url( $action['url'] );?>" class="btn btn-sm <?php echo $class . ' ' . sanitize_html_class( $key );?>" <?php echo ( !empty($action['attrs']) ? $action['attrs'] : '' ) ;?>><?php echo esc_html( $action['name'] );?></a>
            <?php } ?>
        </div>
        <?php
        }
    }
}

add_action( 'wpinv_before_start', 'wpinv_receipt_actions', -10, 1 );