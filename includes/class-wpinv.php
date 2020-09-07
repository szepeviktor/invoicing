<?php
/**
 * Manual payment gateway
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manual Payment Gateway class.
 *
 */
class WPInv_Plugin {

    /**
	 * GetPaid version.
	 *
	 * @var string
	 */
    public $version;

    /**
	 * Session instance.
	 *
	 * @var WPInv_Session|WPInv_Session_Handler
	 */
    public $session;
    
    /**
	 * Notes instance.
	 *
	 * @var WPInv_Notes
	 */
    public $notes;
    
    /**
	 * Reports instance.
	 *
	 * @var WPInv_Reports
	 */
    public $reports;
    
    /**
	 * API instance.
	 *
	 * @var WPInv_API
	 */
    public $api;
    
    /**
	 * Form elements instance.
	 *
	 * @var WPInv_Payment_Form_Elements
	 */
    public $form_elements;
    
    /**
	 * Tax instance.
	 *
	 * @var WPInv_EUVat
	 */
	public $tax;

    /**
     * @param array An array of payment gateways.
     */
    public $gateways;

    /**
     * @param array An array of options.
     */
    public $options;

    /**
	 * Class constructor.
	 */
    public function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
        $this->set_properties();
    }

    /**
	 * Define class properties.
	 */
    public function set_properties() {

        $this->session       = new WPInv_Session_Handler();
        $GLOBALS['wpi_session'] = $this->session; // Backwards compatibility.
        $this->notes         = new WPInv_Notes();
        $this->reports       = new WPInv_Reports();
        $this->api           = new WPInv_API();
        $this->form_elements = new WPInv_Payment_Form_Elements();
        $this->tax           = new WPInv_EUVat();
        $this->tax->init();
        $GLOBALS['wpinv_euvat'] = $this->tax; // Backwards compatibility.

    }

     /**
	 * Define plugin constants.
	 */
    public function define_constants() {
        define( 'WPINV_PLUGIN_DIR', plugin_dir_path( WPINV_PLUGIN_FILE ) );
        define( 'WPINV_PLUGIN_URL', plugin_dir_url( WPINV_PLUGIN_FILE ) );
        $this->version = WPINV_VERSION;
    }

    /**
	 * Hook into actions and filters.
	 *
	 * @since 1.0.19
	 */
    protected function init_hooks() {
        /* Internationalize the text strings used. */
        add_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ) );
        
        /* Perform actions on admin initialization. */
        add_action( 'admin_init', array( &$this, 'admin_init') );

        // Init the plugin after WordPress inits.
        add_action( 'init', array( $this, 'init' ), 1 );
        add_action( 'getpaid_init', array( $this, 'maybe_process_ipn' ), 5 );
        add_action( 'init', array( &$this, 'wpinv_actions' ) );
        
        if ( class_exists( 'BuddyPress' ) ) {
            add_action( 'bp_include', array( &$this, 'bp_invoicing_init' ) );
        }

        add_action( 'wp_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );
        add_action( 'wp_footer', array( &$this, 'wp_footer' ) );
        add_action( 'widgets_init', array( &$this, 'register_widgets' ) );
        add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'wpseo_exclude_from_sitemap_by_post_ids' ) );

        if ( is_admin() ) {
            add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );
            add_filter( 'admin_body_class', array( &$this, 'admin_body_class' ) );
            add_action( 'admin_init', array( &$this, 'init_ayecode_connect_helper' ) );

        } else {
            add_filter( 'pre_get_posts', array( &$this, 'pre_get_posts' ) );
        }
        
        /**
         * Fires after the setup of all WPInv_Plugin actions.
         *
         * @since 1.0.0
         *
         * @param WPInv_Plugin $this. Current WPInv_Plugin instance. Passed by reference.
         */
        do_action_ref_array( 'wpinv_actions', array( &$this ) );

        add_action( 'admin_init', array( &$this, 'activation_redirect') );
    }

    /**
     * Maybe show the AyeCode Connect Notice.
     */
    public function init_ayecode_connect_helper(){
        // AyeCode Connect notice
        if ( is_admin() ){
            // set the strings so they can be translated
            $strings = array(
                'connect_title' => __("WP Invoicing - an AyeCode product!","invoicing"),
                'connect_external'  => __( "Please confirm you wish to connect your site?","invoicing" ),
                'connect'           => sprintf( __( "<strong>Have a license?</strong> Forget about entering license keys or downloading zip files, connect your site for instant access. %slearn more%s","invoicing" ),"<a href='https://ayecode.io/introducing-ayecode-connect/' target='_blank'>","</a>" ),
                'connect_button'    => __("Connect Site","invoicing"),
                'connecting_button'    => __("Connecting...","invoicing"),
                'error_localhost'   => __( "This service will only work with a live domain, not a localhost.","invoicing" ),
                'error'             => __( "Something went wrong, please refresh and try again.","invoicing" ),
            );
            new AyeCode_Connect_Helper($strings,array('wpi-addons'));
        }
    }
    
    public function plugins_loaded() {
        /* Internationalize the text strings used. */
        $this->load_textdomain();

        do_action( 'wpinv_loaded' );

        // Fix oxygen page builder conflict
        if ( function_exists( 'ct_css_output' ) ) {
            wpinv_oxygen_fix_conflict();
        }
    }
    
    /**
     * Load the translation of the plugin.
     *
     * @since 1.0
     */
    public function load_textdomain( $locale = NULL ) {
        if ( empty( $locale ) ) {
            $locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
        }

        $locale = apply_filters( 'plugin_locale', $locale, 'invoicing' );
        
        unload_textdomain( 'invoicing' );
        load_textdomain( 'invoicing', WP_LANG_DIR . '/invoicing/invoicing-' . $locale . '.mo' );
        load_plugin_textdomain( 'invoicing', false, WPINV_PLUGIN_DIR . 'languages' );
        
        /**
         * Define language constants.
         */
        require_once( WPINV_PLUGIN_DIR . 'language.php' );
    }

    /**
	 * Include required core files used in admin and on the frontend.
	 */
    public function includes() {

        // Start with the settings.
        require_once( WPINV_PLUGIN_DIR . 'includes/admin/register-settings.php' );
        $this->options = wpinv_get_settings();
        $GLOBALS['wpinv_options'] = $this->options; // Backwards compatibility.

        // Packages/libraries.
        require_once( WPINV_PLUGIN_DIR . 'vendor/autoload.php' );
        require_once( WPINV_PLUGIN_DIR . 'vendor/ayecode/wp-ayecode-ui/ayecode-ui-loader.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/libraries/action-scheduler/action-scheduler.php' );

        // Load functions.
        require_once( WPINV_PLUGIN_DIR . 'includes/deprecated-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-email-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-general-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-helper-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-tax-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-template-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-address-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/invoice-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-item-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-discount-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-gateway-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-payment-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-user-functions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/error-functions.php' );

        // Register autoloader.
		try {
			spl_autoload_register( array( $this, 'autoload' ), true );
		} catch ( Exception $e ) {
			wpinv_error_log( $e->getMessage(), '', __FILE__, 149, true );
        }

        require_once( WPINV_PLUGIN_DIR . 'includes/class-getpaid-post-types.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-post-types.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-invoice.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-discount.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-item.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-notes.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/abstracts/abstract-wpinv-session.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-session-handler.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-ajax.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-api.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-reports.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-cache-helper.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-db.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/admin/subscriptions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-subscriptions-db.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-subscriptions.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/wpinv-subscription.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-wpinv-subscriptions-list-table.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/abstracts/abstract-wpinv-privacy.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-privacy.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/libraries/class-ayecode-addons.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-addons.php' );
	    require_once( WPINV_PLUGIN_DIR . 'widgets/checkout.php' );
	    require_once( WPINV_PLUGIN_DIR . 'widgets/invoice-history.php' );
	    require_once( WPINV_PLUGIN_DIR . 'widgets/invoice-receipt.php' );
	    require_once( WPINV_PLUGIN_DIR . 'widgets/invoice-messages.php' );
	    require_once( WPINV_PLUGIN_DIR . 'widgets/subscriptions.php' );
        require_once( WPINV_PLUGIN_DIR . 'widgets/buy-item.php' );
        require_once( WPINV_PLUGIN_DIR . 'widgets/getpaid.php' );
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-payment-form-elements.php' );

        /**
         * Load the tax class.
         */
        if ( ! class_exists( 'WPInv_EUVat' ) ) {
            require_once( WPINV_PLUGIN_DIR . 'includes/libraries/wpinv-euvat/class-wpinv-euvat.php' );
        }

        $gateways = array_keys( wpinv_get_enabled_payment_gateways() );
        if ( !empty( $gateways ) ) {
            foreach ( $gateways as $gateway ) {
                if ( $gateway == 'manual' ) {
                    continue;
                }
                
                $gateway_file = WPINV_PLUGIN_DIR . 'includes/gateways/' . $gateway . '.php';
                
                if ( file_exists( $gateway_file ) ) {
                    require_once( $gateway_file );
                }
            }
        }
        
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            GetPaid_Post_Types_Admin::init();

            require_once( WPINV_PLUGIN_DIR . 'includes/admin/wpinv-upgrade-functions.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/wpinv-admin-functions.php' );
            //require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-wpinv-recurring-admin.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/meta-boxes/class-mb-payment-form.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/meta-boxes/class-mb-invoice-notes.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/admin-pages.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-wpinv-admin-menus.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-wpinv-users.php' );
            require_once( WPINV_PLUGIN_DIR . 'includes/admin/class-getpaid-admin-profile.php' );
            //require_once( WPINV_PLUGIN_DIR . 'includes/admin/subscriptions.php' );
            // load the user class only on the users.php page
            global $pagenow;
            if($pagenow=='users.php'){
                new WPInv_Admin_Users();
            }
        }

        // Register cli commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-cli.php' );
            WP_CLI::add_command( 'invoicing', 'WPInv_CLI' );
        }
        
        // include css inliner
        if ( ! class_exists( 'Emogrifier' ) && class_exists( 'DOMDocument' ) ) {
            include_once( WPINV_PLUGIN_DIR . 'includes/libraries/class-emogrifier.php' );
        }
        
        require_once( WPINV_PLUGIN_DIR . 'includes/admin/install.php' );
    }

    /**
	 * Class autoloader
	 *
	 * @param       string $class_name The name of the class to load.
	 * @access      public
	 * @since       1.0.19
	 * @return      void
	 */
	public function autoload( $class_name ) {

		// Normalize the class name...
		$class_name  = strtolower( $class_name );

		// ... and make sure it is our class.
		if ( false === strpos( $class_name, 'getpaid_' ) && false === strpos( $class_name, 'wpinv_' ) ) {
			return;
		}

		// Next, prepare the file name from the class.
		$file_name = 'class-' . str_replace( '_', '-', $class_name ) . '.php';

        // Base path of the classes.
        $plugin_path = untrailingslashit( WPINV_PLUGIN_DIR );

		// And an array of possible locations in order of importance.
		$locations = array(
            "$plugin_path/includes",
            "$plugin_path/includes/data-stores",
            "$plugin_path/includes/gateways",
            "$plugin_path/includes/api",
            "$plugin_path/includes/admin",
            "$plugin_path/includes/admin/meta-boxes",
		);

		foreach ( apply_filters( 'getpaid_autoload_locations', $locations ) as $location ) {

			if ( file_exists( trailingslashit( $location ) . $file_name ) ) {
				include trailingslashit( $location ) . $file_name;
				break;
			}

		}

	}

    /**
     * Inits hooks etc.
     */
    public function init() {

        // Fires before getpaid inits.
        do_action( 'before_getpaid_init', $this );

        // Load default gateways.
        $gateways = apply_filters(
            'getpaid_default_gateways',
            array(
                'manual'        => 'GetPaid_Manual_Gateway',
                'paypal'        => 'GetPaid_Paypal_Gateway',
                'worldpay'      => 'GetPaid_Worldpay_Gateway',
                'bank_transfer' => 'GetPaid_Bank_Transfer_Gateway',
                'authorizenet'  => 'GetPaid_Authorize_Net_Gateway',
            )
        );

        foreach ( $gateways as $id => $class ) {
            $this->gateways[ $id ] = new $class();
        }

        // Fires after getpaid inits.
        do_action( 'getpaid_init', $this );
        
    }

    /**
     * Checks if this is an IPN request and processes it.
     */
    public function maybe_process_ipn() {

        // Ensure that this is an IPN request.
        if ( empty( $_GET['wpi-listener'] ) || 'IPN' !== $_GET['wpi-listener'] || empty( $_GET['wpi-gateway'] ) ) {
            return;
        }

        $gateway = wpinv_clean( $_GET['wpi-gateway'] );

        do_action( 'wpinv_verify_payment_ipn', $gateway );
        do_action( "wpinv_verify_{$gateway}_ipn" );
        exit;

    }

    public function admin_init() {
        $this->default_payment_form = wpinv_get_default_payment_form();
        add_action( 'admin_print_scripts-edit.php', array( &$this, 'admin_print_scripts_edit_php' ) );
    }

    public function activation_redirect() {
        // Bail if no activation redirect
        if ( !get_transient( '_wpinv_activation_redirect' ) ) {
            return;
        }

        // Delete the redirect transient
        delete_transient( '_wpinv_activation_redirect' );

        // Bail if activating from network, or bulk
        if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wpinv-settings&tab=general' ) );
        exit;
    }
    
    public function enqueue_scripts() {
        $suffix       = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        
        $version = filemtime( WPINV_PLUGIN_DIR . 'assets/css/invoice-front.css' );
        wp_register_style( 'wpinv_front_style', WPINV_PLUGIN_URL . 'assets/css/invoice-front.css', array(), $version );
        wp_enqueue_style( 'wpinv_front_style' );
               
        // Register scripts
        wp_register_script( 'jquery-blockui', WPINV_PLUGIN_URL . 'assets/js/jquery.blockUI.min.js', array( 'jquery' ), '2.70', true );
        wp_register_script( 'wpinv-front-script', WPINV_PLUGIN_URL . 'assets/js/invoice-front.js', array( 'jquery' ),  filemtime( WPINV_PLUGIN_DIR . 'assets/js/invoice-front.js' ) );

        $localize                         = array();
        $localize['ajax_url']             = admin_url( 'admin-ajax.php' );
        $localize['nonce']                = wp_create_nonce( 'wpinv-nonce' );
        $localize['currency_symbol']      = wpinv_currency_symbol();
        $localize['currency_pos']         = wpinv_currency_position();
        $localize['thousand_sep']         = wpinv_thousands_separator();
        $localize['decimal_sep']          = wpinv_decimal_separator();
        $localize['decimals']             = wpinv_decimals();
        $localize['txtComplete']          = __( 'Continue', 'invoicing' );
        $localize['UseTaxes']             = wpinv_use_taxes();
        $localize['checkoutNonce']        = wp_create_nonce( 'wpinv_checkout_nonce' );
        $localize['formNonce']            = wp_create_nonce( 'getpaid_form_nonce' );
        $localize['connectionError']      = __( 'Could not establish a connection to the server.', 'invoicing' );

        $localize = apply_filters( 'wpinv_front_js_localize', $localize );
        
        wp_enqueue_script( 'jquery-blockui' );
        $autofill_api = wpinv_get_option('address_autofill_api');
        $autofill_active = wpinv_get_option('address_autofill_active');
        if ( isset( $autofill_active ) && 1 == $autofill_active && !empty( $autofill_api ) && wpinv_is_checkout() ) {
            if ( wp_script_is( 'google-maps-api', 'enqueued' ) ) {
                wp_dequeue_script( 'google-maps-api' );
            }
            wp_enqueue_script( 'google-maps-api', 'https://maps.googleapis.com/maps/api/js?key=' . $autofill_api . '&libraries=places', array( 'jquery' ), '', false );
            wp_enqueue_script( 'google-maps-init', WPINV_PLUGIN_URL . 'assets/js/gaaf.js', array( 'jquery', 'google-maps-api' ), '', true );
        }

        wp_enqueue_style( "select2", WPINV_PLUGIN_URL . 'assets/css/select2/select2.css', array(), WPINV_VERSION, 'all' );
        wp_enqueue_script('select2', WPINV_PLUGIN_URL . 'assets/js/select2/select2.full' . $suffix . '.js', array( 'jquery' ), WPINV_VERSION );

        wp_enqueue_script( 'wpinv-front-script' );
        wp_localize_script( 'wpinv-front-script', 'WPInv', $localize );

        $version = filemtime( WPINV_PLUGIN_DIR . 'assets/js/payment-forms.js' );
        wp_enqueue_script( 'wpinv-payment-form-script', WPINV_PLUGIN_URL . 'assets/js/payment-forms.js', array( 'wpinv-front-script', 'wp-hooks' ),  $version, true );
    }

    public function admin_enqueue_scripts( $hook ) {
        global $post, $pagenow;
        
        $post_type  = wpinv_admin_post_type();
        $suffix     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        $page       = isset( $_GET['page'] ) ? strtolower( $_GET['page'] ) : '';

        $jquery_ui_css = false;
        if ( ( $post_type == 'wpi_invoice' || $post_type == 'wpi_quote' || $post_type == 'wpi_discount' ) && ( $pagenow == 'post-new.php' || $pagenow == 'post.php' ) ) {
            $jquery_ui_css = true;
        } else if ( $page == 'wpinv-settings' || $page == 'wpinv-reports' ) {
            $jquery_ui_css = true;
        }
        if ( $jquery_ui_css ) {
            wp_register_style( 'jquery-ui-css', WPINV_PLUGIN_URL . 'assets/css/jquery-ui' . $suffix . '.css', array(), '1.8.16' );
            wp_enqueue_style( 'jquery-ui-css' );
            wp_deregister_style( 'yoast-seo-select2' );
	        wp_deregister_style( 'yoast-seo-monorepo' );
        }

        wp_register_style( 'wpinv_meta_box_style', WPINV_PLUGIN_URL . 'assets/css/meta-box.css', array(), WPINV_VERSION );
        wp_enqueue_style( 'wpinv_meta_box_style' );
        
        $version = filemtime( WPINV_PLUGIN_DIR . 'assets/css/admin.css' );
        wp_register_style( 'wpinv_admin_style', WPINV_PLUGIN_URL . 'assets/css/admin.css', array(), $version );
        wp_enqueue_style( 'wpinv_admin_style' );

        $enqueue = ( $post_type == 'wpi_discount' || $post_type == 'wpi_invoice' && ( $pagenow == 'post-new.php' || $pagenow == 'post.php' ) );
        if ( $page == 'wpinv-subscriptions' ) {
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_deregister_style( 'yoast-seo-select2' );
	        wp_deregister_style( 'yoast-seo-monorepo' );
        }
        
        if ( $enqueue_datepicker = apply_filters( 'wpinv_admin_enqueue_jquery_ui_datepicker', $enqueue ) ) {
            wp_enqueue_script( 'jquery-ui-datepicker' );
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        
        wp_register_script( 'jquery-blockui', WPINV_PLUGIN_URL . 'assets/js/jquery.blockUI.min.js', array( 'jquery' ), '2.70', true );

        if (($post_type == 'wpi_invoice' || $post_type == 'wpi_quote') && ($pagenow == 'post-new.php' || $pagenow == 'post.php')) {
            $autofill_api = wpinv_get_option('address_autofill_api');
            $autofill_active = wpinv_get_option('address_autofill_active');
            if (isset($autofill_active) && 1 == $autofill_active && !empty($autofill_api)) {
                wp_enqueue_script('google-maps-api', 'https://maps.googleapis.com/maps/api/js?key=' . $autofill_api . '&libraries=places', array('jquery'), '', false);
                wp_enqueue_script('google-maps-init', WPINV_PLUGIN_URL . 'assets/js/gaaf.js', array('jquery'), '', true);
            }
        }

        wp_enqueue_style( "select2", WPINV_PLUGIN_URL . 'assets/css/select2/select2.css', array(), WPINV_VERSION, 'all' );
        wp_enqueue_script('select2', WPINV_PLUGIN_URL . 'assets/js/select2/select2.full' . $suffix . '.js', array( 'jquery' ), WPINV_VERSION );

        $version = filemtime( WPINV_PLUGIN_DIR . 'assets/js/admin.js' );
        wp_register_script( 'wpinv-admin-script', WPINV_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-blockui','jquery-ui-tooltip' ),  $version );
        wp_enqueue_script( 'wpinv-admin-script' );
        
        $localize                               = array();
        $localize['ajax_url']                   = admin_url( 'admin-ajax.php' );
        $localize['post_ID']                    = isset( $post->ID ) ? $post->ID : '';
        $localize['wpinv_nonce']                = wp_create_nonce( 'wpinv-nonce' );
        $localize['add_invoice_note_nonce']     = wp_create_nonce( 'add-invoice-note' );
        $localize['delete_invoice_note_nonce']  = wp_create_nonce( 'delete-invoice-note' );
        $localize['invoice_item_nonce']         = wp_create_nonce( 'invoice-item' );
        $localize['billing_details_nonce']      = wp_create_nonce( 'get-billing-details' );
        $localize['tax']                        = wpinv_tax_amount();
        $localize['discount']                   = wpinv_discount_amount();
        $localize['currency_symbol']            = wpinv_currency_symbol();
        $localize['currency_pos']               = wpinv_currency_position();
        $localize['thousand_sep']               = wpinv_thousands_separator();
        $localize['decimal_sep']                = wpinv_decimal_separator();
        $localize['decimals']                   = wpinv_decimals();
        $localize['save_invoice']               = __( 'Save Invoice', 'invoicing' );
        $localize['status_publish']             = wpinv_status_nicename( 'publish' );
        $localize['status_pending']             = wpinv_status_nicename( 'wpi-pending' );
        $localize['delete_tax_rate']            = __( 'Are you sure you wish to delete this tax rate?', 'invoicing' );
        $localize['OneItemMin']                 = __( 'Invoice must contain at least one item', 'invoicing' );
        $localize['DeleteInvoiceItem']          = __( 'Are you sure you wish to delete this item?', 'invoicing' );
        $localize['FillBillingDetails']         = __( 'Fill the user\'s billing information? This will remove any currently entered billing information', 'invoicing' );
        $localize['confirmCalcTotals']          = __( 'Recalculate totals? This will recalculate totals based on the user billing country. If no billing country is set it will use the base country.', 'invoicing' );
        $localize['AreYouSure']                 = __( 'Are you sure?', 'invoicing' );
        $localize['emptyInvoice']               = __( 'Add at least one item to save invoice!', 'invoicing' );
        $localize['errDeleteItem']              = __( 'This item is in use! Before delete this item, you need to delete all the invoice(s) using this item.', 'invoicing' );
        $localize['delete_subscription']        = __( 'Are you sure you want to delete this subscription?', 'invoicing' );
        $localize['action_edit']                = __( 'Edit', 'invoicing' );
        $localize['action_cancel']              = __( 'Cancel', 'invoicing' );
        $localize['item_description']           = __( 'Item Description', 'invoicing' );
        $localize['discount_description']       = __( 'Discount Description', 'invoicing' );
        $localize['invoice_description']        = __( 'Invoice Description', 'invoicing' );
        $localize['searching']                  = __( 'Searching', 'invoicing' );

        $localize = apply_filters( 'wpinv_admin_js_localize', $localize );

        wp_localize_script( 'wpinv-admin-script', 'WPInv_Admin', $localize );

        // Load payment form scripts on our admin pages only.
        if ( ( $hook == 'post-new.php' || $hook == 'post.php' ) && 'wpi_payment_form' === $post->post_type ) {

            wp_enqueue_script( 'vue', WPINV_PLUGIN_URL . 'assets/js/vue/vue.js', array(), WPINV_VERSION );
            wp_enqueue_script( 'sortable', WPINV_PLUGIN_URL . 'assets/js/sortable.min.js', array(), WPINV_VERSION );
            wp_enqueue_script( 'vue_draggable', WPINV_PLUGIN_URL . 'assets/js/vue/vuedraggable.min.js', array( 'sortable', 'vue' ), WPINV_VERSION );

            $version = filemtime( WPINV_PLUGIN_DIR . 'assets/js/admin-payment-forms.js' );
            wp_register_script( 'wpinv-admin-payment-form-script', WPINV_PLUGIN_URL . 'assets/js/admin-payment-forms.js', array( 'wpinv-admin-script', 'vue_draggable' ),  $version );
        
            wp_localize_script( 'wpinv-admin-payment-form-script', 'wpinvPaymentFormAdmin', array(
                'elements'      => $this->form_elements->get_elements(),
                'form_elements' => $this->form_elements->get_form_elements( $post->ID ),
                'all_items'     => $this->form_elements->get_published_items(),
                'currency'      => wpinv_currency_symbol(),
                'position'      => wpinv_currency_position(),
                'decimals'      => (int) wpinv_decimals(),
                'thousands_sep' => wpinv_thousands_separator(),
                'decimals_sep'  => wpinv_decimal_separator(),
                'form_items'    => $this->form_elements->get_form_items( $post->ID ),
                'is_default'    => $post->ID == $this->default_payment_form,
            ) );

            wp_enqueue_script( 'wpinv-admin-payment-form-script' );
        }

        if ( $page == 'wpinv-subscriptions' ) {
            wp_register_script( 'wpinv-sub-admin-script', WPINV_PLUGIN_URL . 'assets/js/subscriptions.js', array( 'wpinv-admin-script' ),  WPINV_VERSION );
            wp_enqueue_script( 'wpinv-sub-admin-script' );
        }

        if ( $page == 'wpinv-reports' ) {
            wp_enqueue_script( 'jquery-flot', WPINV_PLUGIN_URL . 'assets/js/jquery.flot.min.js', array( 'jquery' ), '0.7' );
        }

    }

    public function admin_body_class( $classes ) {
        global $pagenow, $post, $current_screen;
        
        if ( !empty( $current_screen->post_type ) && ( $current_screen->post_type == 'wpi_invoice' || $current_screen->post_type == 'wpi_payment_form' || $current_screen->post_type == 'wpi_quote' ) ) {
            $classes .= ' wpinv-cpt';
        }
        
        $page = isset( $_GET['page'] ) ? strtolower( $_GET['page'] ) : false;

        $add_class = $page && $pagenow == 'admin.php' && strpos( $page, 'wpinv-' ) === 0 ? true : false;
        if ( $add_class ) {
            $classes .= ' wpi-' . wpinv_sanitize_key( $page );
        }
        
        $settings_class = array();
        if ( $page == 'wpinv-settings' ) {
            if ( !empty( $_REQUEST['tab'] ) ) {
                $settings_class[] = sanitize_text_field( $_REQUEST['tab'] );
            }
            
            if ( !empty( $_REQUEST['section'] ) ) {
                $settings_class[] = sanitize_text_field( $_REQUEST['section'] );
            }
            
            $settings_class[] = isset( $_REQUEST['wpi_sub'] ) && $_REQUEST['wpi_sub'] !== '' ? sanitize_text_field( $_REQUEST['wpi_sub'] ) : 'main';
        }
        
        if ( !empty( $settings_class ) ) {
            $classes .= ' wpi-' . wpinv_sanitize_key( implode( $settings_class, '-' ) );
        }
        
        $post_type = wpinv_admin_post_type();

        if ( $post_type == 'wpi_invoice' || $post_type == 'wpi_quote' || $add_class !== false ) {
            return $classes .= ' wpinv';
        }
        
        if ( $pagenow == 'post.php' && $post_type == 'wpi_item' && !empty( $post ) && !wpinv_item_is_editable( $post ) ) {
            $classes .= ' wpi-editable-n';
        }

        return $classes;
    }
    
    public function admin_print_scripts_edit_php() {

    }
    
    public function wpinv_actions() {
        if ( isset( $_REQUEST['wpi_action'] ) ) {
            do_action( 'wpinv_' . wpinv_sanitize_key( $_REQUEST['wpi_action'] ), $_REQUEST );
        }
    }
    
    public function pre_get_posts( $wp_query ) {
        if ( !empty( $wp_query->query_vars['post_type'] ) && $wp_query->query_vars['post_type'] == 'wpi_invoice' && is_user_logged_in() && is_single() && $wp_query->is_main_query() ) {
            $wp_query->query_vars['post_status'] = array_keys( wpinv_get_invoice_statuses() );
        }
        
        return $wp_query;
    }
    
    public function bp_invoicing_init() {
        require_once( WPINV_PLUGIN_DIR . 'includes/class-wpinv-bp-core.php' );
    }

	/**
	 * Register widgets
	 *
	 */
	public function register_widgets() {
		register_widget( "WPInv_Checkout_Widget" );
		register_widget( "WPInv_History_Widget" );
		register_widget( "WPInv_Receipt_Widget" );
		register_widget( "WPInv_Subscriptions_Widget" );
		register_widget( "WPInv_Buy_Item_Widget" );
        register_widget( "WPInv_Messages_Widget" );
        register_widget( 'WPInv_GetPaid_Widget' );
	}
    
    /**
     * Remove our pages from yoast sitemaps.
     *
     * @since 1.0.19
     * @param int[] $excluded_posts_ids
     */
    public function wpseo_exclude_from_sitemap_by_post_ids( $excluded_posts_ids ){

        // Ensure that we have an array.
        if ( ! is_array( $excluded_posts_ids ) ) {
            $excluded_posts_ids = array();
        }

        // Prepare our pages.
        $our_pages = array();
    
        // Checkout page.
        $our_pages[] = wpinv_get_option( 'checkout_page', false );

        // Success page.
        $our_pages[] = wpinv_get_option( 'success_page', false );

        // Failure page.
        $our_pages[] = wpinv_get_option( 'failure_page', false );

        // History page.
        $our_pages[] = wpinv_get_option( 'invoice_history_page', false );

        // Subscriptions page.
        $our_pages[] = wpinv_get_option( 'invoice_subscription_page', false );

        $our_pages   = array_map( 'intval', array_filter( $our_pages ) );

        $excluded_posts_ids = $excluded_posts_ids + $our_pages;
        return array_unique( $excluded_posts_ids );

    }

    public function wp_footer() {
        echo '
            <div class="bsui">
                <div  id="getpaid-payment-modal" class="modal" tabindex="-1" role="dialog">
                    <div class="modal-dialog modal-dialog-centered modal-lg" role="checkout" style="max-width: 650px;">
                        <div class="modal-content">
                            <div class="modal-body"></div>
                        </div>
                    </div>
                </div>
            </div>
        ';
    }

}
