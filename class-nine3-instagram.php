<?php 
/**
 * Class to authenticate the user using Instagram Graph API. 
 * 
 * @package nine3-instagram
 * @author  93Digital (Mujeeb Nawaz)
 * @version 1.2
 * @see     https://developers.facebook.com/docs/instagram-basic-display-api/
 * @todo 
 * - - Add an option for shared long-live token or seperate long-live token for multisite. 
 * - - Add variable for options page slug instead of hard coded version. See line 129.
 * 
 * Inspired by: https://github.com/jstolpe/blog_code/tree/master/instagram_basic_display_api
 * 
 * Follows a three stage process. 
 * Stage 1 - Getting an authentication code using instagram authorisation window.
 * Stage 2 - Getting a short lived token using authentication code. Valid for an hour.  
 * Stage 3 - Exchanging for a long lived token using short lived token. Valid for 60 days. 
 * 
 * Change Log. 
 * 1.1
 * - Added support for multisite
 * 
 * * Change Log. 
 * 1.2
 * - Added getters and setters for wp_options and wp_transients. 
 * - Refactored the if branches which were using is_multisite.
 * - Added time expiration functionality for long_lived_token instead of checking the validity of token by accessing ig API. See is_valid_long_lived (line 146). 
 * - Added transient cache for media for 15 mins, as recurring requests were causing API limit reach. 
 */

namespace Nine3;

class Instagram {

	/**
	 * 
	 * Instagram API end point, used for obtaining the authorisation code and short lived token. 
	 * https://developers.facebook.com/docs/instagram-basic-display-api/overview%23instagram-user-access-tokens#instagram-user-access-tokens 
	 */
	private $instagram_api            = 'https://api.instagram.com/';

	/**
	 * 
	 * Instagram Graph API end point, used for obtaining the long lived code and user media.
	 * https://developers.facebook.com/docs/instagram-basic-display-api/overview%23instagram-user-access-tokens#instagram-user-access-tokens 
	 */
	private $instagram_graph_api      = 'https://graph.instagram.com/';
	/**
	 * 
	 * Instagram Application ID obtained from Facebook Apps dashboard.
	 * https://developers.facebook.com/apps/
	 */
	private $app_id                   = '';

	/**
	 * 
	 * Instagram Application Secret obtained from Facebook Apps dashboard.
	 * https://developers.facebook.com/apps/
	 */
	private $app_secret               = '';

	/**
	 * 
	 * OAuth redirect URI. Used for obtaining the tokens. 
	 * Once the tokens are obtained Instagram will redirect to this URI.
	 * https://developers.facebook.com/docs/instagram-basic-display-api/guides/getting-access-tokens-and-permissions/
	 */
	private $oauth_redirect_uri       = '';

	/**
	 * 
	 * Authentication code, retrieved and set by the GET request using the Instagram authorisation window. 
	 * https://developers.facebook.com/docs/instagram-basic-display-api/overview#authorization-window
	 */
	private $authentication_code      = '';

	/**
	 * 
	 * Short lived access token, retrieved and set by the POST request using curl. 
	 * Token is set in the constructor using get_short_lived_token().
	 * https://developers.facebook.com/docs/instagram-basic-display-api/guides/getting-access-tokens-and-permissions/#step-2--exchange-the-code-for-a-token
	 */
	private $short_lived_access_token = '';

	/**
	 * 
	 * Long lived access token, retrieved and set by the POST request using curl.
	 * Token is set in the constructor using get_long_lived_token().
	 * https://developers.facebook.com/docs/instagram-basic-display-api/guides/long-lived-access-tokens
	 */
	private $long_lived_access_token  = '';

	/**
	 * 
	 * Expiration date for long lived access token, retrieved and set by the POST request using curl.
	 * Token is set in the constructor using get_long_lived_token().
	 * https://developers.facebook.com/docs/instagram-basic-display-api/guides/long-lived-access-tokens
	 */
	private $access_token_expiration  = '';
	
	/**
	 * 
	 * Numeric instagram user ID. Used for obtaining the media from the API.
	 * https://developers.facebook.com/apps/
	 */
	private $user_id                  = '';
    
  function __construct( $parameters ) {
		/**
		 * Requires an instagram application to be set up.
		 * Credentials can be obtained from app dashboard. 
		 * https://developers.facebook.com/apps/
		 * 
		 */

		$this->app_id             = $parameters['app_id']; 
		$this->app_secret         = $parameters['app_secret'];
		$this->oauth_redirect_uri = $parameters['oauth_redirect_uri'];
		$this->authorise_user(); 
	}
	/**
	 * Function to set the long lived token in the wp_option. 
	 * If the long lived token does not exist in wp_option, function requires the authentication code to process the long lived token.
	 */
	private function authorise_user(){
		global $pagenow;
		$this->long_lived_access_token  = $this->get_ig_option( 'instagram_long_lived_token' );
		if ( is_admin() && $pagenow === 'admin.php' && $_GET[ 'page' ] === 'acf-options-global-options' ) { // long lived token can only be generated in the admin panel. 
			if( !$this->is_valid_long_lived() && isset( $_GET[ 'igcode' ] ) ){
				if( !empty( $_GET[ 'igcode' ] ) ){
					$this->authentication_code       = explode( '#', $_GET['igcode'] )[ 0 ]; //Step 1. Gets the authentication code from Get request. 
					$this->short_lived_access_token  = $this->get_short_lived_token();  //Step 2. Retrieves a short lived token. Valid for 1 hour. 
					$this->long_lived_access_token   = $this->get_long_lived_token();   //Step 3. Exchanges the short lived token for long lived token. Adds it to wp_options. 
					$this->user_id                   = $this->get_users_id(); 					//Step 4. Gets the numerical user ID using long lived token.
				}
			}
		}
		else{
			$this->user_id                 			 = $this->get_users_id();
		}
	}
	/**
	 * Checks if the long lived token is valid. 
	 */
	public function is_valid_long_lived() {
		if( $this->get_ig_option('long_lived_token_stored_at' ) && $this->get_ig_option( 'long_lived_expires_in' ) ){
			$long_lived_expires_in = $this->get_ig_option( 'long_lived_expires_in' );
			$long_lived_stored_at  = $this->get_ig_option( 'long_lived_token_stored_at' );
			if( ( time()-$long_lived_stored_at ) < $long_lived_expires_in ){
				return true;
			}
			else{
				return false;
			}
		}
	}
	/**
	 * Function to get the authorization code from instagram. 
	 * Prompts user to authorize use of facebook app with their instagram to pull their data.
	 * Returns a url that can be visited to obtain the code using a GET response. 
	 * 
	 * https://developers.facebook.com/docs/instagram-basic-display-api/guides/getting-access-tokens-and-permissions/
	 */
	public function get_authorization_code_url() {
		$api_endpoint = $this->instagram_api . 'oauth/authorize?';
		$parameterized_url = '';
		$parameters = array(
			'client_id'     => $this->app_id,
			'response_type' => 'code',
			'redirect_uri'  => $this->oauth_redirect_uri,
			'scope'         => 'user_profile,user_media',
		);
		return $api_endpoint.http_build_query($parameters, '', '&amp;');
	}
	
	/**
	 * Stage 2.
	 * Gets short lived token using authentication code. 
	 */
	private function get_short_lived_token() {
		$arguments = array(
			'api_endpoint' => $this->instagram_api . 'oauth/access_token',
			'type' => 'POST',
			'url_parameters' => array(
					'client_id' => $this->app_id,
					'client_secret' => $this->app_secret,
					'code' => $this->authentication_code,
					'grant_type' => 'authorization_code',
					'redirect_uri' => $this->oauth_redirect_uri,
			)
		);
		$response = $this->remote_api_call( $arguments );
		if(isset($response['access_token'])){
			return $response['access_token'];
		}
		else{
			return $response;
		}
	}
	/**
	 * Stage 3.
	 * Gets long lived token using short lived token. 
	 */
	private function get_long_lived_token() {
		$arguments = array(
				'api_endpoint' => $this->instagram_graph_api . 'access_token',
				'type' => 'GET',
				'url_parameters' => array(
						'client_secret' => $this->app_secret,
						'grant_type' => 'ig_exchange_token',
						'access_token' => $this->short_lived_access_token,
				)
		);
		$response = $this->remote_api_call( $arguments );
		if( isset( $response[ 'access_token' ] ) ){
			$this->set_ig_option( 'instagram_long_lived_token', $response['access_token'] );
			$this->set_ig_option( 'long_lived_expires_in', $response['expires_in'] );
			$this->set_ig_option( 'long_lived_token_stored_at', time() );
			return $response[ 'access_token' ];
		}
		else{
			return 	$response;
		}
	}

	/**
	 * 
	 * Uses long lived token to retrieve the user id. 
	 * Function also used to validate if the long lived token is valid. 
	 * i.e. Response will have an error if the long lived token has expired. 
	 * 
	 * https://developers.facebook.com/docs/instagram-api/getting-started/
	 * 
	 */
	private function get_users_id(){
		if( $this->get_ig_option( 'instagram_user_id' ) === NULL || !$this->get_ig_option( 'instagram_user_id' )  ) {	
			$arguments = array(
				'api_endpoint' => $this->instagram_graph_api . '/me',
				'type' => 'GET',
				'url_parameters' => array(
					'fields' => 'id',
					'access_token' => $this->long_lived_access_token,
				)
			);
			$response = $this->remote_api_call( $arguments );
			if( ! empty( $response[ 'id' ] ) ){
				$this->set_ig_option( 'instagram_user_id', $response[ 'id' ] );
				return $response[ 'id' ];
			}
			else{
				return $response;
			}
		}
		else{
			return $this->get_ig_option('instagram_user_id');
		}
	}

	

	/**
	 * 
	 * Retrieves the users media using instagram graph API. 
	 * https://developers.facebook.com/docs/instagram-api/reference/media/
	 * 
	 */
	public function get_user_media(){
		$instagram_cached = $this->get_ig_transient( 'instagram_media' );
		if ( $instagram_cached ) {
			return $instagram_cached;
		} else {
			if( $this->long_lived_access_token ){
				$arguments = array(
					'api_endpoint' => $this->instagram_graph_api . $this->user_id  . '/media',
					'type' => 'GET',
					'url_parameters' => array(
						'fields' => 'id,permalink,timestamp,caption,media_type,media_url',
						'access_token' => $this->long_lived_access_token,
					)
				);
				$response = $this->remote_api_call( $arguments  );
				if( !empty( $response[ 'data' ] ) ){
					$this->set_ig_transient( 'instagram_media', $response[ 'data' ], 900 );
					return $response[ 'data' ];
				}
				else{
					return $response;
				}
			}
		}
	}

	/**
	 * Custom Setters and Getters to extend wordpress. 
	 * @see if such functionality already exist in WordPress. 
	*/

	/**
	 * Custom Options getter. 
	 * Checks if the site is multisite or not, depending on that uses 'get_site_option' or 'get_option'.
	 */
	public static function get_ig_option( $option_name ){
		if( is_multisite() ){
			return get_site_option( $option_name );
		}
		else{
			return get_option( $option_name );
		}
	}	
	/**
	 * Custom Option setter. 
	 * Checks if the site is multisite or not, depending on that uses 'update_site_option' or 'update_option'.
	 */
	public static function set_ig_option( $option_name, $option_value ){
		if( is_multisite() ){
			return update_site_option( $option_name, $option_value );
		}
		else{
			return update_option( $option_name, $option_value );
		}
	}
	/**
	 * Custom Option remover. 
	 * Checks if the site is multisite or not, depending on that uses 'delete_site_option' or 'delete_option'.
	 */
	public static function delete_ig_option( $option_name ){
		if( is_multisite() ){
			return delete_site_option( $option_name );
		}
		else{
			return delete_option( $option_name );
		}
	}
	/**
	 * Custom transient setter.
	 * Checks if the site is multisite or not, depending on that uses 'set_site_transient' or 'set_transient'.
	 */
	public static function set_ig_transient( $transient_name, $transient_value, $expiration ){
		if( is_multisite() ){
			return set_site_transient( $transient_name, $transient_value, $expiration );
		}
		else{
			return set_transient( $transient_name, $transient_value, $expiration );
		}
	}
	/**
	 * Custom transient getter.
	 * Checks if the site is multisite or not, depending on that uses 'set_site_transient' or 'set_transient'.
	 */
	public static function get_ig_transient( $transient_name ){
		if( is_multisite() ){
			return get_site_transient( $transient_name );
		}
		else{
			return get_transient( $transient_name );
		}
	}
	/**
	 * Custom transient re-setter.
	 * Checks if the site is multisite or not, depending on that uses 'delete_site_transient' or 'delete_transient'.
	 */
	public static function delete_ig_transient( $transient_name ){
		if( is_multisite() ){
			return delete_site_transient( $transient_name );
		}
		else{
			return delete_transient( $transient_name );
		}
	}
	/**
	 * 
	 * Method to make remote calls using Curl. 
	 */
	function remote_api_call( $parameters ){
		$ch = curl_init();  
		$api_endpoint = $parameters[ 'api_endpoint' ];
		if( $parameters['type'] == 'GET' ){
				//All GET requests requires either short lived or long lived token. 
				$api_endpoint .= '?' . http_build_query( $parameters[ 'url_parameters' ] );
		} elseif ( $parameters[ 'type' ] == 'POST') {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $parameters[ 'url_parameters' ] ) );
				curl_setopt( $ch, CURLOPT_POST, 1 );
		}

		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); 
		curl_setopt( $ch, CURLOPT_URL, $api_endpoint ); 
		$result = curl_exec( $ch );  
		curl_close( $ch ); 
		$response = json_decode( $result, true );
		if ( isset( $response[ 'error_type' ] ) ) {
			return $response;
			die();
		} else {
			return $response;
		}
	}
}
?>
