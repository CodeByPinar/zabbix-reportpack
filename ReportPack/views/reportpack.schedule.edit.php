<?php

/**
 * Create or edit a scheduled report.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ReportPack\Includes\Schedule;

$schedule = $data['schedule'];
$is_new = $schedule['id'] === '';

$html_page = (new CHtmlPage())->setTitle($is_new ? _('New scheduled report') : _('Scheduled report'));

$group_options = [];
foreach ($data['groups'] as $group) {
	$group_options[$group['groupid']] = $group['name'].' ('.$group['hosts'].')';
}

$severity_options = [];
foreach (CSeverityHelper::getSeverities() as $severity) {
	$severity_options[$severity['value']] = $severity['label'];
}

$hours = [];
for ($h = 0; $h < 24; $h++) {
	$hours[$h] = sprintf('%02d:00', $h);
}

$monthdays = [];
for ($d = 1; $d <= 28; $d++) {
	$monthdays[$d] = (string) $d;
}

$select = static function (string $name, $value, array $options): CSelect {
	return (new CSelect($name))
		->setFocusableElementId('label-'.$name)
		->setValue($value)
		->addOptions(CSelect::createOptionsFromArray($options))
		->setId($name);
};

$hint = static function (string $text): CDiv {
	return (new CDiv($text))->addClass(ZBX_STYLE_GREY);
};

$form = (new CForm('post'))
	->setId('reportpack-schedule')
	->setName('reportpack_schedule')
	->setAction((new CUrl('zabbix.php'))->setArgument('action', 'reportpack.schedule.update')->getUrl())
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('reportpack.schedule.update')))->removeId())
	->addVar('id', $schedule['id'])
	->setAttribute('aria-labelledby', CHtmlPage::PAGE_TITLE_ID);

$report_list = (new CFormList())
	->addRow((new CLabel(_('Name'), 'name'))->setAsteriskMark(),
		(new CTextBox('name', $schedule['name'], false, 128))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('autofocus', 'autofocus')
			->setAttribute('placeholder', _('Monthly SLA report – Acme'))
	)
	->addRow(new CLabel(_('Enabled'), 'enabled'), (new CCheckBox('enabled'))->setChecked($schedule['enabled']))
	->addRow(new CLabel(_('Report'), 'label-report'), $select('report', $schedule['report'], [
		'availability' => _('Availability by host (SLA)'),
		'top_problems' => _('Most frequent problems')
	]))
	->addRow((new CLabel(_('Host group'), 'label-groupid'))->setAsteriskMark(),
		[$select('groupid', $schedule['groupid'], $group_options), $hint(_('Hosts in subgroups are included.'))]
	)
	->addRow(new CLabel(_('Period'), 'label-period'), [
		$select('period', $schedule['period'], [
			'last_month' => _('Last month'),
			'this_month' => _('This month so far'),
			'last_7_days' => _('Last 7 days'),
			'last_30_days' => _('Last 30 days')
		]),
		$hint(_('Counted back from the moment the report is sent. A monthly report on day 1 with "Last month" covers the whole previous month.'))
	])
	->addRow(new CLabel(_('Minimum severity'), 'label-min_severity'),
		$select('min_severity', $schedule['min_severity'], $severity_options)
	)
	->addRow(new CLabel(_('Maintenance'), 'label-maintenance'), $select('maintenance', $schedule['maintenance'], [
		'exclude' => _('Exclude maintenance windows'),
		'include' => _('Count maintenance as normal time')
	]))
	->addRow(new CLabel(_('SLA target, %'), 'sla'),
		(new CTextBox('sla', $schedule['sla'], false, 7))->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
	)
	->addRow(new CLabel(_('Rows'), 'limit'), [
		(new CTextBox('limit', $schedule['limit'], false, 4))->setWidth(ZBX_TEXTAREA_TINY_WIDTH),
		$hint(_('Most frequent problems: how many to list.'))
	])
	->addRow(new CLabel(_('Prepared for'), 'prepared_for'), [
		(new CTextBox('prepared_for', $schedule['prepared_for'], false, 128))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', _('Host group name')),
		$hint(_('Customer or department named on the report.'))
	])
	->addRow(new CLabel(_('File format'), 'label-format'), $select('format', $schedule['format'], [
		'pdf' => _('PDF (branded)'),
		'xlsx' => _('Excel (.xlsx)'),
		'csv' => _('CSV')
	]));

$delivery_list = (new CFormList())
	->addRow(new CLabel(_('Frequency'), 'label-frequency'), $select('frequency', $schedule['frequency'], [
		'daily' => _('Daily'),
		'weekly' => _('Weekly'),
		'monthly' => _('Monthly')
	]))
	->addRow(new CLabel(_('Weekday'), 'label-weekday'), [
		$select('weekday', $schedule['weekday'], [1 => _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'),
			_('Friday'), _('Saturday'), _('Sunday')
		]),
		$hint(_('Weekly reports only.'))
	])
	->addRow(new CLabel(_('Day of month'), 'label-monthday'), [
		$select('monthday', $schedule['monthday'], $monthdays),
		$hint(_('Monthly reports only.'))
	])
	->addRow(new CLabel(_('Time'), 'label-hour'), [
		$select('hour', $schedule['hour'], $hours),
		$hint(_('In the time zone of the machine that runs the sender.'))
	])
	->addRow((new CLabel(_('Recipients'), 'recipients'))->setAsteriskMark(), [
		(new CTextArea('recipients', $schedule['recipients']))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setRows(3)
			->setAttribute('placeholder', 'it-manager@example.com, customer@example.com'),
		$hint(_('E-mail addresses separated by commas or new lines.'))
	])
	->addRow(new CLabel(_('Subject'), 'subject'), [
		(new CTextBox('subject', $schedule['subject'], false, 255))->setWidth(ZBX_TEXTAREA_BIG_WIDTH),
		$hint(_('Placeholders: {report}, {prepared_for}, {period}, {group}, {from}, {till}.'))
	])
	->addRow(new CLabel(_('Message'), 'message'),
		(new CTextArea('message', $schedule['message']))->setWidth(ZBX_TEXTAREA_BIG_WIDTH)->setRows(6)
	);

$buttons = [new CSubmit('save', $is_new ? _('Add') : _('Update'))];

if (!$is_new) {
	$buttons[] = (new CSubmit('delete', _('Delete')))
		->setAttribute('value', '1')
		->setAttribute('formnovalidate', 'formnovalidate')
		->onClick('return confirm('.json_encode(_('Delete this scheduled report?')).');');
}

$buttons[] = new CRedirectButton(_('Cancel'),
	(new CUrl('zabbix.php'))->setArgument('action', 'reportpack.schedules')->getUrl()
);

$form->addItem(
	(new CTabView())
		->addTab('report', _('Report'), $report_list)
		->addTab('delivery', _('Delivery'), $delivery_list)
		->setFooter(makeFormFooter(array_shift($buttons), $buttons))
);

$html_page->addItem($form)->show();
