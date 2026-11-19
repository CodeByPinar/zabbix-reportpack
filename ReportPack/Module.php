<?php

namespace Modules\ReportPack;

use Zabbix\Core\CModule,
	APP,
	CMenuItem;

class Module extends CModule {

	/**
	 * Adds "Report Pack" to the Reports menu, right below the built-in Availability report.
	 */
	public function init(): void {
		$reports = APP::Component()->get('menu.main')->findOrAdd(_('Reports'))->getSubmenu();
		$item = (new CMenuItem(_('Report Pack')))
			->setAction('reportpack.view')
			// Keeps the menu entry highlighted on the module's other pages.
			->setAliases(['reportpack.schedules', 'reportpack.schedule.edit', 'reportpack.branding']);

		if ($reports->find(_('Availability report')) !== null) {
			$reports->insertAfter(_('Availability report'), $item);
		}
		else {
			$reports->add($item);
		}
	}
}
