<?php

defined( 'ABSPATH' ) || exit;

/**
 * Host_Via_GitHub Class.
 */
class Host_Via_GitHub {

	/**
	 * @var array Plugin data.
	 */
	protected $config;
	/**
	 * @var array Plugin data.
	 */
	protected $pluginData;
	/**
	 * @var string Plugin slug.
	 */
	protected $pluginSlug;
	/**
	 * @var string Plugin version.
	 */
	protected $pluginVersion;
	/**
	 * @var string GitHub username.
	 */
	protected $userName;
	/**
	 * @var string GitHub repository name.
	 */
	protected $repositoryName;
	/**
	 * @var string GitHub organisation.
	 */
	protected $organisation;
	/**
	 * @var object Latest GitHub release.
	 */
	protected $releaseData;

	/**
	 * @var string GitHub authentication token. Optional.
	 */
	protected $accessToken;

	/**
	 * @var boolean GitHub has relaese.
	 */
	protected $hasNewRelease = false;

	/**
	 * @var mixed Plugin updater transient.
	 */
	protected $plugin_updater_transient;
	/**
	 * @var mixed Plugin auto update.
	 */
	protected $autoUpdate;
	/**
	 * @var mixed Plugin minor release tag.
	 */
	protected $preReleaseTag;

	/**
	 * Constructor.
	 */
	public function __construct( $config = array() ) {
		// Initiate plugin updater
		$defaults = array(
			'pluginFile' 		=> '',
			'pluginVersion' 	=> '',
			'userName' 			=> '',
			'repositoryName' 	=> '',
			'organisation' 		=> '',
			'accessToken' 		=> '',
			'autoUpdate' 		=> false,
			'preReleaseTag' 	=> '',
		);

		$this->config = wp_parse_args( $config, $defaults );
		$this->init_updater_credentials();
		add_action( 'wp_loaded', array( $this, 'check_plugin_updater' ) );
		// Plugin Updater information
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'hvg_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'set_hvg_update_info' ), 99, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'hvg_upgrader_process_complete' ), 10, 2 );
       	add_filter( 'upgrader_post_install', array( $this, 'hvg_post_install' ), 10, 3 );
       	// remore updater notification if already done
       	add_filter( 'site_transient_update_plugins', array( $this, 'filter_hvg_plugin_updates' ), 99 );
       	// Enable autoupdate
       	add_filter( 'auto_update_plugin', array( $this, 'hvg_auto_update' ), 10, 2 );
	}

	/**
	 * Initiate plugin updater credentials.
	 */
	public function init_updater_credentials() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->pluginSlug 		= plugin_basename( $this->config['pluginFile'] );
		$this->pluginData 		= get_plugin_data( $this->config['pluginFile'] );
		$this->pluginVersion 	= $this->config['pluginVersion'];
		$this->userName  		= $this->config['userName'];
		$this->repositoryName	= $this->config['repositoryName'];
		$this->organisation		= $this->config['organisation'];
		$plugin_name_slug 		= strtolower( str_replace( ' ', '_', $this->pluginData['Name'] ) );
		$this->plugin_updater_transient = $plugin_name_slug . '_plugin_updater';
		$this->releaseData 		= get_transient( $this->plugin_updater_transient );
		$this->accessToken 		= $this->config['accessToken'];
		$this->autoUpdate 		= $this->config['autoUpdate'];
		$this->preReleaseTag 	= $this->config['preReleaseTag'];
	}

	/**
	 * Check plugin update available or not.
	 */
	public function check_plugin_updater() {
		// check updates
		if ( ! $this->hasNewRelease ) {
			$this->hasNewRelease = $this->has_new_release();
			if ( $this->hasNewRelease ) {
				wp_clean_plugins_cache( true );
				delete_transient( $this->plugin_updater_transient );
				set_transient( $this->plugin_updater_transient, $this->releaseData, 43200 ); // 12 hours cache
			}
		}

		// Minor auto updates
		if( ( true === $this->autoUpdate || 'minor' === $this->autoUpdate ) && $this->hasNewRelease && false !== strpos( $this->releaseData->tag_name, $this->preReleaseTag ) ) {
			$this->do_autoupdate();
		}
	}

	/**
	 * Enable HVG autoupdate.
	 */
	public function hvg_auto_update( $update, $item ) {
		return ( ( true === $this->autoUpdate || 'minor' === $this->autoUpdate ) && $item->plugin === $this->pluginSlug ) ? true : $update;
	}

	/**
	 * Filter HVG updater message.
	 */
	public function filter_hvg_plugin_updates( $plugins ) {
		$has_same_release = $this->has_new_release( '=' );
		if ( isset( $plugins->response[ $this->pluginSlug ] ) && $has_same_release ) {
			unset( $plugins->response[ $this->pluginSlug ] );
		}
    	return $plugins;
	}

	/**
	 * Check for new release of HVG hosted plugin.
	 * @param string $operator, default '<' (less than) 
	 * @return boolean
	 */
	protected function has_new_release( $operator = '<' ) {
		$git_api_query = ( $this->organisation ) ? '/repos/:org/:repo/releases/latest' : '/repos/:user/:repo/releases/latest';
		$release       = $this->api( $git_api_query );
		if ( $release ) {
			if ( ! isset( $release->tag_name ) ) return false;
			$release_version = ltrim( $release->tag_name, 'v' );
			$plugin_version  = $this->pluginVersion;
			// check if has minor release.
			if ( false !== strpos( $release_version, $this->preReleaseTag ) ) {
				$release_version = str_replace( $this->preReleaseTag, '', $release_version );
				$plugin_version  = str_replace( $this->preReleaseTag, '', $plugin_version );
			}
			if ( version_compare( $plugin_version, $release_version, $operator ) ) {
				$this->releaseData = $release;
				return true;
			}
		}
		return false;
	}

	/**
	 * Perform a GitHub API request.
	 *
	 * @param string $url
	 * @param array $queryParams
	 * @return mixed|WP_Error
	 */
	protected function api( $url, $queryParams = array() ) {
		$baseUrl = $url;
		$url     = $this->build_api_url( $url, $queryParams );
		$options = array( 'timeout' => 10 );

		$response = wp_remote_get( $url, $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			$document = json_decode( $body );
			return $document;
		}

		return $body;
	}

	/**
	 * Build a fully qualified URL for an API request.
	 *
	 * @param string $url
	 * @param array $queryParams
	 * @return string
	 */
	protected function build_api_url( $url, $queryParams ) {
		$variables = array(
			'user' => $this->userName,
			'org'  => $this->organisation,
			'repo' => $this->repositoryName,
		);
		foreach ( $variables as $name => $value ) {
			$url = str_replace( '/:' . $name, '/' . urlencode( $value ), $url );
		}
		$url = 'https://api.github.com' . $url;

		if ( ! empty( $this->accessToken ) ) {
			$queryParams['access_token'] = $this->accessToken;
		}
		if ( ! empty( $queryParams ) ) {
			$url = add_query_arg( $queryParams, $url );
		}

		return $url;
	}

	/**
	 * Do plugin auto update.
	 */
	protected function do_autoupdate( $args = array() ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		WP_Filesystem();

		$defaults = array(
			'source' 			=> $this->releaseData->zipball_url,
			'plugin' 			=> $this->pluginSlug,
			'destination' 		=> WP_PLUGIN_DIR,
			'clear_destination' => true,
			'clear_working'		=> true,
		);

		$option            = wp_parse_args( $args, $defaults );
		$skin              = new Automatic_Upgrader_Skin();
		$upgrader 		   = new Plugin_Upgrader( $skin );
		$package           = add_query_arg( 'access_token', $this->accessToken, $option['source'] );

		add_filter( 'upgrader_pre_install', array( $upgrader, 'deactivate_plugin_before_upgrade' ), 10, 2 );
		add_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ), 10, 4 );
		//'source_selection' => array($this, 'source_selection'), //there's a trac ticket to move up the directory for zip's which are made a bit differently, useful for non-.org plugins.
		// Clear cache so wp_update_plugins() knows about the new plugin.
		add_action( 'upgrader_process_complete', 'wp_clean_plugins_cache', 9, 0 );

		$upgrader->run(
			array(
				'package'           => $package,
				'destination'       => $option['destination'],
				'clear_destination' => $option['clear_destination'],
				'clear_working'     => $option['clear_working'],
				'hook_extra'        => array(
					'plugin' => $option['plugin'],
					'type'   => 'plugin',
					'action' => 'update',
				),
			)
		);

		// Cleanup our hooks, in case something else does a upgrade on this connection.
		remove_action( 'upgrader_process_complete', 'wp_clean_plugins_cache', 9 );
		remove_filter( 'upgrader_pre_install', array( $upgrader, 'deactivate_plugin_before_upgrade' ) );
		remove_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ) );
		remove_filter( 'site_transient_update_plugins', array( $this, 'filter_pu_plugin_updates'), 99 );

		if ( ! $upgrader->result || is_wp_error( $upgrader->result ) ) {
			return $upgrader->result;
		}

		if ( ! is_plugin_active( $this->pluginSlug ) ) {
		    $activate = activate_plugin( $this->pluginSlug );
		}
		// Force refresh of plugin update information.
		wp_clean_plugins_cache( true );

		return $upgrader->result;
	}


	/**
     * Show plugin update version information to display in the details lightbox
     *
   	 * @param object $response The response core needs to display the modal.
	 * @param string $action The requested plugins_api() action.
	 * @param object $args Arguments passed to plugins_api().
     * @return object
     */
	public function set_hvg_update_info( $response, $action, $args ) {

		if ( 'plugin_information' !== $action ) {
			return $response;
		}

		if ( empty( $args->slug ) ) {
			return $response;
		}

		if ( $args->slug != $this->pluginSlug ) {
		    return $response;
		}

		if( $this->releaseData ){
			// Add our plugin information
			$response = $this->get_plugin_informations();
		}

		return $response;

	} 

	/**
     * Get plugin imformations
     *
     * @return object
     */
	public function get_plugin_informations() {
		$response 					= new stdClass();
		$response->name 			= $this->pluginData['Name'];
		$response->last_updated 	= $this->releaseData->published_at;
		$response->slug 			= $this->pluginSlug;
		$response->plugin_name  	= $this->pluginData['Name'];
		$response->version 			= ltrim( $this->releaseData->tag_name, 'v' );
		$response->author 			= $this->pluginData['AuthorName'];
		$response->homepage 		= $this->pluginData['PluginURI'];

		// This is our release download zip file
		$downloadLink = $this->releaseData->zipball_url;

		if ( !empty( $this->accessToken ) )
		{
		    $downloadLink = add_query_arg(
		        array( "access_token" => $this->accessToken ),
		        $downloadLink
		    );
		}

		$response->download_link 	= $downloadLink;

		$response->sections = array(
			'description' => $this->pluginData['Description'], // description tab
		);

		if ( $this->releaseData->body ) {
			$response->sections['changelog'] = $this->releaseData->body;
		}

		return $response;
	}

	/**
     * Set HVG updates transient
     *
   	 * @param object $transient plugins site transient.
     * @return object
     */
	public function hvg_update_plugins( $transient ){

		if ( ! empty( $transient->response[ $this->pluginSlug ] ) ) {
            return $transient;
        }

		// trying to get from cache first.
		$release = get_transient( $this->plugin_updater_transient );
		if ( false === $release ) {

			$git_api_query = ( $this->organisation ) ? '/repos/:org/:repo/releases/latest' : '/repos/:user/:repo/releases/latest';
			$release       = $this->api( $git_api_query );
			
			if ( ! is_wp_error( $release ) ) {
				set_transient( $this->plugin_updater_transient, $release, 43200 ); // 12 hours cache
			}

		}

		if ( $release ) {
			$release_version = ltrim( $release->tag_name, 'v' );
			$download_url = add_query_arg( 'access_token', $this->accessToken, $release->zipball_url );

			if ( version_compare( $this->pluginVersion, $release_version, '<' ) ) {
				$res = new stdClass();
				$res->slug = $this->pluginSlug;
				$res->new_version = $release_version;
				$res->package = $download_url;
				$res->compatibility = new stdClass();
           		$transient->response[$res->slug] = $res;
           	}
		}

        return $transient;
	}

    /**
     * Perform additional actions to successfully install HVG hosted plugin
     *
     * @param  boolean $true
     * @param  string  $hook_extra
     * @param  object  $result
     * @return object
     */
    public function hvg_post_install( $true, $hook_extra, $result ) {
		global $wp_filesystem;
		// Since we are hosted in GitHub, our plugin folder would have a dirname of
		// reponame-tagname change it to our original one:
		$pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->pluginSlug );
		$wp_filesystem->move( $result['destination'], $pluginFolder );
		$result['destination'] = $pluginFolder;

		// Re-activate plugin if needed
		if ( ! is_plugin_active( $this->pluginSlug ) ) {
		    $activate = activate_plugin( $this->pluginSlug );
		}

        return $result;
    }

    /**
     * Perform upgrade process complete
     *
     * @param  object $upgrader_object
     * @param  array  $options
     * @return void
     */
	public function hvg_upgrader_process_complete( $upgrader_object, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] )  {
			delete_transient( $this->plugin_updater_transient );
			$this->hasNewRelease = false;
		}
	}

}
