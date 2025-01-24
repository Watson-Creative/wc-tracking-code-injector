<?php
/**
 * @package WC_Tracking_Code_Injector
 * @internal This file is only used as part of the WC Tracking Code Injector plugin.
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Define required constants if not already defined
if (!defined('WP_GITHUB_FORCE_UPDATE')) {
    define('WP_GITHUB_FORCE_UPDATE', false);
}

// Prevent loading this file directly and/or if the class is already defined
if (class_exists('WPGitHubUpdater') || class_exists('WP_GitHub_Updater')) {
    return;
}

class WP_GitHub_Updater {

	/**
	 * GitHub Updater version
	 */
	const VERSION = 1.6;

	/**
	 * @var $config the config for the updater
	 * @access public
	 */
	var $config;

	/**
	 * @var array|null List of configuration parameters that are missing
	 */
	private $missing_config;

	/**
	 * @var $github_data temporiraly store the data fetched from GitHub, allows us to only load the data once per class instance
	 * @access private
	 */
	private $github_data;

	/**
	 * @var string $main_plugin_file Full path to the main plugin file
	 */
	protected $main_plugin_file;

	/**
	 * Class Constructor
	 *
	 * @since 1.0
	 * @param array $config the configuration required for the updater to work
	 * @see has_minimum_config()
	 * @return void
	 */
	public function __construct( $config = array() ) {
		// Store main plugin file reference
		$this->main_plugin_file = isset($config['main_plugin_file']) ? $config['main_plugin_file'] : __FILE__;
		unset($config['main_plugin_file']);

		$defaults = array(
			'slug' => plugin_basename( $this->main_plugin_file ),
			'proper_folder_name' => dirname( plugin_basename( $this->main_plugin_file ) ),
			'sslverify' => true,
			'access_token' => '',
		);

		$this->config = wp_parse_args( $config, $defaults );

		// if the minimum config isn't set, issue a warning and bail
		if ( ! $this->has_minimum_config() ) {
			$message = 'The GitHub Updater was initialized without the minimum required configuration, please check the config in your plugin. The following params are missing: ';
			$message .= implode( ',', $this->missing_config );
			_doing_it_wrong( __CLASS__, $message , self::VERSION );
			return;
		}

		$this->set_defaults();

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );

		// Hook into the plugin details screen
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		// set timeout
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );

		// set sslverify for zip download
		add_filter( 'http_request_args', array( $this, 'http_request_sslverify' ), 10, 2 );
	}

	public function has_minimum_config() {

		$this->missing_config = array();

		$required_config_params = array(
			'api_url',
			'raw_url',
			'github_url',
			'zip_url',
			'requires',
			'tested',
			'readme',
		);

		foreach ( $required_config_params as $required_param ) {
			if ( empty( $this->config[$required_param] ) )
				$this->missing_config[] = $required_param;
		}

		return ( empty( $this->missing_config ) );
	}


	/**
	 * Check wether or not the transients need to be overruled and API needs to be called for every single page load
	 *
	 * @return bool overrule or not
	 */
	public function overrule_transients() {
		return ( defined( 'WP_GITHUB_FORCE_UPDATE' ) && WP_GITHUB_FORCE_UPDATE );
	}


	/**
	 * Set defaults
	 *
	 * @since 1.2
	 * @return void
	 */
	public function set_defaults() {
		if ( !empty( $this->config['access_token'] ) ) {

			// See Downloading a zipball (private repo) https://help.github.com/articles/downloading-files-from-the-command-line
			extract( parse_url( $this->config['zip_url'] ) ); // $scheme, $host, $path

			$zip_url = $scheme . '://api.github.com/repos' . $path;
			$zip_url = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $zip_url );

			$this->config['zip_url'] = $zip_url;
		}


		if ( ! isset( $this->config['new_version'] ) )
			$this->config['new_version'] = $this->get_new_version();

		if ( ! isset( $this->config['last_updated'] ) )
			$this->config['last_updated'] = $this->get_date();

		if ( ! isset( $this->config['description'] ) )
			$this->config['description'] = $this->get_description();

		$plugin_data = $this->get_plugin_data();
		if ( ! isset( $this->config['plugin_name'] ) )
			$this->config['plugin_name'] = $plugin_data['Name'];

		if ( ! isset( $this->config['version'] ) )
			$this->config['version'] = $plugin_data['Version'];

		if ( ! isset( $this->config['author'] ) )
			$this->config['author'] = $plugin_data['Author'];

		if ( ! isset( $this->config['homepage'] ) )
			$this->config['homepage'] = $plugin_data['PluginURI'];

		if ( ! isset( $this->config['readme'] ) )
			$this->config['readme'] = 'README.md';

	}


	/**
	 * Callback fn for the http_request_timeout filter
	 *
	 * @since 1.0
	 * @return int timeout value
	 */
	public function http_request_timeout() {
		return 2;
	}

	/**
	 * Callback fn for the http_request_args filter
	 *
	 * @param unknown $args
	 * @param unknown $url
	 *
	 * @return mixed
	 */
	public function http_request_sslverify( $args, $url ) {
		if ( $this->config[ 'zip_url' ] == $url )
			$args[ 'sslverify' ] = $this->config[ 'sslverify' ];

		return $args;
	}


	/**
	 * Log error message
	 * 
	 * @param string $message Error message to log
	 * @return void
	 */
	private function log_error($message) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WC Tracking Code Injector] ' . $message);
		}
	}

	/**
	 * Get New Version from GitHub
	 *
	 * @since 1.0
	 * @return string|bool $version the version number or false on failure
	 */
	public function get_new_version() {
		$version = get_site_transient(md5($this->config['slug']).'_new_version');

		if ($this->overrule_transients() || (!isset($version) || !$version || '' == $version)) {
			$github_data = $this->get_github_data();
			if (is_wp_error($github_data)) {
				$this->log_error('Failed to get GitHub data: ' . $github_data->get_error_message());
				return false;
			}

			if (empty($github_data['pushed_at'])) {
				$this->log_error('Failed to retrieve pushed_at from GitHub data');
				return false;
			}

			// Parse pushed_at datetime
			$pushed_at = $github_data['pushed_at'];
			$datetime = new DateTime($pushed_at);
			$date_version = $datetime->format('Ymd.His'); // Formats to something like 20250124232528

			// Get current version from plugin data
			$plugin_data = $this->get_plugin_data();
			$current_version = $plugin_data['Version']; // e.g., 2.4.13

			// Generate new version by appending date
			$new_version = $current_version . '.' . $date_version;

			// Cache the new version for 6 hours
			set_site_transient(md5($this->config['slug']).'_new_version', $new_version, 60*60*6);
			$version = $new_version;
		}

		return $version;
	}


	/**
	 * Get GitHub Data from the specified repository
	 *
	 * @since 1.0
	 * @return array $github_data the data
	 */
	public function get_github_data() {
		if (!empty($this->github_data)) {
			$github_data = $this->github_data;
		} else {
			$github_data = get_site_transient(md5($this->config['slug'] . '_github_data'));

			if ($this->overrule_transients() || (!$github_data && !isset($github_data['id']))) {
				// Use the pre-defined api_url directly
				$github_data = $this->remote_get($this->config['api_url']);

				if (is_wp_error($github_data)) {
					return false;
				}

				$github_data = json_decode($github_data['body'], true);

				// Validate decoded JSON data
				if (!is_array($github_data)) {
					return false;
				}

				// refresh every 6 hours
				set_site_transient(md5($this->config['slug'] . '_github_data'), $github_data, 60 * 60 * 6);
			}

			// Store the data in this class instance for future calls
			$this->github_data = $github_data;
		}

		return $github_data;
	}


	/**
	 * Get update date
	 *
	 * @since 1.0
	 * @return string|false $date the date or false if not found
	 */
	public function get_date() {
		$data = $this->get_github_data();
		return (!empty($data['updated_at'])) ? date('Y-m-d', strtotime($data['updated_at'])) : false;
	}


	/**
	 * Get plugin description
	 *
	 * @since 1.0
	 * @return string|false $description the description or false if not found
	 */
	public function get_description() {
		$data = $this->get_github_data();
		return (!empty($data['description'])) ? $data['description'] : false;
	}


	/**
	 * Get Plugin data
	 *
	 * @since 1.0
	 * @return object $data the data
	 */
	public function get_plugin_data() {
		include_once ABSPATH.'/wp-admin/includes/plugin.php';
		$data = get_plugin_data( $this->main_plugin_file );
		return $data;
	}


	/**
	 * Hook into the plugin update check and connect to GitHub
	 *
	 * @since 1.0
	 * @param object  $transient the plugin data transient
	 * @return object $transient updated plugin data transient
	 */
	public function api_check( $transient ) {

		// Clear cached version data
		delete_site_transient(md5($this->config['slug']).'_new_version');
		delete_site_transient(md5($this->config['slug'].'_github_data'));

		if (empty($transient->checked)) {
			return $transient;
		}

		$this->get_github_data();

		$version = $this->get_new_version();
		$current_version = $this->config['version'];

		if ($version && version_compare($version, $current_version, '>')) {
			$response = new stdClass;
			$response->new_version = $version;
			$response->slug = $this->config['proper_folder_name'];
			$response->package = $this->config['zip_url'];
			$transient->response[$this->config['slug']] = $response;
		}

		return $transient;
	}


	/**
	 * Get Plugin info
	 *
	 * @since 1.0
	 * @param bool    $false  always false
	 * @param string  $action the API function being performed
	 * @param object  $args   plugin arguments
	 * @return object|false $response the plugin info or false if not applicable
	 */
	public function get_plugin_info( $false, $action, $args ) {
		// Create response object
		$response = new stdClass();

		// Check if this call API is for the right plugin
		if ( !isset( $args->slug ) || $args->slug != $this->config['slug'] ) {
			return false;
		}

		$response->slug = $this->config['slug'];
		$response->plugin_name  = $this->config['plugin_name'];
		$response->version = $this->config['new_version'];
		$response->author = $this->config['author'];
		$response->homepage = $this->config['homepage'];
		$response->requires = $this->config['requires'];
		$response->tested = $this->config['tested'];
		$response->downloaded   = 0;
		$response->last_updated = $this->config['last_updated'];
		$response->sections = array( 'description' => $this->config['description'] );
		$response->download_link = $this->config['zip_url'];

		return $response;
	}


	/**
	 * Upgrader/Updater
	 * Move & activate the plugin
	 *
	 * @since 1.0
	 * @param boolean $true       always true
	 * @param mixed   $hook_extra not used
	 * @param array   $result     the result of the move
	 * @return array|WP_Error $result the result of the move or error
	 */
	public function upgrader_post_install($true, $hook_extra, $result) {
		ob_start();
		
		global $wp_filesystem;
		try {
			// Add debug logging
			$this->log_error('Starting installation process');
			$this->log_error('Source: ' . $result['destination']);
			
			// Initialize filesystem
			if (!$wp_filesystem || !is_object($wp_filesystem)) {
				$this->log_error('Initializing WP Filesystem');
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			$proper_destination = WP_PLUGIN_DIR.'/'.$this->config['proper_folder_name'];
			$source = $result['destination'];
			
			$this->log_error('Proper destination: ' . $proper_destination);
			
			// Check if the source contains a single subdirectory (common in GitHub ZIPs)
			$files = $wp_filesystem->dirlist($source);
			if (is_array($files)) {
				$files = array_keys($files);
				$this->log_error('Found files in source: ' . implode(', ', $files));
				// Check for a single directory in the extracted files
				if (1 === count($files) && $wp_filesystem->is_dir(trailingslashit($source) . $files[0])) {
					$source = trailingslashit($source) . $files[0];
					$this->log_error('Using subdirectory as source: ' . $source);
				}
			}

			// Move files
			$this->log_error('Attempting to move files from ' . $source . ' to ' . $proper_destination);
			$moved = $wp_filesystem->move($source, $proper_destination, true);
			if (!$moved) {
				throw new Exception('Failed to move files to: ' . $proper_destination);
			}
			$this->log_error('Files moved successfully');

			$result['destination'] = $proper_destination;

			// Activate plugin
			$this->log_error('Attempting to activate plugin: ' . $this->config['slug']);
			$activation_result = activate_plugin($this->config['slug']);
			if (is_wp_error($activation_result)) {
				throw new Exception('Activation failed: ' . $activation_result->get_error_message());
			}
			$this->log_error('Plugin activated successfully');

		} catch (Exception $e) {
			// Add filesystem error details if available
			if ($wp_filesystem && is_object($wp_filesystem) && !empty($wp_filesystem->errors) && is_wp_error($wp_filesystem->errors)) {
				$this->log_error('Filesystem Errors: ' . print_r($wp_filesystem->errors->get_error_messages(), true));
			}
			$this->log_error('Installation failed: ' . $e->getMessage());
			ob_end_clean();
			return new WP_Error('update_failed', $e->getMessage());
		}

		$this->log_error('Installation completed successfully');
		ob_end_clean();
		return $result;
	}

	/**
	 * Enhanced remote get with error logging
	 *
	 * @param string $query URL to fetch
	 * @return mixed Response or false on error
	 */
	public function remote_get($query) {
		if (!empty($this->config['access_token'])) {
			$query = add_query_arg(array('access_token' => $this->config['access_token']), $query);
		}

		$response = wp_remote_get($query, array(
			'sslverify' => $this->config['sslverify'],
			'timeout' => 15
		));

		if (is_wp_error($response)) {
			$this->log_error('API request failed: ' . $response->get_error_message());
			return false;
		}

		return $response;
	}
}