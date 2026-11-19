<?php

namespace Modules\ReportPack\Includes;

/**
 * Builds a report from its settings. Used by the report page and by the scheduled-report runner, so
 * both produce the same numbers.
 */
class ReportBuilder {

	public const AVAILABILITY = 'availability';
	public const TOP_PROBLEMS = 'top_problems';

	/**
	 * @param array    $params  report, groupid, period, from, to, min_severity, sla, limit, maintenance;
	 *                          'group_name' may be passed when already known.
	 * @param int|null $now     Moment the period is counted back from.
	 *
	 * @return array  ['report' => array|null, 'error' => string|null]
	 */
	public static function build(array $params, ?int $now = null): array {
		$params += ['report' => self::AVAILABILITY, 'groupid' => '', 'period' => Period::THIS_MONTH, 'from' => '',
			'to' => '', 'min_severity' => 3, 'sla' => '99.9', 'limit' => 25, 'maintenance' => 'exclude'
		];

		if ((string) $params['groupid'] === '') {
			return self::error('Select a host group.');
		}

		$sla = str_replace(',', '.', trim((string) $params['sla']));

		if ($params['report'] === self::AVAILABILITY && (!is_numeric($sla) || (float) $sla < 0 || (float) $sla > 100)) {
			return self::error('SLA target must be a number from 0 to 100.');
		}

		$period = Period::resolve($params['period'], $params['from'], $params['to'], $now);

		if ($period === null) {
			return self::error('Enter a valid custom period: dates as YYYY-MM-DD, the end not before the start and not in the future.');
		}

		$hosts = ReportData::hosts((string) $params['groupid']);

		if (!$hosts) {
			return self::error('The selected host group has no enabled hosts that you can see.');
		}

		$exclude_maintenance = $params['maintenance'] !== 'include';
		$min_severity = (int) $params['min_severity'];
		$limit = max(1, min(1000, (int) $params['limit']));

		$report = $params['report'] === self::AVAILABILITY
			? ReportData::availability($hosts, $period, $min_severity, (float) $sla, $exclude_maintenance)
			: ReportData::topProblems($hosts, $period, $min_severity, $limit, $exclude_maintenance);

		return [
			'report' => $report + [
				'type' => $params['report'],
				'group' => $params['group_name'] ?? ReportData::groupName((string) $params['groupid']),
				'period' => $period,
				'sla' => (float) $sla,
				'min_severity' => $min_severity,
				'generated' => $now ?? time()
			],
			'error' => null
		];
	}

	/**
	 * File name for a report, such as availability_Customers_Acme_2026-10-01.pdf.
	 */
	public static function fileName(?array $report, string $type, string $extension): string {
		$name = $type === self::AVAILABILITY ? 'availability' : 'top_problems';

		if ($report !== null) {
			$group = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $report['group']), '_');
			$name .= ($group !== '' ? '_'.$group : '').'_'.date('Y-m-d', $report['period']['from']);
		}

		return $name.'.'.$extension;
	}

	private static function error(string $message): array {
		return ['report' => null, 'error' => $message];
	}
}
