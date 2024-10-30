<?php
/*
Plugin Name: HoverSignal WooCommerce Social Proof
Plugin URI: https://ru.wordpress.org/plugins/hoversignal/
Description: HoverSignal adds interactive onsite notifications to your storefront. They display recent orders, on-site activity, products left in stock and a variety of other customer behaviors. You can also grow your email list with newsletter and sales timer notifications.
Version: 1.0.3 
Author: hoversignal
Author URI: https://hoversignal.com
License: GPLv2 

Copyright 2018  HoverSignal  (email  :  support@hoversignal.com)
This program is free software;  you can redistribute it and/or modify
it under the terms of the GNU General Public  License as published by 
the Free Software Foundation;  either version 2 of the License,  or 
(at your option)  any later version.
This program is distributed in the hope that it will be useful, 
but WITHOUT ANY WARRANTY; without even the implied warranty of 
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the 
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program;  if not, write to the Free Software
Foundation,  Inc.,  51 Franklin St,  Fifth Floor,  Boston,  MA  02110-1301  USA
*/

define("HOVERSIGNAL_PLUGIN_URL",  plugin_dir_url(__FILE__));
define("HOVERSIGNAL_OPTIONS_GROUP", "hoversignal_options");
define("hoversignal_api_key", "hoversignal_api_key");

if (!defined('ABSPATH')) {
    die;
}

Class HoverSignal {
	
	/* Activate wp hooks & hoversignal_initialize the plugin
	* @uses wp hooks
	*/
	public function __construct()
	{
		register_activation_hook(__FILE__, array($this, 'hoversignal_initialize'));
		add_action('admin_menu',  array($this, 'hoversignal_admin_menu'));
		add_action('admin_init', array($this, 'hoversignal_admin_init'));
		add_action('wp_footer', array($this, 'hoversignal_set_script')); 
		add_action('woocommerce_checkout_order_processed', array($this, 'hoversignal_get_order'));
		add_action('admin_notices', array( $this, 'hoversignal_global_note'));
	}
		
	/* register settings & scripts/styles
	* @uses wp hooks
	*/
	public function hoversignal_admin_init()
	{
		wp_register_style('hoversignal_style', HOVERSIGNAL_PLUGIN_URL . 'css/admin.css');
		wp_register_script('hoversignal_script', HOVERSIGNAL_PLUGIN_URL . 'js/admin.js');
		register_setting(HOVERSIGNAL_OPTIONS_GROUP, hoversignal_api_key, array($this, 'hoversignal_sanitize_api_key'));
	}
	
	/* Plugin hoversignal_initialize function */
	public function hoversignal_initialize()
	{
		// get WP version
		global $wp_version;
		if(version_compare($wp_version, '3.5', '<')) {
			wp_die('This plugin requires WordPress version 3.5 or higher.');
		}
	}
	
	public function hoversignal_global_note() {
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><? _e( 'Please install WooCommerce and active. HoverSignal Notification is going to working.', 'hoversignal' ); ?></p>
			</div>
			<?
		}
	}
	
	/* Create menu page on admin toolbars 
	* @uses wp hooks
	* @uses $this->hoversignal_enqueue_scripts() to add a scripts on the plugin page
	*/
	public function hoversignal_admin_menu()
	{
		$page = add_menu_page('HoverSignal',  'HoverSignal', 'manage_options',  'hvrsign_admin_menu',  array(
            $this,
            'hoversignal_show_page'
        ), HOVERSIGNAL_PLUGIN_URL.'images/icon.png');
		add_action('admin_print_styles-'.$page, array($this, 'hoversignal_enqueue_scripts'));
		add_action('admin_print_scripts-'.$page, array($this, 'hoversignal_enqueue_scripts'));
	}
	
	/*
	* Add scripts/styles only on the plugin page
	* @uses wp hooks
	*/
	public function hoversignal_enqueue_scripts()
	{
		wp_enqueue_style('hoversignal_style');
		wp_enqueue_script('hoversignal_script');
	}
	
	/*
	* Get last 50 order data's
	* @uses HoverSignal::hoversignal_order_response() to send API key
	*/
	public function hoversignal_get_orders($apiKey) 
	{
		$orders = wc_get_orders(array('limit' => 50));
		$products = array();
		foreach($orders as $order) {
			$data = $order->get_data();
			foreach($order->get_items() as $item) {
				$product = $item->get_product();
				if($product->get_image_id()) {
					$images_arr = wp_get_attachment_image_src( $product->get_image_id(), array('72', '72'), false );
					$image = null;
					if($images_arr !== null && $images_arr[0] !== null) $image = $images_arr[0];
				} else {
					$image = "";
				}
				
				$productArr = array(
					'id' => $data['id'],
					'createdAt' => iso8601_to_datetime($data['date_created']),
					'productName' => $product->get_name(),
					'productUrl' => get_permalink($product->get_id()),
					'imageUrl' => $image
				);
				array_push($products, $productArr);
			}
		}
		
		$this->hoversignal_order_response($products, $apiKey);
	}
	
	/*
	* Get order data
	* @param int $orderID get order data by order Id 
	* @uses HoverSignal::hoversignal_order_response() to send API key
	*/
	public function hoversignal_get_order($orderID)
	{
		$order = wc_get_order( $orderID );
		$data = $order->get_data();
		$items = $order->get_items();
		$products = array();
		foreach($items as $item) {
			$product = $item->get_product();
			if($product->get_image_id()) {
				$images_arr = wp_get_attachment_image_src( $product->get_image_id(), array('72', '72'), false );
				$image = null;
				if($images_arr !== null && $images_arr[0] !== null) $image = $images_arr[0];
			} else {
				$image = "";
			}
			
			$productArr = array(
				'id' => $data['id'],
				'createdAt' => iso8601_to_datetime($data['date_created']),
				'productName' => $product->get_name(),
				'productUrl' => get_permalink($product->get_id()),
				'imageUrl' => $image
			);
			array_push($products, $productArr);
		}
		
		$this->hoversignal_order_response($products);
	}
	
	/*
	* Send order data's to a remote server by api key
	* @param string $api_key
	* @param array $products products data in order
	*/
	private function hoversignal_order_response($products, $api_key = null)
	{
		$apiKey = $this->hoversignal_get_api_key();
		if(!$apiKey) return;
		
		$orderData = array(
			'purchases' => $products
		);
		
		$url = 'https://app.hoversignal.com/api/v1/sites/Purchases';
		$data = json_encode( $orderData );
		$args = array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Api-Key' => ($api_key != null ? $api_key : $apiKey)), 'body' => $data );
		$response = wp_remote_post( esc_url_raw( $url ), $args );
		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );
		
		if ( !in_array( $responseCode, array(200,201) ) || is_wp_error( $responseBody ) ) 
			return false;
	}
	
	/* Get Api Key from wp settings
	* @return string Api key
	*/
	private function hoversignal_get_api_key()
	{
		$apiKey = get_option(hoversignal_api_key);
		
		$url = 'https://app.hoversignal.com/api/v1/sites/ActivateWooCommerce';
		$args = array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Api-Key' => $apiKey));
		$response = wp_remote_post(esc_url_raw( $url ), $args);
		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );
		if ( !in_array( $responseCode, array(200,201) ) || is_wp_error( $responseBody ) ) {
			return null;
		} else {
			return $apiKey;
		}
	}
		
	/* Show form to send api key */
	public function hoversignal_show_page()
	{
		$apiKey = $this->hoversignal_get_api_key();
		
		if(!$apiKey):
		?>
		<div class="wrap hs_page" id="hs_notif">
			<div class="hs_page__info">
				<object class="logo" type="image/svg+xml" data="<?=HOVERSIGNAL_PLUGIN_URL?>images/logo_white.svg"></object>
				<h1><?_e('Notifications for WordPress', 'hoversignal');?></h1>
			</div>
			<div class="hs_page__form">
				<div class="hs_page__form_inner">
					<form action="options.php" method="post">
						<?php wp_nonce_field( 'hs_settings_form_save', 'hs_nonce_field' ); ?>
						<?php settings_fields(HOVERSIGNAL_OPTIONS_GROUP); ?>
						<div class="hs_link">
							<?_e('Please, enter your <b>API-Key</b> to activate the HoverSignal plugin.<br/>', 'hoversignal');?>
							<?_e('You can get the key in your <a target="_blank" href="https://app.hoversignal.com/Account/Login">HoverSignal account</a> in the "Integration" section.<br/>', 'hoversignal');?>
							<?_e('If you don\'t have a HoverSignal account, register <a target="_blank" href="https://app.hoversignal.com/Account/Register?language=ru">here</a>', 'hoversignal');?>
						</div>
						<div class="form_group">
							<label><?_e('Your API-Key', 'hoversignal');?></label>
							<input type="text" class="form-control" name="<?=hoversignal_api_key;?>" value="<?=get_option(esc_attr(hoversignal_api_key));?>" placeholder="<?_e('Enter API-Key', 'hoversignal');?>" />
							<input type="submit" class="btn btn-default" value="<?_e('Send', 'hoversignal');?>" />
						</div>
					</form>
				</div>
			</div>
		</div>
		<?else:?>
		<div class="wrap hs_page hs_page_active" id="hs_notif">
			<div class="hs_page__info">
				<object class="logo" type="image/svg+xml" data="<?=HOVERSIGNAL_PLUGIN_URL?>images/logo_white.svg"></object>
				<h1><?_e('Notifications for WordPress', 'hoversignal');?></h1>
			</div>
			
			<div class="notice notice-success">
				<p><?_e('Current status: Active', 'hoversignal');?></p>
			</div>
			<div class="hs_page__form">
				<div class="hs_page__form_inner">
					<div class="succefully">
						<div class="hs_link">
							<?_e('API-key activation in HoverSignal was successful!', 'hoversignal');?><br/>
							<?_e('You can set up notifications in your personal <a target="_blank" href="https://app.hoversignal.com/Account/Login">account</a>.', 'hoversignal');?>
						</div>
						
						<div class="btn btn-default reconnect"><?_e('Reconfigure', 'hoversignal');?></div>
					</div>
					
					<div class="reconnect_form">
						<div class="btn btn-default preview-btn"><?_e('Back', 'hoversignal');?></div>
						<form action="options.php" method="post">
							<?php wp_nonce_field( 'hs_settings_form_save', 'hs_nonce_field' ); ?>
							<?php settings_fields(HOVERSIGNAL_OPTIONS_GROUP); ?>
							<div class="hs_link">
								<?_e('Please, enter your <b>API-Key</b> to activate the HoverSignal plugin.<br/>', 'hoversignal');?>
								<?_e('You can get the key in your <a target="_blank" href="https://app.hoversignal.com/Account/Login">HoverSignal account</a> in the "Integration" section.<br/>', 'hoversignal');?>
								<?_e('If you don\'t have a HoverSignal account, register <a target="_blank" href="https://app.hoversignal.com/Account/Register?language=ru">here</a>', 'hoversignal');?>
							</div>
							<div class="form_group">
								<label><?_e('Your API-Key', 'hoversignal');?></label>
								<input type="text" class="form-control" name="<?=hoversignal_api_key;?>" value="<?=get_option(esc_attr(hoversignal_api_key));?>" placeholder="<?_e('Enter API-Key', 'hoversignal');?>" />
								<input type="submit" class="btn btn-default" value="<?_e('Send', 'hoversignal');?>" />
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?endif;
	}
	
	/* Sanitize input string and get response from remote server
	* @param string api key
	* @return string
	*/	
	public function hoversignal_sanitize_api_key($input)
	{
		if ( !empty($_POST) && check_admin_referer('hs_settings_form_save','hs_nonce_field') ) {
			$input = sanitize_text_field(esc_attr($input));
			
			$url = 'https://app.hoversignal.com/api/v1/sites/ActivateWooCommerce';
			$args = array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Api-Key' => $input));
			$response = wp_remote_post(esc_url_raw( $url ), $args);
			$responseCode = wp_remote_retrieve_response_code( $response );
			$responseBody = wp_remote_retrieve_body( $response );
			
			if ( !in_array( $responseCode, array(200,201) ) || is_wp_error( $responseBody ) ) {
				return __('Wrong API-Key!', 'hoversignal');
			} else {
				$this->hoversignal_get_orders($input);
			}
			
			return $input;
		}
	}
	
	/* Set the HS script in the head */
	public function hoversignal_set_script()
	{
		$apiKey = $this->hoversignal_get_api_key();
		if(!$apiKey) return;
		
		$url = 'https://app.hoversignal.com/api/v1/sites/ScriptId';
		$args = array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Api-Key' => $apiKey));
		$response = wp_remote_get(esc_url_raw( $url ), $args);
		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );
		
		if ( !in_array( $responseCode, array(200,201) ) || is_wp_error( $responseBody ) ) {
			return false;
		} else {
		
			$scriptKey = json_decode( $responseBody , true);
			
			?>
			<!-- HoverSignal -->
			<script type="text/javascript" >
			(function (d, w) {
			var n = d.getElementsByTagName("script")[0],
			s = d.createElement("script"),
			f = function () { n.parentNode.insertBefore(s, n); };
			s.type = "text/javascript";
			s.async = true;
			s.src = "https://app.hoversignal.com/Api/Script/<?=$scriptKey['scriptId']?>";
			if (w.opera == "[object Opera]") {
			d.addEventListener("DOMContentLoaded", f, false);
			} else { f(); }
			})(document, window);
			</script>
			<!-- /Hoversignal -->
			<?
		}
	}
}

new HoverSignal();