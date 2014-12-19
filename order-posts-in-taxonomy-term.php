<?php

/*
Plugin Name: Order Posts In Taxonomy Term
Plugin URI: http://qstudio.us/plugins
Description: Order Posts or Custom Post Types in category or custom taxonomy term via a drag & drop AJAX interface.
Version: 1.2.0
Author: Q Studio
Author URI: http://qstudio.us/
License: GPLv2
Text Domain: optt_text_domain
Domain Path: /languages
*/

/*
 * Credits: 
 * 
 * This plugin is based on on the Re-order posts within Category plugin bt Aurélien Chappard ( https://wordpress.org/plugins/reorder-post-within-categories/ )
 */

if( ! class_exists( 'Order_Posts_In_Taxonomy_Term' ) ) {
    
    // instatiate plugin via WP action - not too early, not too late ##
    add_action( 'init', array ( 'Order_Posts_In_Taxonomy_Term', 'get_instance' ), 0 );
    
    // hook for activation ##
    register_activation_hook( __FILE__ , array( 'Order_Posts_In_Taxonomy_Term', 'activation_hook' ) );

    // hook for desactivation ##
    register_deactivation_hook( __FILE__ , array( 'Order_Posts_In_Taxonomy_Term', 'deactivation_hook' ) );
    
    // define class ##
    class Order_Posts_In_Taxonomy_Term {
        
        // Refers to a single instance of this class. ##
        private static $instance = null;
        
        // debugging
        const debug = false;
        
        // translations
        static $text_domain = 'optt_text_domain';

        // WP admin options ##
        public $stored_post_types = "optt_stored_post_types";
	public $stored_terms = "optt_stored_terms";
        
        // custom DB table - this could one day be integrated in postmeta or a single value in wp_options ##
	public static $db_table = "postorder";
        
        // set-up filter for wp_query ##
	public $filter_wp_query = true;
        
        
	/**
         * Creates or returns an instance of this class.
         *
         * @since       1.2.0
         * @return      Foo     A single instance of this class.
         */
        public static function get_instance() {

            if ( null == self::$instance ) {
                self::$instance = new self;
            }

            return self::$instance;

        }
        
        
        /**
         * Instatiate Class
         * 
         * @since       1.2.0
         * @return      void
         */
        private function __construct() 
        {
            
            // load plugin translations ##
	    load_plugin_textdomain( self::$text_domain, false, basename( dirname(__FILE__) ) . '/languages' );
	    
	    // link to the plugin settings page ##
	    $plugin = plugin_basename(__FILE__); 
	    add_filter( "plugin_action_links_$plugin", array( $this, 'plugin_action_links' ) );

	    // Update plugin options - run on WP Init action ##
	    add_action( 'init', array( $this, 'update_stored_post_types' ) );
            
	    // Add Plugin Options page ##
	    add_action( 'admin_menu', array( $this, 'add_options_page' ) );
	    
	    // Add post type ordering pages ##
	    add_action( 'admin_menu', array( $this, 'add_order_page') );
	    
            // AJAX callback methods ##
	    add_action( 'wp_ajax_term_status', array( $this, 'ajax_term_status' ) );
	    add_action( 'wp_ajax_order_posts', array( $this, 'ajax_order_posts' ) );
	    
            // save and delete post callbacks ##
	    add_action( 'save_post', array( $this, 'save_post') );
	    add_action( 'before_delete_post', array( $this, 'before_delete_post') );
	    add_action( 'trashed_post', array( $this, 'before_delete_post') );
	    
            // keep everything in sync ##
	    add_action( 'optt_sync', array( $this, 'sync' ) );
	    
            // filters run outside of the admin ##
	    if( ! is_admin() ) {
                
		add_filter( 'posts_join', array( $this, 'posts_join' ) );
		add_filter( 'posts_where', array( $this, 'posts_where' ) );
		add_filter( 'posts_orderby', array( $this, 'posts_orderby' ) );
                
	    }
	}
	
        
	/**
	 * Run whenever the plugin is activated
         * Creates DB tables and adds initial plugin options
         * 
         * @since       1.2.0
         * @return      void
	 */
	public function activation_hook()
	{
            
            // grab global $wpdb ##
            global $wpdb;
            
            // set-up table name ##
            $table_name = $wpdb->prefix . self::$db_table;
            
            // SQL for table creation ##
            $sql_create_table = 
                "
                CREATE TABLE IF NOT EXISTS $table_name (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `term_id` int(11) NOT NULL,
                    `post_id` int(11) NOT NULL,
                    PRIMARY KEY (`id`)
                )
                ";
            
            // execute ##
            $wpdb->query( $sql_create_table );
            
            #wp_die( pr( $sql_create_table ) );
            
            // include WP Delta library ##
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            // Execure SQL ##
            dbDelta( $sql_create_table );

            // Add initial plugin option ##
            #add_option( $this->db_version_name, $this->$db_version );
            
	}
	
        
	/**
	 * Run when the plugin is uninstalled - NOT on de-activation
         * 
         * @since       1.2.0
         * @return      void
	 */
	public function deactivation_hook()
	{
            
	    // grab global $wpdb ##
            global $wpdb;
            
            // include WP Delta library ##
	    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            // set-up table name ##
            $table_name = $wpdb->prefix . self::$db_table;
            
            // SQL to drop DB tables ##
	    $sql_drop_table = "DROP TABLE IF EXISTS $table_name";
            
            // Execure SQL ##
	    $wpdb->query( $sql_drop_table );
	    dbDelta( $sql_drop_table );
	    
            // Delete plugin options ##
	    #delete_option( $this->db_version_name );
	    
            // drop all saved data ##
            #$sql_truncate_table = "TRUNCATE TABLE $table_name";
            #$wpdb->query( $sql_truncate_table );
            
            // clean up WP_Options table ##
	    $sql_delete_options = 
                "
                DELETE from wp_options 
                WHERE option_name like 'optt_%'
                ";
            
            // Execute SQL ##
	    $wpdb->query( $sql_delete_options );
	    dbDelta( $sql_delete_options );
            
	}
        
         
	/**
	 * Display a link to setting page from inside the plugin description
         * 
         * @since       1.2.0
         * @param       Array        $links
         * @return      Array
         */
	public function plugin_action_links( $links )
	{
            
            // set-up link ##
	    $settings_link = '<a href="options-general.php?page=order-posts-in-taxonomy-term.php">' . __( 'Settings', self::$text_domain ) . '</a>'; 
            
            // add the new link to the $links Array ##
	    array_unshift( $links, $settings_link ); 
	    
            // kick back links Array ##
            return $links;
            
	}
        
        
        /**
         * WP Admin Head action - used to add plugin assets
         * 
         * @since       1.2.0
         * @return      false
         */
	public function admin_head()
	{
            
            // plugin styles ##
            wp_enqueue_style( "optt-style", plugins_url( 'order-posts-in-taxonomy-term.css', __FILE__ ) );
            
            // plugin JS ##
            wp_enqueue_script( 'optt-script', plugin_dir_url(__FILE__).'order-posts-in-taxonomy-term.js', array('jquery') );
            
            // Sortable jQuery magic ##
            wp_enqueue_script( 'jquery-ui-sortable', '/wp-includes/js/jquery/ui/jquery.ui.sortable.min.js', array( 'jquery-ui-core', 'jquery-ui-mouse' ), '1.8.20', 1 );
	   
            // localist varaibles for JS ##
            wp_localize_script( 'optt-script', 'OPTT', array(
                'secure_taxonomy'  => wp_create_nonce('secure_taxonomy'),
                'secure_posts'     => wp_create_nonce('secure_posts'),
                'debug'            => self::debug
            ));
           
	}
        
        
        /**
         * Ensure saved data in database table is synced and up-to-date
         * 
         * @since       1.2.0
         * @return      void
         */
	public function sync()
        {
            
            // grab all public post types ##
	    $post_types = get_post_types ( 
                array( 
                    'show_in_nav_menus' => true,
                    'public'            =>true, 
                    'show_ui'           =>true, 
                    'hierarchical'      => false 
                ), 
                'object' 
            );
            
            // get stored options ##
	    $stored_post_types = $this->get_stored_post_types();
            
            // set-up a new array ##
	    $taxonomies_to_delete = array();
            
            // if we found some post types ##
	    if( $post_types ) {
		
                // loop over each post type ##
                foreach ( $post_types as $post_type ) {
                    
                    // grab post type taxonomies - as an object ##
		    $taxonomies = get_object_taxonomies( $post_type->name, 'objects' );
                    
                    // if we found any taxonomies ##
		    if( count( $taxonomies) > 0 ) {
                        
                        // loop over each taxonomty ##
			foreach ( $taxonomies as $taxonomy ){
                            
                            // if the xaonomy is hierarchial ##
			    if( $taxonomy->hierarchical == 1 ) {
                                
                                // if this post type is selected to be used ##
				if( isset( $stored_post_types[$post_type->name] ) ) {
                                    
                                    // but this taxonomy is not selected ##
				    if( ! in_array( $taxonomy->name, $stored_post_types[$post_type->name] ) ) {
                                        
                                        // mark this taxonomy for deletion ##
					$taxonomies_to_delete[] = $taxonomy->name;
				    }
                                
                                // this post type is not being used, to mark all taxonomies for deletion -- seems a little long-winded.. ##
				} else {
                                    
                                    // mark this taxonomy for deletion ##
				    $taxonomies_to_delete[] = $taxonomy->name;
                                    
				}
                                
			    }
                            
			}
                        
		    }
                    
		}
                
            }
            
            // check which taxonomies we're going to clean up ##
            #self::pr( $taxonomies_to_delete );
            
            // set-up a new array - with zero as it's first key ##
	    $terms_to_delete = array( 0 );
            
            // get all term data from $taxonomies_to_delete Array ##
	    $get_terms = get_terms( $taxonomies_to_delete );
            #self::pr( $get_terms );
            
            // loop over terms - get ID of each term ##
	    foreach ( $get_terms as $term ){
                
                // get term translations ##
                $term_translations = self::get_term_translations( $term->term_id );
                
                // loop over and assign the passed value to all translated terms - note: we only want the term_id ##
                foreach ( $term_translations as $term ) {
                
                    $terms_to_delete[] = $term;
                    
                }
                
	    }

            #self::pr( $terms_to_delete );
            
            // grab global $wpdb ##
            global $wpdb;
            
            // set-up DB name ##
            $table_name = $wpdb->prefix . self::$db_table;
            
            // if we have more than zero values in the $terms_to_delete Array ##
	    if( count( $terms_to_delete ) > 0 ) {
                
                // create new SQL script ##
		$sql = "DELETE FROM $table_name WHERE (";

                // loop over each item ##
		for( $d = 0; $d < count( $terms_to_delete ) ; $d++ ){
                    
                    // add OR's ##
		    if($d > 0)
		    {
			$sql .= "OR";
		    }
		    $sql .= sprintf( " (term_id = %d) ", $terms_to_delete[$d]);
		}
                
                // close SQL ##
		$sql.= ")";
                
                #self::pr( $sql );
                
                // Execure SQL ##
		$wpdb->query($sql);		    
                
	    }

	    // check if the DB is empty ##
	    if( 0 == $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ) ) { 
                
                // set-up SQL to reset the AUTO_INCREMENT value ##
		$sql = "ALTER TABLE $table_name AUTO_INCREMENT = 1";
                
                // Execure it ##
		$wpdb->query( $sql );	
                
	    }
            
	}
        
        
        /**
	 * Add an option page link for the administrator only
         * 
         * @since       1.2.0
         * @return      void
	 */
	public function add_options_page()
	{
            
	    if ( function_exists( 'add_options_page' ) ) {
                
		add_options_page( 
                    __( 'Order Posts', self::$text_domain), 
                    '< '.__( 'Order Settings', self::$text_domain).' >', 
                    'manage_options', 
                    basename( __FILE__ ), 
                    array( $this, 'render_options_page' ) 
                );
                
	    }
            
	}
        
        
	/**
	 * Render the plugin options page
         * 
         * @since       1.2.0
         * @return      string      HTML code for admin options page
	 */
	public function render_options_page()
	{
            
            // check if options saved correctly ##
	    if ( 
                ! empty( $_POST ) 
                && check_admin_referer( 'update_stored_post_types', 'nonce_update_stored_post_types' ) 
                && wp_verify_nonce( $_POST['nonce_update_stored_post_types'], 'update_stored_post_types' ) )
	    {
                
                // check for unecessary entries -- using an action allows this to be removable ##
		do_action( "optt_sync" );
                
?>
		<div class="updated">
                    <p>
                        <strong><?php _e( "Options Updated.", self::$text_domain );?></strong> 
                        <?php _e( "Each of your selected Post Types now has an < Order > option.", self::$text_domain );?>
                    </p>
                </div>
<?php
	    }
            
            // grab stored post_types ##
	    $stored_post_types = $this->get_stored_post_types();

?>
	    <div class="wrap">
                
		<h2><?php _e( 'Order Posts By Taxonomy Terms', self::$text_domain ); ?></h2>
                
		<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
<?php 

                    // set-up nonce ##
                    wp_nonce_field( 'update_stored_post_types','nonce_update_stored_post_types' ); 
                    
?>
		    <p><?php _e( "Select the Taxonomies that you want to manually order the posts for.", self::$text_domain ); ?></p>
		    <h3><?php _e( "Available Taxonomies", self::$text_domain ); ?>:</h3>
<?php

                    // grab all public posts types ##
                    $post_types = get_post_types( 
                        array( 
                            'show_in_nav_menus' => true,
                            'public'            =>true, 
                            'show_ui'           =>true, 
                            'hierarchical'      => false 
                        ), 'object' 
                    );

                    // if we found post types - continue ##
                    if( $post_types ) {		

                        // loop over each found post type ##
                        foreach ( $post_types as $post_type ) {

                            // grab all taxonomies for this post type ##
                            $taxonomies = get_object_taxonomies( $post_type->name, 'objects' );

                            // if taxonomies found ##
                            if( count( $taxonomies ) > 0 ) {

                                // echo the Taxonomy name
?>
                                <strong><?php echo $post_type->labels->menu_name; ?></strong>
<?php
				   
                                // Loop over each of the found taxonomy
                                foreach ( $taxonomies as $taxonomy ) {

                                    // only allow ordering on hierarchial taxonomies ##
                                    if( $taxonomy->hierarchical == 1 ) {

                                        // default to not checked ##
                                        $ischecked = '';

                                        // this post type is active ##
                                        if ( isset( $stored_post_types[$post_type->name] ) ) {

                                            if( in_array( $taxonomy->name, $stored_post_types[$post_type->name] ) ) {

                                               $ischecked = ' checked = "checked"';

                                            }

                                        }
                                            
?>
                                        <p>&nbsp;&nbsp;
                                            <label>
                                                <input type="checkbox"<?php echo $ischecked; ?> value="<?php echo $taxonomy->name; ?>" name="post_types[<?php echo $post_type->name; ?>][]">
                                                    <?php echo $taxonomy->labels->name; ?>
                                                </input>
                                            </label>
                                        </p>
<?php
                                    }

                                }

                            }

                        }
			
?>
                        <p class="submit">
                            <input id="submit" class="button button-primary" type="submit" value="<?php _e('Save', self::$text_domain); ?>" name="submit" />
                        </p>
<?php
                            
                    }
                        
?>
		</form>
	    </div>
<?php

	}
        
        
        /**
         * Filter wp_query JOIN statement 
         * 
         * @global      object      $wpdb
         * @global      object      $wp_query
         * @param       string      $args
         * @return      string
         */
	public function posts_join( $args )
        {
	    
            // grab global objects ##
	    global $wpdb, $wp_query;
	    
            // assign table name ##
	    $table_name = $wpdb->prefix . self::$db_table;
	    
            // some checks ##
            if ( ! $wp_query || ! get_queried_object() ) {
                
                // nothing cooking ##
                return $args;
                
            }
            
	    $get_queried_object = $wp_query->get_queried_object();
	    
	    #$category_id = $get_queried_object->slug;
	    $term_id = $get_queried_object->term_id;
	    
	    #if( ! $category_id ) {
		#$category_id = $this->use_term;
	    #}
	    
	    $userOrderOptionSetting = $this->get_stored_terms();
            #self::pr( $userOrderOptionSetting );
            
	    if( 
                isset( $userOrderOptionSetting[$term_id] ) 
                && $userOrderOptionSetting[$term_id] == "true" 
                && $this->filter_wp_query == true
            ){
                
		$args .= " INNER JOIN $table_name ON ".$wpdb->posts.".ID = ".$table_name.".post_id ";
		#self::pr( $args );
                
	    }
	    
	    return $args;
            
	}
	
        
        /**
         * Filter wp_query WHERE statement 
         * 
         * @global      object      $wpdb
         * @global      object      $wp_query
         * @param       string      $args
         * @return      string
         */
        public function posts_where($args)
        {
	    
            // grab global objects ##
	    global $wpdb, $wp_query;
	    
            // assign table name ##
	    $table_name = $wpdb->prefix . self::$db_table;
	    
            // some checks ##
            if ( ! $wp_query || ! get_queried_object() ) {
                
                // nothing cooking ##
                return $args;
                
            }
	    
	    $get_queried_object = $wp_query->get_queried_object();
	    
	    #$category_id = $get_queried_object->slug;
	    $term_id = $get_queried_object->term_id;
	    
	    #if(!$category_id) {
		#$category_id = $this->use_term;
	    #}
	    
            // grab settings ##
	    $userOrderOptionSetting = $this->get_stored_terms();
            
	    if ( 
                isset( $userOrderOptionSetting[$term_id] ) 
                && $userOrderOptionSetting[$term_id] == "true" 
                && $this->filter_wp_query == true
            ){
                
		$args .= " AND $table_name".".term_id = '".$term_id."'";
		#self::pr( $args );
                
	    }
	    
	    return $args;
	}
        
        
        /**
         * Filter wp_query ORDERBY statement 
         * 
         * @global      object      $wpdb
         * @global      object      $wp_query
         * @param       string      $args
         * @return      string
         */
	public function posts_orderby( $args )
        {
	    
            // grab global objects ##
	    global $wpdb, $wp_query;
	    
            // assign table name ##
	    $table_name = $wpdb->prefix . self::$db_table;
	    
            // some checks ##
            if ( ! $wp_query || ! get_queried_object() ) {
                
                #self::pr( 'KICKED' );
                
                // nothing cooking ##
                return $args;
                
            }
	    
	    $get_queried_object = $wp_query->get_queried_object();
	    
	    // get term_id from queried object ##
	    $term_id = $get_queried_object->term_id;
	    
            // load up stored terms ##
	    $get_stored_terms = $this->get_stored_terms();
            
            #self::pr( $args );
            
            // check if we've got a match ##
	    if (
                isset( $get_stored_terms[$term_id] ) 
                && $get_stored_terms[$term_id] == "true" 
                && $this->filter_wp_query == true
            ){
                
                // update SQL args ##
		$args = $table_name.".id ASC";
		#self::pr( $args );
                
            }
	    
	    return $args;
            
	}
        
        
        /**
        * Get default languge from "Polylang" or WordPress
        * 
        * @since        0.1
        * @return       string      language slug
        */
        private static function get_default_language()
        {
            
            // Get locale value from WP
            $default_language = substr( get_locale(), 0, 2 );
            
            // grab polylang global object ##
            global $polylang;
            
            if ( $polylang && is_plugin_active( "polylang/polylang.php" ) ) { 
                
                return pll_default_language() ? pll_default_language() : $default_language ;
                
            } else {
                
                // Return locale value from WP
                return $default_language;
                
            }
            
        }
        
        
        /**
         * Get post languge from "Polylang"
         * 
         * @since       0.1
         * @return      string      Post language slug
         */
        private static function get_post_language( $post = null )
        {
            
            global $polylang;
            
            if ( ! $polylang || ! is_plugin_active( "polylang/polylang.php" ) ) { 
                
                #self::pr( "loaded.." );
                return self::get_default_language(); 
                
            }
            
            if ( is_null ( $post ) ) { 
                
                global $post;
                
            }
            
            if ( ! $post ) { 
                
                #self::pr( "no post.." );
                return self::get_default_language(); 
                
            }
            
            if ( $post_language = $polylang->model->get_post_language( $post->ID ) ) {
                
                #self::pr( "found.." );
                return $post_language->slug;
                
            } else {
                
                #self::pr( "full back" );
                return pll_default_language() ? pll_default_language() : self::get_default_language() ;
                
            }
            
        }
        
        
        /***
         * Check to see if a post or term is transalted - or can be translated
         * 
         * @param       $id             integer      Item ID
         * @since       1.2.0           
         * @return      Mixed           Boolean true is polylang is not installed || or post / term is translated || or false if not    
         */
	public function is_translated( $id = null, $type = 'post' )
        {
            
            // default to true ##
            $is_translated = true;
            
            // add polylang global to function scope ##
            global $polylang;
            
            if ( ! is_null ( $id ) ) { 
            
                // check for polylang ##
                if ( is_plugin_active( "polylang/polylang.php" ) && $polylang ) {

                    // for posts ##
                    if ( $type == 'post' ) {

                        // count number of translations found ##
                        if ( 2 > count ( self::get_post_translations( $id ) ) ) {
                            
                            // nope ##
                            $is_translated = false;
                            
                        }

                    }

                }
            
            }

            // return and allow filtering ##
            return apply_filters( 'optt_is_translated', $is_translated );
            
        }
        
        
        
        /***
         * Check for and return post translation ids in an Array
         * 
         * @param       $post_id        integer      Post ID
         * @since       1.2.0           
         * @return      Mixed           Boolean false if no post_id passed  || Array with single post_id if no translations || Array of post IDs of translations     
         */
	public function get_post_translations( $post_id = null )
        {
            
            // sanity check ##
            if ( is_null ( $post_id ) ) { return false; }
            
            // new array ##
            $post_ids = array();
            $post_ids[self::get_post_language( $post_id )] = $post_id; // add passed ID as first kvp ##
            
            // add polylang global to function scope ##
            global $polylang;
            
            // check for polylang ##
            if ( ! is_plugin_active( "polylang/polylang.php" ) || ! $polylang ) {
                
                // return value as an array ( ex: array( "en" => 1 ); )##
                return $post_ids;
                
            }
                        
            // get post type ##
            $post_type = get_post_type( $post_id ) ? get_post_type( $post_id ) : 'post' ;

            // grab translated ID's from polylang ##
            if ( $polylang_ids = $polylang->get_translations( $post_type, $post_id ) ) {

                // assign results array to return variable ##
                $post_ids = $polylang_ids;

                #self::pr( $polylang_ids );
                
            }

            // remove duplicate values and return ##
            return apply_filters( 'optt_get_post_translations', array_unique( $post_ids ) );
            
        }
        
        
        /***
         * Check for and return category translation ids in an Array
         * 
         * @param       $term_id        integer      Category ID
         * @since       1.2.0           
         * @return      Mixed          Boolean false if no $term_id passed  || Array with single $term_id if no translations || Array of category IDs of translations     
         */
	public function get_term_translations( $term_id = null )
        {
            
            // sanity check ##
            if ( is_null ( $term_id ) ) { return false; }
            
            // new array ##
            $term_ids = array();
            $term_ids[self::get_default_language()] = $term_id; // add passed ID as first kvp ##
            
            // add polylang global to function scope ##
            global $polylang;
            
            // check for polylang ##
            if ( ! is_plugin_active( "polylang/polylang.php" ) || ! $polylang ) {
                
                // return value as an array ( ex: array( "en" => 1 ) )## 
                return $term_ids;
                
            }
                        
            // get translated term ID's ##
            $term_ids = $polylang->model->get_translations( 'term', $term_id );
            #self::pr( $term_ids );
            #exit;

            // remove duplicate values -- allow for filtering ##
            return apply_filters( 'optt_get_term_translations', array_unique( $term_ids ) );
            
        }
                
        
	/**
	 * When a post is deleted or trashed this callback runs - passing the $post_id
         * 
	 * @param       integer         $post_id
	 */
	public function before_delete_post( $post_id = null )
        {
            
            // sanity check ##
            if ( is_null( $post_id ) ) { return false; }
            
            // grab global $wpdb object ##
	    global $wpdb;
            
            // set-up table name ##
	    $table_name = $wpdb->prefix . self::$db_table;
            
            // loop over passed post and all found translations ##
            foreach ( $this->get_post_translations( $post_id ) as $post ) { 
                
                // prepare SQL ##
                $sql = $wpdb->prepare( 
                    "
                    DELETE 
                    FROM $table_name 
                    WHERE ( post_id = %d )
                    ", 
                    $post
                );
                
                // execure it ##
                $wpdb->query( $sql );
                
            }            
	    
	}
        
        
	/**
	 * When a post is saved this callback runs - passing the $post_id
         * We need to check for changes to the taxonomies ( ex: moved from term_1 to term_2 )
         * 
	 * @param       integer     $post_id        Post ID
	 */
	public function save_post( $post_id = null )
	{
            
            // sanity check ##
            if ( is_null( $post_id ) ) { return false; }
            
	    // verify post is not a revision ##
	    if ( wp_is_post_revision( $post_id ) ) { return false; }
                
            // grab the global $wpdb object ##
            global $wpdb;

            // assign table name ##
            $table_name = $wpdb->prefix . self::$db_table;

            // Type de post
            $post_type = get_post_type( $post_id );
            
            // check we got a post type ##
            if ( ! $post_type ) { return false; }
            
            // grab a post_type object ##
            $post_type = get_post_type_object( $post_type );
            #self::pr( $post_type->name );
            
            // get all taxonomies available to the post's post_type ##
            $taxonomies = get_object_taxonomies( $post_type->name, 'objects' );
            
            // taxonomies found ##
            if( count( $taxonomies ) > 0 ) {
                
                // load all saved post types and taxnomies ##
                $stored_post_types = $this->get_stored_post_types();
                
                // grab just the post_type data for post's post_type ##
                $stored_post_type = $stored_post_types[$post_type->name];
                #self::pr( $get_stored_terms );
                
                // loop over taxonomies ##
                foreach ( $taxonomies as $taxonomy ){
                    
                    // check if taxonomy is hierarchial and if post_type is found ##
                    if ( 
                        $taxonomy->hierarchical == 1 
                        && is_array( $stored_post_type )
                        && in_array( $taxonomy->name, $stored_post_type )
                    ){
                        
                        #self::pr( $taxonomy->name, 'taxonomy->name' );
                         
                        // get all terms in this taxonomy ##
                        $terms = get_terms( $taxonomy->name );
                        #self::pr( $terms );

                        // loop over and assign the passed value to all translated posts - note: we only want the post_id ##
                        foreach ( self::get_post_translations( $post_id ) as $post_translations_id ) {

                            // get all terms from the current taxonomy for this post_id ##
                            $post_terms = wp_get_post_terms( $post_translations_id, $taxonomy->name );
                            #self::pr( $post_terms, 'Post Terms' );

                            // just grab the term_id's ##
                            $post_term_ids = wp_list_pluck( $post_terms, 'term_id' );
                            #self::pr( $post_term_ids, 'Post Term IDS' );

                            #self::pr( "----------------------------------------------------------" );

                             // if we have some terms ##
                             if ( count( $terms ) > 0 ) {

                                 // loop over each term ##
                                 foreach ( $terms as $term ) {

                                    // this post IS in this taxonomy term ##
                                    if( in_array( $term->term_id, $post_term_ids ) ) {

                                        #self::pr( "post_id: $post_translations_id found in term_id: $term->term_id ( $term->name )" );

                                        // count all the posts in this term ##
                                        $posts_in_term = $wpdb->get_var( 
                                            $wpdb->prepare(
                                                "
                                                SELECT COUNT(*) 
                                                FROM $table_name 
                                                WHERE term_id = %d
                                                ", 
                                                $term->term_id
                                                ) 
                                            );

                                        #self::pr( "found: $posts_in_term rows with term_id: $term->term_id ( $term->name )" );

                                        // if term count is greater than 0 ##
                                        if( $posts_in_term > 0 ) {

                                            // count how many times THIS post is listed in this category ##
                                            $this_post_in_term = $wpdb->get_var( 
                                                $wpdb->prepare(
                                                    "
                                                    SELECT COUNT(*) 
                                                    FROM $table_name 
                                                    WHERE post_id = %d AND term_id = %d
                                                    ", 
                                                    $post_translations_id, 
                                                    $term->term_id
                                                    ) 
                                                );

                                            #self::pr( "found: $this_post_in_term rows with post_id: $post_translations_id in term_id: $term->term_id ( $term->name )" );

                                            // if the post is NOT found in the term - we need to insert it ##
                                            if( $this_post_in_term == 0 ) {

                                                $wpdb->insert (
                                                    $table_name,
                                                    array (
                                                        'term_id'           => $term_id,
                                                        'post_id'           => $post_translations_id
                                                    ),
                                                    array (
                                                        '%d', // both values are decimals ##
                                                        '%d'
                                                    )
                                                );

                                                #self::pr( "post not found - so inserted post_id: $post_translations_id + term_id: $term->term_id ( $term->name )" );

                                            }

                                        }

                                    // this post is NOT in this taxonomy term ##
                                    } else {   

                                        // delete all references to this post_id in this term_id ##
                                        $delete_post_in_term = $wpdb->query( 
                                            $wpdb->prepare(
                                                "
                                                DELETE FROM $table_name 
                                                WHERE post_id = %d AND term_id = %d", 
                                                $post_translations_id, 
                                                $term->term_id
                                            ) 
                                        );

                                        #self::pr( "delete ( $delete_post_in_term ) where post_id: $post_translations_id and term_id: $term->term_id ( $term->name )" );

                                        // After deleting, count how many posts there are in this term_id left ##
                                        $posts_in_term = $wpdb->get_var( 
                                            $wpdb->prepare(
                                                "
                                                SELECT COUNT(*) 
                                                FROM $table_name 
                                                WHERE term_id = %d
                                                ", 
                                                $term->term_id
                                                ) 
                                            );

                                            #self::pr( "found: $posts_in_term rows with term_id: $term->term_id ( $term->name )" );

                                        // if there is only one posts in this category - delete it, as it can't be ordered alone ##
                                        if( $posts_in_term == 1 ) {

                                            $delete_all_in_term = $wpdb->query( 
                                                $wpdb->prepare(
                                                    "
                                                    DELETE FROM $table_name 
                                                    WHERE term_id = %d", 
                                                    $term->term_id
                                                ) 
                                            );

                                            #self::pr( "delete ( $delete_all_in_term ) where term_id: $term->term_id ( $term->name ) - because only 1 post left" );

                                        }

                                    }

                                    #self::pr( "----------------------------------------------------------" );

                                 }

                             }
                         
                        } // post_id translation ##
                         
                    } // tax found ##
                    
                } // tax loop ##
                 
            }
            
            // kill it for now ##
            #die();
                
	}
        
        
        /**
         * AJAX callback method for saving ordered list of posts
         * Polylang support added
         * 
         * @since       1.2.0
         * @return      void
         */
	public function ajax_order_posts()
	{
            
            // few security and post data checks ##
	    if ( 
                ! isset ($_POST['secure_posts'] ) 
                || ! wp_verify_nonce ( $_POST['secure_posts'], 'secure_posts' ) 
                || ! isset ( $_POST['term_id'] )    
                || ! isset ( $_POST['order'] )    
            ) {
		return false;
            }
            
            // grab the global $wpdb ##
	    global $wpdb;
            
            // set-up table name ##
	    $table_name = $wpdb->prefix . self::$db_table;
            
            // get the translated term ID's ##
            $terms = $this->get_term_translations( $_POST['term_id'] );
            #self::pr( $terms ); // test #
            
            // count ##
            $term_counter = 0;
            
            // create new SQL script ##
            $sql = "DELETE FROM $table_name WHERE (";
            
            // loop over each item ##
            foreach( $terms as $term_id ){

                // add OR's ##
                if( $term_counter > 0 ) {
                    
                    $sql .= "OR";
                    
                }
                
                $sql .= sprintf( " (term_id = %d) ", $term_id );
                
                // iterae counter ##
                $term_counter ++ ;
                
            }

            // close SQL ##
            $sql.= ")";

            #self::pr( $sql );

            // Execure SQL ##
            $wpdb->query($sql);	
            
            // search for post translations and set-up language variables ##
            $posts = array();
            foreach( explode( ",", $_POST['order'] ) as $post ) {

                $posts[] = self::get_post_translations( $post );

            }
            
            // loop over all categories ( ex: array( "en" => 1, "nl" => 2 ) )##
            foreach ( $terms as $term_lang => $term_id ) {
                
                // start a new array ##
                $value = array();

                // $posts contains an array of arrays - let's loop over it ##
                foreach ( $posts as $post ) {

                    // and the internal array for each post ##
                    foreach ( $post as $post_lang => $post_id ) {

                        // we need to grab the post_id for the current term language - held in $term_lang
                        if ( $post_lang == $term_lang ) {

                            // add each ( term_id, post_id ) as new array value ##
                            $value[] = "($term_id, $post_id)";

                        }

                    }

                }

                // Prepare SQL ##
                $sql = sprintf(
                    "
                    insert into $table_name ( term_id, post_id ) values %s
                    ", 
                    implode( ",", $value ) // implode array to string value ##
                );

                // test it ##
                #self::pr( $sql );

                // Excecute SQL ##
                $wpdb->query( $sql );
                    
            }
            
            // debug ##
            if ( self::debug ) {
                
                echo $_POST['order'];
                
            }
	    
            // All AJAX requests must die ##
	    die();
            
	}
	
        
	/**
         * AJAX callback function for activating category ordering
         * 
         * @since       1.2.0
         * @return      void
         */
	public function ajax_term_status()
	{
            
            // validate request value and nonce ##
	    if ( 
                isset( $_POST['secure_taxonomy'] )
                && wp_verify_nonce($_POST['secure_taxonomy'], 'secure_taxonomy') 
                && isset( $_POST['status'] )
                && isset( $_POST['term_id'] )
            ) {
	    
                // get saved values ##
                $get_stored_terms = $this->get_stored_terms();	  
                #self::pr( $get_stored_terms ); // array ##
                
                // get term translations ##
                $terms = self::get_term_translations( intval( $_POST['term_id'] ) );
                
                // loop over and assign the passed value to all translated terms - note: we only want the term_id ##
                foreach ( $terms as $term ) {
                    
                    $get_stored_terms[$term] = $_POST['status'];
                    
                }
                
                // test ##
                #self::pr( $get_stored_terms ); die;
                
                // update stored option value ##
                update_option( $this->stored_terms, $get_stored_terms );
                
                // debug ##
                if ( self::debug ) { echo 'Status: '.$_POST['status']; }
                
            } else {
                
                // debug ##
                if ( self::debug ) { echo "Failed :("; }
                
            }
                
	    // All AJAX requests must die ##
	    die();
            
	}
	
        
	/**
	 * Returns an array of stored admin options for this plugin
         * 
         * @since       1.2.0
         * @return      Array       Stored Plugin options  
	 */
	public function get_stored_post_types() 
        {
            
            return get_option( $this->stored_post_types );
            /*
            
	    $stored_post_types = array();
            
	    $get_option = get_option( $this->stored_post_types ); 
            
	    if ( ! empty( $get_option ) ) {
                
                foreach ( $get_option as $key => $option ) {
                    
                    $stored_post_types[$key] = $option;
                    
                }
                
	    }
            
            // update stored options ##
	    update_option( $this->stored_post_types, $stored_post_types );
            
            // kick back options ##
	    return $stored_post_types;
            */
            
	}
	        
        
        /**
         * Save Plugin Options
         * 
         * @since       1.2.0
         * @return      void
         */
	public function update_stored_post_types()
	{
            
	    // check if the $_POST object is NOT empty and that the Nonce passed ##
	    if ( 
                ! empty( $_POST ) 
                && isset( $_POST['nonce_update_stored_post_types'] ) 
                && wp_verify_nonce( $_POST['nonce_update_stored_post_types'], 'update_stored_post_types') )
	    {
                
                // did we pass a "post_types" ##
		if ( isset( $_POST['post_types'] ) ) {
                    
                    // assign category based on this ##
		    $stored_post_types = $_POST['post_types'];
                    
		} else {
                    
                    // return empty array - allows for deletion of category ##
		    $stored_post_types = array();
                    
		}
                
                #wp_die( self::pr( $stored_post_types ) );
                
		// assign value to correct array key ##
		#$stored_post_types['post_types'] = $stored_post_types;
		
                // update stored option ##
		update_option( $this->stored_post_types, $stored_post_types );
                
	    }
	}
        
        
        /**
	 * Returns an array of categories
	 */
	public function get_stored_terms()
        {
            
            return get_option( $this->stored_terms ) ;
            
	}
        
        
	/**
	 * Add the "< Order >" menu to each CPT admin menu
         * 
         * @since       1.2.0
         * @return      void
	 */
	public function add_order_page()
	{
            
	    // get all stored post types ##
	    $stored_post_types = $this->get_stored_post_types();
            
	    // get all public post types ##
	    $post_types = get_post_types( array( 
                'show_in_nav_menus' => true, 
                'hierarchical' => false )
                , 'object' 
            );
           
            // we found some post types ##
	    if( $post_types ) {
                
		// loop over each ##
		foreach ( $post_types as $post_type ) {
                
                    // this post type is saved, so we should use it ##
		    if ( isset( $stored_post_types[$post_type->name] ) ){
                        
                        // non built-in post type ##
			if ( $post_type->name != "post" ){
                            
			    $the_page = add_submenu_page( 
                                'edit.php?post_type='.$post_type->name, 
                                sprintf( __('Order "%s" Posts', self::$text_domain ), $post_type->name ),
                                '< '.__( 'Order', self::$text_domain ).' >', 
                                'edit_others_pages', 
                                'order-'.$post_type->name, 
                                array( $this,'render_order_page' )
                            );
                            
			} else {
                            
			    $the_page = add_submenu_page( 
                                'edit.php', 
                                'Order Posts', 
                                '< '.__( 'Order', self::$text_domain ).' >', 
                                'edit_others_pages', 
                                'order-'.$post_type->name, 
                                array( $this,'render_order_page' )
                            );
                            
			}
                        
                        // hook on to the admin_head action call ##
			add_action( 'admin_head-'.$the_page, array( $this, 'admin_head' ) );
                        
		    }
                    
		}
                
            }
            
	}
        
        
        /**
         * Render Post Category Order page
         * 
         * @since       1.2.0
         * @return      string      HTML for ordering page
         */
	public function render_order_page()
	{
            
            // get the post_type name ##
            $cpt_name = isset( $_GET['post_type'] ) ? $_GET['post_type'] : 'post' ; // take from post_type in querystring, default to post ##
            #self::pr( $cpt_name );
            
            // get an object of the post type ##
	    $post_type = get_post_types( array( 'name' => $cpt_name ), 'objects' );
            
            // no CPT found ##
            if ( ! $post_type ) { return false; }
            
            // get the CPT details ##
	    $post_type_detail = $post_type[$cpt_name];
            
            // unset the variables ##
	    unset( $post_type, $cpt_name );
	    
	    // get the stored post types ##
	    $stored_post_types = $this->get_stored_post_types();
	    
	    // check for passes values and Nonce result ##
	    if ( 
                ! empty( $_POST ) 
                && check_admin_referer( 'load_posts_in_term', 'nonce_load_posts_in_term' ) 
                && isset( $_POST['nonce_load_posts_in_term'] ) 
                && wp_verify_nonce( $_POST['nonce_load_posts_in_term'], 'load_posts_in_term' ) 
            ) {
                
                // if the $_POST variables are set ##
		if ( 
                    isset( $_POST['term'] ) 
                    && !empty( $_POST['term'] ) 
                    && $_POST['term'] != null
                    && isset( $_POST['taxonomy'] )
                ) {
                    
                    // grab posted values ##
		    $_post_term = $_POST['term'];
		    $_post_taxonomy = $_POST['taxonomy'];
		    
		    // if a $term ID was passsed ##
		    if( $_post_term > 0 ) {
                        
                        // grab global $wpdb ##
			global $wpdb;
			
			// set-up db table name ##
			$table_name = $wpdb->prefix . self::$db_table;
                        
                        // prepare SQL ##
			$sql = $wpdb->prepare(
                            "
                            SELECT * 
                            FROM $table_name 
                            WHERE term_id = '%d' 
                            ORDER BY id
                            ", 
                            $_post_term
                        );
                        
                        // Execure SQL ##
			$ordered_posts = $wpdb->get_results( $sql );
                        
                        // count results ##
			#$nbResult = count( $ordered_posts );
			
			#for( $k =0 ;$k < $nbResult; ++$k ) {
                            
			    #$ordered_posts_incl[$ordered_posts[$k]->post_id] = $ordered_posts[$k]->incl;
                            
			#}
			
			// set-up WP_Query args for post_type and tax_query ##
			$args = array(
                            'tax_query'         => array (
                                array (
                                    'taxonomy'  => $_post_taxonomy, 
                                    'operator'  => 'IN', 
                                    'field'     => 'id', 
                                    'terms'     => $_post_term
                                )
                            ),
                            'posts_per_page'    => -1,
                            'post_type'         => $post_type_detail->name,
                            'orderby'           => 'title',
                            'post_status'       => array ( 'publish', 'draft' ),
                            'order'             => 'ASC' 	
			);

                        // block wp_query filters ##
			$this->filter_wp_query = false;
                        
                        // set custom term ##
			#$this->use_term = $_post_term;
                        
                        // run WP_Query ##
			$query = new WP_Query( $args );
                        
                        // allow wp_query filters again ##
			$this->filter_wp_query = true;
                        
                        // set back to 0 ##
			#$this->use_term = 0;
                        
                        // grab posts from WP_Query ##
			$posts_array = $query->posts;
			
			// Creating an Array whose keys are the IDs of posts and values the posts themselves ##
			$temp_ordered_posts = array();
                        
                        // loop over and fill array keys & values ##
			for( $j = 0; $j < count( $posts_array ); ++$j ) {
                            
			   $temp_ordered_posts[$posts_array[$j]->ID] = $posts_array[$j];
                           
			}
                        
		    }
                    
		}
                
	    }
            
            // clean-up DB table -- using an action allows this to be removable ##
            #do_action( "optt_sync" );
            
?>
	    <div class="wrap">
		<h2><?php printf( __('Order "%s" Taxonomy Terms', self::$text_domain ), $post_type_detail->labels->menu_name ); ?>.</h2>
		<p>
		    <?php _e( 'Select a Term from the list to order the Posts', self::$text_domain );?>
		</p>
		
		<form method="post" id="select_term">
<?php
                    
                    // set-up nonce field ##
		    wp_nonce_field( 'load_posts_in_term', 'nonce_load_posts_in_term' );
                    
                    // grab the saved selected post types ##
		    $stored_post_types = $stored_post_types[$post_type_detail->name];
		    #self::pr( $post_type_detail->name );
                    
                    // start some empty variables ##
		    $taxonomies = '';
		    $taxonomy = '';
		    $term_selected = '';
                    
                    // we have some taxonomies selected ##
		    if( count( $stored_post_types ) > 0 ) {
                        
?>
			<select id="select_term_to_load" name="term">
                            <option value="null" disabled="disabled" selected="selected"><?php _e( 'Select', self::$text_domain );?></option>
<?php

                        // by default, all terms are enabled for selection ##
			$term_disabled = false;
                        
                        // loop over all selected taxonomies ##
			foreach( $stored_post_types as $stored_post_type ){
                            
                            #self::pr( $stored_post_type );
                            
                            // get taxonomies ##
			    $taxonomies = get_taxonomies( array( 'name'=> $stored_post_type ), 'object' );
                            #self::pr( $taxonomies );
                            
                            // get single taxonomy details ##
			    $taxonomy = $taxonomies[$stored_post_type]; 
                            #self::pr( $taxonomy );

			    // get all the terms for each taxonomy ##
			    $get_terms = get_terms( $taxonomy->name );
                            #self::pr( $get_terms );
                            
                            // terms found ##
			    if ( count( $get_terms ) > 0 ) {
                                
?>
				<optgroup id="<?php echo $taxonomy->name; ?>" label="<?php echo $taxonomy->labels->name; ?>">
<?php

                                    // loop over each found term ##
				    foreach ( $get_terms as $term ) {
                                        
                                        // none selected ##
					$selected = '';
                                        
                                        // $post term passed and matched current term_id - so select this ##
					if( isset( $_post_term ) && ( $_post_term == $term->term_id ) ) {
                                            
                                            // selected ##
					    $selected = ' selected = "selected"';
                                            
                                            // save selected term->name for later ##
					    $term_selected = $term->name;
                                            
					}
                                        
                                        // none disabled ##
					$disabled = '';
                                        
                                        // if we have less than 2 posts in the term - disable ##
					if( $term->count < 2 ) {
                                            
                                            // disable ##
					    $disabled = ' disabled = "disabled"';
                                            
                                            // store value to produce notice message ##
					    $term_disabled = true;
                                            
					}
                                        
?>
                                        <option<?php echo $selected; ?><?php echo $disabled; ?> value="<?php echo $term->term_id; ?>"><?php echo $term->name; ?></option>
<?php
                                        
				    }
                                    
?>
                                </optgroup>
<?php
                                
			    }


			}
                        
?>
                        </select>
<?php
                        
                        // disable term ##   
			if( $term_disabled ) {
			 
?>
                        <br/>
                        <span class="description optt-description">
                            <?php _e( 'The greyed out terms are not available for ordering, because they do not contain enough posts.', self::$text_domain ); ?>
                        </span>
<?php

                        }
                        
                        // pass the POSTED taxonomy_id in a hidden field ##
			$taxonomy_id = ( isset( $_post_taxonomy ) ? $_post_taxonomy : '' );
			
?>
                        <input type="hidden" id="taxonomy_id" name="taxonomy" value="<?php  echo $taxonomy_id; ?>"/>
<?php
                        
		    }

?>
		</form>
		<form id="form_result" method="post">
<?php
                    
                // did we get any posts from the WP_Query ? ##
                if( isset( $posts_array ) ) {
                        
?>
                    <div id="result">
                        <div id="sorter_box">
                            <h3><?php _e( 'Activate manual ordering for this Term?', self::$text_domain ); ?></h3>
<?php

                            // set-up values for radio inputs - default to NO ##
                            $checkedRadio1 = '';
                            $checkedRadio2 = ' checked = "checked"';

                            // grab stored values ##
                            $get_stored_terms = $this->get_stored_terms();

                            // check if we have a stored value for this Term and if it's true ##
                            if( isset( $get_stored_terms[$_post_term] ) && $get_stored_terms[$_post_term] == 'true' ) {

                                // select the first radio ( YES ) ##
                                $checkedRadio1 = $checkedRadio2;

                                // empty the second ( NO ) ##
                                $checkedRadio2 = '';

                            }
				
?>
                            <div id="catOrderedRadioBox">
                                <label for="yes">
                                    <input type="radio"<?php echo $checkedRadio1; ?> class="option_order" id="yes" value="true" name="useForThisCat" />
                                    <span><?php _e( 'Yes', self::$text_domain); ?></span>
                                </label><br/>
                                <label for="no">
                                    <input type="radio"<?php echo $checkedRadio2; ?> class="option_order" id="no" value="false" name="useForThisCat" />
                                    <span><?php _e( 'No', self::$text_domain ); ?></span>
                                </label>
                                <input type="hidden" name="termID" id="term_id" value="<?php echo $_post_term; ?>">
                                <span class="spinner" id="spinner_radio"></span>
                            </div>

                            <h3 class="box"><?php printf( __( 'Posts in the Term "%s"', self::$text_domain ), $term_selected ); ?></h3>
                            <span id="spinner_order_posts" class="spinner"></span>
                            <div class="clearBoth"></div>
                            <ul id="sortable-list" class="order-list" rel="<?php echo $_post_term; ?>">
<?php		

                            // set-up a new array to hold non-translated posts ( only for sites that are translated ) ##
                            $not_translated = array(  );
        
                            // loop over each of the stored and ordered posts in this term ##
                            for( $i = 0; $i < count( $ordered_posts ); ++$i ) {
                                
                                // grab post ID ##
                                $post_id = $ordered_posts[$i]->post_id;
                                
                                // grab post data for this $post_id from the WP_Query ##
                                $post = $temp_ordered_posts[$post_id];
                                
                                // unset the $post_id key from the temp array ##
                                unset( $temp_ordered_posts[$post_id] );
                                #$od = $ordered_posts_incl[$post->ID];
                                
                                // get post_status - to not if it's a draft ##
                                $post_status = $post->post_status == 'draft' ? ' ['.__( 'DRAFT', self::$text_domain ).'] ' : '' ;
                                
                                // check if this post has a translation ##
                                if ( self::is_translated( $post->ID ) ) {
                                
?>
                                <li id="<?php echo $post->ID; ?>">
                                    <span class="title"><?php echo get_the_title( $post ); echo $post_status; ?></span>
                                </li>
<?php

                                }
                                
                            }

                            // now loop over the remaining posts for this term, which have not been saved before - smart! ##
                            foreach( $temp_ordered_posts as $temp_ordered_posts_id => $temp_ordered_posts_post ) {
                                
                                // get post ID ##
                                $post_id = $temp_ordered_posts_id;
                                
                                // get post object ##
                                $post = $temp_ordered_posts_post;
                                
                                // get post_status - to not if it's a draft ##
                                $post_status = $post->post_status == 'draft' ? ' ['.__( 'DRAFT', self::$text_domain ).'] ' : '' ;
                                
                                // check if this post has a translation ##
                                if ( self::is_translated( $post->ID ) ) {
                                
?>
                                <li id="<?php echo $post->ID; ?>" class="not-listed">
                                    <span class="title"><?php echo get_the_title( $post ); echo $post_status; ?></span>
                                </li>
<?php

                                }

                            }

?>
                            </ul>
                        </div>
                    </div>
<?php

		    } // loop of posts from WP_Query ##
                    
?>
                </form>
		<div id="debug"></div>
<?php

	}
        
        
        /**
         * Debugging Routine, check if const $debug is set
         * 
         * @since       1.2.0
         * @param       mixed       $var     $value to var_dump
         * @param       string      $title
         * @return      Mixed       Debug info
         */
        protected static function pr( $var = null, $title = null ) 
        { 

            // sanity check ##
            if ( ! self::debug || is_null ( $var ) ) { return false; }

            // add a title to the dump ? ##
            if ( $title ) $title = '<h2>'.$title.'</h2>';

            // print it out ##
            print '<pre class="var_dump">'; echo $title; var_dump($var); print '</pre>'; 

        }
        
			
    }
    
}