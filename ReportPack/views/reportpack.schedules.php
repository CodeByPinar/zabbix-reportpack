<?php

/**
 * Scheduled reports list, with the one-time setup for the runner that sends them.
 *
 * @var CView $this
 * @var array $data
 */

$html_page = (new CHtmlPage())
	->setTitle(_('Scheduled reports'))
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(new CRedirectButton(_('Create scheduled report'),
					(new CUrl('zabbix.php'))->setArgument('action', 'reportpack.schedule.edit')->getUrl()
				))
				->addItem(
					(new CRedirectButton(_('Back to reports'),
						(new CUrl('zabbix.php'))->setArgument('action', 'reportpack.view')->getUrl()
					))->addClass(ZBX_STYLE_BTN_ALT)
				)
		))->setAttribute('aria-label', _('Content controls'))
	);

$reports = ['availability' => _('Availability (SLA)'), 'top_problems' => _('Most frequent problems')];
$periods = ['last_month' => _('Last month'), 'this_month' => _('This month'), 'last_7_days' => _('Last 7 days'),
	'last_30_days' => _('Last 30 days')
];

$table = (new CTableInfo())->setHeader([
	_('Name'), _('Report'), _('Host group'), _('Period'), _('Format'), _('When'), _('Next run'), _('Recipients'),
	_('Status')
]);

foreach ($data['schedules'] as $schedule) {
	$recipients = Modules\ReportPack\Includes\Schedule::recipients($schedule['recipients']);

	$table->addRow([
		new CLink($schedule['name'],
			(new CUrl('zabbix.php'))
				->setArgument('action', 'reportpack.schedule.edit')
				->setArgument('id', $schedule['id'])
				->getUrl()
		),
		$reports[$schedule['report']] ?? $schedule['report'],
		$schedule['group_name'],
		$periods[$schedule['period']] ?? $schedule['period'],
		strtoupper($schedule['format']),
		$schedule['description'],
		$schedule['next'] !== null ? date('Y-m-d H:i', $schedule['next']) : '–',
		(new CSpan(count($recipients) > 2
			? implode(', ', array_slice($recipients, 0, 2)).' '._s('and %1$s more', count($recipients) - 2)
			: implode(', ', $recipients)
		))->setTitle(implode(', ', $recipients)),
		$schedule['enabled']
			? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED)
	]);
}

$runner = $data['module_path'].'/bin/reportpack-runner.php';

$setup = (new CDiv([
	(new CTag('h4', true, _('Sending the reports')))->addStyle('margin: 0 0 6px 0;'),
	new CDiv(_('Schedules are sent by a small command-line program that runs from cron, on this server or any machine with PHP 8 that can reach the Zabbix API and a mail server. Set it up once:')),
	(new CList([
		_('Create an API token for a Super admin user (User settings › API tokens). The runner reads the schedules with it.'),
		_s('Copy %1$s to %2$s and fill in the Zabbix address, the token and your mail server.',
			$data['module_path'].'/bin/reportpack.ini.example', '/etc/zabbix/reportpack.ini'
		),
		_s('Test it: %1$s', 'php '.$runner.' --config /etc/zabbix/reportpack.ini --list'),
		_s('Add to cron (every 15 minutes): %1$s', '*/15 * * * * php '.$runner.' --config /etc/zabbix/reportpack.ini')
	]))->addStyle('margin: 6px 0 0 18px; list-style: decimal;')
]))->addClass(ZBX_STYLE_GREY)->addStyle('margin-top: 12px;');

$html_page
	->addItem($table)
	->addItem($setup)
	->show();
