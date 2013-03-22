<?php
/*
Plugin Name: PixelPost Ajax Ratings Importer
Plugin URI: https://github.com/pbodilis/
Description: Import <a href="http://www.pixelpost.org/extend/addons/ajax-photo-ratings/">ajax ratings</a> from a Pixelpost database.
Author: Pierre Bodilis
Author URI: http://rataki.eu/
Version: 0.1
Text Domain: pixelpost-ajaxRatings-importer
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

// first, the ajax calls
function pp_ajaxRatings2wp_postRatings_migration_status_callback() {
    echo get_option('pp_ajaxRatings2wp_postRatings_migration_percentage', 0);
    die();
}
add_action('wp_ajax_pp_ajaxRatings2wp_postRatings_migration_status', 'pp_ajaxRatings2wp_postRatings_migration_status_callback');

function pp_ajaxRatings2wp_postRatings_migration_stop_callback() {
    update_option('pp_ajaxRatings2wp_postRatings_migration_stop', 'true');
    die();
}
add_action('wp_ajax_pp_ajaxRatings2wp_postRatings_migration_stop', 'pp_ajaxRatings2wp_postRatings_migration_stop_callback');

function pp_ajaxRatings2wp_postRatings_migration_resume_callback() {
    update_option('pp_ajaxRatings2wp_postRatings_migration_stop', 'false');
    echo json_encode($GLOBALS['PP_AjaxRatings_Importer']->pp_ajaxRatings2wp_postRatings());
    die();
}
add_action('wp_ajax_pp_ajaxRatings2wp_postRatings_migration_resume', 'pp_ajaxRatings2wp_postRatings_migration_resume_callback');
add_action('wp_ajax_pp_ajaxRatings2wp_postRatings_migration_start', 'pp_ajaxRatings2wp_postRatings_migration_resume_callback');


// let's comment this, as the ajax callbacks needs it (wp_ajax_pp_ajaxRatings2wp_postRatings_migration_start & wp_ajax_pp_ajaxRatings2wp_postRatings_migration_resume)
// if ( ! defined('WP_LOAD_IMPORTERS'))
//     return;

/** Display verbose errors */
define('IMPORT_DEBUG', true);

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';
if ( ! class_exists( 'WP_Importer')) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (file_exists( $class_wp_importer))
        require $class_wp_importer;
}

if ( ! class_exists( 'PP_Importer' ) ) {
    die('<p>Please install pp2wp importer: <a href="https://github.com/pbodilis/odyssey_pp2wp">get it here!</a></p>');
}

/**
 * Pixelpost Importer class
 *
 * @package PixelPost
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class PP_AjaxRatings_Importer extends PP_Importer {
   
    function get_title() {
        return __('Import Ajax Ratings from Pixelpost');
    }

    function get_slug() {
        return 'pixelpost_ajaxRatings';
    }

    function get_pixelpost_default_settings() {
        $ppImporterOptions = get_option(parent::get_option_name());

        return array(
            'dbuser'      => $ppImporterOptions['dbuser'],
            'dbpass'      => $ppImporterOptions['dbpass'],
            'dbname'      => $ppImporterOptions['dbname'],
            'dbhost'      => $ppImporterOptions['dbhost'],
            'dbpre'       => $ppImporterOptions['dbpre'],
            'ppmaxrating' => 10,
            'wpmaxrating' => get_option('postratings_max'),
        );
    }

    function get_option_name() {
        return 'pp2wp_pixelpost_ajaxratings_importer_settings';
    }

    function get_pixelpost_settings() {
        return get_option($this->get_option_name(), $this->get_pixelpost_default_settings());
    }

    function setting2Label($s) {
         $s2l = array(
            'dbuser'      => __('Pixelpost Database User:'),
            'dbpass'      => __('Pixelpost Database Password:'),
            'dbname'      => __('Pixelpost Database Name:'),
            'dbhost'      => __('Pixelpost Database Host:'),
            'dbpre'       => __('Pixelpost Table Prefix:'),
            'ppmaxrating' => __('Pixelpost max rating value:'),
            'wpmaxrating' => __('Wordpress max rating value:'),
        );
        return $s2l[$s];
    }

    function description() {
        echo '<p>' . __( 'This importer allows you to extract ratings from Pixelpost\'s <a href="http://www.pixelpost.org/extend/addons/ajax-photo-ratings/">ajax ratings</a> into wordpress\' <a href="http://lesterchan.net/portfolio/programming/php/">wp-postRatings</a> by Lester Chan.' ) . '</p>';
        echo '<p>' . __( 'This importer works as an addition to the pp2wp importer, and uses table and data created by the latter.' ) . '</p>';
        echo '<p>' . __( 'Please note that this improter has been developped for pixelpost 1.7.1 and wordpress 3.5.1. It may not work very well with other versions.' ) . '</p>';
    }
    
    function init() {
        $settings = $this->get_pixelpost_settings();

        $dsn = 'mysql:host=' . $settings['dbhost'] . ';dbname=' . $settings['dbname'];
        $username = $settings['dbuser'];
        $password = $settings['dbpass'];
//         $options = array(
//             PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES latin1',
//         );

        $this->ppdbh = new PDO($dsn, $username, $password);

        $this->prefix   = $settings['dbpre'];

        $this->ppmaxrating = $settings['ppmaxrating'];
        $this->wpmaxrating = $settings['wpmaxrating'];

        global $wpdb;
        $wpdb->pp2wp = $wpdb->prefix . parent::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;
    }


    function get_pp_post_ratings() {
        return $this->get_pp_dbh()->query("SELECT * FROM {$this->prefix}ajaxRatings");
    }

    protected $hostnameCache = array();
    function insert_vote($wp_post_id, $wp_post_title, $rate, $now, $voting_ip) {
        if ( ! isset($this->hostnameCache[ $voting_ip ])) {
            $this->hostnameCache[ $voting_ip ] = esc_attr(@gethostbyaddr($voting_ip));
        }
        $hostname = $this->hostnameCache[ $voting_ip ];

        // if the hostname constains 'bot' or 'crawl', that's likely not a human
        $blacklist = array(
            'bot', 'crawl'
        );
        foreach ($blacklist as $bot) {
            if (stristr($hostname, $bot) !== false) {
                return false;
            }
        }

        global $wpdb;
        $rate_log = $wpdb->query(
            "INSERT INTO $wpdb->ratings " .
            "VALUES (0, $wp_post_id, '$wp_post_title', $rate, '$now', '$voting_ip', '$hostname', 0, 0)");
        return true;
    }

    function pp_ajaxRatings2wp_postRatings() {
        $pp_posts_count = $this->get_pp_post_count();

        $count = 0;

        $pp_ratings = $this->get_pp_post_ratings();
        set_time_limit(0);
        foreach($pp_ratings as $pp_rating) {
            $now = current_time('timestamp');

            if ($pp_rating['total_votes'] == 0) { // no vote, skip this entry
                continue;
            }
            $wp_post_id = $this->get_pp2wp_wp_post_id($pp_rating['img_id']);
            if ($wp_post_id === false) { // no matching wp post
                continue;
            }

            $wp_post_meta_ratings_score = get_post_meta($wp_post_id, 'ratings_score');
            if ( ! empty($wp_post_meta_ratings_score)) { // not empty => already processed (for error recovery)
                continue;
            }

            $wp_post_title = get_the_title($wp_post_id);
            // readjust rates from pp scale to wp scale
            $rate = floatval($pp_rating['total_rate']) * $this->wpmaxrating / $this->ppmaxrating;

            $current_total = $rate;
            $current_count = 1;
            $pp_ratings = unserialize($pp_rating['used_ips']);
            foreach($pp_ratings as $voting_ip) {
                $nrate = intval($rate);
                if ((floatval($current_total) / $current_count) < $rate) {
                    ++$nrate;
                }
                // if the value was inserted, add it to the counts
                if ($this->insert_vote($wp_post_id, $wp_post_title, $nrate, $now, $voting_ip)) {
                    $current_total += $nrate;
                    ++$current_count;
                }
            }

            if ( ! update_post_meta($wp_post_id, 'ratings_users', $current_count)) {
                add_post_meta($wp_post_id, 'ratings_users', $current_count, true);
            }
            if ( ! update_post_meta($wp_post_id, 'ratings_score', $current_total)) {
                add_post_meta($wp_post_id, 'ratings_score', $current_total, true);
            }
            if ( ! update_post_meta($wp_post_id, 'ratings_average', floatval($current_total) / $current_count)) {
                add_post_meta($wp_post_id, 'ratings_average', floatval($current_total) / $current_count, true);
            }
// break;
            // keep a trace of the last migrated pixelpost post by keeping its id
            update_option('pp_ajaxRatings2wp_postRatings_migration_percentage', round((++$count) * 100.0 / $pp_posts_count, 2));
        }
        set_time_limit(30);
        
        return $count;
    }

    function import_ratings() {
        wp_enqueue_script( 'pixelpost-importer', plugins_url('/pixelpost-importer.js', __FILE__) );
        echo '<p>' . sprintf(__('Retrieved %d posts from Pixelpost, importing...'), $this->get_pp_post_count()) . '</p>';
        echo '<p id="pp_ajaxRatings2wp_postRatings_migration_log">'. __('Starting...') . '</p>';
        echo '<p>';
        echo '  <input id="pp_ajaxRatings2wp_postRatings_migration_stop"   type="submit" name="stop migration"   value="stop migration"   class="button button-primary"/>';
        echo '  <input id="pp_ajaxRatings2wp_postRatings_migration_resume" type="submit" name="resume migration" value="resume migration" class="button button-primary"/>';
        echo '</p>';

// echo "<pre>\n";
//         $n_cats = $this->pp_ajaxRatings2wp_postRatings();
//         echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> ratings imported.'), $n_cats).'<br /><br /></p>';
    }
    
    function dispatch() {
        $this->header();

        $step = intval ( $_GET ['step']);

        switch ( $step ) {
            default:
            case 0 : $this->greet();            break;
            case 1 : $this->import_ratings();   break;
        }
        
        $step2Str = array(
            0 => __('Import Ratings'),
            1 => __('Finish'),
        );

        if ( isset ( $step2Str[ $step ] ) ) {
            echo '<form action="admin.php?import=' . $this->get_slug() . '&amp;step=' . ($step + 1) . '" method="post">';
            echo '  <input type="submit" name="submit" value="' . $step2Str[$step] . '" class="button button-primary" />';
            echo '</form>';
        }

        $this->footer();
    }
    
    
}

} // class_exists( 'WP_Importer' )

function pixelpost_ratings_importer_init() {
    //load_plugin_textdomain( 'pixelpost-ajaxRatings-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    /**
     * WordPress Importer object for registering the import callback
     * @global WP_Import $wp_import
     */
    $GLOBALS['PP_AjaxRatings_Importer'] = new PP_AjaxRatings_Importer();
    register_importer(
        $GLOBALS['PP_AjaxRatings_Importer']->get_slug(),
        'PixelPost Ajax Ratings',
        __('Import <strong>ajaxRatings</strong> from a pixelpost installation.', 'pixelpost-ajaxRatings-importer'),
        array($GLOBALS['PP_AjaxRatings_Importer'], 'dispatch')
    );
}
add_action('admin_init', 'pixelpost_ratings_importer_init');
