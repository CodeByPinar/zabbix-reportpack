<?php

namespace Modules\ReportPack\Includes;

use APP;

/**
 * Reads and writes the module configuration stored by Zabbix (Administration › Modules).
 * Writing needs a Super admin, as Zabbix allows module.update only to them.
 */
class Settings {

	public const MODULE_ID = 'reportpack';

	public static function all(): array {
		$module = APP::ModuleManager()->getModule(self::MODULE_ID);

		return $module !== null ? $module->getConfig() : [];
	}

	public static function merge(array $values): void {
		$module = APP::ModuleManager()->getModule(self::MODULE_ID);

		if ($module !== null) {
			$module->setConfig(array_merge($module->getConfig(), $values));
		}
	}

	/**
	 * @return array  id => normalised schedule
	 */
	public static function schedules(): array {
		$schedules = [];

		foreach (self::all()['schedules'] ?? [] as $schedule) {
			if (is_array($schedule) && ($schedule['id'] ?? '') !== '') {
				$schedules[$schedule['id']] = Schedule::normalise($schedule);
			}
		}

		return $schedules;
	}

	public static function saveSchedules(array $schedules): void {
		self::merge(['schedules' => array_values($schedules)]);
	}
}
