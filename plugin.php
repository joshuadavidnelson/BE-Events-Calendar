<?php
/*
Plugin Name: BE Events Calendar
Plugin URI: http://www.billerickson.net
Description: Allows you to manage events
Version: 1.0
Author: Bill Erickson
Author URI: http://www.billerickson.net
License: GPLv2
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'be-events-calendar.php';
require_once plugin_dir_path( __FILE__ ) . 'recurring-events.php';