<?php

/*
Plugin Name: WP Large Nav Fix
Description: Fixes max_input_vars overflow issue encountered with WP Nav menus with many links resulting in menu arbitrarily chopping on save
Author: Jon Lauters
Version: 1.0
*/

class WP_Large_Nav {

    static $instance;

    public static function factory() {
        if(!isset(self::$instance)) {
            self::$instance = new WP_Large_Nav();
        }

        return self::$instance;
    }

    public function admin_scripts() {
        wp_localize_script('wpjs', 'WPJS', array('siteurl' => get_option('siteurl') ));
    }

    public function init() {
    
        add_action('admin_init', array($this, 'admin_scripts'));
        add_action('init', array($this, 'request_handler'), -1);

        require_once ABSPATH.'/wp-includes/pluggable.php';

        // Backwards Compat for global $wp_rewrite
        global $wp_rewrite;
        if(!is_object($wp_rewrite)) {
            $wp_rewrite = new WP_Rewrite();
        }

        // Register and Enqueue or javascript for WP_Large_Nav Save
        if(is_admin()) {

            wp_register_script(
                'wp-large-nav-save'
               ,WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__),"", plugin_basename(__FILE__)).'js/wp_large_nav_save.js'
               ,array('jquery')
               ,1.0
            );
            wp_enqueue_script('wp-large-nav-save');
           
        }
    }

    function request_handler() {
        if(isset($_REQUEST['action'])) {
            switch($_REQUEST['action']) {
                case 'wp-large-save-nav':
                    echo self::save_menu();
                    exit;
            }
        }
    }


    // Based on the update case in nav-menus.php
    public static function save_menu() {

        // User Permissions Check
        if(!current_user_can('edit_theme_options')) {
            wp_die(__('Sorry, you can\'t edit the menu.'));
        }

        check_admin_referer('update-nav_menu', 'update_nav_menu_nonce');

        $messages  = array();
        $menu      = trim($_REQUEST['menu']);
        $menu_name = trim(esc_html($_REQUEST['menu_name']));

        // Decode our string. Build up a "sane" array structure of items
        $nav_items  = array();
        $items_array = explode('&', urldecode($_REQUEST['items']));
        foreach($items_array as $idx => $value) {
            $value_parts = explode('=', $value);

            $key = $value_parts[0];
            if($idx < 1) { $key = $value_parts[0]; }

            // Pull off the id from our keyname
            $key_parts = explode('[', $key);
            $key       = $key_parts[0];
            $id        = str_replace(']', '', $key_parts[1]);

            $nav_items[$id][$key] = $value_parts[1];
        }

        // Add New Menu
        if(0 == $menu) {

            $new_menu_title = $menu_name;
            if($new_menu_title) {
                $_nav_menu_selected_id = wp_update_nav_menu_object(0, array('menu-name' => $new_menu_title));
                if(is_wp_error($_nav_menu_selected_id)) {
                    $messages[] = '<div id="message" class="error"><p>'.$_nav_menu_selected_id->get_error_message().'</p></div>';
                } else {
                    $_menu_object            = wp_get_nav_menu_object($_nav_menu_selected_id);
                    $nav_menu_selected_id    = $_nav_menu_selected_id;
                    $nav_menu_selected_title = $_menu_object->name;
                    $message[] = '<div id="message" class="updated"><p>'.sprintf(__('The <strong>%s</strong> menu has been successfully create.'),
                        $nav_menu_selected_title).'</p></div>';
                }
            } else {
                $messages[] = '<div id="message" class="error"><p>'.__('Please enter a valid menu name.').'</p></div>'; 
            }
        } else {
        // Edit Existing Menu

            $_menu_object = wp_get_nav_menu_object($menu);
            $menu_title   = $menu_name;
            if(!$menu_title) {
                $messages[] = '<div id="message" class="error"><p>'.__('Please enter a valid menu name.').'</p></div>';
                $menu_title = $_menu_object->name;
            }

            if(!is_wp_error($_menu_object)) {
                $_nav_menu_selected_id = wp_update_nav_menu_object($menu, array('menu-name' => $menu_title));
                if(is_wp_error($_nav_menu_selected_id)) {
                    $_menu_object = $_nav_menu_selected_id;
                    $messages[] = '<div id="message" class="error"><p>'.$_nav_menu_selected_id->get_error_message().'</p></div>';
                } else {
                    $_menu_object = wp_get_nav_menu_object($_nav_menu_selected_id);
                    $nav_menu_selected_title = $_menu_object->name;
                }
            }

            // Update Nav Items
            if(!is_wp_error($_menu_object)) {
                $unsorted_menu_items = wp_get_nav_menu_items(
                    $menu, array('orderby' => 'ID', 'output' => ARRAY_A, 'output_key' => 'ID', 'post_status' => 'draft,publish')
                );

                $menu_items = array();
                foreach($unsorted_menu_items as $_item) {
                    $menu_items[$_item->db_id] = $_item;
                }

                wp_defer_term_counting(true);
                foreach($nav_items as $nav_id => $items) {
                    if(empty($items['menu-item-title'])) { continue; }

                    $menu_item_db_id = wp_update_nav_menu_item($menu, $nav_id, $items);
                    if(is_wp_error($menu_item_db_id)) {
                        $messages[] = '<div id="message" class="error"><p>'.$menu_item_db_id->get_error_message().'</p></div>';
                    } elseif (isset($menu_items[$menu_item_db_id])) {
                        unset($menu_items[$menu_item_db_id]);
                    }
                }

                // Remove Menu Items from the menu that were not in $_POST
                if(!empty($menu_items)) {
                    foreach(array_keys($menu_items) as $menu_item_id) {
                        if(is_nav_menu_item($menu_item_id)) {
                            wp_delete_post($menu_item_id);
                        }
                    }
                }

                // Remove nonexistent/deleted menus
                $nav_menu_option             = (array) get_option('nav_menu_options');
                $nav_menu_option['auto_add'] = array_intersect($nav_menu_option['auto_add'], wp_get_nav_menus(array('fields' => 'ids')));
                update_option('nav_menu_options', $nav_menu_option);

                wp_defer_term_counting(false);

                do_action('wp_update_nav_menu', $nav_menu_selected_id);
                $messages[] = '<div id="message" class="updated"><p>'.sprintf(__('The <strong>%s</strong> menu has been updated.'),
                    $nav_menu_selected_title).'</p></div>';
                unset($menu_items, $unsorted_menu_items);
            }
        }

        return json_encode(array('success' => true, 'messages' => $messages));
    }
}
WP_Large_Nav::factory()->init();
