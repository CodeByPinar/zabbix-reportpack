<?php

namespace Modules\ReportPack\Actions;

use CController,
	CControllerResponseFatal,
	CControllerResponseRedirect,
	CMessageHelper,
	CUrl,
	Exception,
	Modules\ReportPack\Includes\Schedule,
	Modules\ReportPack\Includes\Settings;

/**
 * Saves a scheduled report, or deletes it when "delete" is sent.
 */
class ScheduleUpdate extends CController {

	protected function checkInput(): bool {
		$fields = array_fill_keys(ScheduleEdit::FIELDS, 'string') + [
			'id' => 'string',
			'enabled' => 'in 1',
			'delete' => 'in 1'
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
			CMessageHelper::setErrorTitle(_('The scheduled report no longer exists.'));
			$this->setResponse(new CControllerResponseRedirect(self::listUrl()));

			return;
		}

		if ($this->hasInput('delete')) {
			unset($schedules[$id]);

			try {
				Settings::saveSchedules($schedules);
				CMessageHelper::setSuccessTitle(_('Scheduled report deleted'));
			}
			catch (Exception $e) {
				CMessageHelper::setErrorTitle(_('Cannot delete scheduled report'));
				CMessageHelper::addError($e->getMessage());
			}

			$this->setResponse(new CControllerResponseRedirect(self::listUrl()));

			return;
		}

		$input = [];

		foreach (ScheduleEdit::FIELDS as $field) {
			$input[$field] = trim($this->getInput($field, ''));
		}

		$input['message'] = $this->getInput('message', '');
		$input['sla'] = str_replace(',', '.', $input['sla']);
		$input['enabled'] = $this->hasInput('enabled');

		$schedule = Schedule::normalise($input + ['id' => $id !== '' ? $id : Schedule::newId()]);
		$errors = Schedule::validate($schedule);

		if (!$errors) {
			$schedules[$schedule['id']] = $schedule;

			try {
				Settings::saveSchedules($schedules);
			}
			catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($errors) {
			CMessageHelper::setErrorTitle(_('Cannot save scheduled report'));

			foreach ($errors as $error) {
				CMessageHelper::addError($error);
			}

			$url = (new CUrl('zabbix.php'))
				->setArgument('action', 'reportpack.schedule.edit')
				->setArgument('id', $id);

			$response = new CControllerResponseRedirect($url);
			// array_merge, not +: the string form of "enabled" must replace the boolean.
			$response->setFormData(array_merge($input, ['id' => $id, 'enabled' => $input['enabled'] ? '1' : '0']));
			$this->setResponse($response);

			return;
		}

		CMessageHelper::setSuccessTitle($id !== '' ? _('Scheduled report updated') : _('Scheduled report added'));
		$this->setResponse(new CControllerResponseRedirect(self::listUrl()));
	}

	private static function listUrl(): CUrl {
		return (new CUrl('zabbix.php'))->setArgument('action', 'reportpack.schedules');
	}
}
