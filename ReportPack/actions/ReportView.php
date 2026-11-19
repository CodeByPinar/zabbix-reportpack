<?php

namespace Modules\ReportPack\Actions;

use API,
	CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CControllerResponseRedirect,
	CMessageHelper,
	CSeverityHelper,
	CUrl,
	Modules\ReportPack\Includes\Branding,
	Modules\ReportPack\Includes\PdfReport,
	Modules\ReportPack\Includes\Period,
	Modules\ReportPack\Includes\ReportBuilder,
	Modules\ReportPack\Includes\XlsxReport,
	Throwable;

/**
 * The Report Pack page and its exports. All actions run the same report: reportpack.view shows it,
 * reportpack.csv, reportpack.pdf and reportpack.xlsx return it as a file.
 */
class ReportView extends CController {

	public const REPORT_AVAILABILITY = 'availability';
	public const REPORT_TOP_PROBLEMS = 'top_problems';

	private const DEFAULTS = [
		'report' => self::REPORT_AVAILABILITY,
		'period' => Period::THIS_MONTH,
		'from' => '',
		'to' => '',
		'min_severity' => TRIGGER_SEVERITY_AVERAGE,
		'sla' => '99.9',
		'limit' => 25,
		'prepared_for' => '',
		'maintenance' => 'exclude'
	];

	/** Form fields that make up a report; passed on to the export links. */
	public const REPORT_FIELDS = ['report', 'groupid', 'period', 'from', 'to', 'min_severity', 'sla', 'limit',
		'prepared_for', 'maintenance'
	];

	public function init(): void {
		// Read-only report opened with GET requests; nothing is changed.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'report' => 'in '.self::REPORT_AVAILABILITY.','.self::REPORT_TOP_PROBLEMS,
			'groupid' => 'id',
			'period' => 'in '.implode(',', Period::ALL),
			'from' => 'string',
			'to' => 'string',
			'min_severity' => 'in 0,1,2,3,4,5',
			'sla' => 'string',
			'limit' => 'int32',
			'prepared_for' => 'string',
			'maintenance' => 'in exclude,include',
			'show' => 'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// The API limits every report to the hosts the user can read.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$form = self::DEFAULTS;
		$this->getInputs($form, array_keys(self::DEFAULTS));
		$form['groupid'] = $this->getInput('groupid', '');
		$form['min_severity'] = (int) $form['min_severity'];
		$form['limit'] = max(1, min(1000, (int) $form['limit']));

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'selectHosts' => API_OUTPUT_COUNT,
			'with_hosts' => true,
			'sortfield' => 'name'
		]) ?: [];

		$is_csv = $this->getAction() === 'reportpack.csv';
		$document = ['reportpack.pdf' => 'pdf', 'reportpack.xlsx' => 'xlsx'][$this->getAction()] ?? null;
		$show = $is_csv || $document !== null || $this->hasInput('show');

		$data = [
			'form' => $form,
			'groups' => $groups,
			'report' => null,
			'error' => null
		];

		if ($show) {
			$data = $this->buildReport($data);
		}

		if ($document !== null) {
			$this->respondDocument($data, $document);

			return;
		}

		$data['can_brand'] = $this->getUserType() == USER_TYPE_SUPER_ADMIN;

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Report Pack'));

		if ($is_csv) {
			$response->setFileName(self::fileName($data, 'csv'));
		}

		$this->setResponse($response);
	}

	/**
	 * Sends the report as a branded PDF or Excel file, or goes back to the report page with the reason
	 * it could not.
	 */
	private function respondDocument(array $data, string $format): void {
		$error = $data['error'];
		$title = $format === 'pdf' ? _('Cannot create PDF') : _('Cannot create Excel file');

		if ($error === null) {
			$fonts = Branding::fonts();

			if ($format === 'pdf' && $fonts['regular'] === null) {
				$error = _('No font file for PDF found: the Zabbix graph font (assets/fonts/graphfont.ttf) is missing.');
			}
			else {
				try {
					$logo = Branding::logo();
					$severities = [];
					$colours = [];

					foreach (range(TRIGGER_SEVERITY_NOT_CLASSIFIED, TRIGGER_SEVERITY_COUNT - 1) as $severity) {
						$severities[$severity] = CSeverityHelper::getName($severity);
						$colours[$severity] = CSeverityHelper::getColor($severity);
					}

					$options = [
						'prepared_for' => $data['form']['prepared_for'],
						'severity_names' => $severities,
						'severity_colors' => $colours
					];

					$data['document'] = $format === 'pdf'
						? PdfReport::render($data['report'], Branding::get(), $logo !== null ? $logo['bytes'] : null,
							$fonts, $options
						)
						: XlsxReport::render($data['report'], Branding::get(), $options);
				}
				catch (Throwable $e) {
					$error = $title.': '.$e->getMessage();
				}
			}
		}

		if ($error !== null) {
			$url = (new CUrl('zabbix.php'))->setArgument('action', 'reportpack.view');

			foreach (self::REPORT_FIELDS as $field) {
				$url->setArgument($field, $data['form'][$field]);
			}

			CMessageHelper::setErrorTitle($title);
			CMessageHelper::addError($error);
			$this->setResponse(new CControllerResponseRedirect($url));

			return;
		}

		$response = new CControllerResponseData($data);
		$response->setFileName(self::fileName($data, $format));
		$this->setResponse($response);
	}

	private function buildReport(array $data): array {
		$params = $data['form'];

		foreach ($data['groups'] as $group) {
			if ($group['groupid'] === $params['groupid']) {
				$params['group_name'] = $group['name'];
			}
		}

		$result = ReportBuilder::build($params);
		$data['report'] = $result['report'];
		$data['error'] = $result['error'] !== null ? _($result['error']) : null;

		return $data;
	}

	private static function fileName(array $data, string $extension): string {
		return ReportBuilder::fileName($data['report'], $data['form']['report'], $extension);
	}
}
