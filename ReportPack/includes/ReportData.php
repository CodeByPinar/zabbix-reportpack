<?php

namespace Modules\ReportPack\Includes;

use DateTimeImmutable,
	DateTimeZone;

/**
 * Collects report data through the Zabbix API, so every user sees only the hosts they have access to.
 *
 * Talks to Zabbix through ZabbixApi, so the same code runs inside the frontend and in the
 * scheduled-report runner. Zabbix constants are spelled out here for the same reason.
 */
class ReportData {

	private const EVENT_SOURCE_TRIGGERS = 0;
	private const EVENT_OBJECT_TRIGGER = 0;
	private const TRIGGER_VALUE_TRUE = 1;
	private const TRIGGER_STATUS_ENABLED = 0;
	private const HOST_STATUS_MONITORED = 0;

	/**
	 * Availability per host: share of the period with no problem of the chosen severity or higher.
	 *
	 * With $exclude_maintenance, time in maintenance windows counts neither as downtime nor as part of
	 * the period, and problems that started inside a window are not counted.
	 *
	 * @return array  ['rows' => [...], 'summary' => [...], 'daily' => [...]]
	 */
	public static function availability(array $hosts, array $period, int $min_severity, float $sla,
			bool $exclude_maintenance = true): array {
		$triggers = self::triggers(array_keys($hosts), $min_severity);
		$per_trigger = self::triggerIntervals($triggers, $period);
		$maintenance = $exclude_maintenance ? self::maintenanceWindows($hosts, $period) : [];

		$per_host = [];

		foreach ($hosts as $hostid => $host) {
			$per_host[$hostid] = ['intervals' => [], 'starts' => []];
		}

		foreach ($triggers as $triggerid => $trigger) {
			foreach ($trigger['hosts'] as $trigger_host) {
				if (!array_key_exists($trigger_host['hostid'], $per_host)) {
					continue;
				}

				$entry = &$per_host[$trigger_host['hostid']];
				$entry['intervals'] = array_merge($entry['intervals'], $per_trigger[$triggerid]['intervals']);
				$entry['starts'] = array_merge($entry['starts'], $per_trigger[$triggerid]['starts']);
				unset($entry);
			}
		}

		$rows = [];
		$down_by_host = [];

		foreach ($per_host as $hostid => $entry) {
			$windows = $maintenance[$hostid] ?? [];
			$down = Availability::subtract($entry['intervals'], $windows);
			$in_maintenance = Availability::total(Availability::subtract($windows, []));
			$seconds = $period['till'] - $period['from'] - $in_maintenance;
			$downtime = Availability::total($down);
			$percent = Availability::percent($seconds, $downtime);

			$problems = 0;

			foreach ($entry['starts'] as $start) {
				if (!Availability::contains($windows, $start)) {
					$problems++;
				}
			}

			$down_by_host[$hostid] = $down;

			$rows[] = [
				'hostid' => $hostid,
				'host' => $hosts[$hostid]['name'],
				'availability' => $percent,
				'downtime' => $downtime,
				'outages' => count($down),
				'longest' => Availability::longest($down),
				'problems' => $problems,
				'maintenance' => $in_maintenance,
				'sla_met' => Availability::meets($percent, $sla)
			];
		}

		// Worst first: that is what a reader looks for.
		usort($rows, static function (array $a, array $b): int {
			return $a['availability'] <=> $b['availability'] ?: strnatcasecmp($a['host'], $b['host']);
		});

		$count = count($rows);
		$met = count(array_filter($rows, static function (array $row): bool {
			return $row['sla_met'];
		}));

		return [
			'rows' => $rows,
			'daily' => self::daily($period, $down_by_host, $maintenance),
			'summary' => [
				'hosts' => $count,
				'triggers' => count($triggers),
				'sla_met' => $met,
				'sla_missed' => $count - $met,
				'average' => $count > 0
					? floor(array_sum(array_column($rows, 'availability')) / $count * 1000) / 1000
					: 100.0,
				'downtime' => array_sum(array_column($rows, 'downtime')),
				'maintenance' => array_sum(array_column($rows, 'maintenance')),
				'allowed_downtime' => Availability::allowedDowntime($period['till'] - $period['from'], $sla),
				'exclude_maintenance' => $exclude_maintenance
			]
		];
	}

	/**
	 * Problems that occurred most often in the period, with their total duration.
	 */
	public static function topProblems(array $hosts, array $period, int $min_severity, int $limit,
			bool $exclude_maintenance = true): array {
		$triggers = self::triggers(array_keys($hosts), $min_severity);
		$per_trigger = self::triggerIntervals($triggers, $period);
		$maintenance = $exclude_maintenance ? self::maintenanceWindows($hosts, $period) : [];

		$rows = [];

		foreach ($triggers as $triggerid => $trigger) {
			$entry = $per_trigger[$triggerid];

			// A problem on several hosts is set aside only for time all of them were in maintenance.
			$windows = null;

			foreach ($trigger['hosts'] as $trigger_host) {
				$host_windows = $maintenance[$trigger_host['hostid']] ?? [];
				$windows = $windows === null ? $host_windows : self::intersect($windows, $host_windows);
			}

			$windows = $windows ?? [];
			$intervals = Availability::subtract($entry['intervals'], $windows);
			$starts = array_values(array_filter($entry['starts'], static function (int $start) use ($windows): bool {
				return !Availability::contains($windows, $start);
			}));

			if (!$starts && !$intervals) {
				continue;
			}

			$rows[] = [
				'triggerid' => $triggerid,
				'host' => implode(', ', array_column($trigger['hosts'], 'name')),
				'problem' => $trigger['description'],
				'severity' => (int) $trigger['priority'],
				'problems' => count($starts),
				'duration' => Availability::total($intervals),
				'last' => $starts ? max($starts) : null
			];
		}

		usort($rows, static function (array $a, array $b): int {
			return $b['problems'] <=> $a['problems'] ?: $b['duration'] <=> $a['duration'];
		});

		$total = count($rows);

		return [
			'rows' => array_slice($rows, 0, $limit),
			'summary' => [
				'triggers' => count($triggers),
				'with_problems' => $total,
				'problems' => array_sum(array_column($rows, 'problems')),
				'shown' => min($limit, $total),
				'exclude_maintenance' => $exclude_maintenance
			]
		];
	}

	/**
	 * Enabled hosts of a host group and all of its subgroups, keyed by host ID, with their groups.
	 */
	public static function hosts(string $groupid): array {
		$api = ZabbixApi::current();

		$groups = $api->call('hostgroup.get', [
			'output' => ['groupid', 'name'],
			'groupids' => [$groupid]
		]);

		if (!$groups) {
			return [];
		}

		$groupids = [$groupid];

		$subgroups = $api->call('hostgroup.get', [
			'output' => ['groupid'],
			'search' => ['name' => $groups[0]['name'].'/'],
			'startSearch' => true
		]) ?: [];

		foreach ($subgroups as $subgroup) {
			$groupids[] = $subgroup['groupid'];
		}

		$hosts = $api->call('host.get', [
			'output' => ['hostid', 'name'],
			'selectHostGroups' => ['groupid'],
			'groupids' => $groupids,
			'filter' => ['status' => self::HOST_STATUS_MONITORED]
		]) ?: [];

		return array_column($hosts, null, 'hostid');
	}

	public static function groupName(string $groupid): string {
		$groups = ZabbixApi::current()->call('hostgroup.get', [
			'output' => ['name'],
			'groupids' => [$groupid]
		]);

		return $groups ? $groups[0]['name'] : '';
	}

	/**
	 * Average availability of the hosts for each calendar day of the period.
	 *
	 * @return array  [['day' => midnight timestamp, 'availability' => float, 'downtime' => int], ...]
	 */
	private static function daily(array $period, array $down_by_host, array $maintenance): array {
		$zone = new DateTimeZone(date_default_timezone_get());
		$day = (new DateTimeImmutable('@'.$period['from']))->setTimezone($zone)->setTime(0, 0);
		$days = [];

		// Long custom periods are summarised by day only up to a year.
		for ($n = 0; $day->getTimestamp() < $period['till'] && $n < 366; $n++) {
			$next = $day->modify('+1 day');
			$start = max($day->getTimestamp(), $period['from']);
			$end = min($next->getTimestamp(), $period['till']);
			$sum = 0.0;
			$hosts = 0;
			$downtime = 0;

			foreach ($down_by_host as $hostid => $down) {
				$windows = $maintenance[$hostid] ?? [];
				$seconds = $end - $start - Availability::total(self::intersect($windows, [[$start, $end]]));

				if ($seconds <= 0) {
					continue;
				}

				$host_down = Availability::total(self::intersect($down, [[$start, $end]]));
				$downtime += $host_down;
				$sum += Availability::percent($seconds, $host_down);
				$hosts++;
			}

			$days[] = [
				'day' => $day->getTimestamp(),
				'availability' => $hosts > 0 ? floor($sum / $hosts * 1000) / 1000 : 100.0,
				'downtime' => $downtime
			];

			$day = $next;
		}

		return $days;
	}

	/**
	 * Maintenance windows of each host inside the period, merged.
	 *
	 * @return array  hostid => [[start, end], ...]
	 */
	private static function maintenanceWindows(array $hosts, array $period): array {
		if (!$hosts) {
			return [];
		}

		$maintenances = ZabbixApi::current()->call('maintenance.get', [
			'output' => ['maintenanceid', 'name', 'active_since', 'active_till'],
			'selectTimeperiods' => 'extend',
			'selectHosts' => ['hostid'],
			'selectHostGroups' => ['groupid'],
			'hostids' => array_keys($hosts)
		]) ?: [];

		$result = [];

		foreach ($maintenances as $maintenance) {
			$windows = Maintenance::windows($maintenance, $period['from'], $period['till']);

			if (!$windows) {
				continue;
			}

			$maintenance_hosts = array_column($maintenance['hosts'] ?? [], 'hostid');
			$maintenance_groups = array_column($maintenance['hostgroups'] ?? [], 'groupid');

			foreach ($hosts as $hostid => $host) {
				$in_group = array_intersect(array_column($host['hostgroups'] ?? [], 'groupid'), $maintenance_groups);

				if (in_array($hostid, $maintenance_hosts) || $in_group) {
					$result[$hostid] = Availability::union(array_merge($result[$hostid] ?? [], $windows));
				}
			}
		}

		return $result;
	}

	private static function triggers(array $hostids, int $min_severity): array {
		if (!$hostids) {
			return [];
		}

		$triggers = ZabbixApi::current()->call('trigger.get', [
			'output' => ['triggerid', 'description', 'priority', 'value', 'lastchange'],
			'selectHosts' => ['hostid', 'name'],
			'hostids' => $hostids,
			'min_severity' => $min_severity,
			'filter' => ['status' => self::TRIGGER_STATUS_ENABLED],
			'expandDescription' => true
		]) ?: [];

		return array_column($triggers, null, 'triggerid');
	}

	/**
	 * Problem intervals of every trigger inside the period.
	 *
	 * @return array  triggerid => ['intervals' => [...], 'problems' => int, 'starts' => [...]]
	 */
	private static function triggerIntervals(array $triggers, array $period): array {
		$result = [];

		if (!$triggers) {
			return $result;
		}

		$events_by_trigger = array_fill_keys(array_keys($triggers), []);

		$events = ZabbixApi::current()->call('event.get', [
			'output' => ['eventid', 'objectid', 'clock', 'value'],
			'source' => self::EVENT_SOURCE_TRIGGERS,
			'object' => self::EVENT_OBJECT_TRIGGER,
			'objectids' => array_keys($triggers),
			'time_from' => $period['from'],
			'time_till' => $period['till'],
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => 'ASC'
		]) ?: [];

		foreach ($events as $event) {
			$events_by_trigger[$event['objectid']][] = $event;
		}

		foreach ($triggers as $triggerid => $trigger) {
			$result[$triggerid] = Availability::triggerIntervals($period['from'], $period['till'],
				self::problemAtStart($trigger, $period['from']), $events_by_trigger[$triggerid]
			);
		}

		return $result;
	}

	/**
	 * Whether a trigger was in problem state when the period began.
	 *
	 * A trigger that has not changed state since before the period still has that state, so only
	 * triggers that changed during or after the period need a look at their event history.
	 */
	private static function problemAtStart(array $trigger, int $from): bool {
		if ((int) $trigger['lastchange'] < $from) {
			return (int) $trigger['value'] === self::TRIGGER_VALUE_TRUE;
		}

		$last = ZabbixApi::current()->call('event.get', [
			'output' => ['value'],
			'source' => self::EVENT_SOURCE_TRIGGERS,
			'object' => self::EVENT_OBJECT_TRIGGER,
			'objectids' => [$trigger['triggerid']],
			'time_till' => $from - 1,
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => 'DESC',
			'limit' => 1
		]);

		return $last && (int) $last[0]['value'] === self::TRIGGER_VALUE_TRUE;
	}

	/**
	 * Time covered by both interval lists.
	 */
	private static function intersect(array $a, array $b): array {
		$result = [];

		foreach (Availability::union($a) as [$a_start, $a_end]) {
			foreach (Availability::union($b) as [$b_start, $b_end]) {
				$start = max($a_start, $b_start);
				$end = min($a_end, $b_end);

				if ($end > $start) {
					$result[] = [$start, $end];
				}
			}
		}

		return Availability::union($result);
	}
}
