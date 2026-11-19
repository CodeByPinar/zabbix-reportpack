<?php

/**
 * Report Pack CSV export.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\ReportPack\Includes\CsvReport;

if ($data['report'] === null) {
	echo "\xEF\xBB\xBF".'"error","'.str_replace('"', '""', $data['error'] ?? _('No report.')).'"'."\r\n";

	return;
}

$names = [];

foreach (range(TRIGGER_SEVERITY_NOT_CLASSIFIED, TRIGGER_SEVERITY_COUNT - 1) as $severity) {
	$names[$severity] = CSeverityHelper::getName($severity);
}

echo CsvReport::render($data['report'], ['severity_names' => $names]);
