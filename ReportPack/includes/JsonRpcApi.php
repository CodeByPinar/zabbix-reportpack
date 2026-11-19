<?php

namespace Modules\ReportPack\Includes;

use RuntimeException;

/**
 * JSON-RPC over HTTP, for running reports outside the frontend.
 */
class JsonRpcApi extends ZabbixApi {

	private string $url;
	private string $token;
	private bool $verify_tls;
	private int $id = 0;

	/**
	 * @param string $url  Frontend address, for example https://zabbix.example.com/ (api_jsonrpc.php is added).
	 */
	public function __construct(string $url, string $token, bool $verify_tls = true) {
		$url = rtrim($url, '/');

		if (substr($url, -16) !== '/api_jsonrpc.php') {
			$url .= '/api_jsonrpc.php';
		}

		$this->url = $url;
		$this->token = $token;
		$this->verify_tls = $verify_tls;
	}

	public function call(string $method, array $params) {
		$body = json_encode([
			'jsonrpc' => '2.0',
			'method' => $method,
			'params' => $params === [] ? new \stdClass() : $params,
			'id' => ++$this->id
		]);

		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer ".$this->token."\r\n",
				'content' => $body,
				'timeout' => 60,
				'ignore_errors' => true
			],
			'ssl' => [
				'verify_peer' => $this->verify_tls,
				'verify_peer_name' => $this->verify_tls
			]
		]);

		$response = @file_get_contents($this->url, false, $context);

		if ($response === false) {
			throw new RuntimeException('Cannot reach the Zabbix API at '.$this->url.'.');
		}

		$decoded = json_decode($response, true);

		if (!is_array($decoded)) {
			throw new RuntimeException('The Zabbix API returned an unreadable answer for '.$method.'.');
		}

		if (array_key_exists('error', $decoded)) {
			throw new RuntimeException('Zabbix API '.$method.': '.($decoded['error']['data'] ?? $decoded['error']['message'] ?? 'error'));
		}

		return $decoded['result'];
	}
}
