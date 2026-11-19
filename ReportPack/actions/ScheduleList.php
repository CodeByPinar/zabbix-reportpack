<?php

namespace Modules\ReportPack\Actions;

use API,
	CController,
	CControllerResponseData,
	Modules\ReportPack\Includes\Schedule,
	Modules\ReportPack\Includes\Settings;

/**
 * List of scheduled reports.
 */
class ScheduleList extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$schedules = Settings::schedules();
		$groupids = array_unique(array_filter(array_column($schedules, 'groupid')));

		$groups = $groupids
			? array_column(API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $groupids]) ?: [],
				'name', 'groupid'
			)
			: [];

		$now = time();

		foreach ($schedules as &$schedule) {
			$schedule['group_name'] = $groups[$schedule['groupid']] ?? '?';
			$schedule['description'] = Schedule::describe($schedule);
			$schedule['next'] = $schedule['enabled'] ? Schedule::nextDue($schedule, $now) : null;
		}
		unset($schedule);

		uasort($schedules, static function (array $a, array $b): int {
			return strnatcasecmp($a['name'], $b['name']);
		});

		$response = new CControllerResponseData([
			'schedules' => $schedules,
			'module_path' => realpath(__DIR__.'/..')
		]);
		$response->setTitle(_('Scheduled reports'));
		$this->setResponse($response);
	}
}
