<?php
/*
Plugin Name: Twitter for WordPress
Plugin URI: http://www.atomicsmash.co.uk
Description: Pull from Twitter API and cache
Version: 0.2.0
Author: Atomic Smash
Author URI: n/a
*/

global $action, $wpdb, $twitterAPI;

function register_session() {
    if ( !session_id() ) {
        session_start();
    }
}

add_action('init','register_session');
add_action( 'admin_menu', 'baseMenuPage' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require 'vendor/autoload.php';
}

include('class-atomic-table-clone.php');
include('twitter.php');

$twitterAPI = new atomic_api();

register_activation_hook( __FILE__, [$twitterAPI , 'create_table'] );
// register_deactivation_hook( __FILE__, array ( $twitterAPI, 'delete_table' ) );

function baseMenuPage() {

    add_menu_page( 'Tweets', 'Tweets', 'edit_posts', 'atomic_apis' , 'twitter_page' , 'dashicons-twitter', '100' );

};


function twitter_page() {
    //
};
