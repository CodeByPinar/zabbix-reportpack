<?php

namespace Modules\ReportPack\Includes;

/**
 * How report code talks to Zabbix. Inside the frontend the calls go straight to Zabbix's API classes
 * (with the signed-in user's permissions); the scheduled-report runner sends them as JSON-RPC requests
 * with an API token.
 */
abstract class ZabbixApi {

	private static ?ZabbixApi $current = null;

	/**
	 * @param string $method  For example "host.get".
	 */
	abstract public function call(string $method, array $params);

	public static function use(ZabbixApi $api): void {
		self::$current = $api;
	}

	public static function current(): ZabbixApi {
		if (self::$current === null) {
			self::$current = new FrontendApi();
		}

		return self::$current;
	}
}
