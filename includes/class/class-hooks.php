<?php

namespace Custom_MIME_Types;

/**
 * Prevent direct script
 */
defined('ABSPATH') or die();

/**
 * 
 * Hooks
 */

if ( !class_exists('\Custom_MIME_Types\Hooks' )) {
    /**
     * Class Hooks
     */
    final class Hooks
    {

        /**
         * Init Hooks
         */
        public function init()
        { 
            /**
             * Admin hooks
             */
            add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_scripts'] );
            add_action( 'admin_menu', [$this, 'admin_settings_page'] );

            /**
             * Filter
             */
            add_filter('upload_size_limit', [$this, 'cmt_upload_size_limit'] );
            add_filter('upload_mimes', [$this, 'cmt_upload_mimes'] );

            
        }


        /**
         * Current available roles
         */
        function wp_roles_array()
        {
            $editable_roles = get_editable_roles();
            $roles = [];
            foreach ($editable_roles as $role => $details) { 
                if( array_key_exists('upload_files', $details['capabilities']) )
                $roles[esc_attr($role)] = translate_user_role($details['name']);
            }
            return $roles;
        }


        /**
         * Suggested Mimes
         */
        function default_suggestions(){
            $default_suggestions = [
                "webp" => "application/octet-stream",
                "svg" => "application/octet-stream",
                "bin" => "application/octet-stream",
                "eot" => "application/vnd.ms-fontobject",
                "jar" => "application/java-archive",
                "vue" => "text/js",
                "jsx" => "text/js",
                "json" => "application/json",
                "ts" => "video/mp2t",
                "otf" => "font/otf",
                "ttf" => "font/ttf",
                "woff" => "font/woff",
                "woff2" => "font/woff2",
                "weba" => "audio/webm",
            ];

            return apply_filters('cmt_default_suggestions', $default_suggestions );
        }
 

        /**
         * Current available extentions
         */
        function getExtentions(){
            $mimes = json_decode(json_encode(maybe_unserialize(stripslashes(get_option('_cmt_mimes')))));
            return $mimes;
        }


        /**
         * Current maximum upload size according to server
         */
        function file_upload_max_size()
        {
            static $max_size = -1;

            if ($max_size < 0) {
                // Start with post_max_size.
                $post_max_size = $this->parse_size(ini_get('post_max_size'));
                if ($post_max_size > 0) {
                    $max_size = $post_max_size;
                }

                // If upload_max_size is less, then reduce. Except if upload_max_size is
                // zero, which indicates no limit.
                $upload_max = $this->parse_size(ini_get('upload_max_filesize'));
                if ($upload_max > 0 && $upload_max < $max_size) {
                    $max_size = $upload_max;
                }
            }
            return $max_size;
        }


        /**
         * Parse size
         */
        function parse_size($size)
        {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
            $size = preg_replace('/[^0-9\.]/', '', $size); // Remove the non-numeric characters from the size.
            if ($unit) {
                // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
                return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
            } else {
                return round($size);
            }
        }


        /**
         * Enqueue admin scripts
         */
        public function admin_enqueue_scripts()
        {
            $localizable_array = [ 
                'ajaxurl' => admin_url('admin-ajax.php'),
                'roles' => $this->wp_roles_array(),
                'suggestions' => $this->default_suggestions(), 
                'extentions' => $this->getExtentions(), 
                'wp_max_upload_size' => $this->file_upload_max_size(),
                'max_upload_size' => get_option('_cmt_max_upload_size'),
                'size_unit' => get_option('_cmt_size_unit'),
                'size_units' => [
                    'bytes' => 1, 
                    'kb' => KB_IN_BYTES,
                    'mb' => MB_IN_BYTES,
                    'gb' => GB_IN_BYTES,                   
                ]
            ];

            wp_register_script('cmt_options', '');
            wp_localize_script('cmt_options', '_cmt', $localizable_array);
            wp_enqueue_script('cmt_options'); 
            wp_enqueue_style('cmt-admin', plugin_dir_url( CMT_FILE ) . 'public/css/admin.min.css');
            wp_enqueue_script('cmt-vue', plugin_dir_url( CMT_FILE ) . 'public/js/vue.global.prod.js', ['jquery'], filemtime(plugin_dir_path( CMT_FILE ) . 'public/js/vue.global.prod.js'), true);
            wp_enqueue_script('cmt-admin', plugin_dir_url( CMT_FILE ) . 'public/js/admin.js', ['jquery'], filemtime(plugin_dir_path( CMT_FILE ) . 'public/js/admin.js'), true);
        }


        /**
         * Admin settings page
         */
        public function admin_settings_page()
        {
            add_submenu_page('options-general.php', 'Custom MIME Types', 'Custom MIME Types', 'manage_options', 'custom-mime-types', function(){
                include_once plugin_dir_path( CMT_FILE ) . 'includes/templates/admin-dashboard.php';
            });
        }


        /**
         * Reset default extentions
         */
        function reset_default_extentions(){
            $allowed_mimes = get_allowed_mime_types();

            $new_mimes = [];
            foreach($allowed_mimes as $ext => $types){
                $new_mimes[$ext] = [
                    'types' => $types,
                    'roles' => array_keys($this->wp_roles_array()),
                    'enabled' => 1
                ];
            }

            $new_mimes = maybe_serialize(  $new_mimes );

            update_option( '_cmt_mimes', $new_mimes );
        }

        /**
         * Current custom upload size limit
         */
        function cmt_upload_size_limit( $size ){

            $custom_size = (int) get_option('_cmt_max_upload_size');
            return $custom_size; 
        }


        /**
         * Check before uploading mimes if its available
         */
        function cmt_upload_mimes( $mimes ){

            $custom_mimes = $this->getExtentions();
            $new_mimes_array = [];
            $user = wp_get_current_user();

            if($custom_mimes) {
                foreach($custom_mimes as $ext => $mime) {
                    $matched = array_intersect($mime->roles, (array) $user->roles);
                    $enabled = (bool) $mime->enabled;

                    if ( $matched && $enabled ) {
                        $new_mimes_array[$ext] = $mime->types;
                    } 

                }
            }
 
            return $new_mimes_array;
        }
    }
}