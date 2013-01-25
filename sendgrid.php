<?php
/*
Plugin Name: MyMail SendGrid Integration
Plugin URI: http://rxa.li/mymail
Description: Uses SendGrid to deliver emails for the MyMail Newsletter Plugin for WordPress. This requires at least version 1.3.2 of the plugin
Version: 0.1
Author: revaxarts.com
Author URI: http://revaxarts.com
License: GPLv2 or later
*/


define('MYMAIL_SENDGRID_VERSION', '0.1');
define('MYMAIL_SENDGRID_REQUIRED_VERSION', '1.3.2');
define('MYMAIL_SENDGRID_ID', 'sendgrid');
define('MYMAIL_SENDGRID_DOMAIN', 'mymail-sendgrid');
define('MYMAIL_SENDGRID_DIR', WP_PLUGIN_DIR.'/mymail-sendgrid-integration');
define('MYMAIL_SENDGRID_URI', plugins_url().'/mymail-sendgrid-integration');
define('MYMAIL_SENDGRID_SLUG', substr(__FILE__, strlen(WP_PLUGIN_DIR)+1));


add_action('init', 'mymail_sendgrid_init');
register_activation_hook(MYMAIL_SENDGRID_SLUG, 'mymail_sendgrid_activation');
register_deactivation_hook(MYMAIL_SENDGRID_SLUG, 'mymail_sendgrid_deactivation');


/**
 * mymail_sendgrid_init function.
 *
 * init the plugin
 *
 * @access public
 * @return void
 */
function mymail_sendgrid_init() {

	if (!defined('MYMAIL_VERSION') || version_compare(MYMAIL_SENDGRID_REQUIRED_VERSION, MYMAIL_VERSION, '>')) {
		add_action('admin_notices', 'mymail_sendgrid_notice');
		add_action('shutdown', 'mymail_sendgrid_deactivate');
	}else {
		add_filter('mymail_delivery_methods', 'mymail_sendgrid_delivery_method');
		add_action('mymail_deliverymethod_tab_sendgrid', 'mymail_sendgrid_deliverytab');

		add_filter('mymail_verify_options', 'mymail_sendgrid_verify_options');

		if (mymail_option('deliverymethod') == MYMAIL_SENDGRID_ID) {
			add_action('mymail_initsend', 'mymail_sendgrid_initsend');
			add_action('mymail_presend', 'mymail_sendgrid_presend');
			add_action('mymail_dosend', 'mymail_sendgrid_dosend');
			add_action('mymail_sendgrid_cron', 'mymail_sendgrid_reset');
		}
	}
	
}


/**
 * mymail_sendgrid_initsend function.
 *
 * uses mymail_initsend hook to set initial settings
 *
 * @access public
 * @param mixed $mailobject
 * @return void
 */
function mymail_sendgrid_initsend($mailobject) {

	if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'smtp') {

		$secure = mymail_option(MYMAIL_SENDGRID_ID.'_secure');

		$mailobject->mailer->Mailer = 'smtp';
		$mailobject->mailer->SMTPSecure = $secure ? 'ssl' : 'none';
		$mailobject->mailer->Host = 'smtp.sendgrid.net';
		$mailobject->mailer->Port = $secure ? 465 : 587;
		$mailobject->mailer->SMTPAuth = true;
		$mailobject->mailer->Username = mymail_option(MYMAIL_SENDGRID_ID.'_user');
		$mailobject->mailer->Password = mymail_option(MYMAIL_SENDGRID_ID.'_pwd');


	}else if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'web') {

	}

	//sendgrid will handle DKIM integration
	$mailobject->dkim = false;
	
}


/**
 * mymail_sendgrid_presend function.
 *
 * uses the mymail_presend hook to apply setttings before each mail
 * @access public
 * @param mixed $mailobject
 * @return void
 */
function mymail_sendgrid_presend($mailobject) {

	if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'smtp') {

		//use pre_send from the main class
		$mailobject->pre_send();

	}else if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'web') {
			
			
			$mailobject->sendgrid_object = array(
				'from' => $mailobject->from,
				'fromname' => $mailobject->from_name,
				'to' => $mailobject->to,
				'subject' => $mailobject->subject,
				'text' => $mailobject->plain_text($mailobject->content),
				'html' => $mailobject->content,
				'api_user' => mymail_option(MYMAIL_SENDGRID_ID.'_user'),
				'api_key' => mymail_option(MYMAIL_SENDGRID_ID.'_pwd'),
				'headers' => $mailobject->headers,
			);
			
			if($mailobject->embed_images){
				
				//currenlty not working on some clients
				//$mailobject->sendgrid_object = wp_parse_args(mymail_sendgrid_embedd_images( $mailobject->make_img_relative($mailobject->content) ) , $mailobject->sendgrid_object);
			}
			
			//set pre_send to true if all is good
			$mailobject->pre_send = true;
		}

}


/**
 * mymail_sendgrid_dosend function.
 * 
 * uses the ymail_dosend hook and triggers the send
 * @access public
 * @param mixed $mailobject
 * @return void
 */
function mymail_sendgrid_dosend($mailobject) {
	if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'smtp') {

		//use send from the main class
		$mailobject->do_send();

	}else if (mymail_option(MYMAIL_SENDGRID_ID.'_api') == 'web') {

			if (!$mailobject->sendgrid_object) {
				$mailobject->set_error(__('SendGrid options not defined', MYMAIL_SENDGRID_DOMAIN));
				return false;
			}
			$response = json_decode( wp_remote_retrieve_body( wp_remote_post( 'https://sendgrid.com/api/mail.send.json', array(  'body' => $mailobject->sendgrid_object ) ) ) );

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
 * mymail_sendgrid_reset function.
 * 
 * resets the current time
 * @access public
 * @param mixed $message
 * @return array
 */
function mymail_sendgrid_reset() {
	update_option('_transient__mymail_send_period_timeout', false);
	update_option('_transient__mymail_send_period', 0);

}



/**
 * mymail_sendgrid_embedd_images function.
 * 
 * prepares the array for embedded iamges
 * @access public
 * @param mixed $message
 * @return array
 */
function mymail_sendgrid_embedd_images($message) {
	
	$return = array(
		'files' => array(),
		'content' => array(),
		'html' => $message,
	);
	
	preg_match_all("/(src|background)=[\"'](.*)[\"']/Ui", $message, $images);
	
	if(isset($images[2])) {
	
		foreach($images[2] as $i => $url) {
			if(empty($url)) continue;
			if(substr($url, 0, 7) == 'http://') continue;
			if(substr($url, 0, 8) == 'https://') continue;
			if(!file_exists(ABSPATH.$url)) continue;
			$filename = basename($url);
			$directory = dirname($url);
			if ($directory == '.') {
				$directory = '';
			}
			$md5 = md5($url.time());
			//$filename = $md5.'_'.$filename;
			
			$cid = $md5;
			$return['html'] = str_replace($url, 'cid:' . $cid, $return['html']);
			$return['files'][$filename] = file_get_contents(ABSPATH.$url);
			$return['content'][$filename] = $cid;
		}
	}
	return $return;
}




/**
 * mymail_sendgrid_delivery_method function.
 * 
 * add the delivery method to the options
 * @access public
 * @param mixed $delivery_methods
 * @return void
 */
function mymail_sendgrid_delivery_method($delivery_methods) {
	$delivery_methods[MYMAIL_SENDGRID_ID] = 'SendGrid';
	return $delivery_methods;
}


/**
 * mymail_sendgrid_deliverytab function.
 * 
 * the content of the tab for the options
 * @access public
 * @return void
 */
function mymail_sendgrid_deliverytab() {

	$verified = mymail_option(MYMAIL_SENDGRID_ID.'_verified');
	
	 echo wp_timezone_override_offset();
?>
	<table class="form-table">
		<?php if(!$verified) { ?>
		<tr valign="top">
			<th scope="row">&nbsp;</th>
			<td><p class="description"><?php echo sprintf(__('You need a %s account to use this service!', MYMAIL_SENDGRID_DOMAIN), '<a href="http://mbsy.co/sendgrid/63320" class="external">SendGrid</a>'); ?></p>
			</td>
		</tr>
		<?php }?>
		<tr valign="top">
			<th scope="row"><?php _e('SendGrid Username' , MYMAIL_SENDGRID_DOMAIN) ?></th>
			<td><input type="text" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_user]" value="<?php echo esc_attr(mymail_option(MYMAIL_SENDGRID_ID.'_user')); ?>" class="regular-text"></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e('SendGrid Password' , MYMAIL_SENDGRID_DOMAIN) ?></th>
			<td><input type="password" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_pwd]" value="<?php echo esc_attr(mymail_option(MYMAIL_SENDGRID_ID.'_pwd')); ?>" class="regular-text"></td>
		</tr>
		<tr valign="top">
			<th scope="row">&nbsp;</th> 
			<td>
				<img src="<?php echo MYMAIL_URI.'/assets/img/icons/'.($verified ? 'green' : 'red').'_2x.png'?>" width="16" height="16">
				<?php echo ($verified) ? __('Your credentials are ok!', MYMAIL_SENDGRID_DOMAIN) : __('Your credentials are WRONG!', MYMAIL_SENDGRID_DOMAIN)?>
				<input type="hidden" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_verified]" value="<?php echo $verified?>">
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e('Send Emails with' , MYMAIL_SENDGRID_DOMAIN) ?></th>
			<td>
			<select name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_api]">
				<option value="web" <?php selected(mymail_option( MYMAIL_SENDGRID_ID.'_api'), 'web')?>>WEB API</option>
				<option value="smtp" <?php selected(mymail_option( MYMAIL_SENDGRID_ID.'_api'), 'smtp')?>>SMTP API</option>
			</select>
			<?php if(mymail_option( MYMAIL_SENDGRID_ID.'_api') == 'web') : ?>
			<span class="description"><?php _e('embedded images are not working with the web API!', MYMAIL_SENDGRID_DOMAIN); ?></span>
			<?php endif; ?>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e('Secure Connection' , MYMAIL_SENDGRID_DOMAIN) ?></th>
			<td><label><input type="checkbox" name="mymail_options[<?php echo MYMAIL_SENDGRID_ID ?>_secure]" value="1" <?php checked(mymail_option( MYMAIL_SENDGRID_ID.'_secure'), true)?>> <?php _e('use secure connection', MYMAIL_SENDGRID_DOMAIN); ?></label></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e('DKIM' , MYMAIL_SENDGRID_DOMAIN) ?></th>
			<td><p class="howto"><?php _e('Set the domain to "sendgrid.me" on the "Apps" page at SendGrid.com (default)' , MYMAIL_SENDGRID_DOMAIN) ?></p></td>
		</tr>
	</table>

<?php

}

/**
 * mymail_sendgrid_verify_options function.
 * 
 * some verification if options are saved
 * @access public
 * @param mixed $options
 * @return void
 */
function mymail_sendgrid_verify($user = '', $pwd = '') {

	if (!$user) $user = mymail_option(MYMAIL_SENDGRID_ID.'_user');
	if (!$pwd) $pwd = mymail_option(MYMAIL_SENDGRID_ID.'_pwd');
	
	
	$response = json_decode(wp_remote_retrieve_body(wp_remote_get( 'https://sendgrid.com/api/profile.get.json?api_user='.$user.'&api_key='.$pwd )));
	
	if(isset($response->error)){
		return false;
	}else if(isset($response[0]->username)){
		return true;
	}
	
	return false;
}



/**
 * mymail_sendgrid_verify_options function.
 * 
 * some verification if options are saved
 * @access public
 * @param mixed $options
 * @return void
 */
function mymail_sendgrid_verify_options($options) {

	if ( $timestamp = wp_next_scheduled( 'mymail_sendgrid_cron' ) ) {
		wp_unschedule_event($timestamp, 'mymail_sendgrid_cron' );
	}
	
	if ($options['deliverymethod'] == MYMAIL_SENDGRID_ID) {
	
		$old_user = mymail_option(MYMAIL_SENDGRID_ID.'_user');
		$old_pwd = mymail_option(MYMAIL_SENDGRID_ID.'_pwd');
		
		if (false || $old_user != $options[MYMAIL_SENDGRID_ID.'_user']
			|| $old_pwd != $options[MYMAIL_SENDGRID_ID.'_pwd']
			|| !mymail_option(MYMAIL_SENDGRID_ID.'_verified')) {
			
			
			$options[MYMAIL_SENDGRID_ID.'_verified'] = mymail_sendgrid_verify($options[MYMAIL_SENDGRID_ID.'_user'], $options[MYMAIL_SENDGRID_ID.'_pwd']);
			
			if($options[MYMAIL_SENDGRID_ID.'_verified']){
				add_settings_error( 'mymail_options', 'mymail_options', sprintf(__('Please update your sending limits! %s', MYMAIL_SENDGRID_DOMAIN), '<a href="http://sendgrid.com/account/overview" class="external">SendGrid Dashboard</a>' ));

			}

		}
		
		if ( !wp_next_scheduled( 'mymail_sendgrid_cron' ) ) {
			//reset on 00:00 PST ( GMT -8 ) == GMT +16
			$timeoffset = strtotime('midnight')+((24-8)*HOUR_IN_SECONDS);
			if($timeoffset < time()) $timeoffset+(24*HOUR_IN_SECONDS);
			wp_schedule_event($timeoffset, 'daily', 'mymail_sendgrid_cron');
		}
	}
	
	return $options;
}


/**
 * mymail_sendgrid_notice function.
 * 
 * Notice if MyMail is not avaiable
 * @access public
 * @return void
 */
function mymail_sendgrid_notice() {
?>
<div id="message" class="error">
  <p>
   <strong>SendGrid integration for MyMail</strong> requires the <a href="http://rxa.li/mymail">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_SENDGRID_REQUIRED_VERSION?></strong>. Plugin deactivated.
  </p>
</div>
	<?php
}


/**
 * mymail_sendgrid_deactivate function.
 * 
 * deactivation function
 * @access public
 * @return void
 */
function mymail_sendgrid_deactivate() {
	deactivate_plugins( MYMAIL_SENDGRID_SLUG, false, is_network_admin() );
}


/**
 * mymail_sendgrid_activation function.
 * 
 * actication function
 * @access public
 * @return void
 */
function mymail_sendgrid_activation() {
	if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_SENDGRID_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
		mymail_notice(sprintf(__('Change the delivery method on the %s!', MYMAIL_SENDGRID_DOMAIN), '<a href="options-general.php?page=newsletter-settings#delivery">Settings Page</a>'), '', false, 'delivery_method');
		mymail_sendgrid_reset();
	}
}


/**
 * mymail_sendgrid_deactivation function.
 * 
 * deactication function
 * @access public
 * @return void
 */
function mymail_sendgrid_deactivation() {
	if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_SENDGRID_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
		if(mymail_option('deliverymethod') == MYMAIL_SENDGRID_ID){
			mymail_update_option('deliverymethod', 'simple');
			mymail_notice(sprintf(__('Change the delivery method on the %s!', MYMAIL_SENDGRID_DOMAIN), '<a href="options-general.php?page=newsletter-settings#delivery">Settings Page</a>'), '', false, 'delivery_method');
		}
	}
}


?>