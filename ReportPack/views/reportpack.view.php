<?php

/**
 * Report Pack page: the report form and, once submitted, the report with an export button.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ReportPack\Actions\ReportView,
	Modules\ReportPack\Includes\Period;

$form_values = $data['form'];
$report = $data['report'];

$html_page = (new CHtmlPage())->setTitle(_('Report Pack'));

// --- Buttons in the page header ------------------------------------------------

$controls = new CList();

if ($report !== null) {
	foreach (['reportpack.pdf' => _('Export to PDF'), 'reportpack.xlsx' => _('Export to Excel'),
			'reportpack.csv' => _('Export to CSV')] as $action => $label) {
		$export_url = (new CUrl('zabbix.php'))->setArgument('action', $action);

		foreach (ReportView::REPORT_FIELDS as $field) {
			$export_url->setArgument($field, $form_values[$field]);
		}

		$button = new CRedirectButton($label, $export_url->getUrl());

		if ($action !== 'reportpack.pdf') {
			$button->addClass(ZBX_STYLE_BTN_ALT);
		}

		$controls->addItem($button);
	}
}

if ($data['can_brand']) {
	$controls->addItem(
		(new CRedirectButton(_('Scheduled reports'),
			(new CUrl('zabbix.php'))->setArgument('action', 'reportpack.schedules')->getUrl()
		))->addClass(ZBX_STYLE_BTN_ALT)
	);
	$controls->addItem(
		(new CRedirectButton(_('Branding'),
			(new CUrl('zabbix.php'))->setArgument('action', 'reportpack.branding')->getUrl()
		))->addClass(ZBX_STYLE_BTN_ALT)
	);
}

$html_page->setControls(
	(new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls'))
);

// --- Form ----------------------------------------------------------------------

$group_options = [];
foreach ($data['groups'] as $group) {
	$group_options[$group['groupid']] = $group['name'].' ('.$group['hosts'].')';
}

$severity_options = [];
foreach (CSeverityHelper::getSeverities() as $severity) {
	$severity_options[$severity['value']] = $severity['label'];
}

$form = (new CForm('get'))
	->setId('reportpack-form')
	->setName('reportpack')
	->addVar('action', 'reportpack.view')
	->addVar('show', '1')
	->setAttribute('aria-labelledby', CHtmlPage::PAGE_TITLE_ID);

$form_list = (new CFormList())
	->addRow(
		new CLabel(_('Report'), 'label-report'),
		(new CSelect('report'))
			->setFocusableElementId('label-report')
			->setValue($form_values['report'])
			->addOptions(CSelect::createOptionsFromArray([
				ReportView::REPORT_AVAILABILITY => _('Availability by host (SLA)'),
				ReportView::REPORT_TOP_PROBLEMS => _('Most frequent problems')
			]))
			->setId('report')
	)
	->addRow(
		(new CLabel(_('Host group'), 'label-groupid'))->setAsteriskMark(),
		[
			(new CSelect('groupid'))
				->setFocusableElementId('label-groupid')
				->setValue($form_values['groupid'])
				->addOptions(CSelect::createOptionsFromArray($group_options))
				->setId('groupid'),
			(new CDiv(_('Hosts in subgroups are included. The number is the host count of the group.')))
				->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('Period'), 'label-period'),
		[
			(new CSelect('period'))
				->setFocusableElementId('label-period')
				->setValue($form_values['period'])
				->addOptions(CSelect::createOptionsFromArray([
					Period::THIS_MONTH => _('This month'),
					Period::LAST_MONTH => _('Last month'),
					Period::LAST_7_DAYS => _('Last 7 days'),
					Period::LAST_30_DAYS => _('Last 30 days'),
					Period::CUSTOM => _('Custom dates')
				]))
				->setId('period'),
			(new CDiv([
				(new CTextBox('from', $form_values['from'], false, 10))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
					->setAttribute('placeholder', 'YYYY-MM-DD'),
				' – ',
				(new CTextBox('to', $form_values['to'], false, 10))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
					->setAttribute('placeholder', 'YYYY-MM-DD'),
				' ',
				(new CSpan(_('used with "Custom dates"; both days are included')))->addClass(ZBX_STYLE_GREY)
			]))->addStyle('margin-top: 4px;')
		]
	)
	->addRow(
		new CLabel(_('Minimum severity'), 'label-min_severity'),
		[
			(new CSelect('min_severity'))
				->setFocusableElementId('label-min_severity')
				->setValue($form_values['min_severity'])
				->addOptions(CSelect::createOptionsFromArray($severity_options))
				->setId('min_severity'),
			(new CDiv(_('Only problems of this severity or higher count.')))->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('Maintenance'), 'label-maintenance'),
		[
			(new CSelect('maintenance'))
				->setFocusableElementId('label-maintenance')
				->setValue($form_values['maintenance'])
				->addOptions(CSelect::createOptionsFromArray([
					'exclude' => _('Exclude maintenance windows'),
					'include' => _('Count maintenance as normal time')
				]))
				->setId('maintenance'),
			(new CDiv(_('Excluded: time in maintenance is neither downtime nor part of the period, and problems that started in it are not counted.')))
				->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('SLA target, %'), 'sla'),
		[
			(new CTextBox('sla', $form_values['sla'], false, 7))->setWidth(ZBX_TEXTAREA_TINY_WIDTH),
			(new CDiv(_('Availability report: hosts below this value are marked as missing the target.')))
				->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('Prepared for'), 'prepared_for'),
		[
			(new CTextBox('prepared_for', $form_values['prepared_for'], false, 128))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', _('Host group name')),
			(new CDiv(_('Customer or department named on the PDF cover. Empty: the host group name.')))
				->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('Rows'), 'limit'),
		[
			(new CTextBox('limit', $form_values['limit'], false, 4))->setWidth(ZBX_TEXTAREA_TINY_WIDTH),
			(new CDiv(_('Most frequent problems: how many to list.')))->addClass(ZBX_STYLE_GREY)
		]
	);

$form->addItem(
	(new CTabView())
		->addTab('reportpack', _('Report'), $form_list)
		->setFooter(makeFormFooter(new CSubmit('submit', _('Show report'))))
);

$html_page->addItem($form);

if ($data['error'] !== null) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]],
		_('Cannot create report'), false
	));
}

// --- Report --------------------------------------------------------------------

if ($report !== null) {
	$period = $report['period'];
	$title = $report['type'] === ReportView::REPORT_AVAILABILITY
		? _('Availability by host')
		: _('Most frequent problems');

	$meta = (new CList())
		->addClass('reportpack-meta')
		->addItem([_('Host group').': ', bold($report['group'])])
		->addItem([_('Period').': ', bold($period['label']), ' (',
			date('Y-m-d H:i', $period['from']), ' – ', date('Y-m-d H:i', $period['till']), ')'
		])
		->addItem([_('Minimum severity').': ', bold(CSeverityHelper::getName($report['min_severity']))])
		->addItem([_('Generated').': ', date('Y-m-d H:i', $report['generated'])]);

	$summary = $report['summary'];

	if ($report['type'] === ReportView::REPORT_AVAILABILITY) {
		$headline = (new CDiv([
			(new CSpan(sprintf('%.3f%%', $summary['average'])))->addClass('reportpack-figure'),
			' ', _('average availability of'), ' ', bold($summary['hosts']), ' ', _('hosts'), '. ',
			bold($summary['sla_met']), ' ', _('met the SLA target of'), ' ', bold(sprintf('%s%%', $report['sla'])),
			', ',
			(new CSpan([bold($summary['sla_missed']), ' ', _('missed it')]))
				->addClass($summary['sla_missed'] > 0 ? ZBX_STYLE_RED : ZBX_STYLE_GREEN),
			'. ', _('Allowed downtime per host for this period'), ': ',
			bold($summary['allowed_downtime'] > 0 ? convertUnitsS($summary['allowed_downtime']) : '0'), '.'
		]))->addClass('reportpack-headline');

		$table = (new CTableInfo())->setHeader([
			_('Host'),
			_('Availability'),
			_('SLA'),
			_('Downtime'),
			_('Outages'),
			_('Longest outage'),
			_('Problems'),
			_('In maintenance')
		]);

		foreach ($report['rows'] as $row) {
			$table->addRow([
				$row['host'],
				(new CSpan(sprintf('%.3f%%', $row['availability'])))
					->addClass($row['sla_met'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
				$row['sla_met'] ? _('Met') : (new CSpan(_('Missed')))->addClass(ZBX_STYLE_RED),
				$row['downtime'] > 0 ? convertUnitsS($row['downtime']) : '–',
				$row['outages'],
				$row['longest'] > 0 ? convertUnitsS($row['longest']) : '–',
				$row['problems'],
				$row['maintenance'] > 0 ? convertUnitsS($row['maintenance']) : '–'
			]);
		}

		$note = _('Availability is the share of the period in which the host had no problem of the minimum severity or higher. Overlapping problems on one host are counted once.')
			.' '.($summary['exclude_maintenance']
				? _('Maintenance windows are excluded from both the downtime and the period.')
				: _('Maintenance windows are counted as normal time.'));
	}
	else {
		$headline = (new CDiv([
			(new CSpan($summary['problems']))->addClass('reportpack-figure'),
			' ', _('problems on'), ' ', bold($summary['with_problems']), ' ', _('triggers'), '. ',
			_('Showing the top'), ' ', bold($summary['shown']), '.'
		]))->addClass('reportpack-headline');

		$table = (new CTableInfo())->setHeader([
			_('Host'),
			_('Problem'),
			_('Severity'),
			_('Times'),
			_('Total duration'),
			_('Last started')
		]);

		foreach ($report['rows'] as $row) {
			$table->addRow([
				$row['host'],
				$row['problem'],
				CSeverityHelper::makeSeverityCell($row['severity']),
				$row['problems'],
				$row['duration'] > 0 ? convertUnitsS($row['duration']) : '–',
				$row['last'] !== null ? date('Y-m-d H:i', $row['last']) : '–'
			]);
		}

		$note = _('Times counts how often the problem started in the period. Total duration is the time the trigger spent in problem state within the period.')
			.' '.($summary['exclude_maintenance']
				? _('Problems and problem time inside maintenance windows are left out.')
				: _('Maintenance windows are counted as normal time.'));
	}

	$html_page->addItem(
		(new CDiv([
			(new CTag('h4', true, $title))->addClass('reportpack-title'),
			$meta,
			$headline,
			$table,
			(new CDiv($note))->addClass(ZBX_STYLE_GREY)->addClass('reportpack-note')
		]))->addClass('reportpack-report')
	);
}

$html_page->show();

?>
<style>
	/* No colours of its own, so the report follows the user's Zabbix theme, dark ones included. */
	.reportpack-report { margin-top: 10px; }
	.reportpack-title { font-size: 16px; margin: 0 0 8px 0; }
	.reportpack-meta { list-style: none; margin: 0 0 10px 0; padding: 0; }
	.reportpack-meta li { display: inline-block; margin-right: 18px; }
	.reportpack-headline { margin: 6px 0 12px 0; font-size: 13px; }
	.reportpack-figure { font-size: 20px; font-weight: bold; margin-right: 4px; }
	.reportpack-note { margin-top: 8px; }
</style>
