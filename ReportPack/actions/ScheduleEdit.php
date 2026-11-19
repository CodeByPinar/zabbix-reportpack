<?php

namespace Modules\ReportPack\Actions;

use API,
	CController,
	CControllerResponseData,
	CControllerResponseFatal,
	Modules\ReportPack\Includes\Schedule,
	Modules\ReportPack\Includes\Settings;

/**
 * Form for creating or changing a scheduled report.
 */
class ScheduleEdit extends CController {

	public const FIELDS = ['name', 'report', 'groupid', 'period', 'min_severity', 'sla', 'limit', 'prepared_for',
		'maintenance', 'format', 'frequency', 'weekday', 'monthday', 'hour', 'recipients', 'subject', 'message'
	];

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = array_fill_keys(self::FIELDS, 'string') + [
			'id' => 'string',
			'enabled' => 'in 0,1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$id = $this->getInput('id', '');
		$schedules = Settings::schedules();

		if ($id !== '' && !array_key_exists($id, $schedules)) {
			$this->setResponse(new CControllerResponseFatal());

			return;
		}

		$schedule = $id !== '' ? $schedules[$id] : Schedule::DEFAULTS;

		// Values sent back after a refused save win over the stored ones.
		foreach (self::FIELDS as $field) {
			if ($this->hasInput($field)) {
				$schedule[$field] = $this->getInput($field);
			}
		}

		if ($this->hasInput('enabled')) {
			$schedule['enabled'] = $this->getInput('enabled') === '1';
		}

		$schedule['id'] = $id;

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'selectHosts' => API_OUTPUT_COUNT,
			'with_hosts' => true,
			'sortfield' => 'name'
		]) ?: [];

		$response = new CControllerResponseData([
			'schedule' => $schedule,
			'groups' => $groups
		]);
		$response->setTitle($id !== '' ? _('Scheduled report') : _('New scheduled report'));
		$this->setResponse($response);
	}
}
