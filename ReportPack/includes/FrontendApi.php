<?php

namespace Modules\ReportPack\Includes;

use API,
	RuntimeException;

/**
 * Calls inside the Zabbix frontend.
 */
class FrontendApi extends ZabbixApi {

	private const SERVICES = [
		'hostgroup' => 'HostGroup',
		'host' => 'Host',
		'trigger' => 'Trigger',
		'event' => 'Event',
		'maintenance' => 'Maintenance',
		'image' => 'Image',
		'module' => 'Module'
	];

	public function call(string $method, array $params) {
		[$object, $action] = explode('.', $method, 2);

		if (!array_key_exists($object, self::SERVICES)) {
			throw new RuntimeException('Unsupported API method '.$method);
		}

		$service = self::SERVICES[$object];
		$result = API::$service()->$action($params);

		if ($result === false) {
			throw new RuntimeException('Zabbix API call '.$method.' failed.');
		}

		return $result;
	}
}
