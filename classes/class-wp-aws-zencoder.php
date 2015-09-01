<?php
use Aws\S3\S3Client;

/**
 * Class WP_AWS_Zencoder
 *
 * @TODO need a lot better error handling
 * Especially on save_post, check_video_for_encoding, send_video_for_encoding
 */

class WP_AWS_Zencoder extends AWS_Plugin_Base {
	private $aws, $s3client;

	const SETTINGS_KEY = 'tantan_wordpress_s3';

	function __construct( $plugin_file_path, $aws ) {

		$this->plugin_title = __( 'WP AWS Zencoder', 'waz' );
		$this->plugin_menu_title = __( 'Zencoder', 'waz' );
		$this->plugin_slug = 'wp-aws-zencoder';

		// lets do this before anything else gets loaded
		$this->require_zencoder();

		parent::__construct( $plugin_file_path );

		$this->aws = $aws;
		$this->zen = new Services_Zencoder( $this->get_api_key() );

		// Admin
		add_action( 'aws_admin_menu', array( $this, 'admin_menu' ) );

		// Whenever a post is saved, check if any attached media should be encoded
		add_action( 'save_post', array( $this, 'save_post' ), 1000 );
		add_action( 'edit_post', array( $this, 'save_post' ), 1000 );
		add_action( 'publish_post', array( $this, 'save_post' ), 1000 );
		add_action( 'maj_post_attached_to_media', array( $this, 'save_post' ), 1000 );

		// Let's delete the attachments
		add_filter( 'delete_attachment', array( $this, 'delete_attachment' ), 20 );

		// Rewrites
		add_action( 'wp_loaded', array( $this, 'flush_rules' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Catch notifications from zencoder
		add_action( 'pre_get_posts', array( $this, 'zencoder_notification' ) );

	}

	function get_installed_version(){
		return $GLOBALS['aws_meta']['wp-aws-zencoder']['version'];
	}

	function require_zencoder(){
		if( !class_exists( 'Services_Zencoder') ){
			$file = WAZ_PATH . '/vendor/autoload.php';
			if( file_exists( $file ) ){
				require_once( $file );
			} else {
				// Need to figure out a good way to alert people the required
				// library doesn't exist. Maybe refer them to a website? Or the
				// github readme?
				$msg = __( 'Oh no! It would appear the required Zencoder library doesn\'t exist.', 'waz' );
				$msg .= '<br /><br />';
				$msg .= sprintf(
					__( '%s has been deactivated until the issue has been resolved. ', 'waz' ),
					$this->plugin_title
				);
				$msg .= sprintf(
					__( 'Please refer to the <a href="%s">documentation</a> for more information.', 'waz' ),
					'https://github.com/nathanielks/wp-aws-zencoder'
				);
				$msg .= '<br /><br />';
				$msg .= sprintf(
					__( '<a href="%s">Return to the previous page.</a>', 'waz' ),
					esc_url( $_SERVER['HTTP_REFERER'] )
				);
				waz_plugin_die( $msg );
			}
		}
	}

	function is_plugin_setup() {
		return (bool) $this->get_api_key() && !is_wp_error( $this->aws->get_client() );
	}

	/*
	 *Admin
	 */

	function admin_menu( $aws ) {
		$hook_suffix = $aws->add_page( $this->plugin_title, $this->plugin_menu_title, 'manage_options', $this->plugin_slug, array( $this, 'render_page' ) );
		add_action( 'load-' . $hook_suffix , array( $this, 'plugin_load' ) );
	}

	function render_page() {
		$this->aws->render_view( 'header', array( 'page_title' => $this->plugin_title ) );

		$aws_client = $this->aws->get_client();

		if ( is_wp_error( $aws_client ) ) {
			$this->render_view( 'error', array( 'error' => $aws_client ) );
		}
		else {
			$this->render_view( 'settings' );
		}

		$this->aws->render_view( 'footer' );
	}

	function plugin_load(){
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$src = plugins_url( 'assets/js/script' . $suffix . '.js', $this->plugin_file_path );
		wp_enqueue_script( 'waz-script', $src, array( 'jquery' ), $this->get_installed_version(), true );

		$this->handle_post_request();
	}

	function are_key_constants_set(){
		return defined( 'AWS_ZENCODER_API_KEY' );
	}

	function get_api_key() {
		if ( $this->are_key_constants_set() ) {
			return AWS_ZENCODER_API_KEY;
		}

		return $this->get_setting( 'api_key' );
	}

	function handle_post_request() {
		if ( empty( $_POST['action'] ) || 'save' != $_POST['action'] ) {
			return;
		}

		if ( empty( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'waz-save-settings' ) ) {
			die( __( "Cheatin' eh?", 'waz' ) );
		}

		// Make sure $this->settings has been loaded
		$this->get_settings();

		$post_vars = array( 'api_key' );
		foreach ( $post_vars as $var ) {
			if ( !isset( $_POST[$var] ) ) {
				continue;
			}

			$this->set_setting( $var, $_POST[$var] );
		}

		$this->save_settings();
	}

	function is_video( $post_id ){
		$type = get_post_mime_type( $post_id );
		return in_array( $type, $this->accepted_mime_types() );
	}

	function accepted_mime_types(){
		return array(
			'video/x-ms-asf',
			'video/x-ms-wmv',
			'video/x-ms-wmx',
			'video/x-ms-wm',
			'video/avi',
			'video/divx',
			'video/x-flv',
			'video/quicktime',
			'video/mpeg',
			'video/mp4',
			'video/ogg',
			'video/webm',
			'video/x-matroska'
		);
	}

	/*
	 *Delete the Attachment
	 */

	function delete_attachment( $post_id ){
		if ( ! $this->is_plugin_setup() ) {
			return;
		}

		if ( ! $this->is_video( $post_id ) ) {
			return;
		}

		if ( !( $s3object = $this->get_original_s3_info( $post_id ) ) ) {
			return;
		}

       	$objects = array(
			array(
				'Key' => $s3object['key']
			)
        );

		try {
	        $this->get_s3client()->deleteObjects( array(
	        	'Bucket' => $s3object['bucket'],
	        	'Objects' => $objects
	        ) );
		}
		catch ( Exception $e ) {
			error_log( 'Error removing files from S3: ' . $e->getMessage() );
			return;
		}

		delete_post_meta( $post_id, 'waz_original' );
	}

	function get_original_s3_info( $post_id ) {
		return get_post_meta( $post_id, 'waz_original', true );
	}

	function get_s3client() {
		if ( is_null( $this->s3client ) ) {
			$this->s3client = $this->aws->get_client()->get( 's3' );
		}

		return $this->s3client;
	}

	/*
	 *Rewrites
	 */

	function flush_rules() {
		$rules = get_option( 'rewrite_rules' );

		if ( ! isset( $rules['waz_zencoder_notification?$'] ) ) {
			$this->network_flush_rules();
		}
	}

	function network_flush_rules(){
		global $wp_rewrite;
		//If multisite, we loop through all sites
		if (is_multisite()) {
			$sites = wp_get_sites();
			foreach ($sites as $site) {
				switch_to_blog($site['blog_id']);
				//Rebuild rewrite rules for this site
				$wp_rewrite->init();
				//Flush them
				$wp_rewrite->flush_rules();
				restore_current_blog();
			}
			$wp_rewrite->init();
		} else {
			//Flush rewrite rules
			$wp_rewrite->flush_rules();
		}
	}

	function rewrite_rules( $rules ) {
		$newrules = array();
		$newrules['waz_zencoder_notification?$'] = 'index.php?waz_zencoder_notification=true';
		return $newrules + $rules;
	}

	function query_vars( $vars ) {
		array_push( $vars, 'waz_zencoder_notification' );
		return $vars;
	}

	/*
	 *Process notifications
	 */

	function zencoder_notification(){
		if( true == get_query_var('waz_zencoder_notification') ){
			try{
				$notification = $this->zen->notifications->parseIncoming();
				$this->process_notification( $notification );
			} catch( Services_Zencoder_Exception $e ){
				$errors = array(
					$e->getMessage(),
					$e->getErrors()
				);
				die( implode( "\n", $errors ) );
			}
			die(0);
		}
	}

	function process_notification( $notification ){

		do_action( 'waz_before_process_notification', $notification );

		$ids = $this->get_site_and_post_id_from_job_id( $notification->job->id );
		$post_id = $ids['post'];
		$site_id = $ids['site'];

		$switched = false;
		if( 0 != $site_id ){
			switch_to_blog( $site_id );
			$switched = true;
		}

		// If you're encoding to multiple outputs and only care when all of the outputs are finished
		// you can check if the entire job is finished.
		if( 0 !== $ids['post'] && $notification->job->state == "finished" ) {


			$output = $notification->job->outputs['web'];

			// Get the Attachment
			$meta = $_meta = wp_get_attachment_metadata( $post_id );

			require_once( ABSPATH . '/wp-includes/ID3/getid3.lib.php' );
			require_once( ABSPATH . '/wp-includes/ID3/getid3.php' );
			require_once( ABSPATH . '/wp-includes/ID3/module.audio-video.quicktime.php' );

			// Let's start modifying the metadata
			// TODO figure out a viable way to use built in WP ID3
			$meta['filesize'] = $output->file_size_in_bytes;
			$meta['mime_type'] = 'video/mp4';
			$meta['length'] = ceil( $output->duration_in_ms * 0.001 );
			$meta['length_formatted'] = getid3_lib::PlaytimeString( $meta['length'] );
			$meta['width'] = $output->width;
			$meta['height'] = $output->height;
			$meta['fileformat'] = 'mp4';
			$meta['dataformat'] = $output->format;
			$meta['codec'] = $output->video_codec;

			// TODO this needs to take into consideration other file formats
			// other than quicktime
			$id3 = new getID3();
			$qt = new getid3_quicktime( $id3 );
			$meta['audio'] = array(
				'dataformat' => $output->audio_codec,
				'codec' => $qt->QuicktimeAudioCodecLookup( $output->audio_codec ),
				'sample_rate' => $output->audio_sample_rate,
				'channels' => $output->channels,
				//'bits_per_sample' => 16,
				'lossless' => false,
				'channelmode' => 'stereo',
			);

			// Update the Metadata
			wp_update_attachment_metadata( $post_id, $meta );

			// Video has been encoded, here's the meta data
			do_action('waz_video_encoded', $post_id, $meta);

			// Let's update the S3 information
			$s3info = $_s3info = get_post_meta( $post_id, 'amazonS3_info', true );

			$parsed = parse_url( $output->url );
			$host = explode( '.', $parsed['host'] );
			$key = ltrim ($parsed['path'],'/');
			$s3info['bucket'] = $host[0];
			$s3info['key'] = $key;

			// Update S3 to point to new file
			update_post_meta( $post_id, 'amazonS3_info', $s3info );

			// Save original file for later
			update_post_meta( $post_id, 'waz_original', $_s3info );

			// And we're done!
			update_post_meta( $post_id, 'waz_encode_status', 'finished' );

			// Clean up after ourselves
			delete_site_option('waz_job_' . $notification->job->id . '_blog_id');

			// Delete the original file
			$this->delete_attachment($post_id);
			
			foreach( $ids as $key => $id ){
				echo ucfirst($key) . ': ' . $id . "\n";
			}
		} else {
			update_post_meta( $post_id, 'waz_encode_status', 'failed' );
			update_post_meta( $post_id, 'waz_notification_response', $notification );
		}
		echo 'Received!';

		if( $switched ){
			restore_current_blog();
		}

		do_action( 'waz_after_process_notification', $notification );
		die(0);
	}

	function get_post_id_from_job_id( $job_id ){
		global $wpdb;
		$results = $wpdb->get_results( "select post_id from $wpdb->postmeta where meta_value = $job_id" );
		if( !empty( $results ) ){
			return (int)$results[0]->post_id;
		}
		return 0;
	}

	function get_site_and_post_id_from_job_id( $job_id ){
		global $wpdb;
		$return = array(
			'site' => 0,
			'post' => 0,
		);
		if( is_multisite() ){
			$return['site'] = get_site_option( 'waz_job_' . $job_id . '_blog_id' );
		}
		$site_id = ( !empty( $return['site'] ) && 1 != $return['site'] ) ? $return['site'] . '_' : '';
		//Sometimes the prefix contains the proper site id, other times it does not.  
		//Do a quick check to get the proper table name
		$postmeta = $wpdb->prefix . 'postmeta';
		if ($postmeta == 'wp_postmeta')
		{
			$postmeta = $wpdb->prefix . $site_id . 'postmeta';
		}
		$results = $wpdb->get_results( "select post_id from $postmeta where meta_value = $job_id" );
		if( !empty( $results ) ){
			$return ['post'] = (int)$results[0]->post_id;
		}
		return $return;
	}

	public function save_post( $post_id ) {
		$post = get_post( $post_id );
		if ( wp_is_post_revision( $post_id ) || ! $post instanceof WP_Post || 'post' != $post->post_type ) {
			return;
		}

		if ( 'publish' == get_post_status( $post_id ) || 'private' == get_post_status( $post_id ) ) {
			$attached_media = get_attached_media( 'video', $post_id );
			if ( $attached_media ) {
				foreach ( $attached_media as $media ) {
					if ( $this->is_video( $media->ID ) && $this->should_video_be_encoded( $media->ID ) ) {
						$this->send_video_for_encoding( $media->ID );
					}
				}
			}
		}
	}

	/**
	 * If post meta 'waz_encode_status' has not been created it, it should be encoded
	 * Also check for enough time in the user's site
	 *
	 * @param int $post_id
	 *
	 * @return bool
	 */
	public function should_video_be_encoded( $post_id ) {
		$encoding_status = get_post_meta( $post_id, 'waz_encode_status', true );
		$length = $this->get_video_length( $post_id );

		if ( ! $this->has_sufficient_encoding_time( $length ) ) {
			$post = get_post( $post_id );
			do_action( 'maj_not_enough_encoding_time', $post );
			return false;
		}

		return empty( $encoding_status );
	}

	private function has_sufficient_encoding_time( $length ) {
		restore_current_blog();
		$monthly = (int) get_option('maj_upload_time_remaining');
		$purchased = (int) get_option('maj_purchased_time_remaining');

		return ($monthly + $purchased) > $length;
	}

	private function get_video_length( $post_id ) {
		$meta = wp_get_attachment_metadata( $post_id );
		return $meta['length'];
	}

	private function send_video_for_encoding( $post_id ) {
		$s3info = get_post_meta( $post_id, 'amazonS3_info', true );

		if( empty( $s3info ) ) {
			return false;
		}

		update_post_meta( $post_id, 'waz_encode_status', 'pending' );
		$encoding_job = null;
		try {
			$input = "s3://{$s3info['bucket']}/{$s3info['key']}";
			$pathinfo = pathinfo( $input );
			$key = trailingslashit( dirname( $input ) );

			//Easiest way to force a new bucket, this does tie this fork to MAJ though
			$key = preg_replace('#myartsjournal#', 'myartsjournal-encoded', $key);

			// New Encoding Job
			$job = $this->zen->jobs->create( array(
				"input" => $input,
				"outputs" => array(
					array(
						'label' => 'web',
						'url' => $key . $pathinfo['filename'] . '.mp4',
						'public' => true,
						'device_profile' => 'mobile/advanced',
						'notifications' => array(
							array(
								"url" => apply_filters( 'waz_notification_url', get_home_url( get_current_blog_id(), '/waz_zencoder_notification/', 'https' ) )
							)
						)
					)
				)
			));

			update_post_meta( $post_id, 'waz_encode_status', 'submitting' );
			update_post_meta( $post_id, 'waz_encode_status', 'transcoding' );
			update_post_meta( $post_id, 'waz_job_id', $job->id );
			update_post_meta( $post_id, 'waz_outputs', (array)$job->outputs );
			if( is_multisite() ){
				update_site_option( 'waz_job_' . $job->id . '_blog_id', get_current_blog_id() );
			}
		} catch (Services_Zencoder_Exception $e) {
			error_log_array( $e->getMessage() );
			error_log_array( $e->getErrors() );
			update_post_meta( $post_id, 'waz_encode_status', 'failed' );
			update_post_meta( $post_id, 'waz_encode_error', $e->getErrors() );
		}

		return true;
	}

}
