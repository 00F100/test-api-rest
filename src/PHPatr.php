<?php

namespace PHPatr
{
	use Phar;
	use Exception;
	use GuzzleHttp\Client;
	use PHPatr\Exceptions\ConfigFileNotFoundException;
	use PHPatr\Exceptions\ConfigFileEmptyException;
	use PHPatr\Exceptions\OutputFileEmptyException;
	use PHPatr\Exceptions\ErrorTestException;

	class PHPatr
	{
		private $_client;
		private $_auths = array();
		private $_bases = array();
		private $_configFile = './phpatr.json';
		private $_hasError = false;
		private $_saveFile = false;
		private $_version = '0.7.0';
		private $_update = array(
			'base' => 'https://raw.githubusercontent.com',
			'path' => '/00F100/phpatr/master/dist/version',
		);
		private $_download = 'https://github.com/00F100/phpatr/raw/master/dist/phpatr.phar?';
		private $_error = array();
		private $_debug = false;

		public function init()
		{
			$configured = false;
			$args = func_get_args();
			if($args[0] == 'index.php'){
				unset($args[0]);
			}
			while($value = current($args)){
				switch($value){
					case '-c':
					case '--config':
						$this->_configFile = next($args);
						$configured = true;
						break;
					case '-e':
					case '--example-config-json':
						$this->_exampleConfigJson();
						break;
					case '-o':
					case '--output':
						$this->_saveFile = next($args);
						break;
					case '-u':
					case '--self-update':
						$this->_selfUpdate();
						break;
					case '-d':
					case '--debug':
						$this->_debug = true;
						break;
					case '-h':
					case '--help':
						$this->_echoWelcome();
						$this->_help();
						break;
					case '-v':
					case '--version':
						$this->_version();
						break;
				}
				next($args);
			}
			if(!$configured){
				$this->_echoWelcome();
				$this->_help();
			}
			$this->_echoWelcome();
			if($this->_saveFile){
				$this->_resetLogFile();
			}
			$this->_checkUpdate();
			return $this->_run();
		}

		private function _run()
		{
			if(empty($this->_configFile)){
				throw new ConfigFileEmptyException();
			}
			$configFile = str_replace($_SERVER['argv'][0], '', Phar::running(false)) . $this->_configFile;
			if(!is_file($configFile)){
				throw new ConfigFileNotFoundException($configFile);
			}
			$this->_log('Start: ' . date('Y-m-d H:i:s'));
			$this->_log('Config File: ' . $this->_configFile);
			$this->_config = json_decode(file_get_contents($configFile), true);
			$this->_log('Test Config: ' . $this->_config['name']);
			$this->_configAuth();
			$this->_configBase();
			$this->_log('Run Tests!');

			if(count($this->_config['tests']) > 0){
				foreach($this->_config['tests'] as $index => $test){
					// debug($this->_config['tests'], false);
					$base = $this->_bases[$test['base']];
					$auth = $this->_auths[$test['auth']];
					$header = [];
					$query = [];
					$data = [];
					if(array_key_exists('header', $base)){
						$header = array_merge($header, $base['header']);
					}
					if(array_key_exists('query', $base)){
						$query = array_merge($query, $base['query']);
					}
					if(array_key_exists('data', $base)){
						$data = array_merge($data, $base['data']);
					}
					if(array_key_exists('header', $auth)){
						$header = array_merge($header, $auth['header']);
					}
					if(array_key_exists('query', $auth)){
						$query = array_merge($query, $auth['query']);
					}
					if(array_key_exists('data', $auth)){
						$data = array_merge($data, $auth['data']);
					}
					if(array_key_exists('data', $test)){
						$data = array_merge($data, $test['data']);
					}

					$this->_client = new Client([
						'base_uri' => $base['url'],
						'timeout' => 10,
						'allow_redirects' => false,
					]);
					$assert = $test['assert'];
					$statusCode = $assert['code'];
					$break = true;
					try {
						if($test['method'] == 'POST' || $test['method'] == 'PUT'){
							$response = $this->_client->request($test['method'], $test['path'], [
								'query' => $query,
								'headers' => $header,
								'json' => $data,
							]);
						}else{
							$response = $this->_client->request($test['method'], $test['path'], [
								'query' => $query,
								'headers' => $header,
							]);
						}
						$break = false;
					} catch(Exception $e){
						if($e->getCode() == $statusCode){
							$this->_success($base, $auth, $test);
							if($this->_debug){
								if(array_key_exists('fields', $assert)){
									$this->_log('JSON config');
									print_r($assert['fields'], false);
									echo "\n";
								}
								$this->_log('JSON response');
								if(isset($json)){
									print_r($json, false);
								}
								echo "\n======================\n\n";
							}
						}else{
							$this->_error[] = 'The status code does not match the assert';
							$this->_error($base, $auth, $test);
						}
						continue;
					}

					if($response->getStatusCode() != $statusCode){
						$this->_error[] = 'The status code does not match the assert';
						$this->_error($base, $auth, $test);
						
						continue;
					}

					if(array_key_exists('type', $assert)){
						switch($assert['type']){
							case 'json':
								$body = $response->getBody();
								$json = array();
								while (!$body->eof()) {
									$json[] = $body->read(1024);
								}
								$json = trim(implode($json));
								if(
									(substr($json, 0, 1) == '{' && substr($json, -1) == '}') ||
									(substr($json, 0, 1) == '[' && substr($json, -1) == ']')
								){
									$json = json_decode($json, true);
									if(!$this->_parseJson($assert['fields'], $json)){
										$this->_error[] = 'The tests[]->assert->fields does not match to test';
										$this->_error($base, $auth, $test);
										if($this->_debug){

											$this->_log('JSON config');
											print_r($assert['fields'], false);
											if($this->_saveFile){
												$this->_logFile(print_r($assert['fields'], true));
											}
											echo "\n";

											$this->_log('JSON response');
											print_r($json, false);
											if($this->_saveFile){
												$this->_logFile(print_r($json, true));
											}

											echo "\n======================\n\n";
										}
										continue;
									}else{
										$this->_success($base, $auth, $test);
										if($this->_debug){

											$this->_log('JSON config');
											print_r($assert['fields'], false);
											if($this->_saveFile){
												$this->_logFile(print_r($assert['fields'], true));
											}
											echo "\n";

											$this->_log('JSON response');
											print_r($json, false);
											if($this->_saveFile){
												$this->_logFile(print_r($json, true));
											}
											echo "\n======================\n\n";
										}
										continue;
									}
								}else{
									$this->_error[] = 'The response of HTTP server no corresponds to a valid JSON format';
									$this->_error($base, $auth, $test);
									if($this->_debug){

										$this->_log('JSON config');
										print_r($assert['fields'], false);
										if($this->_saveFile){
											$this->_logFile(print_r($assert['fields'], true));
										}
										echo "\n";

										$this->_log('JSON response');
										print_r($json, false);
										if($this->_saveFile){
											$this->_logFile(print_r($json, true));
										}
										echo "\n======================\n\n";
									}
									continue;
								}
								if($this->_debug){
									$this->_log('JSON config');
									print_r($assert['fields'], false);
									if($this->_saveFile){
										$this->_logFile(print_r($assert['fields'], true));
									}
									echo "\n";
									$this->_log('JSON response');
									print_r($json, false);
									if($this->_saveFile){
										$this->_logFile(print_r($json, true));
									}
									echo "\n======================\n\n";
								}
								continue;
						}
					}else{
						$this->_success($base, $auth, $test);
						if($this->_debug){
							$this->_log('JSON config');
							print_r($assert['fields'], false);
							if($this->_saveFile){
								$this->_logFile(print_r($assert['fields'], true));
							}
							echo "\n";

							$this->_log('JSON response');
							if(isset($json)){
								print_r($json, false);
								if($this->_saveFile){
									$this->_logFile(print_r($json, true));
								}
							}
							echo "\n======================\n\n";
						}
						continue;
					}
				}
			}
			$this->_log('End: ' . date('Y-m-d H:i:s'));
			die(0);
			if($this->_hasError){
				throw new ErrorTestException();
			}
		}

		private function _parseJson($required, $json)
		{
			if(is_array($required) && is_array($json)){

				$findFields = array();
				$success = array();
				$error = false;

				foreach($required as $indexRequired => $valueRequired){

					if(!array_key_exists($indexRequired, $json)){
						$error = true;
					}
					$field = $json[$indexRequired];
					if(is_array($valueRequired)){
						if(is_array($field)){
							return $this->_parseJson($valueRequired, $field);
						}else{
							$error = true;
						}
						
					}else{
						if(gettype($field) == $valueRequired){
							$success[] = $field;
						}
					}
				}
				if($error){
					return false;
				}
				if(count($success) == count($required)){
					return true;
				}
			}
		}

		private function _configAuth()
		{
			$this->_auths = array();
			foreach($this->_config['auth'] as $auth){
				$this->_auths[$auth['name']] = $auth;
			}
		}

		private function _configBase()
		{
			$this->_bases = array();
			foreach($this->_config['base'] as $base){
				$this->_bases[$base['name']] = $base;
			}
		}

		private function _log($message, $array = false)
		{
			echo "[\033[33mS\033[0m\033[30mLOG\033[0m] \033[33m$message\033[0m \n";
			if($array && is_array($array)){
				print_r($array);
				if($this->_saveFile){
					$this->_logFile(print_r($array, true));
				}
			}
			if($this->_saveFile){
				$this->_logFile('[SLOG] ' . $message . "\n");
			}
		}

		private function _echoWelcome()
		{
			echo "\033[33mPHPatr version " . $this->_version . "\033[0m\n";
		}

		private function _error($base, $auth, $test)
		{
			$this->_hasError = 1;
			echo "[\033[31mFAIL\033[0m] " . $test['name'] . " \n";
			if(count($this->_error) > 0){
				foreach($this->_error as $run_error){
					echo "[\033[31mF\033[0m\033[30mLOG\033[0m] $run_error \n";
				}
				$this->_error = array();
			}
			if($this->_saveFile){
				$this->_logFile('[FAIL] ' . $test['name'] . "\n");
			}
		}

		private function _true($message)
		{
			echo "[\033[32m OK \033[0m] " . $message . " \n";
			if($this->_saveFile){
				$this->_logFile('[ OK ] ' . $message . "\n");
			}
		}

		private function _success($base, $auth, $test)
		{
			echo "[\033[32m OK \033[0m] " . $test['name'] . " \n";
			if($this->_saveFile){
				$this->_logFile('[ OK ] ' . $test['name'] . "\n");
			}
		}

		private function _logFile($message)
		{
			if(empty($this->_saveFile)){
				throw new OutputFileEmptyException();
			}
			$fopen = fopen($this->_saveFile, 'a');
			fwrite($fopen, $message);
			fclose($fopen);
		}

		private function _resetLogFile()
		{
			if(is_file($this->_saveFile)){
				unlink($this->_saveFile);
			}
		}

		private function _help()
		{
			echo "   \033[33mUsage:\033[0m\n";
			echo "        \033[33m Test API REST: \033[0m\n";
			echo "	\033[32m php phpatr.phar --config <config file> [--output <file>, [--debug]] \033[0m \n\n";
			echo "        \033[33m Generate example JSON configuration: \033[0m\n";
			echo "	\033[32m php phpatr.phar --example-config-json \033[0m \n\n";
			echo "        \033[33m Self Update: \033[0m\n";
			echo "	\033[32m php phpatr.phar --self-update \033[0m \n\n";
			echo "        \033[33m Help: \033[0m\n";
			echo "	\033[32m php phpatr.phar --help \033[0m \n\n";
			echo "	Options:\n";
			echo "	\033[37m  -d,  --debug                    		Debug the calls to API REST \033[0m \n";
			echo "	\033[37m  -c,  --config                     		File of configuration in JSON to test API REST calls \033[0m \n";
			echo "	\033[37m  -e,  --example-config-json         		Generate a example file JSON to configuration \033[0m \n";
			echo "	\033[37m  -o,  --output                     		Output file to save log \033[0m \n";
			echo "	\033[37m  -u,  --self-update                		Upgrade to the latest version version \033[0m \n";
			echo "	\033[37m  -v,  --version                    		Return the installed version of this package \033[0m \n";
			echo "	\033[37m  -h,  --help                      		Show this menu \033[0m \n";
			die(0);
		}

		private function _checkUpdate($returnVersion = false)
		{
			$client = new Client([
				'base_uri' => $this->_update['base'],
				'timeout' => 10,
				'allow_redirects' => false,
			]);

			try {
				$response = $client->request('GET', $this->_update['path']);
			} catch(Exception $e){
				return false;
			}

			$body = $response->getBody();
			$version = array();
			while (!$body->eof()) {
				$version[] = $body->read(1024);
			}
			$version = implode($version);
			$_cdn_version = str_replace('.', '', $version);

			if($returnVersion){
				return $version;
			}

			$_local_version = $this->_version;
			$_local_version = str_replace('.', '', $_local_version);

			if($_local_version < $_cdn_version){
				$this->_messageUpdate($version);
			}
		}

		private function _messageUpdate($version)
		{
			echo "\033[31mUPDATE:\033[0m \033[31m There is a new version available! \033[0m \n";
			echo "\033[31mUPDATE:\033[0m \033[31m $this->_version -> $version \033[0m \n";
			echo "\033[31mUPDATE:\033[0m \033[31m Automatic: Run the self-update: php phpatr.phar --self-update \033[0m \n";
			echo "\033[31mUPDATE:\033[0m \033[31m Manual: visit the GitHub repository and download the latest version (https://github.com/00F100/phpatr/) \033[0m \n";
		}

		private function _selfUpdate()
		{
			$version = $this->_checkUpdate(true);
			$_cdn_version = str_replace('.', '', $version);
			$_local_version = $this->_version;
			$_local_version = str_replace('.', '', $_local_version);

			if($_local_version < $_cdn_version){
				$pharFile = str_replace($_SERVER['argv'][0], '', Phar::running(false)) . '/phpatr-updated.phar';
				 try {
				 	$client = new Client();
				 	$this->_log('Downloading new version');
					$response = $client->request('GET', $this->_download . md5(microtime()));
					$body = $response->getBody();
					$phar = array();
					while (!$body->eof()) {
						$phar[] = $body->read(10240);
					}
					$phar = implode($phar);
				 	$this->_true('Downloading new version');
					$fopen = fopen($pharFile, 'w');
					fwrite($fopen, $phar);
					fclose($fopen);
					 	$this->_log('Updating Phar file');
					copy($pharFile, 'phpatr.phar');
					 	$this->_true('Updating Phar file');
					 	$this->_log('Removing temporary file');
						unlink($pharFile);
					 	$this->_true('Removing temporary file');
						$this->_true('PHPatr updated to: ' . $version);
				     	die(0);
				 } catch (Exception $e) {
					$this->_error('Downloading new version');
				     	die(1);
				 }
			}else{
				$this->_log('Your version is updated');
			     	die(0);
			}
		}

		private function _version()
		{
			echo $this->_version;
			die(0);
		}

		private function _exampleConfigJson()
		{
		 	$this->_log('Loading example file');
			$content = file_get_contents('phpatr.json');
		 	$this->_true('Loading example file');
		 	$this->_log('Save new file in: "./phpatr.json"');
			$jsonFile = str_replace($_SERVER['argv'][0], '', Phar::running(false)) . '/phpatr.json';
			$fopen = fopen($jsonFile, 'w');
			fwrite($fopen, $content);
			fclose($fopen);
		 	$this->_true('Save new file in: "./phpatr.json"');
	     		die(0);
		}
	}
}