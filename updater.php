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
	 * Get New Version from GitHub
	 *
	 * @since 1.0
	 * @return int $version the version number
	 */
	public function get_new_version() {
		$version = get_site_transient( md5($this->config['slug']).'_new_version' );

		if ( $this->overrule_transients() || ( !isset( $version ) || !$version || '' == $version ) ) {

			$raw_response = $this->remote_get( trailingslashit( $this->config['raw_url'] ) . basename( $this->config['slug'] ) );

			if ( is_wp_error( $raw_response ) )
				$version = false;

			if (is_array($raw_response)) {
				if (!empty($raw_response['body']))
					preg_match( '/.*Version\:\s*(.*)$/mi', $raw_response['body'], $matches );
			}

			if ( empty( $matches[1] ) )
				$version = false;
			else
				$version = $matches[1];

			// back compat for older readme version handling
			// only done when there is no version found in file name
			if ( false === $version ) {
				$raw_response = $this->remote_get( trailingslashit( $this->config['raw_url'] ) . $this->config['readme'] );

				if ( is_wp_error( $raw_response ) )
					return $version;

				preg_match( '#^\s*`*~Current Version\:\s*([^~]*)~#im', $raw_response['body'], $__version );

				if ( isset( $__version[1] ) ) {
					$version_readme = $__version[1];
					if ( -1 == version_compare( $version, $version_readme ) )
						$version = $version_readme;
				}
			}

			// refresh every 6 hours
			if ( false !== $version )
				set_site_transient( md5($this->config['slug']).'_new_version', $version, 60*60*6 );
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
				$github_data = $this->remote_get(trailingslashit($this->config['api_url']) . 'repos/' . $this->config['github_user'] . '/' . $this->config['github_repo']);

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

		// Check if the transient contains the 'checked' information
		// If not, just return its value without hacking it
		if ( empty( $transient->checked ) )
			return $transient;

		// check the version and decide if it's new
		$update = version_compare( $this->config['new_version'], $this->config['version'] );

		if ( 1 === $update ) {
			$response = new stdClass;
			$response->new_version = $this->config['new_version'];
			$response->slug = $this->config['proper_folder_name'];
			$response->url = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $this->config['github_url'] );
			$response->package = $this->config['zip_url'];

			// If response is false, don't alter the transient
			if ( false !== $response )
				$transient->response[ $this->config['slug'] ] = $response;
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
	 * Move & activate the plugin, echo the update message
	 *
	 * @since 1.0
	 * @param boolean $true       always true
	 * @param mixed   $hook_extra not used
	 * @param array   $result     the result of the move
	 * @return array $result the result of the move
	 */
	public function upgrader_post_install( $true, $hook_extra, $result ) {

		global $wp_filesystem;

		// Move & Activate
		$proper_destination = WP_PLUGIN_DIR.'/'.$this->config['proper_folder_name'];
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;
		$activate = activate_plugin( WP_PLUGIN_DIR.'/'.$this->config['slug'] );

		// Output the update message
		$fail  = __( 'The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'github_plugin_updater' );
		$success = __( 'Plugin reactivated successfully.', 'github_plugin_updater' );
		echo is_wp_error( $activate ) ? $fail : $success;
		return $result;

	}

	/**
	 * Log error message
	 *
	 * @param string $message Error message to log
	 * @return void
	 */
	private function log_error($message) {
		error_log('GitHub Updater (' . $this->config['slug'] . '): ' . $message);
		update_option('github_updater_error_' . $this->config['slug'], $message);
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