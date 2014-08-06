<?php
/*
	Plugin Name:	Comet
	Plugin URL:		http://cookie-lab.miraiserver.com/
	Description:	Provide method to establish real time connection between cliet and server
	Author:			Jin Sakuma
	Version:		0.0
*/

if (!defined('ABSPATH')) exit;

class Comet {
	function __construct () {
		global $wpdb;
		$this->db = $wpdb;
		$this->prefix = $this->db->prefix.'comet_';
		
		// ---------- On Activation ----------
		register_activation_hook(__FILE__, array(&$this, 'create_db'));
		register_activation_hook(__FILE__, array(&$this, 'create_wp_socket'));
		
		// ---------- Attach JS ----------
		add_action('wp_enqueue_scripts', array(&$this, 'enqueue'));
		
		// ---------- Ajax Actions ----------
		add_action('wp_ajax_websocket_connect', array(&$this, 'ajax_connector'));
		add_action('wp_ajax_websocket_emit', array(&$this, 'ajax_emitter'));
	}
	
	function create_db () {
		// ----- Create Database -----
		// Set Database Setting
		$charset_collate = '';

		if ( ! empty( $wpdb->charset ) ) {
		  $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
		}

		if ( ! empty( $wpdb->collate ) ) {
		  $charset_collate .= " COLLATE {$wpdb->collate}";
		}
		
		$table_name = $this->prefix.'socket';
		
		require_once( ABSPATH.'wp-admin/includes/upgrade.php' );
		
		/********** Socket Database **********/
		$table_name = $this->prefix.'socket';
		$sql = 
			"CREATE TABLE $table_name (
				ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				socket_name VARCHAR(60) UNIQUE,
				socket_pass VARCHAR(64),
				socket_displayname VARCHAR(255),
				socket_create DATETIME DEFAULT CURRENT_TIMESTAMP,
				socket_last DATETIME DEFAULT CURRENT_TIMESTAMP,
				socket_status VARCHAR(20) DEFAULT 'private'
			) $charset_collate;";
		dbDelta($sql);
		
		/********** Socket Meta Database **********/
		$table_name = $this->prefix.'socketmeta';
		$sql = 
			"CREATE TABLE $table_name (
				ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				socket_id BIGINT UNSIGNED NOT NULL,
				meta_key VARCHAR(60),
				meta_value LONGTEXT
			) $charset_collate;";
		dbDelta($sql);
		
		/********** User Database **********/
		$table_name = $this->prefix.'user';
		$sql = 
			"CREATE TABLE wp_comet_user (
				ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				socket_id BIGINT UNSIGNED NOT NULL,
				latest_lod BIGINT UNSIGNED
			) $charset_collate;";
		dbDelta($sql);
		
		/********** User Meta Database **********/
		$table_name = $this->prefix.'usermeta';
		$sql = 
			"CREATE TABLE $table_name (
				ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				meta_key VARCHAR(60),
				meta_value LONGTEXT
			) $charset_collate;";
		dbDelta($sql);
		
		/********** User Meta Database **********/
		$table_name = $this->prefix.'event';
		$sql = 
			"CREATE TABLE wp_comet_event (
				ID BIGINT UNSIGNED NULL AUTO_INCREMENT PRIMARY KEY,
				socket_id BIGINT UNSIGNED NOT NULL,
				event_type VARCHAR(60),
				event_emitter BIGINT UNSIGNED NOT NULL,
				event_time DATETIME DEFAULT CURRENT_TIMESTAMP,
				event_info LONGTEXT
			) $charset_collate;";
		dbDelta($sql);

		/********** User Meta Database **********/
		$table_name = $this->prefix.'eventmeta';
		$sql = 
			"CREATE TABLE $table_name (
				ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				event_id BIGINT UNSIGNED NOT NULL,
				meta_key VARCHAR(60),
				meta_value LONGTEXT
			) $charset_collate;";
		dbDelta($sql);
	}
	
	function create ($socket) {
		$arg = array();
		/***** Parse Name *****/
		if (!isset($socket['name'])) {
			return false;
		} else {
			$arg['socket_name'] = $socket['name'];
		}
		
		/***** Parse Pass *****/
		if (isset($socket['password'])) {
			$arg['socket_pass'] = wp_hash_password($socket['password']);
		}
		
		/***** Parse Displayname *****/
		if (!isset($socket['displayname'])) {
			$arg['socket_displayname'] = $socket['name'];
		} else {
			$arg['socket_displayname'] = $socket['displayname'];
		}
		
		/***** Parse Status *****/
		if (!isset($socket['status'])) {
			$socket['status'] = COMET_PRIVATE;
		}
		switch ($socket['status']) {
		case COMET_PRIVATE:
			$arg['socket_status'] = 'private';
			break;
		case COMET_PUBLIC:
			$arg['socket_status'] = 'public';
			break;
		case COMET_TRASH:
			$arg['socket_status'] = 'trash';
			break;
		}
		
		return $this->db->insert($this->prefix.'socket', $arg);
	}
	
	function destroy ($socket_id) {
		$this->db->delete(
			$this->prefix.'socket',
			array(
				'socket_id' => $socket_id
			),
			array('%d')
		);
		
		$this->db->delete(
			$this->prefix.'event',
			array(
				'socket_id' => $socket_id,
			),
			array('%d')
		);
		
		$this->db->delete(
			$this->prefix.'user',
			array(
				'socket_id' => $socket_id,
			),
			array('%d')
		);
	}
	
	function join ($socket_id, $password = null) {
		if ($password !== null) {
			$password = wp_hash_password($password);
			$sql = $this->db->prepare(
				"SELECT count(*) FROM {$this->prefix} WHERE ID = %d AND socket_pass = %s;",
				$socket_id, $password
			);
		} else {
			$sql = $this->db->prepare(
				"SELECT count(*) FROM {$this->db_prefix} WHERE ID = %d;",
				$socket_id
			);
		}
		
		if ($this->db->get_col($sql)) {
			return $this->db->insert(
				$this->prefix.'user', 
				array(
					'socket_id' => $socket_id,
					'user_id' => get_current_user_id()
				),
				array(
					'%d', '%d'
				)
			);
		} else {
			return false;
		}
	}
	
	function emit_event ($socket_id, $event_type, $event_info) {
		return $wpdb->insert(
			$this->prefix.'event',
			array(
				'websocket_id' => $socket_id,
				'event_type' => $event_type,
				'event_emitter' => get_current_user_id(),
				'event_info' => json_encode($event_info)
			),
			array('%d', '%s', '%d', '%s')
		);
	}
	
	function get_event ($socket_id, $after) {
		$events = $this->db->get_results($this->db->prepare(
			"SELECT * FROM {$this->prefix}event WHERE socket_id = %d AND ID > %d;",
			array(
				$socket_id, $after
			)
		), ARRAY_A);
		
		for ($i = 0; $i < count($events); $i++) {
			$events[$i]['event_info'] = json_decode($events[$i]['event_info']);
		}
		
		return $events;
	}
	
	function start_loop ($socket_id) {
		// ---------- Get Options ----------
		$loop_period = get_option('comet_loop_period', 1);
		$loop_count = get_option('comet_loop_count', 120);
		
		// ---------- Get Latest Load ----------
		$result = $this->db->get_col($this->prepare(
			"SELECT latest_load FROM {$this->prefix}user WHERE socket_id = %d AND user_id = %d;",
			array($socket_id, get_current_user_id())
		));
		
		if (count($result)) {
			if ($result[0] === null) {
				$latest_load = 0;
			} else {
				$latest_load = intval($result[0]);
			}
		} else {
			return false;
		}
		
		// ---------- Set Loop Period ----------
		set_time_limit($loop_period * $loop_count + 10);
		
		// ---------- Loop ----------
		for ($i = 0; $i < $loop_count; $i++) {
			$events = $this->get_events($socket_id, $latest_load);
			if (count($events)) {
				
				// --------- Rewrite Latest Load ----------
				$last = end($events);
				$this->db->update(
					$this->prefix.'user',
					array(
						'latest_load' => $last['latest_load']
					),
					array(
						'socket_id' => $socket_id,
						'user_id' => get_current_user_id()
					),
					array( '%d' )
				);
				
				return $events;
			} else {
				sleep($loop_period);
			}
		}
		
		return array();
	}

	function ajax_connector () {
		if (isset($_GET['socket_id'])) {
			$result = $this->start_loop(intval($_GET['socket_id']));
		} else {
			$result = false;
		}
		if ($result === false) {
			$result = json_encode(array(
				'status' => false
			));
		} else {
			$result = json_encode(array(
				'status' => true,
				'result' => $result
			));
		}
		echo $result;
		exit;
	}
	
	function ajax_emitter () {
		if (isset($_POST['socket_id']) && isset($_POST['event_type']) && isset($_POST['event_info'])) {
			$socket_id = intval($_POST['socket_id']);
			$event_type = $_POST['event_type'];
			$event_info = json_decode($_POST['event_info']);
			$result = $this->emit_event($socket_id, $event_type, $event_info);
		} else {
			$result = false;
		}
		echo json_encode(array('status' => !!$result));
		exit;
	}

	function enqueue () {
		wp_enqueue_script('comet', 
			WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)).'jquery_plugin.js', array('jquery'));
		wp_localize_script('comet', 'comet', array(
			'ajaxurl' => admin_url('admin-ajax.php')
		));
	}

	function create_wp_socket () {
		$this->create (array(
			'name' => 'wp',
			'displayname' => 'Wordpress'
		));
	}
}

$comet = new comet;

?>