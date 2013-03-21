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
    echo json_encode($GLOBALS['pp_ajaxRatings_import']->pp_ajaxRatings2wp_postRatings());
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

if ( class_exists( 'PP_Importer' ) ) {
    die('<p>Please install pp2wp importer: <a href="https://github.com/pbodilis/odyssey_pp2wp">get it here!</a></p>');
}

/**
 * Pixelpost Importer class
 *
 * @package PixelPost
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class PP_AjaxRatings_Import extends WP_Importer {
    const PPIMPORTER_PIXELPOST_AJAXRATINGS_OPTIONS = 'pp2wp_pixelpost_ajaxratings_importer_settings';
    const PPIMPORTER_PIXELPOST_SUBMIT  = 'pp2wp_pixelpost_importer_submit';
    const PPIMPORTER_PIXELPOST_RESET   = 'pp2wp_pixelpost_importer_reset';
   
    private $ppdbh;
    private $prefix;
    private $ppurl;
    private $tmp_dir;
    private $img_size;

    function header() {
        echo '<div class="wrap">';
        echo '<div id="icon-tools" class="icon32"><br></div>' . PHP_EOL;
        echo '<h2>'.__('Import ajaxRatings from Pixelpost').'</h2>';
    }

    function footer() {
        echo '</div>';
    }

    function get_pixelpost_default_settings() {
        $ppImporterOptions = get_option(PP_Import::PPIMPORTER_PIXELPOST_OPTIONS);

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
    function get_pixelpost_settings() {
        return get_option(self::PPIMPORTER_PIXELPOST_AJAXRATINGS_OPTIONS, $this->get_pixelpost_default_settings());
    }

    static function setting2Type($s) {
        return ($s == 'dbpass') ? 'password' : 'text';
    }
    static function setting2Label($s) {
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

    function greet() {
        if ( isset( $_POST[self::PPIMPORTER_PIXELPOST_RESET] ) ) {
            delete_option(self::PPIMPORTER_PIXELPOST_AJAXRATINGS_OPTIONS);
        }
        $settings = $this->get_pixelpost_settings();
        if ( isset( $_POST[ self::PPIMPORTER_PIXELPOST_SUBMIT ] ) ) {
            unset( $_POST[ self::PPIMPORTER_PIXELPOST_SUBMIT ] );
            foreach ( $_POST as $name => $setting ) {
                $settings[$name] = $setting;
            }
            update_option(self::PPIMPORTER_PIXELPOST_AJAXRATINGS_OPTIONS, $settings);
        }

        echo '<p>' . __( 'This importer allows you to extract ratings from Pixelpost\'s <a href="http://www.pixelpost.org/extend/addons/ajax-photo-ratings/">ajax ratings</a> into wordpress\' <a href="http://lesterchan.net/portfolio/programming/php/">wp-postRatings</a> by Lester Chan.' ) . '</p>';
        echo '<p>' . __( 'This importer works as an addition to the pp2wp importer, and uses table and data created by the latter.' ) . '</p>';
        echo '<p>' . __( 'Please note that this improter has been developped for pixelpost 1.7.1 and wordpress 3.5.1. It may not work very well with other versions.' ) . '</p>';
        echo '<p>' . __( 'Your Pixelpost configuration settings are as follows:' ) . '</p>';

        echo '<form action="admin.php?import=pixelpost_ajaxRatings&amp;step=1" method="post">';
        echo '  <table class="form-table">';
        echo '    <tbody>';
        foreach ($settings as $name => &$setting) {
            echo '      <tr valign="top">';
            echo '        <th scope="row">';
            echo '          <label for="' . $name . '" name="' . $name . '" style="width: 300px; display: inline-block;">';
            echo             self::setting2Label($name);
            echo '          </label>';
            echo '        </th>';
            echo '        <td>';
            echo '          <input id="' . $name . '" name="' . $name . '" type="' . self::setting2Type($name) . '" value="' . $setting . '"  size="60" />';
            echo '        </td>';
            echo '      </tr>';
        }
        echo '    </tbody>';
        echo '  </table>';
        echo '  <p>';
        echo '    <input type="submit" name="' . self::PPIMPORTER_PIXELPOST_SUBMIT . '"  class="button button-primary" value="' . __( 'update settings' ) . '" />';
        echo '    <input type="submit" name="' . self::PPIMPORTER_PIXELPOST_RESET  . '"  class="button button-primary" value="' . __( 'reset settings' )  . '" />';
        echo '  </p>';
        echo '</form>';
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
        $wpdb->pp2wp = $wpdb->prefix . PP_Import::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;
    }

    function get_pp_dbh() {
        if (!isset($this->ppdbh)) $this->init();
        return $this->ppdbh;
    }

    /**
     * gets the wp post id bound to a pp post id.
     */
    function get_pp2wp_wp_post_id($pp_post_id) {
        global $wpdb;
        $wpdb->pp2wp = $wpdb->prefix . PP_Import::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;

        $row = $wpdb->get_row("SELECT * FROM $wpdb->pp2wp WHERE pp_post_id = $pp_post_id", ARRAY_A);
        return is_null($row) ? false : $row['wp_post_id'];
    }

    function get_pp_post_count() {
        $res_pdo = $this->get_pp_dbh()->query("SELECT count(id) as 'post_count' FROM {$this->prefix}pixelpost");
        $ret = $res_pdo->fetchAll();
        if (is_array($ret)) {
            return $ret[0]['post_count'];
        } else {
            return 0;
        }
    }

    function get_pp_post_ratings() {
        return $this->get_pp_dbh()->query("SELECT * FROM {$this->prefix}ajaxRatings");
    }

    protected $hostnameCache = array();
    function insert_vote($wp_post_id, $wp_post_title, $rate, $now, $voting_ip) {
        if (!isset($this->hostnameCache[ $voting_ip ])) {
            $hostname = $this->hostnameCache[ $voting_ip ] = esc_attr(@gethostbyaddr($voting_ip));
        }
        $hostname = $this->hostnameCache[ $voting_ip ];
        global $wpdb;
        $rate_log = $wpdb->query(
            "INSERT INTO $wpdb->ratings " .
            "VALUES (0, $wp_post_id, '$wp_post_title', $rate, '$now', '$voting_ip', '$hostname', 0, 0)");
    }

    function pp_ajaxRatings2wp_postRatings() {
        $pp_posts_count = $this->get_pp_post_count();

        $now = current_time('timestamp');

        $count = 0;
        $pp_ratings = $this->get_pp_post_ratings();
        set_time_limit(0);
        foreach($pp_ratings as $pp_rating) {
            if ($pp_rating['total_votes'] == 0) { // no vote, skip this entry
                continue;
            }
            $wp_post_id = $this->get_pp2wp_wp_post_id($pp_rating['img_id']);
            if ($wp_post_id === false) { // no matching wp post
                continue;
            }
            $wp_post_title = get_the_title($wp_post_id);
            // readjust rates from pp scale to wp scale
            $rate = $pp_rating['total_rate'] * $this->wpmaxrating / $this->ppmaxrating;
            foreach(unserialize($pp_rating['used_ips']) as $voting_ip) {
                $this->insert_vote($wp_post_id, $wp_post_title, $rate, $now, $voting_ip);
            }
            ++$count;
break;
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

        if (isset ( $_POST[ self::PPIMPORTER_PIXELPOST_SUBMIT ] ) ||
            isset ( $_POST[ self::PPIMPORTER_PIXELPOST_RESET ] )  ||
            empty ( $_GET['step'] ) ) {
            $step = 0;
        } else {
            $step = intval ( $_GET ['step']);
        }

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
            echo '<form action="admin.php?import=pixelpost_ajaxRatings&amp;step=' . ($step + 1) . '" method="post">';
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
    $GLOBALS['pp_ajaxRatings_import'] = new PP_AjaxRatings_Import();
    register_importer(
        'pixelpost_ajaxRatings',
        'PixelPost Ajax Ratings',
        __('Import <strong>ajaxRatings</strong> from a pixelpost installation.', 'pixelpost-ajaxRatings-importer'),
        array($GLOBALS['pp_ajaxRatings_import'], 'dispatch')
    );
}
add_action('admin_init', 'pixelpost_ratings_importer_init');
