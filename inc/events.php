<?php
/**
 * Event Calendar Base
 *
 * @package    BE-Events-Calendar
 * @since      1.0.0
 * @link       https://github.com/billerickson/BE-Events-Calendar
 * @author     Bill Erickson <bill@billerickson.net>
 * @copyright  Copyright (c) 2014, Bill Erickson
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */
 
class BE_Events_Calendar {
	
	var $post_type_name = 'events';
	
	/**
	 * Primary class constructor
	 *
	 * @since 1.0.0
	 */
	function __construct() {

		// Fire on activation
		register_activation_hook( BE_EVENTS_CALENDAR_FILE, array( $this, 'activation' ) );

		// Load the plugin base
		add_action( 'plugins_loaded', array( $this, 'init' ) );	
	}
	
	/**
	 * Flush the WordPress permalink rewrite rules on activation
	 *
	 * @since 1.0.0
	 */
	function activation() {

		$this->post_type();
		flush_rewrite_rules();
	}

	/**
	 * Loads the plugin base into WordPress
	 *
	 * @since 1.0.0
	 */
	function init() {
	
		// Create Post Type
		add_action( 'init', array( $this, 'post_type' ) );
		
		// Post Type columns
		add_filter( 'manage_edit-events_columns', array( $this, 'edit_event_columns' ) ) ;
		add_action( 'manage_events_posts_custom_column', array( $this, 'manage_event_columns' ), 10, 2 );

		// Post Type sorting
		add_filter( 'manage_edit-events_sortable_columns', array( $this, 'event_sortable_columns' ) );
		add_action( 'load-edit.php', array( $this, 'edit_event_load' ) );

		// Post Type title placeholder
		add_action( 'gettext',  array( $this, 'title_placeholder' ) );
		
		// Create Taxonomy
		add_action( 'init', array( $this, 'taxonomies' ) );
		
		// Create Metabox
		$metabox = apply_filters( 'be_events_manager_metabox_override', false );
		if ( false === $metabox ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'metabox_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'metabox_scripts' ) );
			add_action( 'add_meta_boxes', array( $this, 'metabox_register' ) );
			add_action( 'save_post', array( $this, 'metabox_save' ),  1, 2  );
		}
		
		// Add Hooks to Generate Recurring Events
		$this->add_insert_post_hooks();
		
		// Avoid generating events on trash/untrash post, which can cause all sorts of issues
		add_action( 'wp_trash_post', array( $this, 'remove_insert_post_hooks' ) );
		add_action( 'untrash_post', array( $this, 'remove_insert_post_hooks' ) );
		add_action( 'trashed_post', array( $this, 'add_insert_post_hooks' ) );
		add_action( 'untrashed_post', array( $this, 'add_insert_post_hooks' ) );
		
		// Modify Event Listings query
		add_action( 'pre_get_posts', array( $this, 'event_query' ) );
	}
	
	/**
	 * Check if recurring events are supported.
	 *
	 * @since 1.2.0
	 *
	 * @return boolean
	 */
	function recurring_supported() {
		$supports = get_theme_support( 'be-events-calendar' );
		if( isset( $supports[0] ) && is_array( $supports[0] ) && in_array( 'recurring-events', $supports[0] ) )
			return true;
		
		return false;
	}
	
	/** 
	 * Register Post Type
	 *
	 * @since 1.0.0
	 */
	function post_type() {

		$labels = array(
			'name'               => 'Events',
			'singular_name'      => 'Event',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Event',
			'edit_item'          => 'Edit Event',
			'new_item'           => 'New Event',
			'view_item'          => 'View Event',
			'search_items'       => 'Search Events',
			'not_found'          =>  'No events found',
			'not_found_in_trash' => 'No events found in trash',
			'parent_item_colon'  => '',
			'menu_name'          => 'Events'
		);
		
		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true, 
			'show_in_menu'       => true, 
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'events', 'with_front' => false ),
			'capability_type'    => 'post',
			'has_archive'        => true, 
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => array( 'title', 'editor' ),
			'menu_icon'          => 'dashicons-calendar',
		); 
	
		$args = apply_filters( 'be_events_manager_post_type_args', $args );
		register_post_type( $this->post_type_name, $args );	
	}
	
	/**
	 * Edit Column Titles
	 *
	 * @since 1.0.0
	 * @link http://justintadlock.com/archives/2011/06/27/custom-columns-for-custom-post-types
	 * @param array $columns
	 * @return array
	 */
	function edit_event_columns( $columns ) {

		// Change Titles
		$columns['title'] = 'Event';
		$columns['date'] = 'Published Date';
		
		// New Columns
		$new_columns = array(
			'event_start' => 'Starts',
			'event_end'   => 'Ends',
		);
		
		if( $this->recurring_supported() )
			$new_columns[ 'recurring' ] = __( 'Recurring Series' );
		
		// Add new columns after title column
		$column_end = array_splice( $columns, 2 );
		$column_start = array_splice( $columns, 0, 2);
		$columns = array_merge( $column_start, $new_columns, $column_end );
		
		return $columns;
	}
	
	/**
	 * Edit Column Content
	 *
	 * @since 1.0.0
	 * @link http://justintadlock.com/archives/2011/06/27/custom-columns-for-custom-post-types
	 * @param string $column
	 * @param int $post_id
	 */
	function manage_event_columns( $column, $post_id ) {
	
		switch( $column ) {
	
			/* If displaying the 'event_start' column. */
			case 'event_start' :
	
				/* Get the post meta. */
				$allday = get_post_meta( $post_id, 'be_event_allday', true );
				$date_format = $allday ? 'M j, Y' : 'M j, Y g:i A';
				$start = esc_attr( date( $date_format, get_post_meta( $post_id, 'be_event_start', true ) ) );
	
				/* If no duration is found, output a default message. */
				if ( empty( $start ) )
					echo __( 'Unknown' );
	
				/* If there is a duration, append 'minutes' to the text string. */
				else
					echo $start;
	
				break;
	
			/* If displaying the 'event_end' column. */
			case 'event_end' :
	
				/* Get the post meta. */
				$allday = get_post_meta( $post_id, 'be_event_allday', true );
				$date_format = $allday ? 'M j, Y' : 'M j, Y g:i A';
				$end = esc_attr( date( $date_format, get_post_meta( $post_id, 'be_event_end', true ) ) );
	
				/* If no duration is found, output a default message. */
				if ( empty( $end ) )
					echo __( 'Unknown' );
	
				/* If there is a duration, append 'minutes' to the text string. */
				else
					echo $end;
	
				break;
			
			/* If displaying the 'recurring' column. */
			case 'recurring' :
				
				// Date format
				$allday = get_post_meta( $post_id, 'be_event_allday', true );
				$date_format = $allday ? 'M j, Y' : 'M j, Y g:i A';
				
				// Recurring options
				$start = absint( get_post_meta( $post_id , 'be_event_start', true ) );
				$end   = absint( get_post_meta( $post_id , 'be_event_end',   true ) );
				$recurring_period = get_post_meta( $post_id , 'be_recurring_period', true );
				$recurring_end    = absint( get_post_meta( $post_id , 'be_recurring_end', true ) );
				$recurring = get_post_meta( $post_id, 'be_recurring', true );
				$parent = wp_get_post_parent_id( $post_id );
				$output = '';
				if ( !empty( $parent ) ) {
					$output = 'Part of series: <a href="' . get_edit_post_link( $parent ) . '">' . get_the_title( $parent ) . '</a><br/>';
				} elseif( empty( $parent ) && $recurring ) {
					$output = '<strong>Series Master</strong><br/>';
				}
				if( !empty( $output ) ) {
					$output .= 'Starting ' . date( $date_format, $start ) . ', recurring ' . ucfirst( $recurring_period ) . ' until ' .  date( 'M j, Y', $recurring_end ) . ' ' .  date( 'g:i A', $end );
					echo $output;
				}
				
				break;
	
			/* Just break out of the switch statement for everything else. */
			default :
				break;
		}
	}	 
	
	/**
	 * Make Columns Sortable
	 *
	 * @since 1.0.0
	 * @link http://justintadlock.com/archives/2011/06/27/custom-columns-for-custom-post-types
	 * @param array $columns
	 * @return array
	 */
	function event_sortable_columns( $columns ) {
	
		$columns['event_start'] = 'event_start';
		$columns['event_end']   = 'event_end';
		
		return $columns;
	}	 
	
	/**
	 * Check for load request
	 *
	 * @since 1.0.0
	 */
	function edit_event_load() {

		add_filter( 'request', array( $this, 'sort_events' ) );
	}
	
	/**
	 * Sort events on load request
	 *
	 * @since 1.0.0
	 * @param array $vars
	 * @return array
	 */
	function sort_events( $vars ) {

		/* Check if we're viewing the 'event' post type. */
		if ( isset( $vars['post_type'] ) && $this->post_type_name == $vars['post_type'] ) {
	
			/* Check if 'orderby' is set to 'start_date'. */
			if ( isset( $vars['orderby'] ) && 'event_start' == $vars['orderby'] ) {
	
				/* Merge the query vars with our custom variables. */
				$vars = array_merge(
					$vars,
					array(
						'meta_key' => 'be_event_start',
						'orderby' => 'meta_value_num'
					)
				);
			}
			
			/* Check if 'orderby' is set to 'end_date'. */
			if ( isset( $vars['orderby'] ) && 'event_end' == $vars['orderby'] ) {
	
				/* Merge the query vars with our custom variables. */
				$vars = array_merge(
					$vars,
					array(
						'meta_key' => 'be_event_end',
						'orderby' => 'meta_value_num'
					)
				);
			}
			
		}
	
		return $vars;
	}

	/**
	 * Change the default title placeholder text
	 *
	 * @since 1.0.0
	 * @global array $post
	 * @param string $translation
	 * @return string Customized translation for title
	 */
	function title_placeholder( $translation ) {

		global $post;
		if ( isset( $post ) && $this->post_type_name == $post->post_type && 'Enter title here' == $translation ) {
			$translation = 'Enter Event Name Here';
		}
		return $translation;
	}

	/**
	 * Create Taxonomies
	 *
	 * @since 1.0.0
	 */
	function taxonomies() {
	
		$supports = get_theme_support( 'be-events-calendar' );
		if ( !is_array( $supports ) || !in_array( 'event-category', $supports[0] ) )
			return;
			
		$labels = array(
			'name'              => 'Categories',
			'singular_name'     => 'Category',
			'search_items'      => 'Search Categories',
			'all_items'         => 'All Categories',
			'parent_item'       => 'Parent Category',
			'parent_item_colon' => 'Parent Category:',
			'edit_item'         => 'Edit Category',
			'update_item'       => 'Update Category',
			'add_new_item'      => 'Add New Category',
			'new_item_name'     => 'New Category Name',
			'menu_name'         => 'Categories'
		); 	
	
		register_taxonomy( 'event-category', $post_types, array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'event-category' ),
		));
	}

	/**
	 * Loads styles for metaboxes
	 *
	 * @since 1.0.0
	 */
	function metabox_styles() {

		if ( isset( get_current_screen()->base ) && 'post' !== get_current_screen()->base ) {
			return;
		}

		if ( isset( get_current_screen()->post_type ) && $this->post_type_name != get_current_screen()->post_type ) {
			return;
		}

		// Load styles
		wp_register_style( 'be-events-calendar', BE_EVENTS_CALENDAR_URL . 'css/events-admin.css', array(), BE_EVENTS_CALENDAR_VERSION );
		wp_enqueue_style( 'be-events-calendar' );
	}

	/**
	 * Loads scripts for metaboxes.
	 *
	 * @since 1.0.0
	 */
	function metabox_scripts() {

		if ( isset( get_current_screen()->base ) && 'post' !== get_current_screen()->base ) {
			return;
		}

		if ( isset( get_current_screen()->post_type ) && $this->post_type_name != get_current_screen()->post_type ) {
			return;
		}

		// Load scripts.
		wp_register_script( 'be-events-calendar', BE_EVENTS_CALENDAR_URL . 'js/events-admin.js', array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker' ) , BE_EVENTS_CALENDAR_VERSION, true );
		wp_enqueue_script( 'be-events-calendar' );
	}

	/**
	 * Initialize the metabox
	 *
	 * @since 1.0.0
	 */
	function metabox_register() {

		add_meta_box( 'be-events-calendar-date-time', 'Date and Time Details', array( $this, 'render_metabox' ), $this->post_type_name, 'normal', 'high' );
	}

	/**
	 * Render the metabox
	 *
	 * @since 1.0.0
	 */
	function render_metabox() {

		$start  = get_post_meta( get_the_ID() , 'be_event_start', true );
		$end    = get_post_meta( get_the_ID() , 'be_event_end',   true );
		$allday = get_post_meta( get_the_ID(), 'be_event_allday', true );

		if ( !empty( $start ) && !empty( $end ) && $this->is_timestamp( $start ) && $this->is_timestamp( $end ) ) {
			$start_date = date( 'm/d/Y', $start );
			$start_time = date( 'g:ia',  $start );
			$end_date   = date( 'm/d/Y', $end   );
			$end_time   = date( 'g:ia',  $end   );
		}

		wp_nonce_field( 'be_events_calendar_date_time', 'be_events_calendar_date_time_nonce' );
		?>

		<div class="section" style="min-height:0;">
			<label for="be-events-calendar-allday">All Day event?</label>
			<input name="be-events-calendar-allday" type="checkbox" id="be-events-calendar-allday" value="1" <?php checked( '1', $allday ); ?>>
		</div>
		<div class="section">
			<label for="be-events-calendar-start">Start date and time:</label> 
			<input name="be-events-calendar-start" type="text"  id="be-events-calendar-start" class="be-events-calendar-date" value="<?php echo !empty( $start ) ? $start_date : ''; ?>" placeholder="Date">
			<input name="be-events-calendar-start-time" type="text"  id="be-events-calendar-start-time" class="be-events-calendar-time" value="<?php echo !empty( $start ) ? $start_time : ''; ?>" placeholder="Time">
		</div>
		<div class="section">
			<label for="be-events-calendar-end">End date and time:</label> 
			<input name="be-events-calendar-end" type="text"  id="be-events-calendar-end" class="be-events-calendar-date" value="<?php echo !empty( $end ) ? $end_date : ''; ?>" placeholder="Date">
			<input name="be-events-calendar-end-time" type="text"  id="be-events-calendar-end-time" class="be-events-calendar-time" value="<?php echo !empty( $end ) ? $end_time : ''; ?>" placeholder="Time">
		</div>
		<p class="desc">Date format should be <strong>MM/DD/YYYY</strong>. Time format should be <strong>H:MM am/pm</strong>.<br>Example: 05/12/2015 6:00pm</p>
		<?php
		
		// Recurring Options
		if( $this->recurring_supported() ) {
			
			$recurring        = get_post_meta( get_the_ID() , 'be_recurring',  true );
			$recurring_period = get_post_meta( get_the_ID() , 'be_recurring_period',  true );
			$recurring_end    = get_post_meta( get_the_ID() , 'be_recurring_end',     true );
			$regenerate       = get_post_meta( get_the_ID() , 'be_regenerate_events', true );

			if ( !empty( $recurring_end ) ) {
				$recurring_end = date( 'm/d/Y', $recurring_end );
			}
			
			?>
			<hr>
			<div class="section">
				<p class="title">Recurring Options</p>
			</div>
			<div class="section">
				<label for="be-events-calendar-recurring">Recurring Event:</label> 				<input type="checkbox" name="be-events-calendar-recurring" id="be-events-calendar-recurring" value="1" <?php checked( '1', $recurring ); ?>>
			</div>
			<div class="section">
				<label for="be-events-calendar-repeat">Repeat period:</label> 				<select name="be-events-calendar-repeat" id="be-events-calendar-repeat">
					<option value="daily" <?php selected( 'daily', $recurring_period ); ?>>Daily</option>
					<option value="weekly" <?php selected( 'weekly', $recurring_period ); ?>>Weekly</option>
					<option value="monthly" <?php selected( 'montly', $recurring_period ); ?>>Monthly</option>
				</select>
			</div>
			<div class="section">
				<label for="be-events-calendar-repeat-end">Repeat ends:</label> 			<input name="be-events-calendar-repeat-end" type="text"  id="be-events-calendar-repeat-end" class="be-events-calendar-date" value="<?php echo !empty( $recurring_end ) ? $recurring_end : ''; ?>" placeholder="Date">
			</div>
			<div class="section">
				<label for="be-events-calendar-regenerate">Repeat events:</label>
				<input type="checkbox" name="be-events-calendar-regenerate" id="be-events-calendar-regenerate" value="1" <?php checked( '1', $regenerate ); ?>>
				<span class="check-desc"><strong>This will delete all scheduled events!</strong> Past events will be unchanged.</span>
			</div>
			<?php
		}	
	}
	
	/**
	 * Save metabox contents
	 *
	 * @since 1.0.0
	 * @param int $post_id
	 * @param array $post
	 */
	function metabox_save( $post_id, $post ) {
		
		// Security check
		if ( ! isset( $_POST['be_events_calendar_date_time_nonce'] ) || ! wp_verify_nonce( $_POST['be_events_calendar_date_time_nonce'], 'be_events_calendar_date_time' ) ) {
			return;
		}

		// Bail out if running an autosave, ajax, cron, or revision.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		 // Bail out if the user doesn't have the correct permissions to update the slider.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Make sure the event start/end dates were not left blank before we run the save
		if ( isset( $_POST['be-events-calendar-start'] ) && isset( $_POST['be-events-calendar-end'] ) && !empty( $_POST['be-events-calendar-start'] ) && !empty( $_POST['be-events-calendar-end'] ) ) {
			$start      = $_POST['be-events-calendar-start'] . ' ' . $_POST['be-events-calendar-start-time'];
			$start_unix = strtotime( $start );
			$end        = $_POST['be-events-calendar-end'] . ' ' . $_POST['be-events-calendar-end-time'];
			$end_unix   = strtotime( $end );
			$allday     = ( isset( $_POST['be-events-calendar-allday'] ) ? '1' : '0' );

			update_post_meta( $post_id, 'be_event_start',  $start_unix );
			update_post_meta( $post_id, 'be_event_end',    $end_unix   );
			update_post_meta( $post_id, 'be_event_allday', $allday     );
		}
		
		// Recurring Options
		if( $this->recurring_supported() ) {
			
			// This will loop forever unless the parent id is set to the original post
			// use parent/child relationship for recurring events in series
			// the parent is the "series master event"
			
			// Make sure the event start/end dates were not left blank before we run the save
			if ( isset( $_POST['be-events-calendar-start'] )
				&& isset( $_POST['be-events-calendar-recurring'] )
				&& isset( $_POST['be-events-calendar-end'] ) 
				&& isset( $_POST['be-events-calendar-repeat-end'] ) 
				&& !empty( $_POST['be-events-calendar-start'] ) 
				&& !empty( $_POST['be-events-calendar-end'] ) 
				&& !empty( $_POST['be-events-calendar-repeat-end'] )
				&& !empty( $_POST['be-events-calendar-recurring'] ) )
			{
				
				$start      = $_POST['be-events-calendar-start'] . ' ' . $_POST['be-events-calendar-start-time'];
				$start_unix = strtotime( $start );
				$end        = $_POST['be-events-calendar-end'] . ' ' . $_POST['be-events-calendar-end-time'];
				$end_unix   = strtotime( $end );
				
				update_post_meta( $post_id, 'be_event_start', $start_unix );
				update_post_meta( $post_id, 'be_event_end',   $end_unix   );
				update_post_meta( $post_id, 'be_recurring_period', $_POST['be-events-calendar-repeat'] );
				update_post_meta( $post_id, 'be_recurring_end',  strtotime( $_POST['be-events-calendar-repeat-end'] )  );

				if ( isset( $_POST['be-events-calendar-regenerate'] ) ) {
					update_post_meta( $post_id, 'be_regenerate_events', '1' );
				}
				if ( isset( $_POST['be-events-calendar-recurring'] ) ) {
					update_post_meta( $post_id, 'be_recurring', '1' );
				}
			}
		}
	}
	
	/**
	 * Removes the generate functions for recurring events from the wp_insert_post action.
	 *
	 * @since 1.2.0
	 */
	function remove_insert_post_hooks() {
		remove_action( 'wp_insert_post', array( $this, 'generate_events' ) );
		remove_action( 'wp_insert_post', array( $this, 'regenerate_events' ) );
	}
	
	/**
	 * Adds the generate functions for recurring events to the wp_insert_post action.
	 *
	 * @since 1.2.0
	 */
	function add_insert_post_hooks() {
		add_action( 'wp_insert_post', array( $this, 'generate_events' ) );
		add_action( 'wp_insert_post', array( $this, 'regenerate_events' ) );
	}
	
	/**
	 * Generate Events
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id
	 * @param boolean $regenerating
	 */
	function generate_events( $post_id, $regenerating = false ) {
		
		if( !$this->recurring_supported() )
			return;

		if( $this->post_type_name !== get_post_type( $post_id ) )
			return;
			
		if( 'publish' !== get_post_status( $post_id ) )
			return;
			
		// Only generate once
		$generated = get_post_meta( $post_id, 'be_generated_events', true );
		if( $generated )
			return;
		
		// Make sure this is a master event, and not a repeated child
		$parent_event = wp_get_post_parent_id( $post_id );
		if( $parent_event )
			return;
		
		// Make sure this is a recurring event
		$recurring = get_post_meta( $post_id, 'be_recurring', true );
		if( !$recurring )
			return;
		
		// Event data
		$event_title = get_post( $post_id )->post_title;
		$event_content = get_post( $post_id )->post_content;
		$event_start = get_post_meta( $post_id, 'be_event_start', true );
		$event_end = get_post_meta( $post_id, 'be_event_end', true );
		
		// Save parent start date to skip the first recurring event
		$parent_start = $event_start;
		
		$stop = get_post_meta( $post_id, 'be_recurring_end', true );
		if( empty( $stop ) && !empty( $event_start ) )
			$stop = strtotime( '+1 Years', $event_start );
		$period = get_post_meta( $post_id, 'be_recurring_period', true );
		
		// Validate the stop timestamp, if it's not valid bail
		if( !$this->is_timestamp( $stop ) || !$this->is_timestamp( $event_start ) )
			return;
		
		// Remove Generate Recurring Events, this prevents an infinite loop
		$this->remove_insert_post_hooks();
		
		// Build the posts!
		$limit = apply_filters( 'be_calendar_recurring_limit', 100 );
		$i = 1;
		while( ( $event_start < $stop ) && ( $i < $limit ) ) {
			
			// For regenerating, only create future events
			// And don't recreate the series master
			if( $event_start != $parent_start && ( !$regenerating || ( $regenerating && $event_start > (int) current_time( 'timestamp' ) ) ) ):
			
				// Create the Event
				$args = array(
					'post_title' => $event_title,
					'post_content' => $event_content,
					'post_status' => 'publish',
					'post_type' => $this->post_type_name,
					'post_parent' => $post_id,
				);
				$event_id = wp_insert_post( $args );
				if( $event_id ) {
					update_post_meta( $event_id, 'be_recurring', '0' );
					update_post_meta( $event_id, 'be_event_start', $event_start );
					update_post_meta( $event_id, 'be_event_end', $event_end );
					
					// Add any additional metadata
					$metas = apply_filters( 'be_events_manager_recurring_meta', array() );
					if( !empty( $metas ) ) {
						foreach( $metas as $meta ) {
							
							update_post_meta( $event_id, $meta, get_post_meta( $post_id, $meta, true ) );
						}
					}
					
					// Event Category
					$supports = get_theme_support( 'be-events-calendar' );
					if( is_array( $supports ) && in_array( 'event-category', $supports[0] ) ) {
						$terms = get_the_terms( $post_id, 'event-category' );
						if( !empty( $terms ) && !is_wp_error( $terms ) ) {
							$terms = wp_list_pluck( $terms, 'slug' );
							wp_set_object_terms( $event_id, $terms, 'event-category' );
						}
					}

				}
			endif;
			
			// Set current start/end as past, prior to the incrementing
			$previous_start = $event_start;
			$previous_end = $event_end;
			
			// Increment the date
			switch( $period ) {
		
				case 'daily':
					$event_start = strtotime( '+1 Days', $event_start );
					$event_end = strtotime( '+1 Days', $event_end );
					break;
			
				case 'weekly':
					$event_start = strtotime( '+1 Weeks', $event_start );
					$event_end = strtotime( '+1 Weeks', $event_end );
					break;
			
				case 'monthly':
					$event_start = strtotime( '+1 Months', $event_start );
					$event_end = strtotime( '+1 Months', $event_end );
					break;
			}
			
			// Allow for custom recurring options
			$event_start = apply_filters( 'be_calendar_recurrance_start', $event_start, $previous_start, $period, $post_id, $event_id );
			$event_end = apply_filters( 'be_calendar_recurrance_end', $event_end, $previous_start, $period, $post_id, $event_id );
			
			// Limit the recurrances
			$i++;
		}
		
		// Replace Generate Recurring Events, we need these normally, see above
		$this->add_insert_post_hooks();
		
		// Dont generate again
		update_post_meta( $post_id, 'be_generated_events', true );
	}
	
	/**
	 * Validate the timestamp.
	 *
	 * @since 1.2.0
	 *
	 * @link https://gist.github.com/sepehr/6351385
	 *
	 * @param string $timestamp 
	 * @return boolean
	 */
	function is_timestamp( $timestamp ) {
		$check = (is_int($timestamp) OR is_float($timestamp))
			? $timestamp
			: (string) (int) $timestamp;
		return  ($check === $timestamp)
	        	AND ( (int) $timestamp <=  PHP_INT_MAX)
	        	AND ( (int) $timestamp >= ~PHP_INT_MAX);
	}
	
	/**
	 * Regenerate Events
	 * 
	 * @since 1.0.0
	 * @param int $post_id
	 */
	function regenerate_events( $post_id ) {
		if( $this->post_type_name !== get_post_type( $post_id ) )
			return;
		
		if( !$this->recurring_supported() )
			return;
			
		// Make sure they want to regenerate them
		$regenerate = get_post_meta( $post_id, 'be_regenerate_events', true );
		if( ! $regenerate )	
			return;
			
		// Delete all future events
		$args = array(
			'post_type' => $this->post_type_name,
			'posts_per_page' => -1,
			'post_parent' => $post_id,
			'meta_query' => array(
				array(
					'key' => 'be_event_start',
					'value' => time(),
					'compare' => '>'
				),
			)
		);
		$loop = new WP_Query( $args );
		if( $loop->have_posts() ): while( $loop->have_posts() ): $loop->the_post();
			if( $post_id !== get_the_ID() )
				wp_delete_post( get_the_ID(), false );
		endwhile; endif; wp_reset_postdata();
		
		// Turn off regenerate and on generate
		delete_post_meta( $post_id, 'be_regenerate_events' );
		delete_post_meta( $post_id, 'be_generated_events' );
		
		// Generate new events
		$this->generate_events( $post_id, true );
	}
	
	/**
	 * Modify WordPress query where needed for event listings
	 *
	 * @since 1.0.0
	 * @param object $query
	 */
	function event_query( $query ) {

		// If you don't want the plugin to mess with the query, use this filter to override it
		$override = apply_filters( 'be_events_manager_query_override', false );
		if ( $override )
			return;
		
		if ( $query->is_main_query() && !is_admin() && ( is_post_type_archive( $this->post_type_name ) || is_tax( 'event-category' ) ) ) {	
			$meta_query = array(
				array(
					'key' => 'be_event_end',
					'value' => (int) current_time( 'timestamp' ),
					'compare' => '>'
				)
			);
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'ASC' );
			$query->set( 'meta_query', $meta_query );
			$query->set( 'meta_key', 'be_event_start' );
		}
	}
	
	/**
	 * Validate the timestamp.
	 *
	 * @since 1.2.0
	 *
	 * @link https://gist.github.com/sepehr/6351385
	 *
	 * @param string $timestamp 
	 * @return boolean
	 */
	function is_timestamp( $timestamp ) {
		$check = (is_int($timestamp) OR is_float($timestamp))
			? $timestamp
			: (string) (int) $timestamp;
		return  ($check === $timestamp)
	        	AND ( (int) $timestamp <=  PHP_INT_MAX)
	        	AND ( (int) $timestamp >= ~PHP_INT_MAX);
	}
	
}

new BE_Events_Calendar;