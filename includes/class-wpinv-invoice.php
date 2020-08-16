<?php

// MUST have WordPress.
if ( !defined( 'WPINC' ) ) {
    exit( 'Do NOT access this file directly: ' . basename( __FILE__ ) );
}

/**
 * Invoice class.
 */
class WPInv_Invoice extends GetPaid_Data {

    /**
	 * Which data store to load.
	 *
	 * @var string
	 */
    protected $data_store_name = 'invoice';

    /**
	 * This is the name of this object type.
	 *
	 * @var string
	 */
    protected $object_type = 'invoice';

    /**
	 * Item Data array. This is the core item data exposed in APIs.
	 *
	 * @since 1.0.19
	 * @var array
	 */
	protected $data = array(
		'parent_id'            => 0,
		'status'               => 'wpi-pending',
		'version'              => '',
		'date_created'         => null,
        'date_modified'        => null,
        'due_date'             => null,
        'completed_date'       => null,
        'number'               => '',
        'title'                => '',
        'path'                 => '',
        'key'                  => '',
        'description'          => '',
        'author'               => 1,
        'type'                 => 'invoice',
        'post_type'            => 'wpi_invoice',
        'mode'                 => 'live',
        'user_ip'              => null,
        'first_name'           => null,
        'last_name'            => null,
        'phone'                => null,
        'email'                => null,
        'country'              => null,
        'city'                 => null,
        'state'                => null,
        'zip'                  => null,
        'company'              => null,
        'vat_number'           => null,
        'vat_rate'             => null,
        'address'              => null,
        'address_confirmed'    => false,
        'subtotal'             => 0,
        'total_discount'       => 0,
        'total_tax'            => 0,
        'total_fees'           => 0,
        'fees'                 => array(),
        'discounts'            => array(),
        'taxes'                => array(),
        'items'                => array(),
        'payment_form'         => 1,
        'submission_id'        => null,
        'discount_code'        => null,
        'gateway'              => 'none',
        'transaction_id'       => '',
        'currency'             => '',
        'disable_taxes'        => 0,
		'subscription_id'      => null,
		'is_viewed'            => false,
		'email_cc'             => '',
		'template'             => 'quantity', // hours, amount only
    );

    /**
	 * Stores meta in cache for future reads.
	 *
	 * A group must be set to to enable caching.
	 *
	 * @var string
	 */
	protected $cache_group = 'getpaid_invoices';

    /**
     * Stores a reference to the original WP_Post object
     *
     * @var WP_Post
     */
    protected $post = null;

    /**
     * Stores a reference to the recurring item id instead of looping through the items.
     *
     * @var int
     */
	protected $recurring_item = null;

	/**
     * Stores an array of item totals.
	 *
	 * e.g $totals['discount'] = array(
	 * 		'initial'   => 10,
	 * 		'recurring' => 10,
	 * )
     *
     * @var array
     */
	protected $totals = array();

	/**
	 * Stores the status transition information.
	 *
	 * @since 1.0.19
	 * @var bool
	 */
	protected $status_transition = false;

    /**
	 * Get the invoice if ID is passed, otherwise the invoice is new and empty.
	 *
	 * @param  int/string|object|WPInv_Invoice|WPInv_Legacy_Invoice|WP_Post $invoice Invoice id, key, number or object to read.
	 */
    public function __construct( $invoice = false ) {

        parent::__construct( $invoice );

		if ( is_numeric( $invoice ) && getpaid_is_invoice_post_type( get_post_type( $invoice ) ) ) {
			$this->set_id( $invoice );
		} elseif ( $invoice instanceof self ) {
			$this->set_id( $invoice->get_id() );
		} elseif ( ! empty( $invoice->ID ) ) {
			$this->set_id( $invoice->ID );
		} elseif ( is_array( $invoice ) ) {
			$this->set_props( $invoice );

			if ( isset( $invoice['ID'] ) ) {
				$this->set_id( $invoice['ID'] );
			}

		} elseif ( is_scalar( $invoice ) && $invoice_id = self::get_discount_id_by_code( $invoice, 'key' ) ) {
			$this->set_id( $invoice_id );
		} elseif ( is_scalar( $invoice ) && $invoice_id = self::get_discount_id_by_code( $invoice, 'number' ) ) {
			$this->set_id( $invoice_id );
		} else {
			$this->set_object_read( true );
		}

        // Load the datastore.
		$this->data_store = GetPaid_Data_Store::load( $this->data_store_name );

		if ( $this->get_id() > 0 ) {
            $this->post = get_post( $this->get_id() );
            $this->ID   = $this->get_id();
			$this->data_store->read( $this );
        }

    }

    /**
	 * Given a discount code, it returns a discount id.
	 *
	 *
	 * @static
	 * @param string $discount_code
	 * @since 1.0.15
	 * @return int
	 */
	public static function get_discount_id_by_code( $invoice_key_or_number, $field = 'key' ) {
        global $wpdb;

		// Trim the code.
        $key = trim( $invoice_key_or_number );

        // Valid fields.
        $fields = array( 'key', 'number' );

		// Ensure a value has been passed.
		if ( empty( $key ) || ! in_array( $field, $fields ) ) {
			return 0;
		}

		// Maybe retrieve from the cache.
		$invoice_id   = wp_cache_get( $key, 'getpaid_invoice_keys_' . $field );
		if ( ! empty( $invoice_id ) ) {
			return $invoice_id;
		}

        // Fetch from the db.
        $table       = $wpdb->prefix . 'getpaid_invoices';
        $invoice_id  = $wpdb->get_var(
            $wpdb->prepare( "SELECT `post_id` FROM $table WHERE $field=%s LIMIT 1", $key )
        );

		if ( empty( $invoice_id ) ) {
			return 0;
		}

		// Update the cache with our data
		wp_cache_set( $key, $invoice_id, 'getpaid_invoice_keys_' . $field );

		return $invoice_id;
    }

    /**
     * Checks if an invoice key is set.
     */
    public function _isset( $key ) {
        return isset( $this->data[$key] ) || method_exists( $this, "get_$key" );
    }

    /*
	|--------------------------------------------------------------------------
	| CRUD methods
	|--------------------------------------------------------------------------
	|
	| Methods which create, read, update and delete items from the database.
	|
    */

    /*
	|--------------------------------------------------------------------------
	| Getters
	|--------------------------------------------------------------------------
    */

    /**
	 * Get parent invoice ID.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_parent_id( $context = 'view' ) {
		return (int) $this->get_prop( 'parent_id', $context );
    }

    /**
	 * Get parent invoice.
	 *
	 * @since 1.0.19
	 * @return WPInv_Invoice
	 */
    public function get_parent_payment() {
        return new WPInv_Invoice( $this->get_parent_id() );
    }

    /**
	 * Alias for self::get_parent_payment().
	 *
	 * @since 1.0.19
	 * @return WPInv_Invoice
	 */
    public function get_parent() {
        return $this->get_parent_payment();
    }

    /**
	 * Get invoice status.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_status( $context = 'view' ) {
		return $this->get_prop( 'status', $context );
    }

    /**
	 * Get invoice status nice name.
	 *
	 * @since 1.0.19
	 * @return string
	 */
    public function get_status_nicename() {
        $statuses = wpinv_get_invoice_statuses( true, true, $this );

        if ( $this->is_quote() && class_exists( 'Wpinv_Quotes_Shared' ) ) {
            $statuses = Wpinv_Quotes_Shared::wpinv_get_quote_statuses();
        }

        $status = isset( $statuses[ $this->get_status() ] ) ? $statuses[ $this->get_status() ] : $this->get_status();

        return apply_filters( 'wpinv_get_invoice_status_nicename', $status );
    }

    /**
	 * Get plugin version when the invoice was created.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_version( $context = 'view' ) {
		return $this->get_prop( 'version', $context );
	}

	/**
	 * @deprecated
	 */
	public function get_invoice_date( $formatted = true ) {
        $date_completed = $this->get_date_completed();
        $invoice_date   = $date_completed != '0000-00-00 00:00:00' ? $date_completed : '';

        if ( $invoice_date == '' ) {
            $date_created   = $this->get_date_created();
            $invoice_date   = $date_created != '0000-00-00 00:00:00' ? $date_created : '';
        }

        if ( $formatted && $invoice_date ) {
            $invoice_date   = date_i18n( get_option( 'date_format' ), strtotime( $invoice_date ) );
        }

        return apply_filters( 'wpinv_get_invoice_date', $invoice_date, $formatted, $this->ID, $this );
    }

    /**
	 * Get date when the invoice was created.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_date_created( $context = 'view' ) {
		return $this->get_prop( 'date_created', $context );
	}
	
	/**
	 * Alias for self::get_date_created().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_created_date( $context = 'view' ) {
		return $this->get_date_created( $context );
    }

    /**
	 * Get GMT date when the invoice was created.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_date_created_gmt( $context = 'view' ) {
        $date = $this->get_date_created( $context );

        if ( $date ) {
            $date = get_gmt_from_date( $date );
        }
		return $date;
    }

    /**
	 * Get date when the invoice was last modified.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_date_modified( $context = 'view' ) {
		return $this->get_prop( 'date_modified', $context );
	}

	/**
	 * Alias for self::get_date_modified().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_modified_date( $context = 'view' ) {
		return $this->get_date_modified( $context );
    }

    /**
	 * Get GMT date when the invoice was last modified.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_date_modified_gmt( $context = 'view' ) {
        $date = $this->get_date_modified( $context );

        if ( $date ) {
            $date = get_gmt_from_date( $date );
        }
		return $date;
    }

    /**
	 * Get the invoice due date.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_due_date( $context = 'view' ) {
		return $this->get_prop( 'due_date', $context );
    }

    /**
	 * Alias for self::get_due_date().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_date_due( $context = 'view' ) {
		return $this->get_due_date( $context );
    }

    /**
	 * Get the invoice GMT due date.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_due_date_gmt( $context = 'view' ) {
        $date = $this->get_due_date( $context );

        if ( $date ) {
            $date = get_gmt_from_date( $date );
        }
		return $date;
    }

    /**
	 * Alias for self::get_due_date_gmt().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_gmt_date_due( $context = 'view' ) {
		return $this->get_due_date_gmt( $context );
    }

    /**
	 * Get date when the invoice was completed.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_completed_date( $context = 'view' ) {
		return $this->get_prop( 'completed_date', $context );
    }

    /**
	 * Alias for self::get_completed_date().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_date_completed( $context = 'view' ) {
		return $this->get_completed_date( $context );
    }

    /**
	 * Get GMT date when the invoice was was completed.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_completed_date_gmt( $context = 'view' ) {
        $date = $this->get_completed_date( $context );

        if ( $date ) {
            $date = get_gmt_from_date( $date );
        }
		return $date;
    }

    /**
	 * Alias for self::get_completed_date_gmt().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_gmt_completed_date( $context = 'view' ) {
		return $this->get_completed_date_gmt( $context );
    }

    /**
	 * Get the invoice number.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_number( $context = 'view' ) {
        $number = $this->get_prop( 'number', $context );

        if ( empty( $number ) ) {
            $number = $this->generate_number();
            $this->set_number( $number );
        }

		return $number;
    }

    /**
	 * Get the invoice key.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_key( $context = 'view' ) {
        $key = $this->get_prop( 'key', $context );

        if ( empty( $key ) ) {
            $key = $this->generate_key( $this->post_type );
            $this->set_key( $key );
        }

		return $key;
    }

    /**
	 * Get the invoice type.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_type( $context = 'view' ) {
        return $this->get_prop( 'type', $context );
	}

	/**
	 * @deprecated
	 */
	public function get_invoice_quote_type( $post_id ) {
        if ( empty( $post_id ) ) {
            return '';
        }

        $type = get_post_type( $post_id );

        if ( 'wpi_invoice' === $type ) {
            $post_type = __('Invoice', 'invoicing');
        } else{
            $post_type = __('Quote', 'invoicing');
        }

        return apply_filters('get_invoice_type_label', $post_type, $post_id);
    }

    /**
	 * Get the invoice post type.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_post_type( $context = 'view' ) {
        return $this->get_prop( 'post_type', $context );
    }

    /**
	 * Get the invoice mode.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_mode( $context = 'view' ) {
        return $this->get_prop( 'mode', $context );
    }

    /**
	 * Get the invoice path.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_path( $context = 'view' ) {
        $path = $this->get_prop( 'path', $context );

        if ( empty( $path ) ) {
            $prefix = apply_filters( 'wpinv_post_name_prefix', 'inv-', $this->post_type );
            $path   = sanitize_title( $prefix . $this->get_id() );
        }

		return $path;
    }

    /**
	 * Get the invoice name/title.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_name( $context = 'view' ) {
        $name = $this->get_prop( 'title', $context );

		return empty( $name ) ? $this->get_number( $context ) : $name;
    }

    /**
	 * Alias of self::get_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_title( $context = 'view' ) {
		return $this->get_name( $context );
    }

    /**
	 * Get the invoice description.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_description( $context = 'view' ) {
		return $this->get_prop( 'description', $context );
    }

    /**
	 * Alias of self::get_description().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_excerpt( $context = 'view' ) {
		return $this->get_description( $context );
    }

    /**
	 * Alias of self::get_description().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_summary( $context = 'view' ) {
		return $this->get_description( $context );
    }

    /**
	 * Returns the user info.
	 *
	 * @since 1.0.19
     * @param  string $context View or edit context.
	 * @return array
	 */
    public function get_user_info( $context = 'view' ) {
        $user_info = array(
            'user_id'    => $this->get_user_id( $context ),
            'email'      => $this->get_email( $context ),
            'first_name' => $this->get_first_name( $context ),
            'last_name'  => $this->get_last_name( $context ),
            'address'    => $this->get_address( $context ),
            'phone'      => $this->get_phone( $context ),
            'city'       => $this->get_city( $context ),
            'country'    => $this->get_country( $context ),
            'state'      => $this->get_state( $context ),
            'zip'        => $this->get_zip( $context ),
            'company'    => $this->get_company( $context ),
            'vat_number' => $this->get_vat_number( $context ),
            'discount'   => $this->get_discount_code( $context ),
        );
        return apply_filters( 'wpinv_user_info', $user_info, $this->ID, $this );
    }

    /**
	 * Get the customer id.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_author( $context = 'view' ) {
		return (int) $this->get_prop( 'author', $context );
    }

    /**
	 * Alias of self::get_author().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_id( $context = 'view' ) {
		return $this->get_author( $context );
    }

     /**
	 * Alias of self::get_author().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_id( $context = 'view' ) {
		return $this->get_author( $context );
    }

    /**
	 * Get the customer's ip.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_ip( $context = 'view' ) {
		return $this->get_prop( 'user_ip', $context );
    }

    /**
	 * Alias of self::get_ip().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_ip( $context = 'view' ) {
		return $this->get_ip( $context );
    }

     /**
	 * Alias of self::get_ip().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_ip( $context = 'view' ) {
		return $this->get_ip( $context );
    }

    /**
	 * Get the customer's first name.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_first_name( $context = 'view' ) {
		return $this->get_prop( 'first_name', $context );
    }

    /**
	 * Alias of self::get_first_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_first_name( $context = 'view' ) {
		return $this->get_first_name( $context );
    }

     /**
	 * Alias of self::get_first_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_first_name( $context = 'view' ) {
		return $this->get_first_name( $context );
    }

    /**
	 * Get the customer's last name.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_last_name( $context = 'view' ) {
		return $this->get_prop( 'last_name', $context );
    }

    /**
	 * Alias of self::get_last_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_last_name( $context = 'view' ) {
		return $this->get_last_name( $context );
    }

    /**
	 * Alias of self::get_last_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_last_name( $context = 'view' ) {
		return $this->get_last_name( $context );
    }

    /**
	 * Get the customer's full name.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_full_name( $context = 'view' ) {
		return trim( $this->get_first_name( $context ) . ' ' . $this->get_last_name( $context ) );
    }

    /**
	 * Alias of self::get_full_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_full_name( $context = 'view' ) {
		return $this->get_full_name( $context );
    }

    /**
	 * Alias of self::get_full_name().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_full_name( $context = 'view' ) {
		return $this->get_full_name( $context );
    }

    /**
	 * Get the customer's phone number.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_phone( $context = 'view' ) {
		return $this->get_prop( 'phone', $context );
    }

    /**
	 * Alias of self::get_phone().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_phone_number( $context = 'view' ) {
		return $this->get_phone( $context );
    }

    /**
	 * Alias of self::get_phone().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_phone( $context = 'view' ) {
		return $this->get_phone( $context );
    }

    /**
	 * Alias of self::get_phone().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_phone( $context = 'view' ) {
		return $this->get_phone( $context );
    }

    /**
	 * Get the customer's email address.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_email( $context = 'view' ) {
		return $this->get_prop( 'email', $context );
    }

    /**
	 * Alias of self::get_email().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_email_address( $context = 'view' ) {
		return $this->get_email( $context );
    }

    /**
	 * Alias of self::get_email().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_email( $context = 'view' ) {
		return $this->get_email( $context );
    }

    /**
	 * Alias of self::get_email().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_email( $context = 'view' ) {
		return $this->get_email( $context );
    }

    /**
	 * Get the customer's country.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_country( $context = 'view' ) {
		$country = $this->get_prop( 'country', $context );
		return empty( $country ) ? wpinv_get_default_country() : $country;
    }

    /**
	 * Alias of self::get_country().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_country( $context = 'view' ) {
		return $this->get_country( $context );
    }

    /**
	 * Alias of self::get_country().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_country( $context = 'view' ) {
		return $this->get_country( $context );
    }

    /**
	 * Get the customer's state.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_state( $context = 'view' ) {
		$state = $this->get_prop( 'state', $context );
		return empty( $state ) ? wpinv_get_default_state() : $state;
    }

    /**
	 * Alias of self::get_state().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_user_state( $context = 'view' ) {
		return $this->get_state( $context );
    }

    /**
	 * Alias of self::get_state().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_customer_state( $context = 'view' ) {
		return $this->get_state( $context );
    }

    /**
	 * Get the customer's city.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_city( $context = 'view' ) {
		return $this->get_prop( 'city', $context );
    }

    /**
	 * Alias of self::get_city().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_city( $context = 'view' ) {
		return $this->get_city( $context );
    }

    /**
	 * Alias of self::get_city().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_city( $context = 'view' ) {
		return $this->get_city( $context );
    }

    /**
	 * Get the customer's zip.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_zip( $context = 'view' ) {
		return $this->get_prop( 'zip', $context );
    }

    /**
	 * Alias of self::get_zip().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_zip( $context = 'view' ) {
		return $this->get_zip( $context );
    }

    /**
	 * Alias of self::get_zip().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_zip( $context = 'view' ) {
		return $this->get_zip( $context );
    }

    /**
	 * Get the customer's company.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_company( $context = 'view' ) {
		return $this->get_prop( 'company', $context );
    }

    /**
	 * Alias of self::get_company().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_company( $context = 'view' ) {
		return $this->get_company( $context );
    }

    /**
	 * Alias of self::get_company().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_company( $context = 'view' ) {
		return $this->get_company( $context );
    }

    /**
	 * Get the customer's vat number.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_vat_number( $context = 'view' ) {
		return $this->get_prop( 'vat_number', $context );
    }

    /**
	 * Alias of self::get_vat_number().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_vat_number( $context = 'view' ) {
		return $this->get_vat_number( $context );
    }

    /**
	 * Alias of self::get_vat_number().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_vat_number( $context = 'view' ) {
		return $this->get_vat_number( $context );
    }

    /**
	 * Get the customer's vat rate.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_vat_rate( $context = 'view' ) {
		return $this->get_prop( 'vat_rate', $context );
    }

    /**
	 * Alias of self::get_vat_rate().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_vat_rate( $context = 'view' ) {
		return $this->get_vat_rate( $context );
    }

    /**
	 * Alias of self::get_vat_rate().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_vat_rate( $context = 'view' ) {
		return $this->get_vat_rate( $context );
    }

    /**
	 * Get the customer's address.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_address( $context = 'view' ) {
		return $this->get_prop( 'address', $context );
    }

    /**
	 * Alias of self::get_address().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_user_address( $context = 'view' ) {
		return $this->get_address( $context );
    }

    /**
	 * Alias of self::get_address().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_customer_address( $context = 'view' ) {
		return $this->get_address( $context );
    }

    /**
	 * Get whether the customer has viewed the invoice or not.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_is_viewed( $context = 'view' ) {
		return (bool) $this->get_prop( 'is_viewed', $context );
	}

	/**
	 * Get other recipients for invoice communications.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_email_cc( $context = 'view' ) {
		return $this->get_prop( 'email_cc', $context );
	}

	/**
	 * Get invoice template.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_template( $context = 'view' ) {
		return $this->get_prop( 'template', $context );
	}

	/**
	 * Get whether the customer has confirmed their address.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_address_confirmed( $context = 'view' ) {
		return (bool) $this->get_prop( 'address_confirmed', $context );
    }

    /**
	 * Alias of self::get_address_confirmed().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_user_address_confirmed( $context = 'view' ) {
		return $this->get_address_confirmed( $context );
    }

    /**
	 * Alias of self::get_address().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_customer_address_confirmed( $context = 'view' ) {
		return $this->get_address_confirmed( $context );
    }

    /**
	 * Get the invoice subtotal.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return float
	 */
	public function get_subtotal( $context = 'view' ) {
        $subtotal = (float) $this->get_prop( 'subtotal', $context );

        // Backwards compatibility.
        if ( is_bool( $context ) && $context ) {
            return wpinv_price( wpinv_format_amount( $subtotal ), $this->get_currency() );
        }

        return $subtotal;
    }

    /**
	 * Get the invoice discount total.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return float
	 */
	public function get_total_discount( $context = 'view' ) {
		return (float) $this->get_prop( 'total_discount', $context );
    }

    /**
	 * Get the invoice tax total.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return float
	 */
	public function get_total_tax( $context = 'view' ) {
		return (float) $this->get_prop( 'total_tax', $context );
	}

	/**
	 * @deprecated
	 */
	public function get_final_tax( $currency = false ) {
		$tax = $this->get_total_tax();

        if ( $currency ) {
			return wpinv_price( wpinv_format_amount( $tax, NULL, false ), $this->get_currency() );
        }

        return $tax;
    }

    /**
	 * Get the invoice fees total.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return float
	 */
	public function get_total_fees( $context = 'view' ) {
		return (float) $this->get_prop( 'total_fees', $context );
    }

    /**
	 * Alias for self::get_total_fees().
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return float
	 */
	public function get_fees_total( $context = 'view' ) {
		return $this->get_total_fees( $context );
    }

    /**
	 * Get the invoice total.
	 *
	 * @since 1.0.19
     * @return float
	 */
	public function get_total() {
		$total = $this->is_renewal() ? $this->get_recurring_total() : $this->get_initial_total();
		return apply_filters( 'getpaid_get_invoice_total_amount', $total, $this  );
    }

    /**
	 * Get the initial invoice total.
	 *
	 * @since 1.0.19
     * @param  string $context View or edit context.
     * @return float
	 */
    public function get_initial_total() {

		if ( empty( $this->totals ) ) {
			$this->recalculate_total();
		}

		$tax      = $this->totals['tax']['initial'];
		$fee      = $this->totals['fee']['initial'];
		$discount = $this->totals['discount']['initial'];
		$subtotal = $this->totals['subtotal']['initial'];
		$total    = $tax + $fee - $discount + $subtotal;

		if ( 0 > $total ) {
			$total = 0;
		}

        return apply_filters( 'wpinv_get_initial_invoice_total', $total, $this );
	}

	/**
	 * Get the recurring invoice total.
	 *
	 * @since 1.0.19
     * @param  string $context View or edit context.
     * @return float
	 */
    public function get_recurring_total() {

		if ( empty( $this->totals ) ) {
			$this->recalculate_total();
		}

		$tax      = $this->totals['tax']['recurring'];
		$fee      = $this->totals['fee']['recurring'];
		$discount = $this->totals['discount']['recurring'];
		$subtotal = $this->totals['subtotal']['recurring'];
		$total    = $tax + $fee - $discount + $subtotal;

		if ( 0 > $total ) {
			$total = 0;
		}

        return apply_filters( 'wpinv_get_recurring_invoice_total', $total, $this );
	}

	/**
	 * Returns recurring payment details.
	 *
	 * @since 1.0.19
     * @param  string $field Optionally provide a field to return.
	 * @param string $currency Whether to include the currency.
     * @return float
	 */
    public function get_recurring_details( $field = '', $currency = false ) {

		// Maybe recalculate totals.
		if ( empty( $this->totals ) ) {
			$this->recalculate_total();
		}

		// Prepare recurring totals.
        $data = apply_filters(
			'wpinv_get_invoice_recurring_details',
			array(
				'cart_details' => $this->get_cart_details(),
				'subtotal'     => $this->totals['subtotal']['recurring'],
				'discount'     => $this->totals['discount']['recurring'],
				'tax'          => $this->totals['tax']['recurring'],
				'fee'          => $this->totals['fee']['recurring'],
				'total'        => $this->get_recurring_total(),
			),
			$this,
			$field,
			$currency
		);

        if ( isset( $data[$field] ) ) {
            return ( $currency ? wpinv_price( $data[$field], $this->get_currency() ) : $data[$field] );
        }

        return $data;
    }

    /**
	 * Get the invoice fees.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return array
	 */
	public function get_fees( $context = 'view' ) {
		return wpinv_parse_list( $this->get_prop( 'fees', $context ) );
    }

    /**
	 * Get the invoice discounts.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return array
	 */
	public function get_discounts( $context = 'view' ) {
		return wpinv_parse_list( $this->get_prop( 'discounts', $context ) );
    }

    /**
	 * Get the invoice taxes.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return array
	 */
	public function get_taxes( $context = 'view' ) {
		return wpinv_parse_list( $this->get_prop( 'taxes', $context ) );
    }

    /**
	 * Get the invoice items.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return GetPaid_Form_Item[]
	 */
	public function get_items( $context = 'view' ) {
        return $this->get_prop( 'items', $context );
    }

    /**
	 * Get the invoice's payment form.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
	public function get_payment_form( $context = 'view' ) {
		return intval( $this->get_prop( 'payment_form', $context ) );
    }

    /**
	 * Get the invoice's submission id.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_submission_id( $context = 'view' ) {
		return $this->get_prop( 'submission_id', $context );
    }

    /**
	 * Get the invoice's discount code.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_discount_code( $context = 'view' ) {
		return $this->get_prop( 'discount_code', $context );
    }

    /**
	 * Get the invoice's gateway.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_gateway( $context = 'view' ) {
		return $this->get_prop( 'gateway', $context );
    }

    /**
	 * Get the invoice's gateway display title.
	 *
	 * @since 1.0.19
	 * @return string
	 */
    public function get_gateway_title() {
        $title =  wpinv_get_gateway_checkout_label( $this->get_gateway() );
        return apply_filters( 'wpinv_gateway_title', $title, $this->ID, $this );
    }

    /**
	 * Get the invoice's transaction id.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_transaction_id( $context = 'view' ) {
		return $this->get_prop( 'transaction_id', $context );
    }

    /**
	 * Get the invoice's currency.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return string
	 */
	public function get_currency( $context = 'view' ) {
        $currency = $this->get_prop( 'currency', $context );
        return empty( $currency ) ? wpinv_get_currency() : $currency;
    }

    /**
	 * Checks if we are charging taxes for this invoice.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return bool
	 */
	public function get_disable_taxes( $context = 'view' ) {
        return (bool) $this->get_prop( 'disable_taxes', $context );
    }

    /**
	 * Retrieves the subscription id for an invoice.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return int
	 */
    public function get_subscription_id( $context = 'view' ) {
        $subscription_id = $this->get_prop( 'subscription_id', $context );

        if ( empty( $subscription_id ) && $this->is_renewal() ) {
            $parent = $this->get_parent();
            return $parent->get_subscription_id( $context );
        }

        return $subscription_id;
    }

    /**
	 * Retrieves the payment meta for an invoice.
	 *
	 * @since 1.0.19
	 * @param  string $context View or edit context.
	 * @return array
	 */
    public function get_payment_meta( $context = 'view' ) {

        return array(
            'price'        => $this->get_total( $context ),
            'date'         => $this->get_date_created( $context ),
            'user_email'   => $this->get_email( $context ),
            'invoice_key'  => $this->get_key( $context ),
            'currency'     => $this->get_currency( $context ),
            'items'        => $this->get_items( $context ),
            'user_info'    => $this->get_user_info( $context ),
            'cart_details' => $this->get_cart_details(),
            'status'       => $this->get_status( $context ),
            'fees'         => $this->get_fees( $context ),
            'taxes'        => $this->get_taxes( $context ),
        );

    }

    /**
	 * Retrieves the cart details for an invoice.
	 *
	 * @since 1.0.19
	 * @return array
	 */
    public function get_cart_details() {
        $items        = $this->get_items();
        $cart_details = array();

        foreach ( $items as $item_id => $item ) {
            $cart_details[] = $item->prepare_data_for_saving();
        }

        return $cart_details;
	}

	/**
	 * Retrieves the recurring item.
	 *
	 * @return null|GetPaid_Form_Item
	 */
	public function get_recurring( $object = false ) {

		// Are we returning an object?
        if ( $object ) {
            return $this->get_item( $this->recurring_item );
        }

        return $this->recurring_item;
    }

	/**
	 * Retrieves the subscription name.
	 *
	 * @since 1.0.19
	 * @return string
	 */
	public function get_subscription_name() {

		// Retrieve the recurring name
        $item = $this->get_recurring( true );

		// Abort if it does not exist.
        if ( empty( $item ) ) {
            return '';
        }

		// Return the item name.
        return apply_filters( 'wpinv_invoice_get_subscription_name', $item->get_name(), $this );
	}

	/**
	 * Retrieves the view url.
	 *
	 * @since 1.0.19
	 * @return string
	 */
	public function get_view_url() {
        $invoice_url = get_permalink( $this->get_id() );
		$invoice_url = add_query_arg( 'invoice_key', $this->get_key(), $invoice_url );
        return apply_filters( 'wpinv_get_view_url', $invoice_url, $this );
	}

	/**
	 * Retrieves the payment url.
	 *
	 * @since 1.0.19
	 * @return string
	 */
	public function get_checkout_payment_url( $deprecated = false, $secret = false ) {

		// Retrieve the checkout url.
        $pay_url = wpinv_get_checkout_uri();

		// Maybe force ssl.
        if ( is_ssl() ) {
            $pay_url = str_replace( 'http:', 'https:', $pay_url );
        }

		// Add the invoice key.
		$pay_url = add_query_arg( 'invoice_key', $this->get_key(), $pay_url );

		// (Maybe?) add a secret
        if ( $secret ) {
            $pay_url = add_query_arg( array( '_wpipay' => md5( $this->get_user_id() . '::' . $this->get_email() . '::' . $this->get_key() ) ), $pay_url );
        }

        return apply_filters( 'wpinv_get_checkout_payment_url', $pay_url, $this, $deprecated, $secret );
    }

    /**
	 * Magic method for accessing invoice properties.
	 *
	 * @since 1.0.15
	 * @access public
	 *
	 * @param string $key Discount data to retrieve
	 * @param  string $context View or edit context.
	 * @return mixed Value of the given invoice property (if set).
	 */
	public function get( $key, $context = 'view' ) {
        return $this->get_prop( $key, $context );
	}

    /*
	|--------------------------------------------------------------------------
	| Setters
	|--------------------------------------------------------------------------
	|
	| Functions for setting item data. These should not update anything in the
	| database itself and should only change what is stored in the class
	| object.
    */

    /**
	 * Magic method for setting invoice properties.
	 *
	 * @since 1.0.19
	 * @access public
	 *
	 * @param string $key Discount data to retrieve
	 * @param  mixed $value new value.
	 * @return mixed Value of the given invoice property (if set).
	 */
	public function set( $key, $value ) {

        $setter = "set_$key";
        if ( is_callable( array( $this, $setter ) ) ) {
            $this->{$setter}( $value );
        }

	}

	/**
	 * Sets item status.
	 *
	 * @since 1.0.19
	 * @param string $new_status    New status.
	 * @param string $note          Optional note to add.
	 * @param bool   $manual_update Is this a manual status change?.
	 * @return array details of change.
	 */
	public function set_status( $new_status, $note = '', $manual_update = false ) {
		$old_status = $this->get_status();

		$this->set_prop( 'status', $new_status );

		// If setting the status, ensure it's set to a valid status.
		if ( true === $this->object_read ) {

			// Only allow valid new status.
			if ( ! array_key_exists( $new_status, wpinv_get_invoice_statuses( false, true ) ) ) {
				$new_status = 'wpi-pending';
			}

			// If the old status is set but unknown (e.g. draft) assume its pending for action usage.
			if ( $old_status && ! array_key_exists( $new_status, wpinv_get_invoice_statuses( false, true ) ) ) {
				$old_status = 'wpi-pending';
			}
		}

		if ( true === $this->object_read && $old_status !== $new_status ) {
			$this->status_transition = array(
				'from'   => ! empty( $this->status_transition['from'] ) ? $this->status_transition['from'] : $old_status,
				'to'     => $new_status,
				'note'   => $note,
				'manual' => (bool) $manual_update,
			);

			if ( $manual_update ) {
				do_action( 'getpaid_' . $this->object_type .'_edit_status', $this->get_id(), $new_status );
			}

			$this->maybe_set_date_paid();

		}

		return array(
			'from' => $old_status,
			'to'   => $new_status,
		);
	}

	/**
	 * Maybe set date paid.
	 *
	 * Sets the date paid variable when transitioning to the payment complete
	 * order status.
	 *
	 * @since 1.0.19
	 */
	public function maybe_set_date_paid() {

		if ( ! $this->get_date_completed( 'edit' ) && $this->is_paid() ) {
			$this->set_date_completed( current_time( 'mysql' ) );
		}
	}

    /**
	 * Set parent invoice ID.
	 *
	 * @since 1.0.19
	 */
	public function set_parent_id( $value ) {
		if ( $value && ( $value === $this->get_id() || ! get_post( $value ) ) ) {
			return;
		}
		$this->set_prop( 'parent_id', absint( $value ) );
    }

    /**
	 * Set plugin version when the invoice was created.
	 *
	 * @since 1.0.19
	 */
	public function set_version( $value ) {
		$this->set_prop( 'version', $value );
    }

    /**
	 * Set date when the invoice was created.
	 *
	 * @since 1.0.19
	 * @param string $value Value to set.
     * @return bool Whether or not the date was set.
	 */
	public function set_date_created( $value ) {
        $date = strtotime( $value );

        if ( $date && $date !== '0000-00-00 00:00:00' ) {
            $this->set_prop( 'date_created', date( 'Y-m-d H:i:s', $date ) );
            return true;
        }

        return $this->set_prop( 'date_created', '' );

    }

    /**
	 * Set date invoice due date.
	 *
	 * @since 1.0.19
	 * @param string $value Value to set.
     * @return bool Whether or not the date was set.
	 */
	public function set_due_date( $value ) {
        $date = strtotime( $value );

        if ( $date && $date !== '0000-00-00 00:00:00' ) {
            $this->set_prop( 'due_date', date( 'Y-m-d H:i:s', $date ) );
            return true;
        }

		$this->set_prop( 'due_date', '' );
        return false;

    }

    /**
	 * Alias of self::set_due_date().
	 *
	 * @since 1.0.19
	 * @param  string $value New name.
	 */
	public function set_date_due( $value ) {
		$this->set_due_date( $value );
    }

    /**
	 * Set date invoice was completed.
	 *
	 * @since 1.0.19
	 * @param string $value Value to set.
     * @return bool Whether or not the date was set.
	 */
	public function set_completed_date( $value ) {
        $date = strtotime( $value );

        if ( $date && $date !== '0000-00-00 00:00:00'  ) {
            $this->set_prop( 'completed_date', date( 'Y-m-d H:i:s', $date ) );
            return true;
        }

		$this->set_prop( 'completed_date', '' );
        return false;

    }

    /**
	 * Alias of self::set_completed_date().
	 *
	 * @since 1.0.19
	 * @param  string $value New name.
	 */
	public function set_date_completed( $value ) {
		$this->set_completed_date( $value );
    }

    /**
	 * Set date when the invoice was last modified.
	 *
	 * @since 1.0.19
	 * @param string $value Value to set.
     * @return bool Whether or not the date was set.
	 */
	public function set_date_modified( $value ) {
        $date = strtotime( $value );

        if ( $date && $date !== '0000-00-00 00:00:00' ) {
            $this->set_prop( 'date_modified', date( 'Y-m-d H:i:s', $date ) );
            return true;
        }

		$this->set_prop( 'date_modified', '' );
        return false;

    }

    /**
	 * Set the invoice number.
	 *
	 * @since 1.0.19
	 * @param  string $value New number.
	 */
	public function set_number( $value ) {
        $number = sanitize_text_field( $value );
		$this->set_prop( 'number', $number );
    }

    /**
	 * Set the invoice type.
	 *
	 * @since 1.0.19
	 * @param  string $value Type.
	 */
	public function set_type( $value ) {
        $type = sanitize_text_field( str_replace( 'wpi_', '', $value ) );
		$this->set_prop( 'type', $type );
    }

    /**
	 * Set the invoice post type.
	 *
	 * @since 1.0.19
	 * @param  string $value Post type.
	 */
	public function set_post_type( $value ) {
        if ( getpaid_is_invoice_post_type( $value ) ) {
            $this->set_prop( 'post_type', $value );
        }
    }

    /**
	 * Set the invoice key.
	 *
	 * @since 1.0.19
	 * @param  string $value New key.
	 */
	public function set_key( $value ) {
        $key = sanitize_text_field( $value );
		$this->set_prop( 'key', $key );
    }

    /**
	 * Set the invoice mode.
	 *
	 * @since 1.0.19
	 * @param  string $value mode.
	 */
	public function set_mode( $value ) {
        if ( ! in_array( $value, array( 'live', 'test' ) ) ) {
            $this->set_prop( 'value', $value );
        }
    }

    /**
	 * Set the invoice path.
	 *
	 * @since 1.0.19
	 * @param  string $value path.
	 */
	public function set_path( $value ) {
        $this->set_prop( 'path', $value );
    }

    /**
	 * Set the invoice name.
	 *
	 * @since 1.0.19
	 * @param  string $value New name.
	 */
	public function set_name( $value ) {
        $name = sanitize_text_field( $value );
		$this->set_prop( 'name', $name );
    }

    /**
	 * Alias of self::set_name().
	 *
	 * @since 1.0.19
	 * @param  string $value New name.
	 */
	public function set_title( $value ) {
		$this->set_name( $value );
    }

    /**
	 * Set the invoice description.
	 *
	 * @since 1.0.19
	 * @param  string $value New description.
	 */
	public function set_description( $value ) {
        $description = wp_kses_post( $value );
		return $this->set_prop( 'description', $description );
    }

    /**
	 * Alias of self::set_description().
	 *
	 * @since 1.0.19
	 * @param  string $value New description.
	 */
	public function set_excerpt( $value ) {
		$this->set_description( $value );
    }

    /**
	 * Alias of self::set_description().
	 *
	 * @since 1.0.19
	 * @param  string $value New description.
	 */
	public function set_summary( $value ) {
		$this->set_description( $value );
    }

    /**
	 * Set the receiver of the invoice.
	 *
	 * @since 1.0.19
	 * @param  int $value New author.
	 */
	public function set_author( $value ) {
		$this->set_prop( 'author', (int) $value );
    }

    /**
	 * Alias of self::set_author().
	 *
	 * @since 1.0.19
	 * @param  int $value New user id.
	 */
	public function set_user_id( $value ) {
		$this->set_author( $value );
    }

    /**
	 * Alias of self::set_author().
	 *
	 * @since 1.0.19
	 * @param  int $value New user id.
	 */
	public function set_customer_id( $value ) {
		$this->set_author( $value );
    }

    /**
	 * Set the customer's ip.
	 *
	 * @since 1.0.19
	 * @param  string $value ip address.
	 */
	public function set_ip( $value ) {
		$this->set_prop( 'ip', $value );
    }

    /**
	 * Alias of self::set_ip().
	 *
	 * @since 1.0.19
	 * @param  string $value ip address.
	 */
	public function set_user_ip( $value ) {
		$this->set_ip( $value );
    }

    /**
	 * Set the customer's first name.
	 *
	 * @since 1.0.19
	 * @param  string $value first name.
	 */
	public function set_first_name( $value ) {
		$this->set_prop( 'first_name', $value );
    }

    /**
	 * Alias of self::set_first_name().
	 *
	 * @since 1.0.19
	 * @param  string $value first name.
	 */
	public function set_user_first_name( $value ) {
		$this->set_first_name( $value );
    }

    /**
	 * Alias of self::set_first_name().
	 *
	 * @since 1.0.19
	 * @param  string $value first name.
	 */
	public function set_customer_first_name( $value ) {
		$this->set_first_name( $value );
    }

    /**
	 * Set the customer's last name.
	 *
	 * @since 1.0.19
	 * @param  string $value last name.
	 */
	public function set_last_name( $value ) {
		$this->set_prop( 'last_name', $value );
    }

    /**
	 * Alias of self::set_last_name().
	 *
	 * @since 1.0.19
	 * @param  string $value last name.
	 */
	public function set_user_last_name( $value ) {
		$this->set_last_name( $value );
    }

    /**
	 * Alias of self::set_last_name().
	 *
	 * @since 1.0.19
	 * @param  string $value last name.
	 */
	public function set_customer_last_name( $value ) {
		$this->set_last_name( $value );
    }

    /**
	 * Set the customer's phone number.
	 *
	 * @since 1.0.19
	 * @param  string $value phone.
	 */
	public function set_phone( $value ) {
		$this->set_prop( 'phone', $value );
    }

    /**
	 * Alias of self::set_phone().
	 *
	 * @since 1.0.19
	 * @param  string $value phone.
	 */
	public function set_user_phone( $value ) {
		$this->set_phone( $value );
    }

    /**
	 * Alias of self::set_phone().
	 *
	 * @since 1.0.19
	 * @param  string $value phone.
	 */
	public function set_customer_phone( $value ) {
		$this->set_phone( $value );
    }

    /**
	 * Alias of self::set_phone().
	 *
	 * @since 1.0.19
	 * @param  string $value phone.
	 */
	public function set_phone_number( $value ) {
		$this->set_phone( $value );
    }

    /**
	 * Set the customer's email address.
	 *
	 * @since 1.0.19
	 * @param  string $value email address.
	 */
	public function set_email( $value ) {
		$this->set_prop( 'email', $value );
    }

    /**
	 * Alias of self::set_email().
	 *
	 * @since 1.0.19
	 * @param  string $value email address.
	 */
	public function set_user_email( $value ) {
		$this->set_email( $value );
    }

    /**
	 * Alias of self::set_email().
	 *
	 * @since 1.0.19
	 * @param  string $value email address.
	 */
	public function set_email_address( $value ) {
		$this->set_email( $value );
    }

    /**
	 * Alias of self::set_email().
	 *
	 * @since 1.0.19
	 * @param  string $value email address.
	 */
	public function set_customer_email( $value ) {
		$this->set_email( $value );
    }

    /**
	 * Set the customer's country.
	 *
	 * @since 1.0.19
	 * @param  string $value country.
	 */
	public function set_country( $value ) {
		$this->set_prop( 'country', $value );
    }

    /**
	 * Alias of self::set_country().
	 *
	 * @since 1.0.19
	 * @param  string $value country.
	 */
	public function set_user_country( $value ) {
		$this->set_country( $value );
    }

    /**
	 * Alias of self::set_country().
	 *
	 * @since 1.0.19
	 * @param  string $value country.
	 */
	public function set_customer_country( $value ) {
		$this->set_country( $value );
    }

    /**
	 * Set the customer's state.
	 *
	 * @since 1.0.19
	 * @param  string $value state.
	 */
	public function set_state( $value ) {
		$this->set_prop( 'state', $value );
    }

    /**
	 * Alias of self::set_state().
	 *
	 * @since 1.0.19
	 * @param  string $value state.
	 */
	public function set_user_state( $value ) {
		$this->set_state( $value );
    }

    /**
	 * Alias of self::set_state().
	 *
	 * @since 1.0.19
	 * @param  string $value state.
	 */
	public function set_customer_state( $value ) {
		$this->set_state( $value );
    }

    /**
	 * Set the customer's city.
	 *
	 * @since 1.0.19
	 * @param  string $value city.
	 */
	public function set_city( $value ) {
		$this->set_prop( 'city', $value );
    }

    /**
	 * Alias of self::set_city().
	 *
	 * @since 1.0.19
	 * @param  string $value city.
	 */
	public function set_user_city( $value ) {
		$this->set_city( $value );
    }

    /**
	 * Alias of self::set_city().
	 *
	 * @since 1.0.19
	 * @param  string $value city.
	 */
	public function set_customer_city( $value ) {
		$this->set_city( $value );
    }

    /**
	 * Set the customer's zip code.
	 *
	 * @since 1.0.19
	 * @param  string $value zip.
	 */
	public function set_zip( $value ) {
		$this->set_prop( 'zip', $value );
    }

    /**
	 * Alias of self::set_zip().
	 *
	 * @since 1.0.19
	 * @param  string $value zip.
	 */
	public function set_user_zip( $value ) {
		$this->set_zip( $value );
    }

    /**
	 * Alias of self::set_zip().
	 *
	 * @since 1.0.19
	 * @param  string $value zip.
	 */
	public function set_customer_zip( $value ) {
		$this->set_zip( $value );
    }

    /**
	 * Set the customer's company.
	 *
	 * @since 1.0.19
	 * @param  string $value company.
	 */
	public function set_company( $value ) {
		$this->set_prop( 'company', $value );
    }

    /**
	 * Alias of self::set_company().
	 *
	 * @since 1.0.19
	 * @param  string $value company.
	 */
	public function set_user_company( $value ) {
		$this->set_company( $value );
    }

    /**
	 * Alias of self::set_company().
	 *
	 * @since 1.0.19
	 * @param  string $value company.
	 */
	public function set_customer_company( $value ) {
		$this->set_company( $value );
    }

    /**
	 * Set the customer's var number.
	 *
	 * @since 1.0.19
	 * @param  string $value var number.
	 */
	public function set_vat_number( $value ) {
		$this->set_prop( 'vat_number', $value );
    }

    /**
	 * Alias of self::set_vat_number().
	 *
	 * @since 1.0.19
	 * @param  string $value var number.
	 */
	public function set_user_vat_number( $value ) {
		$this->set_vat_number( $value );
    }

    /**
	 * Alias of self::set_vat_number().
	 *
	 * @since 1.0.19
	 * @param  string $value var number.
	 */
	public function set_customer_vat_number( $value ) {
		$this->set_vat_number( $value );
    }

    /**
	 * Set the customer's vat rate.
	 *
	 * @since 1.0.19
	 * @param  string $value var rate.
	 */
	public function set_vat_rate( $value ) {
		$this->set_prop( 'vat_rate', $value );
    }

    /**
	 * Alias of self::set_vat_rate().
	 *
	 * @since 1.0.19
	 * @param  string $value var number.
	 */
	public function set_user_vat_rate( $value ) {
		$this->set_vat_rate( $value );
    }

    /**
	 * Alias of self::set_vat_rate().
	 *
	 * @since 1.0.19
	 * @param  string $value var number.
	 */
	public function set_customer_vat_rate( $value ) {
		$this->set_vat_rate( $value );
    }

    /**
	 * Set the customer's address.
	 *
	 * @since 1.0.19
	 * @param  string $value address.
	 */
	public function set_address( $value ) {
		$this->set_prop( 'address', $value );
    }

    /**
	 * Alias of self::set_address().
	 *
	 * @since 1.0.19
	 * @param  string $value address.
	 */
	public function set_user_address( $value ) {
		$this->set_address( $value );
    }

    /**
	 * Alias of self::set_address().
	 *
	 * @since 1.0.19
	 * @param  string $value address.
	 */
	public function set_customer_address( $value ) {
		$this->set_address( $value );
    }

    /**
	 * Set whether the customer has viewed the invoice or not.
	 *
	 * @since 1.0.19
	 * @param  int|bool $value confirmed.
	 */
	public function set_is_viewed( $value ) {
		$this->set_prop( 'is_viewed', $value );
	}

	/**
	 * Set extra email recipients.
	 *
	 * @since 1.0.19
	 * @param  string $value email recipients.
	 */
	public function set_email_cc( $value ) {
		$this->set_prop( 'email_cc', $value );
	}

	/**
	 * Set the invoice template.
	 *
	 * @since 1.0.19
	 * @param  string $value email recipients.
	 */
	public function set_template( $value ) {
		if ( in_array( $value, array( 'quantity', 'hours', 'amount' ) ) ) {
			$this->set_prop( 'template', $value );
		}
	}

	/**
	 * Set the customer's address confirmed status.
	 *
	 * @since 1.0.19
	 * @param  int|bool $value confirmed.
	 */
	public function set_address_confirmed( $value ) {
		$this->set_prop( 'address_confirmed', $value );
    }

    /**
	 * Alias of self::set_address_confirmed().
	 *
	 * @since 1.0.19
	 * @param  int|bool $value confirmed.
	 */
	public function set_user_address_confirmed( $value ) {
		$this->set_address_confirmed( $value );
    }

    /**
	 * Alias of self::set_address_confirmed().
	 *
	 * @since 1.0.19
	 * @param  int|bool $value confirmed.
	 */
	public function set_customer_address_confirmed( $value ) {
		$this->set_address_confirmed( $value );
    }

    /**
	 * Set the invoice sub total.
	 *
	 * @since 1.0.19
	 * @param  float $value sub total.
	 */
	public function set_subtotal( $value ) {
		$this->set_prop( 'subtotal', $value );
    }

    /**
	 * Set the invoice discount amount.
	 *
	 * @since 1.0.19
	 * @param  float $value discount total.
	 */
	public function set_total_discount( $value ) {
		$this->set_prop( 'total_discount', $value );
    }

    /**
	 * Alias of self::set_total_discount().
	 *
	 * @since 1.0.19
	 * @param  float $value discount total.
	 */
	public function set_discount( $value ) {
		$this->set_total_discount( $value );
    }

    /**
	 * Set the invoice tax amount.
	 *
	 * @since 1.0.19
	 * @param  float $value tax total.
	 */
	public function set_total_tax( $value ) {
		$this->set_prop( 'total_tax', $value );
    }

    /**
	 * Alias of self::set_total_tax().
	 *
	 * @since 1.0.19
	 * @param  float $value tax total.
	 */
	public function set_tax_total( $value ) {
		$this->set_total_tax( $value );
    }

    /**
	 * Set the invoice fees amount.
	 *
	 * @since 1.0.19
	 * @param  float $value fees total.
	 */
	public function set_total_fees( $value ) {
		$this->set_prop( 'total_fees', $value );
    }

    /**
	 * Alias of self::set_total_fees().
	 *
	 * @since 1.0.19
	 * @param  float $value fees total.
	 */
	public function set_fees_total( $value ) {
		$this->set_total_fees( $value );
    }

    /**
	 * Set the invoice fees.
	 *
	 * @since 1.0.19
	 * @param  array $value fees.
	 */
	public function set_fees( $value ) {

        $this->set_prop( 'fees', array() );

        // Ensure that we have an array.
        if ( ! is_array( $value ) ) {
            return;
        }

        foreach ( $value as $name => $data ) {
            if ( isset( $data['amount'] ) ) {
                $this->add_fee( $name, $data['amount'], $data['recurring'] );
            }
        }

    }

    /**
	 * Set the invoice taxes.
	 *
	 * @since 1.0.19
	 * @param  array $value taxes.
	 */
	public function set_taxes( $value ) {
		$this->set_prop( 'taxes', $value );
    }

    /**
	 * Set the invoice discounts.
	 *
	 * @since 1.0.19
	 * @param  array $value discounts.
	 */
	public function set_discounts( $value ) {
		$this->set_prop( 'discounts', array() );

        // Ensure that we have an array.
        if ( ! is_array( $value ) ) {
            return;
        }

        foreach ( $value as $name => $data ) {
            if ( isset( $data['amount'] ) ) {
                $this->add_discount( $name, $data['amount'], $data['recurring'] );
            }
        }
    }

    /**
	 * Set the invoice items.
	 *
	 * @since 1.0.19
	 * @param  GetPaid_Form_Item[] $value items.
	 */
	public function set_items( $value ) {

        // Remove existing items.
        $this->set_prop( 'items', array() );

        // Ensure that we have an array.
        if ( ! is_array( $value ) ) {
            return;
        }

        foreach ( $value as $item ) {
            $this->add_item( $item );
        }

    }

    /**
	 * Set the payment form.
	 *
	 * @since 1.0.19
	 * @param  int $value payment form.
	 */
	public function set_payment_form( $value ) {
		$this->set_prop( 'payment_form', $value );
    }

    /**
	 * Set the submission id.
	 *
	 * @since 1.0.19
	 * @param  string $value submission id.
	 */
	public function set_submission_id( $value ) {
		$this->set_prop( 'submission_id', $value );
    }

    /**
	 * Set the discount code.
	 *
	 * @since 1.0.19
	 * @param  string $value discount code.
	 */
	public function set_discount_code( $value ) {
		$this->set_prop( 'discount_code', $value );
    }

    /**
	 * Set the gateway.
	 *
	 * @since 1.0.19
	 * @param  string $value gateway.
	 */
	public function set_gateway( $value ) {
		$this->set_prop( 'gateway', $value );
    }

    /**
	 * Set the transaction id.
	 *
	 * @since 1.0.19
	 * @param  string $value transaction id.
	 */
	public function set_transaction_id( $value ) {
		$this->set_prop( 'transaction_id', $value );
    }

    /**
	 * Set the currency id.
	 *
	 * @since 1.0.19
	 * @param  string $value currency id.
	 */
	public function set_currency( $value ) {
		$this->set_prop( 'currency', $value );
    }

    /**
	 * Set the subscription id.
	 *
	 * @since 1.0.19
	 * @param  string $value subscription id.
	 */
	public function set_subscription_id( $value ) {
		$this->set_prop( 'subscription_id', $value );
    }

    /*
	|--------------------------------------------------------------------------
	| Boolean methods
	|--------------------------------------------------------------------------
	|
	| Return true or false.
	|
    */

    /**
     * Checks if this is a parent invoice.
     */
    public function is_parent() {
        $parent = $this->get_parent_id();
        return apply_filters( 'wpinv_invoice_is_parent', empty( $parent ), $this );
    }

    /**
     * Checks if this is a renewal invoice.
     */
    public function is_renewal() {
        return ! $this->is_parent();
    }

    /**
     * Checks if this is a recurring invoice.
     */
    public function is_recurring() {
        return $this->is_renewal() || ! empty( $this->recurring_item );
    }

    /**
     * Checks if this is a taxable invoice.
     */
    public function is_taxable() {
        return (int) $this->disable_taxes === 0;
	}

	/**
	 * @deprecated
	 */
	public function has_vat() {
        global $wpinv_euvat, $wpi_country;

        $requires_vat = false;

        if ( $this->country ) {
            $wpi_country        = $this->country;
            $requires_vat       = $wpinv_euvat->requires_vat( $requires_vat, $this->get_user_id(), $wpinv_euvat->invoice_has_digital_rule( $this ) );
        }

        return apply_filters( 'wpinv_invoice_has_vat', $requires_vat, $this );
	}

	/**
	 * Checks to see if the invoice requires payment.
	 */
	public function is_free() {
        $is_free = ! ( (float) wpinv_round_amount( $this->get_initial_total() ) > 0 );

		if ( $is_free && $this->is_recurring() ) {
			$is_free = ! ( (float) wpinv_round_amount( $this->get_recurring_total() ) > 0 );
		}

        return apply_filters( 'wpinv_invoice_is_free', $is_free, $this );
    }

    /**
     * Checks if the invoice is paid.
     */
    public function is_paid() {
        $is_paid = $this->has_status( array( 'publish', 'wpi-processing', 'wpi-renewal' ) );
        return apply_filters( 'wpinv_invoice_is_paid', $is_paid, $this );
	}

	/**
     * Checks if the invoice needs payment.
     */
	public function needs_payment() {
		$needs_payment = ! $this->is_paid() && ! $this->is_free();
        return apply_filters( 'wpinv_needs_payment', $needs_payment, $this );
    }

	/**
     * Checks if the invoice is refunded.
     */
	public function is_refunded() {
        $is_refunded = $this->has_status( 'wpi-refunded' );
        return apply_filters( 'wpinv_invoice_is_refunded', $is_refunded, $this );
	}

	/**
     * Checks if the invoice is draft.
     */
	public function is_draft() {
        return $this->has_status( 'draft, auto-draft' );
	}

    /**
     * Checks if the invoice has a given status.
     */
    public function has_status( $status ) {
        $status = wpinv_parse_list( $status );
        return apply_filters( 'wpinv_has_status', in_array( $this->get_status(), $status ), $status );
	}

	/**
     * Checks if the invoice is of a given type.
     */
    public function is_type( $type ) {
        $type = wpinv_parse_list( $type );
        return in_array( $this->get_type(), $type );
    }

    /**
     * Checks if this is a quote object.
     *
     * @since 1.0.15
     */
    public function is_quote() {
        return $this->has_status( 'wpi_quote' );
    }

    /**
     * Check if the invoice (or it's parent has a free trial).
     *
     */
    public function has_free_trial() {
        return $this->is_recurring() && 0 == $this->get_initial_total();
	}

	/**
     * @deprecated
     */
    public function is_free_trial() {
        $this->has_free_trial();
    }

	/**
     * Check if the initial payment if 0.
     *
     */
	public function is_initial_free() {
        $is_initial_free = ! ( (float) wpinv_round_amount( $this->get_initial_total() ) > 0 );
        return apply_filters( 'wpinv_invoice_is_initial_free', $is_initial_free, $this->get_cart_details(), $this );
    }
	
	/**
     * Check if the recurring item has a free trial.
     *
     */
    public function item_has_free_trial() {

        // Ensure we have a recurring item.
        if ( ! $this->is_recurring() ) {
            return false;
        }

        $item = new WPInv_Item( $this->recurring_item );
        return $item->has_free_trial();
	}

	/**
     * Check if the free trial is a result of a discount.
     */
    public function is_free_trial_from_discount() {
		return $this->has_free_trial() && ! $this->item_has_free_trial();
	}
	
	/**
     * @deprecated
     */
    public function discount_first_payment_only() {

		$discount_code = $this->get_discount_code();
        if ( empty( $this->discount_code ) || ! $this->is_recurring() ) {
            return true;
        }

        $discount = wpinv_get_discount_obj( $discount_code );

        if ( ! $discount || ! $discount->exists() ) {
            return true;
        }

        return ! $discount->get_is_recurring();
    }

    /*
	|--------------------------------------------------------------------------
	| Cart related methods
	|--------------------------------------------------------------------------
	|
	| Do not forget to recalculate totals after calling the following methods.
	|
    */

    /**
     * Adds an item to the invoice.
     *
     * @param GetPaid_Form_Item $item
     * @return WP_Error|Bool
     */
    public function add_item( $item ) {

        // Make sure that it is available for purchase.
		if ( $item->get_id() > 0 && ! $item->can_purchase() ) {
			return new WP_Error( 'invalid_item', __( 'This item is not available for purchase', 'invoicing' ) );
        }

        // Do we have a recurring item?
		if ( $item->is_recurring() ) {
			$this->recurring_item = $item->get_id();
        }

        // Invoice id.
        $item->invoice_id = $this->get_id();

        // Retrieve all items.
        $items = $this->get_items();
        $items[ $item->get_id() ] = $item;

        $this->set_prop( 'items', $items );

    }

    /**
	 * Retrieves a specific item.
	 *
	 * @since 1.0.19
	 */
	public function get_item( $item_id ) {
        $items = $this->get_items();
		return ( ! empty( $item_id ) && isset( $items[ $item_id ] ) ) ? $items[ $item_id ] : null;
    }

    /**
	 * Removes a specific item.
	 *
	 * @since 1.0.19
	 */
	public function remove_item( $item_id ) {
        $items = $this->get_items();

        if ( $item_id == $this->recurring_item ) {
            $this->recurring_item = null;
        }

        if ( isset( $items[ $item_id ] ) ) {
            unset( $items[ $item_id ] );
            $this->set_prop( 'items', $items );
        }
    }

    /**
     * Adds a fee to the invoice.
     *
     * @param string $fee
     * @param float $value
     * @return WP_Error|Bool
     */
    public function add_fee( $fee, $value, $recurring = false ) {

        $amount = wpinv_sanitize_amount( $value );
        $fees   = $this->get_fees();

        if ( isset( $fees[ $fee ] ) && isset( $fees[ $fee ]['amount'] ) ) {

            $amount = $fees[ $fee ]['amount'] += $amount;
			$fees[ $fee ] = array(
                'amount'    => $amount,
                'recurring' => (bool) $recurring,
            );

		} else {
			$fees[ $fee ] = array(
                'amount'    => $amount,
                'recurring' => (bool) $recurring,
            );
		}

        $this->set_prop( 'fees', $fee );

    }

    /**
	 * Retrieves a specific fee.
	 *
	 * @since 1.0.19
	 */
	public function get_fee( $fee ) {
        $fees = $this->get_fees();
		return isset( $fees[ $fee ] ) ? $fees[ $fee ] : null;
    }

    /**
	 * Removes a specific fee.
	 *
	 * @since 1.0.19
	 */
	public function remove_fee( $fee ) {
        $fees = $this->get_fees();
        if ( isset( $fees[ $fee ] ) ) {
            unset( $fees[ $fee ] );
            $this->set_prop( 'fees', $fees );
        }
    }

    /**
     * Adds a discount to the invoice.
     *
     * @param string $discount
     * @param float $value
     * @return WP_Error|Bool
     */
    public function add_discount( $discount, $value, $recurring = false ) {

        $amount    = wpinv_sanitize_amount( $value );
        $discounts = $this->get_discounts();

        if ( isset( $discounts[ $discount ] ) && isset( $discounts[ $discount ]['amount'] ) ) {

            $amount = $discounts[ $discount ]['amount'] += $amount;
			$discounts[ $discount ] = array(
                'amount'    => $amount,
                'recurring' => (bool) $recurring,
            );

		} else {
			$discounts[ $discount ] = array(
                'amount'    => $amount,
                'recurring' => (bool) $recurring,
            );
		}

        $this->set_prop( 'discounts', $discount );

    }

    /**
	 * Retrieves a specific discount.
	 *
	 * @since 1.0.19
	 */
	public function get_discount( $discount = false ) {

		// Backwards compatibilty.
		if ( empty( $discount ) ) {
			return $this->get_total_discount();
		}

        $discounts = $this->get_discounts();
		return isset( $discounts[ $discount ] ) ? $discounts[ $discount ] : null;
    }

    /**
	 * Removes a specific discount.
	 *
	 * @since 1.0.19
	 */
	public function remove_discount( $discount ) {
        $discounts = $this->get_discounts();
        if ( isset( $discounts[ $discount ] ) ) {
            unset( $discounts[ $discount ] );
            $this->set_prop( 'discounts', $discounts );
        }
    }

    /**
     * Adds a tax to the invoice.
     *
     * @param string $tax
     * @param float $value
     */
    public function add_tax( $tax, $value, $recurring = true ) {

        if ( ! $this->is_taxable() ) {
            return;
        }

        $amount    = wpinv_sanitize_amount( $value );
        $taxes     = $this->get_taxes();

        if ( isset( $taxes[ $tax ] ) && isset( $taxes[ $tax ]['amount'] ) ) {

            $amount = $taxes[ $tax ]['amount'] += $amount;
			$taxes[ $tax ] = array(
                'amount'    => $amount,
                'recurring' => (bool) $recurring,
            );

		} else {
			$taxes[ $tax ] = array(
                'amount'    => $amount,
                'recurring' => (bool) $recurring,
            );
		}

        $this->set_prop( 'taxes', $tax );

    }

    /**
	 * Retrieves a specific tax.
	 *
	 * @since 1.0.19
	 */
	public function get_tax( $tax ) {
        $taxes = $this->get_taxes();
		return isset( $taxes[ $tax ] ) ? $taxes[ $tax ] : null;
    }

    /**
	 * Removes a specific tax.
	 *
	 * @since 1.0.19
	 */
	public function remove_tax( $tax ) {
        $taxes = $this->get_discounts();
        if ( isset( $taxes[ $tax ] ) ) {
            unset( $taxes[ $tax ] );
            $this->set_prop( 'taxes', $taxes );
        }
    }

    /**
	 * Recalculates the invoice subtotal.
	 *
	 * @since 1.0.19
	 * @return float The recalculated subtotal
	 */
	public function recalculate_subtotal() {
        $items     = $this->get_items();
		$subtotal  = 0;
		$recurring = 0;

        foreach ( $items as $item ) {
			$subtotal  += $item->get_sub_total();
			$recurring += $item->get_recurring_sub_total();
        }

		if ( $this->is_renewal() ) {
			$this->set_subtotal( $recurring );
		} else {
			$this->set_subtotal( $subtotal );
		}

		$this->totals['subtotal'] = array(
			'initial'   => $subtotal,
			'recurring' => $recurring,
		);

        return $this->is_renewal() ? $recurring : $subtotal;
    }

    /**
	 * Recalculates the invoice discount total.
	 *
	 * @since 1.0.19
	 * @return float The recalculated discount
	 */
	public function recalculate_total_discount() {
        $discounts = $this->get_discounts();
		$discount  = 0;
		$recurring = 0;

        foreach ( $discounts as $data ) {

			if ( $data['recurring'] ) {
				$recurring += $data['amount'];
			} else {
				$discount += $data['amount'];
			}

		}

		if ( $this->is_renewal() ) {
			$this->set_total_discount( $recurring );
		} else {
			$this->set_total_discount( $discount );
		}

		$this->totals['discount'] = array(
			'initial'   => $discount,
			'recurring' => $recurring,
		);

		return $this->is_renewal() ? $recurring : $discount;

    }

    /**
	 * Recalculates the invoice tax total.
	 *
	 * @since 1.0.19
	 * @return float The recalculated tax
	 */
	public function recalculate_total_tax() {
        $taxes     = $this->get_taxes();
		$tax       = 0;
		$recurring = 0;

        foreach ( $taxes as $data ) {

			if ( $data['recurring'] ) {
				$recurring += $data['amount'];
			} else {
				$tax += $data['amount'];
			}

		}

		if ( $this->is_renewal() ) {
			$this->set_total_tax( $recurring );
		} else {
			$this->set_total_tax( $tax );
		}

		$this->totals['tax'] = array(
			'initial'   => $tax,
			'recurring' => $recurring,
		);

		return $this->is_renewal() ? $recurring : $tax;

    }

    /**
	 * Recalculates the invoice fees total.
	 *
	 * @since 1.0.19
	 * @return float The recalculated fee
	 */
	public function recalculate_total_fees() {
		$fees      = $this->get_fees();
		$fee       = 0;
		$recurring = 0;

        foreach ( $fees as $data ) {

			if ( $data['recurring'] ) {
				$recurring += $data['amount'];
			} else {
				$fee += $data['amount'];
			}

		}

        if ( $this->is_renewal() ) {
			$this->set_total_fees( $recurring );
		} else {
			$this->set_total_fees( $fee );
		}

		$this->totals['fee'] = array(
			'initial'   => $fee,
			'recurring' => $recurring,
		);

        $this->set_total_fees( $fee );
        return $this->is_renewal() ? $recurring : $fee;
    }

    /**
	 * Recalculates the invoice total.
	 *
	 * @since 1.0.19
     * @return float The invoice total
	 */
	public function recalculate_total() {
        $this->recalculate_subtotal();
        $this->recalculate_total_fees();
        $this->recalculate_total_discount();
        $this->recalculate_total_tax();
		return $this->get_total();
	}

	/**
	 * @deprecated
	 */
    public function recalculate_totals( $temp = false ) {
        $this->update_items( $temp );
        $this->save( true );
        return $this;
    }

    /**
     * Convert this to an array.
     */
    public function array_convert() {
        return $this->get_data();
    }

    /**
     * Adds a note to an invoice.
     *
     * @param string $note The note being added.
     *
     */
    public function add_note( $note = '', $customer_type = false, $added_by_user = false, $system = false ) {

        // Bail if no note specified or this invoice is not yet saved.
        if ( ! $note || $this->get_id() == 0 ) {
            return false;
        }

        if ( ( ( is_user_logged_in() && wpinv_current_user_can_manage_invoicing() ) || $added_by_user ) && !$system ) {
            $user                 = get_user_by( 'id', get_current_user_id() );
            $comment_author       = $user->display_name;
            $comment_author_email = $user->user_email;
        } else {
            $comment_author       = 'System';
            $comment_author_email = 'system@';
            $comment_author_email .= isset( $_SERVER['HTTP_HOST'] ) ? str_replace( 'www.', '', $_SERVER['HTTP_HOST'] ) : 'noreply.com';
            $comment_author_email = sanitize_email( $comment_author_email );
        }

        do_action( 'wpinv_pre_insert_invoice_note', $this->get_id(), $note, $customer_type );

        $note_id = wp_insert_comment( wp_filter_comment( array(
            'comment_post_ID'      => $this->get_id(),
            'comment_content'      => $note,
            'comment_agent'        => 'GetPaid',
            'user_id'              => is_admin() ? get_current_user_id() : 0,
            'comment_date'         => current_time( 'mysql' ),
            'comment_date_gmt'     => current_time( 'mysql', 1 ),
            'comment_approved'     => 1,
            'comment_parent'       => 0,
            'comment_author'       => $comment_author,
            'comment_author_IP'    => wpinv_get_ip(),
            'comment_author_url'   => '',
            'comment_author_email' => $comment_author_email,
            'comment_type'         => 'wpinv_note'
        ) ) );

        do_action( 'wpinv_insert_payment_note', $note_id, $this->get_id(), $note );

        if ( $customer_type ) {
            add_comment_meta( $note_id, '_wpi_customer_note', 1 );
            do_action( 'wpinv_new_customer_note', array( 'invoice_id' => $this->get_id(), 'user_note' => $note ) );
        }

        return $note_id;
	}

	/**
     * Generates a unique key for the invoice.
     */
    public function generate_key( $string = '' ) {
        $auth_key  = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        return strtolower(
            md5( $this->get_id() . $string . date( 'Y-m-d H:i:s' ) . $auth_key . uniqid( 'wpinv', true ) )
        );
    }

    /**
     * Generates a new number for the invoice.
     */
    public function generate_number() {
        $number = $this->get_id();

        if ( $this->has_status( 'auto-draft' ) && wpinv_sequential_number_active( $this->post_type ) ) {
            $next_number = wpinv_get_next_invoice_number( $this->post_type );
            $number      = $next_number;
        }

		$number = wpinv_format_invoice_number( $number, $this->post_type );

		return $number;
	}

	/**
	 * Handle the status transition.
	 */
	protected function status_transition() {
		$status_transition = $this->status_transition;

		// Reset status transition variable.
		$this->status_transition = false;

		if ( $status_transition ) {
			try {

				// Fire a hook for the status change.
				do_action( 'getpaid_invoice_status_' . $status_transition['to'], $this->get_id(), $this, $status_transition );

				// @deprecated this is deprecated and will be removed in the future.
				do_action( 'wpinv_status_' . $status_transition['to'], $this->get_id(), $status_transition['from'] );

				if ( ! empty( $status_transition['from'] ) ) {

					/* translators: 1: old invoice status 2: new invoice status */
					$transition_note = sprintf( __( 'Status changed from %1$s to %2$s.', 'invoicing' ), wpinv_status_nicename( $status_transition['from'] ), wpinv_status_nicename( $status_transition['to'] ) );

					// Fire another hook.
					do_action( 'getpaid_invoice_status_' . $status_transition['from'] . '_to_' . $status_transition['to'], $this->get_id(), $this );
					do_action( 'getpaid_invoice_status_changed', $this->get_id(), $status_transition['from'], $status_transition['to'], $this );

					// @deprecated this is deprecated and will be removed in the future.
					do_action( 'wpinv_status_' . $status_transition['from'] . '_to_' . $status_transition['to'], $this->ID, $status_transition['from'] );

					// Note the transition occurred.
					$this->add_note( trim( $status_transition['note'] . ' ' . $transition_note ), 0, $status_transition['manual'] );

					// Work out if this was for a payment, and trigger a payment_status hook instead.
					if (
						in_array( $status_transition['from'], array( 'wpi-cancelled', 'wpi-pending', 'wpi-failed' ), true )
						&& in_array( $status_transition['to'], array( 'publish', 'wpi-processing', 'wpi-renewal' ), true )
					) {
						do_action( 'getpaid_invoice_payment_status_changed', $this->get_id(), $this, $status_transition );
					}
				} else {
					/* translators: %s: new invoice status */
					$transition_note = sprintf( __( 'Status set to %s.', 'invoicing' ), wpinv_status_nicename( $status_transition['to'] ) );

					// Note the transition occurred.
					$this->add_note( trim( $status_transition['note'] . ' ' . $transition_note ), 0, $status_transition['manual'] );

				}
			} catch ( Exception $e ) {
				$this->add_note( __( 'Error during status transition.', 'invoicing' ) . ' ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Updates an invoice status.
	 */
	public function update_status( $new_status = false, $note = '', $manual = false ) {

		// Fires before updating a status.
		do_action( 'wpinv_before_invoice_status_change', $this->ID, $new_status, $this->get_status( 'edit' ) );

		// Update the status.
		$this->set_status( $new_status, $note, $manual );

		// Save the order.
		return $this->save();

	}

	/**
	 * @deprecated
	 */
	public function refresh_item_ids() {
        $item_ids = implode( ',', array_unique( array_keys( $this->get_items() ) ) );
        update_post_meta( $this->get_id(), '_wpinv_item_ids', $item_ids );
	}

	/**
	 * @deprecated
	 */
	public function update_items( $temp = false ) {

		$this->set_items( $this->get_items() );

		if ( ! $temp ) {
			$this->save();
		}

        return $this;
	}

	/**
	 * @deprecated
	 */
    public function validate_discount() {

        $discount_code = $this->get_discount_code();

        if ( empty( $discount_code ) ) {
            return false;
        }

        $discount = wpinv_get_discount_obj( $discount_code );

        // Ensure it is active.
        return $discount->exists();

    }

	/**
	 * Refunds an invoice.
	 */
    public function refund() {
		$this->set_status( 'wpi-refunded' );
        $this->save();
    }

	/**
	 * Save data to the database.
	 *
	 * @since 1.0.19
	 * @return int invoice ID
	 */
	public function save() {
		$this->maybe_set_date_paid();
		parent::save();
		$this->status_transition();
		return $this->get_id();
	}

}
