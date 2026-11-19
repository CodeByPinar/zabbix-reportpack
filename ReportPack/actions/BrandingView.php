<?php

namespace Modules\ReportPack\Actions;

use CController,
	CControllerResponseData,
	CControllerResponseFatal,
	Modules\ReportPack\Includes\Branding;

/**
 * Branding settings for PDF reports: company name, accent colour, footer text and logo.
 */
class BrandingView extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// Sent back by the update action when a value was refused, so the user does not retype it.
		$ret = $this->validateInput([
			'company' => 'string',
			'accent' => 'string',
			'footer' => 'string'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// Saving uses module.update and image.create, which Zabbix allows to Super admins only.
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$branding = Branding::get();
		$this->getInputs($branding, ['company', 'accent', 'footer']);

		$logo = Branding::logo();

		$response = new CControllerResponseData([
			'branding' => $branding,
			'logo_id' => $logo !== null ? $logo['imageid'] : null,
			'fonts' => Branding::fonts(),
			'max_upload' => min(1048576, \CUploadFile::getMaxUploadSize())
		]);
		$response->setTitle(_('Report Pack branding'));
		$this->setResponse($response);
	}
}
