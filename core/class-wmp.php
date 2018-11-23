<?php

/**
 __        __                      _     _      _
 \ \      / /__    __ _ _ __ ___  | |__ (_)_ __(_)_ __   __ _
  \ \ /\ / / _ \  / _` | '__/ _ \ | '_ \| | '__| | '_ \ / _` |
   \ V  V /  __/ | (_| | | |  __/ | | | | | |  | | | | | (_| |
    \_/\_/ \___|  \__,_|_|  \___| |_| |_|_|_|  |_|_| |_|\__, |
                                                        |___/
Surprised? Don't be. Please do come in, we've been expecting you.
http://www.appticles.com/jobs.html
---------------------------------
Growth Hackers: gh@appticles.com
JS Developers: js@appticles.com
*/


if ( ! class_exists( 'WMobilePack_Options' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-wmp-options.php');
}

if ( ! class_exists( 'WMobilePack_Uploads' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-wmp-uploads.php');
}

if ( ! class_exists( 'WMobilePack_Cookie' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-wmp-cookie.php');
}

if ( ! interface_exists( 'Manager' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/interface-manager.php');
}

if ( ! class_exists( 'Theme' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-theme.php');
}

if ( ! class_exists( 'ThemeManager' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-theme-manager.php');
}

if ( ! class_exists( 'Manifest' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-manifest.php');
}

if ( ! class_exists( 'ManifestManager' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-manifest-manager.php');
}

if ( ! class_exists( 'Icon' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-icon.php');
}

if ( ! class_exists( 'FileHelper' ) ) {
    require_once(WMP_PLUGIN_PATH.'inc/class-file-helper.php');
}

if ( ! class_exists( 'JsonSerializer' ) ) {
    require_once (WMP_PLUGIN_PATH.'libs/json-serializer/JsonSerializer/JsonSerializer.php');
}

if ( ! class_exists( 'WMobilePack' ) ) {

    /**
     * WMobilePack
     *
     * Main class for the Wordpress Mobile Pack plugin. This class handles:
     *
     * - activation / deactivation of the plugin
     * - setting / getting the plugin's options
     * - loading the admin section, javascript and css files
     * - loading the app in the frontend
     *
     */
    class WMobilePack
    {

        /* ----------------------------------*/
        /* Methods							 */
        /* ----------------------------------*/

        /**
         *
         * Construct method that initializes the plugin's options
         *
         */
        public function __construct()
        {
            // create uploads folder and define constants
            if ( !defined( 'WMP_FILES_UPLOADS_DIR' ) && !defined( 'WMP_FILES_UPLOADS_URL' ) ){
                $WMP_Uploads = new WMobilePack_Uploads();
                $WMP_Uploads->define_uploads_dir();
            }

            // we only need notices for admins
            if ( is_admin() ) {
                $this->setup_hooks();
            }
        }


        /**
         *
         * The activate() method is called on the activation of the plugin.
         *
         * This method adds to the DB the default settings of the plugin, creates the upload folder and
         * imports settings from the free version.
         *
         */
        public function activate()
        {
            // add settings to database
            WMobilePack_Options::save_settings(WMobilePack_Options::$options);

            // reset tracking schedule using the current option value
            self::schedule_tracking(WMobilePack_Options::get_setting('allow_tracking'));

            $WMP_Uploads = new WMobilePack_Uploads();
            $WMP_Uploads->create_uploads_dir();

            $this->backwards_compatibility();
        }


        /**
         *
         * The deactivate() method is called when the plugin is deactivated.
         * This method removes temporary data (transients and cookies).
         *
         */
        public function deactivate()
        {

            // clear scheduled tracking cron
            self::schedule_tracking( WMobilePack_Options::get_setting('allow_tracking'),  true);

            // delete plugin settings
            WMobilePack_Options::deactivate();

            // remove the cookies
            $WMP_Cookie = new WMobilePack_Cookie();

            $WMP_Cookie->set_cookie("theme_mode", false, -3600);
            $WMP_Cookie->set_cookie("load_app", false, -3600);
        }


        /**
         * Init admin notices hook
         */
        public function setup_hooks(){
            add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
        }

        /**
         *
         * Show admin notice if license is not active
         *
         */
        public function display_admin_notices(){

            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            if (version_compare(PHP_VERSION, '5.3') < 0) {
                echo '<div class="error"><p><b>Warning!</b> The ' . WMP_PLUGIN_NAME . ' plugin requires at least PHP 5.3.0!</p></div>';
                return;
            }

			// display notice to reupload icon
			$this->display_icon_reupload_notice();

        }

		/**
		 *
		 * Display icon reupload notice if icon is uploaded and manifest icon sizes are missing.
		 *
		 */
		 public function display_icon_reupload_notice(){

			$icon_filename = WMobilePack_Options::get_setting('icon');

			if ($icon_filename == '') {
				echo '<div class="notice notice-warning is-dismissible"><p>Publishers Toolbox PWA: Upload an <a href="' . get_admin_url() . 'admin.php?page=wmp-options-theme-settings"/>App Icon</a> to take advantage of the Add To Home Screen functionality!</p></div>';

			} elseif ($icon_filename != '' && file_exists(WMP_FILES_UPLOADS_DIR . $icon_filename)) {
				foreach (WMobilePack_Uploads::$manifest_sizes as $manifest_size) {
					if (!file_exists(WMP_FILES_UPLOADS_DIR . $manifest_size . $icon_filename)) {
						echo '<div class="notice notice-warning is-dismissible"><p>Publishers Toolbox PWA comes with Add To Home Screen functionality which requires you to reupload your <a href="' . get_admin_url() . 'admin.php?page=wmp-options-theme-settings"/>App Icon</a>!</p></div>';
						return;
					}
				}
			}
		}

        /**
         *
         * Transform settings to fit the new plugin structure
         *
         */
        public function backwards_compatibility(){

            if ( ! class_exists( 'WMobilePack_Themes_Config' )) {
                require_once(WMP_PLUGIN_PATH.'inc/class-wmp-themes-config.php');
            }

            if (class_exists('WMobilePack_Themes_Config')){

                foreach (array('headlines', 'subtitles', 'paragraphs') as $font_type) {

                    $font_option = WMobilePack_Options::get_setting('font_'.$font_type);

                    if (!is_numeric($font_option)){
                        $new_font_option = array_search($font_option, WMobilePack_Themes_Config::$allowed_fonts) + 1;
                        WMobilePack_Options::update_settings('font_'.$font_type, $new_font_option);
                    }
                }
			}
			
			// switch from Obliq v1 to v2
			$theme = WMobilePack_Options::get_setting('theme');

			if ($theme == 1) {
				$this->reset_theme_settings();
				WMobilePack_Options::update_settings('theme', 2);
			}

			// delete premium options
			delete_option(WMobilePack_Options::$prefix . 'premium_api_key');
			delete_option(WMobilePack_Options::$prefix . 'premium_config_path');
			delete_option(WMobilePack_Options::$prefix . 'premium_active');
		}
		

		/**
		 * Reset theme settings (for migrating from Obliq v1 to Obliq v2)
		 */
		protected function reset_theme_settings(){

			// reset color schemes and fonts
			WMobilePack_Options::update_settings('color_scheme', 1);
			WMobilePack_Options::update_settings('custom_colors', array());
			WMobilePack_Options::update_settings('font_headlines', 1);
			WMobilePack_Options::update_settings('font_subtitles', 1);
			WMobilePack_Options::update_settings('font_paragraphs', 1);
			WMobilePack_Options::update_settings('font_size', 1);

			// remove compiled css file (if it exists)
			$theme_timestamp = WMobilePack_Options::get_setting('theme_timestamp');

			if ($theme_timestamp != ''){

				$file_path = WMP_FILES_UPLOADS_DIR.'theme-'.$theme_timestamp.'.css';

				if (file_exists($file_path)) {
					unlink($file_path);
				}

				WMobilePack_Options::update_settings('theme_timestamp', '');
			}
		}

        /**
         *
         * Method used to check if a specific plugin is installed and active,
         * returns true if the plugin is installed and false otherwise.
         *
         * @param $plugin_name - the name of the plugin
         *
         * @return bool
         */
        public static function is_active_plugin($plugin_name)
        {

            $active_plugin = false; // by default, the search plugin does not exist

            // if the plugin name is empty return false
            if ($plugin_name != '') {

                // if function doesn't exist, load plugin.php
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                // get active plugins from the DB
                $apl = get_option('active_plugins');

                // get list withh all the installed plugins
                $plugins = get_plugins();

                foreach ($apl as $p){
                    if (isset($plugins[$p])){
                        // check if the active plugin is the searched plugin
                        if ($plugins[$p]['Name'] == $plugin_name)
                            $active_plugin = true;
                    }
                }
            }

            return $active_plugin; //return the active plugin variable
        }


        /**
         *
         * Static method used to request the content of different pages using curl or fopen
         * This method returns false if both curl and fopen are dissabled and an empty string ig the json could not be read
         *
         */
        public static function read_data($json_url) {

            // check if curl is enabled
            if (extension_loaded('curl')) {

                $send_curl = curl_init($json_url);

                // set curl options
                curl_setopt($send_curl, CURLOPT_URL, $json_url);
                curl_setopt($send_curl, CURLOPT_HEADER, false);
                curl_setopt($send_curl, CURLOPT_CONNECTTIMEOUT, 2);
                curl_setopt($send_curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($send_curl, CURLOPT_HTTPHEADER,array('Accept: application/json', "Content-type: application/json"));
                curl_setopt($send_curl, CURLOPT_FAILONERROR, FALSE);
                curl_setopt($send_curl, CURLOPT_SSL_VERIFYPEER, FALSE);
                curl_setopt($send_curl, CURLOPT_SSL_VERIFYHOST, FALSE);
                $json_response = curl_exec($send_curl);

                // get request status
                $status = curl_getinfo($send_curl, CURLINFO_HTTP_CODE);
                curl_close($send_curl);

                // return json if success
                if ($status == 200)
                    return $json_response;

            } elseif (ini_get( 'allow_url_fopen' )) { // check if allow_url_fopen is enabled

                // open file
                $json_file = fopen( $json_url, 'rb' );

                if($json_file) {

                    $json_response = '';

                    // read contents of file
                    while (!feof($json_file)) {
                        $json_response .= fgets($json_file);
                    }
                }

                // return json response
                if($json_response)
                    return $json_response;

            } else
                // both curl and fopen are disabled
                return false;

            // by default return an empty string
            return '';

        }

        /**
         * (Un-)schedule the tracking cronjob if the tracking option has changed
         *
         * Better to be done here, rather than in the WMobilePack_Tracking class as
         * class-wmp-tracking.php may not be loaded and might not need to be (lean loading).
         *
         * @static
         *
         * @param  array $value            The (new/current) value of the allow_tracking option
         * @param  bool  $force_unschedule Whether to force an unschedule (i.e. on deactivate)
         *
         * @return  void
         *
         */
        public static function schedule_tracking( $value, $force_unschedule = false )
        {

            $current_schedule = wp_next_scheduled( WMobilePack_Options::$prefix.'tracking' );

            if ( $force_unschedule !== true && ( $value === 1 && $current_schedule === false ) ) {

                // The tracking checks daily, but only sends new data every 7 days.
                wp_schedule_event( time(), 'daily', WMobilePack_Options::$prefix.'tracking' );

            } elseif ( $force_unschedule === true || ( $value === 0 && $current_schedule !== false ) ) {
                wp_clear_scheduled_hook( WMobilePack_Options::$prefix.'tracking' );
            }
        }
    }
}

/**
 *
 * Action hook and method for creating tracking (executed only if option was enabled)
 *
 */
add_action( WMobilePack_Options::$prefix.'tracking', 'wmp_create_tracking');

function wmp_create_tracking()
{
    if (WMobilePack_Options::get_setting('allow_tracking') == 1) {

        if ( ! class_exists( 'WMobilePack_Tracking' ) ) {
            require_once(WMP_PLUGIN_PATH.'inc/class-wmp-tracking.php');
        }

        if ( class_exists( 'WMobilePack_Tracking') ) {
            $WMP_Tracking = new WMobilePack_Tracking();
            $WMP_Tracking->tracking();
        }
    }
}
