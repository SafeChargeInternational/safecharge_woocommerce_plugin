<?php

/**
 * SC_HELPER Class
 *
 * @year 2019
 * @author SafeCharge
 */
class SC_HELPER {

	/**
	 * Function call_rest_api
	 * Call REST API with cURL post and get response.
	 * The URL depends from the case.
	 *
	 * @param type $url - API URL
	 * @param array $params - parameters
	 *
	 * @return mixed
	 */
	public static function call_rest_api( $url, $params) {
		self::create_log($url, 'REST API URL:');
		self::create_log($params, 'SC_REST_API, parameters for the REST API call:');
		
		if (empty($url)) {
			self::create_log('SC_REST_API, the URL is empty!');
			return false;
		}
		
		$resp = false;
		
		// get them only if we pass them empty
		if (isset($params['deviceDetails']) && empty($params['deviceDetails'])) {
			$params['deviceDetails'] = self::get_device_details();
		}
		
		$json_post = json_encode($params);
		
		try {
			$header =  array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($json_post),
			);
			
			// create cURL post
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$resp = curl_exec($ch);
			curl_close($ch);
			
			if (false === $resp) {
				return false;
			}

			$resp_arr = json_decode($resp, true);
			self::create_log($resp_arr, 'REST API response: ');

			return $resp_arr;
		} catch (Exception $e) {
			self::create_log($e->getMessage(), 'Exception ERROR when call REST API: ');
			return false;
		}
	}
	
	/**
	 * Function get_device_details
	 * Get browser and device based on HTTP_USER_AGENT.
	 * The method is based on D3D payment needs.
	 *
	 * @return array $device_details
	 */
	public static function get_device_details() {
		$device_details = array(
			'deviceType'    => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
			'deviceName'    => 'UNKNOWN',
			'deviceOS'      => 'UNKNOWN',
			'browser'       => 'UNKNOWN',
			'ipAddress'     => '0.0.0.0',
		);
		
		$user_agent = strtolower(filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_SANITIZE_STRING));
		
		if (empty($user_agent)) {
			$device_details['Warning'] = 'Probably the merchant Server has problems with PHP filter_input function!';
			
			return $device_details;
		}
		
		$device_details['deviceName'] = $user_agent;

		if (defined('SC_DEVICES_TYPES')) {
			$devs_tps = json_decode(SC_DEVICES_TYPES, true);

			if (is_array($devs_tps) && !empty($devs_tps)) {
				foreach ($devs_tps as $d) {
					if (strstr($user_agent, $d) !== false) {
						if ('linux' === $d || 'windows' === $d) {
							$device_details['deviceType'] = 'DESKTOP';
						} else {
							$device_details['deviceType'] = $d;
						}

						break;
					}
				}
			}
		}

		if (defined('SC_DEVICES')) {
			$devs = json_decode(SC_DEVICES, true);

			if (is_array($devs) && !empty($devs)) {
				foreach ($devs as $d) {
					if (strstr($user_agent, $d) !== false) {
						$device_details['deviceOS'] = $d;
						break;
					}
				}
			}
		}

		if (defined('SC_BROWSERS')) {
			$brs = json_decode(SC_BROWSERS, true);

			if (is_array($brs) && !empty($brs)) {
				foreach ($brs as $b) {
					if (strstr($user_agent, $b) !== false) {
						$device_details['browser'] = $b;
						break;
					}
				}
			}
		}

		// get ip
		$ip_address = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
		
		if(empty($ip_address)) {
			$ip_address = filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_FOR', FILTER_VALIDATE_IP);
		}
		if(empty($ip_address)) {
			$ip_address = filter_input(INPUT_SERVER, 'HTTP_CLIENT_IP', FILTER_VALIDATE_IP);
		}
		
		if(!empty($ip_address)) {
			$device_details['ipAddress'] = (string) $ip_address;
		}
			
		return $device_details;
	}
	
	/**
	 * Function create_log
	 *
	 * @param mixed $data
	 * @param string $title - title for the printed log
	 */
	public static function create_log( $data, $title = '') {
		// path is different fore each plugin
		if (!defined('SC_LOGS_DIR') && !is_dir(SC_LOGS_DIR)) {
			die('SC_LOGS_DIR is not set!');
		}
		
		if (
			( isset($_REQUEST['sc_create_logs']) && in_array($_REQUEST['sc_create_logs'], array(1, 'yes'), true) )
			|| in_array($_SESSION['SC_Variables']['sc_create_logs'], array(1, 'yes'), true)
		) {
			// same for all plugins
			$d = $data;

			if (is_array($data)) {
				if (isset($data['cardData']) && is_array($data['cardData'])) {
					foreach ($data['cardData'] as $k => $v) {
						if (empty($v)) {
							$data['cardData'][$k] = 'empty value!';
						} elseif ('ccTempToken' === $k) {
							$data['cardData'][$k] = $v;
						} else {
							$data['cardData'][$k] = 'a string';
						}
					}
				}
                
				if (isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
					foreach ($data['userAccountDetails'] as $k => $v) {
						$data['userAccountDetails'][$k] = 'a string';
					}
				}
                
				if (isset($data['userPaymentOption']) && is_array($data['userPaymentOption'])) {
					foreach ($data['userPaymentOption'] as $k => $v) {
						$data['userPaymentOption'][$k] = 'a string';
					}
				}
				
				if (isset($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                    $data['paymentMethods'] = json_encode($data['paymentMethods']);
				}
				
				$d = print_r($data, true);
			} elseif (is_object($data)) {
				$d = print_r($data, true);
			} elseif (is_bool($data)) {
				$d = $data ? 'true' : 'false';
			} elseif (is_null($data)) {
				$d = 'null';
			} else {
				$d = $data . "\r\n";
			}

			if (!empty($title)) {
				$d = $title . "\r\n" . $d;
			}
			// same for all plugins

			try {
				file_put_contents(
					SC_LOGS_DIR . date('Y-m-d', time()) . '.txt',
					date('H:i:s', time()) . ': ' . $d . "\r\n",
					FILE_APPEND
				);
			} catch (Exception $exc) {
				echo esc_html($exc->getMessage());
			}
		}
	}
}
