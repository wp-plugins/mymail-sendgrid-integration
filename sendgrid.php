<?php
/*
Plugin Name: MyMail SendGrid Integration
Plugin URI: http://rxa.li/mymail
Description: Uses SendGrid to deliver emails for the MyMail Newsletter Plugin for WordPress. This requires at least version 1.3.2 of the plugin
Version: 0.3
Author: revaxarts.com
Author URI: http://revaxarts.com
License: GPLv2 or later
*/


define('MYMAIL_SENDGRID_VERSION', '0.3');
define('MYMAIL_SENDGRID_REQUIRED_VERSION', '1.3.2');
define('MYMAIL_SENDGRID_ID', 'sendgrid');


class MyMailSendGird{
		
	private $plugin_path;
	private $plugin_url;

	public function __construct(){

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate') );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate') );
		
		load_plugin_textdomain( 'mymail_sendgrid', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
		
		add_action( 'init', array( &$this, 'init'), 1 );
	}
	 /*
	 * init function.
	 *
	 * init the plugin
	 *
	 * @access public
	 * @return void
	 */
	public function init() {

		if (!defined('MYMAIL_VERSION') || version_compare(MYMAIL_SENDGRID_REQUIRED_VERSION, MYMAIL_VERSION, '>')) {
			add_action('admin_notices', array(&$this, 'notice'));
		}else {
			add_filter('mymail_delivery_methods', array(&$this, 'delivery_method'));
			add_action('mymail_deliverymethod_tab_sendgrid', array(&$this, 'deliverytab'));

			add_filter('mymail_verify_options', array(&$this, 'verify_options'));

			if (mymail_option('deliverymethod') == MYMAIL_SENDGRID_ID) {
				add_action('mymail_initsend', array(&$this, 'initsend'));
				add_action('mymail_presend', array(&$this, 'presend'));
				add_action('mymail_dosend', array(&$this, 'dosend'));
				add_action('mymail_sendgrid_cron', array(&$this, 'reset'));
			}
		}
		
	}


	/**
	 * initsend function.
	 *
	 * uses mymail_initsend hook to set initial settings
	 *
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function initsend($mailobject) {

		if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'smtp') {

			$secure = mymail_option(MYMAIL_SENDGRID_ID.'_secure');

			$mailobject->mailer->Mailer = 'smtp';
			$mailobject->mailer->SMTPSecure = $secure ? 'ssl' : 'none';
			$mailobject->mailer->Host = 'smtp.sendgrid.net';
			$mailobject->mailer->Port = $secure ? 465 : 587;
			$mailobject->mailer->SMTPAuth = true;
			$mailobject->mailer->Username = mymail_option(MYMAIL_SENDGRID_ID.'_user');
			$mailobject->mailer->Password = mymail_option(MYMAIL_SENDGRID_ID.'_pwd');
			$mailobject->mailer->SMTPKeepAlive = true;


		}else if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'web') {

		}

		//sendgrid will handle DKIM integration
		$mailobject->dkim = false;
		
	}


	/**
	 * presend function.
	 *
	 * uses the mymail_presend hook to apply settings before each mail
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function presend($mailobject) {

		if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'smtp') {

			//use pre_send from the main class
			$mailobject->pre_send();

		}else if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'web') {
				
			//embedding images doesn't work
			$mailobject->embed_images = false;

			$mailobject->pre_send();

			$mailobject->sendgrid_object = array(
				'from' => $mailobject->from,
				'fromname' => $mailobject->from_name,
				'to' => $mailobject->to,
				'subject' => $mailobject->subject,
				'text' => $mailobject->mailer->AltBody,
				'html' => $mailobject->mailer->Body,
				'api_user' => mymail_option(MYMAIL_SENDGRID_ID.'_user'),
				'api_key' => mymail_option(MYMAIL_SENDGRID_ID.'_pwd'),
				'headers' => $mailobject->headers,
				'files' => array(),
				'content' => array(),
			);
			
			//currently not working on some clients
			if(false){

				$images = $this->embedd_images( $mailobject->make_img_relative( $mailobject->content ) );

				$mailobject->sendgrid_object['files'] = wp_parse_args($images['files'] , $mailobject->sendgrid_object['files']);
				$mailobject->sendgrid_object['content'] = wp_parse_args($images['content'] , $mailobject->sendgrid_object['content']);
				
			}

			if(!empty( $mailobject->attachments )){
				
				$attachments = $this->attachments( $mailobject->attachments );
				
				$mailobject->sendgrid_object['files'] = wp_parse_args($attachments['files'] , $mailobject->sendgrid_object['files']);
				$mailobject->sendgrid_object['content'] = wp_parse_args($attachments['content'] , $mailobject->sendgrid_object['content']);
				
			}

		}

	}


	/**
	 * dosend function.
	 * 
	 * uses the ymail_dosend hook and triggers the send
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function dosend($mailobject) {
		if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'smtp') {

			//use send from the main class
			$mailobject->do_send();

		}else if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'web') {

			if (!isset($mailobject->sendgrid_object)) {
				$mailobject->set_error(__('SendGrid options not defined', 'mymail_sendgrid'));
				return false;
			}
			$response = json_decode( wp_remote_retrieve_body( wp_remote_post( 'http://sendgrid.com/api/mail.send.json', array(
					'body' => $mailobject->sendgrid_object
			 ) ) ) );

			//set errors if exists
			if (isset($response->errors))
				$mailobject->set_error($response->errors);

			if ( isset( $response->message ) && $response->message == 'success' ) {
				$mailobject->sent = true;
			} else {
				$mailobject->sent = false;
			}
		}
	}




	/**
	 * reset function.
	 * 
	 * resets the current time
	 * @access public
	 * @param mixed $message
	 * @return array
	 */
	public function reset() {
		update_option('_transient__mymail_send_period_timeout', false);
		update_option('_transient__mymail_send_period', 0);

	}



	/**
	 * attachments function.
	 * 
	 * prepares the array for attachemnts
	 * @access public
	 * @param mixed $message
	 * @return array
	 */
	public function attachments($attachments) {
		
		$return = array(
			'files' => array(),
			'content' => array(),
		);
		
		foreach($attachments as $attachment){
			if(!file_exists($attachment)) continue;
			$filename = basename($attachment);
			
			$return['files'][$filename] = file_get_contents($attachment);
			$return['content'][$filename] = $filename;
			
		}
		
		return $return;
	}
	/**
	 * embedd_images function.
	 * 
	 * prepares the array for embedded images
	 * @access public
	 * @param mixed $message
	 * @return array
	 */
	public function embedd_images($message) {
		
		$return = array(
			'files' => array(),
			'content' => array(),
			'html' => $message,
		);
		
		$upload_folder = wp_upload_dir();
		$folder = $upload_folder['basedir'];

		preg_match_all("/(src|background)=[\"']([^\"']+)[\"']/Ui", $message, $images);

		if(isset($images[2])) {
		
			foreach($images[2] as $i => $url) {
				if(empty($url)) continue;
				if(substr($url, 0, 7) == 'http://') continue;
				if(substr($url, 0, 8) == 'https://') continue;
				if(!file_exists($folder.'/'.$url)) continue;
				$filename = basename($url);
				$directory = dirname($url);
				if ($directory == '.') {
					$directory = '';
				}
				$cid = md5($folder.'/'.$url.time());
				$return['html'] = str_replace($url, 'cid:' . $cid, $return['html']);
				$return['files'][$filename] = file_get_contents($folder.'/'.$url);
				$return['content'][$filename] = $cid;
			}
		}
		return $return;
	}




	/**
	 * delivery_method function.
	 * 
	 * add the delivery method to the options
	 * @access public
	 * @param mixed $delivery_methods
	 * @return void
	 */
	public function delivery_method($delivery_methods) {
		$delivery_methods[MYMAIL_SENDGRID_ID] = 'SendGrid';
		return $delivery_methods;
	}


	/**
	 * deliverytab function.
	 * 
	 * the content of the tab for the options
	 * @access public
	 * @return void
	 */
	public function deliverytab() {

		$verified = mymail_option(MYMAIL_SENDGRID_ID.'_verified');
		
	?>
		<table class="form-table">
			<?php if(!$verified) { ?>
			<tr valign="top">
				<th scope="row">&nbsp;</th>
				<td><p class="description"><?php echo sprintf(__('You need a %s account to use this service!', 'mymail_sendgrid'), '<a href="http://mbsy.co/sendgrid/63320" class="external">SendGrid</a>'); ?></p>
				</td>
			</tr>
			<?php }?>
			<tr valign="top">
				<th scope="row"><?php _e('SendGrid Username' , 'mymail_sendgrid') ?></th>
				<td><input type="text" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_user]" value="<?php echo esc_attr(mymail_option(MYMAIL_SENDGRID_ID.'_user')); ?>" class="regular-text"></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('SendGrid Password' , 'mymail_sendgrid') ?></th>
				<td><input type="password" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_pwd]" value="<?php echo esc_attr(mymail_option(MYMAIL_SENDGRID_ID.'_pwd')); ?>" class="regular-text"></td>
			</tr>
			<tr valign="top">
				<th scope="row">&nbsp;</th> 
				<td>
					<img src="<?php echo MYMAIL_URI . 'assets/img/icons/'.($verified ? 'green' : 'red').'_2x.png'?>" width="16" height="16">
					<?php echo ($verified) ? __('Your credentials are ok!', 'mymail_sendgrid') : __('Your credentials are WRONG!', 'mymail_sendgrid')?>
					<input type="hidden" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_verified]" value="<?php echo $verified?>">
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Send Emails with' , 'mymail_sendgrid') ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_api]">
					<option value="web" <?php selected(mymail_option( MYMAIL_SENDGRID_ID.'_api'), 'web')?>>WEB API</option>
					<option value="smtp" <?php selected(mymail_option( MYMAIL_SENDGRID_ID.'_api'), 'smtp')?>>SMTP API</option>
				</select>
				<?php if(mymail_option( MYMAIL_SENDGRID_ID.'_api') == 'web') : ?>
				<span class="description"><?php _e('embedded images are not working with the web API!', 'mymail_sendgrid'); ?></span>
				<?php endif; ?>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Secure Connection' , 'mymail_sendgrid') ?></th>
				<td><label><input type="checkbox" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_secure]" value="1" <?php checked(mymail_option( MYMAIL_SENDGRID_ID.'_secure'), true)?>> <?php _e('use secure connection', 'mymail_sendgrid'); ?></label></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('DKIM' , 'mymail_sendgrid') ?></th>
				<td><p class="howto"><?php _e('Set the domain to "sendgrid.me" on the "Apps" page at SendGrid.com (default)' , 'mymail_sendgrid') ?></p></td>
			</tr>
		</table>

	<?php

	}

	/**
	 * verify_options function.
	 * 
	 * some verification if options are saved
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify($user = '', $pwd = '') {

		if (!$user) $user = mymail_option(MYMAIL_SENDGRID_ID.'_user');
		if (!$pwd) $pwd = mymail_option(MYMAIL_SENDGRID_ID.'_pwd');
		
		$response = wp_remote_get( 'http://sendgrid.com/api/profile.get.json?api_user='.$user.'&api_key='.$pwd );

		$body = wp_remote_retrieve_body($response);
		$body = json_decode($body);
		
		if(isset($body->error)){
			return false;
		}else if(isset($body[0]->username)){
			return true;
		}
		
		return false;
	}



	/**
	 * verify_options function.
	 * 
	 * some verification if options are saved
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify_options($options) {

		if ( $timestamp = wp_next_scheduled( 'mymail_sendgrid_cron' ) ) {
			wp_unschedule_event($timestamp, 'mymail_sendgrid_cron' );
		}
		
		if ($options['deliverymethod'] == MYMAIL_SENDGRID_ID) {
		
			$old_user = mymail_option(MYMAIL_SENDGRID_ID.'_user');
			$old_pwd = mymail_option(MYMAIL_SENDGRID_ID.'_pwd');
			
			if (false || $old_user != $options[MYMAIL_SENDGRID_ID.'_user']
				|| $old_pwd != $options[MYMAIL_SENDGRID_ID.'_pwd']
				|| !mymail_option(MYMAIL_SENDGRID_ID.'_verified')) {
				
				
				$options[MYMAIL_SENDGRID_ID.'_verified'] = $this->verify($options[MYMAIL_SENDGRID_ID.'_user'], $options[MYMAIL_SENDGRID_ID.'_pwd']);
				
				if($options[MYMAIL_SENDGRID_ID.'_verified']){
					add_settings_error( 'mymail_options', 'mymail_options', sprintf(__('Please update your sending limits! %s', 'mymail_sendgrid'), '<a href="http://sendgrid.com/account/overview" class="external">SendGrid Dashboard</a>' ));

				}

			}
			
			if ( !wp_next_scheduled( 'mymail_sendgrid_cron' ) ) {
				//reset on 00:00 PST ( GMT -8 ) == GMT +16
				$timeoffset = strtotime('midnight')+((24-8)*HOUR_IN_SECONDS);
				if($timeoffset < time()) $timeoffset+(24*HOUR_IN_SECONDS);
				wp_schedule_event($timeoffset, 'daily', 'mymail_sendgrid_cron');
			}
			
			if(function_exists( 'fsockopen' ) && $options[MYMAIL_SENDGRID_ID.'_api'] == 'smtp'){
				$host = 'smtp.sendgrid.net';
				$port = isset($options[MYMAIL_SENDGRID_ID.'_secure']) && $options[MYMAIL_SENDGRID_ID.'_secure'] == 'tls' ? 587 : 465;
				$conn = fsockopen($host, $port, $errno, $errstr, 5);
				
				if(!is_resource($conn)){
					
					fclose($conn);
					
				}else{
					
					add_settings_error( 'mymail_options', 'mymail_options', sprintf(__('Not able to use SendGrid with SMTP API cause of the blocked port %s! Please send with the WEB API or choose a different delivery method!', 'mymail_sendgrid'), $port) );
					
				}
			}
		}
		
		return $options;
	}


	/**
	 * notice function.
	 * 
	 * Notice if MyMail is not avaiable
	 * @access public
	 * @return void
	 */
	public function notice() {
	?>
	<div id="message" class="error">
	  <p>
	   <strong>SendGrid integration for MyMail</strong> requires the <a href="http://rxa.li/mymail?utm_source=SendGrid+integration+for+MyMail">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_SENDGRID_REQUIRED_VERSION ?></strong>. Plugin deactivated.
	  </p>
	</div>
		<?php
	}



	/**
	 * activate function.
	 * 
	 * activate function
	 * @access public
	 * @return void
	 */
	public function activate() {
		if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_SENDGRID_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
			mymail_notice(sprintf(__('Change the delivery method on the %s!', 'mymail_sendgrid'), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=mymail_delivery_method#delivery">Settings Page</a>'), '', false, 'delivery_method');
			$this->reset();
		}
	}


	/**
	 * deactivate function.
	 * 
	 * deactivate function
	 * @access public
	 * @return void
	 */
	public function deactivate() {
		if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_SENDGRID_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
			if(mymail_option('deliverymethod') == MYMAIL_SENDGRID_ID){
				mymail_update_option('deliverymethod', 'simple');
				mymail_notice(sprintf(__('Change the delivery method on the %s!', 'mymail_sendgrid'), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=mymail_delivery_method#delivery">Settings Page</a>'), '', false, 'delivery_method');
			}
		}
	}

}

new MyMailSendGird();