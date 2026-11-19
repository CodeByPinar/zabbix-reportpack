<?php

namespace Modules\ReportPack\Actions;

use API,
	CController,
	CControllerResponseFatal,
	CControllerResponseRedirect,
	CMessageHelper,
	CUploadFile,
	CUrl,
	Exception,
	Modules\ReportPack\Includes\Branding;

/**
 * Saves the branding settings and the logo.
 */
class BrandingUpdate extends CController {

	private const MAX_LOGO_BYTES = 1048576;
	private const MAX_LOGO_PIXELS = 800;

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'company' => 'string',
			'accent' => 'string',
			'footer' => 'string',
			'remove_logo' => 'in 1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$branding = [
			'company' => trim($this->getInput('company', '')),
			'accent' => strtoupper(trim($this->getInput('accent', Branding::DEFAULT_ACCENT))),
			'footer' => trim($this->getInput('footer', ''))
		];

		if ($branding['accent'] !== '' && $branding['accent'][0] !== '#') {
			$branding['accent'] = '#'.$branding['accent'];
		}

		$errors = [];

		if (mb_strlen($branding['company']) > 128) {
			$errors[] = _('Company name is limited to 128 characters.');
		}

		if (mb_strlen($branding['footer']) > 255) {
			$errors[] = _('Footer text is limited to 255 characters.');
		}

		if (!Branding::isColour($branding['accent'])) {
			$errors[] = _('Accent colour must be a hex colour such as #0B3A5B.');
		}

		$logo_png = null;

		if (!$errors && isset($_FILES['logo']) && $_FILES['logo']['error'] != UPLOAD_ERR_NO_FILE) {
			try {
				$logo_png = self::preparedLogo((new CUploadFile($_FILES['logo']))->getContent());
			}
			catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($errors) {
			CMessageHelper::setErrorTitle(_('Cannot save branding'));

			foreach ($errors as $error) {
				CMessageHelper::addError($error);
			}

			$this->redirect($branding);

			return;
		}

		try {
			Branding::save($branding);
			$this->saveLogo($logo_png, $this->hasInput('remove_logo'));
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('Cannot save branding'));
			CMessageHelper::addError($e->getMessage());
			$this->redirect($branding);

			return;
		}

		CMessageHelper::setSuccessTitle(_('Branding saved'));
		$this->redirect();
	}

	/**
	 * Checks an uploaded image and returns it as PNG, scaled down if it is very large.
	 *
	 * @throws Exception
	 */
	private static function preparedLogo(string $bytes): string {
		if (strlen($bytes) > self::MAX_LOGO_BYTES) {
			throw new Exception(_('The logo file is larger than 1 MB.'));
		}

		$image = @imagecreatefromstring($bytes);

		if ($image === false) {
			throw new Exception(_('The logo must be a PNG, JPEG or GIF image.'));
		}

		$width = imagesx($image);
		$height = imagesy($image);
		$scale = min(1, self::MAX_LOGO_PIXELS / max($width, $height));

		if ($scale < 1) {
			$resized = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
			imagealphablending($resized, false);
			imagesavealpha($resized, true);
			imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
			imagedestroy($image);
			$image = $resized;
		}
		else {
			imagesavealpha($image, true);
		}

		ob_start();
		imagepng($image, null, 9);
		$png = ob_get_clean();
		imagedestroy($image);

		return $png;
	}

	/**
	 * Creates, replaces or removes the logo image.
	 *
	 * @throws Exception
	 */
	private function saveLogo(?string $png, bool $remove): void {
		$current = API::Image()->get([
			'output' => ['imageid'],
			'filter' => ['name' => Branding::LOGO_NAME]
		]) ?: [];

		if ($png !== null) {
			$result = $current
				? API::Image()->update(['imageid' => $current[0]['imageid'], 'image' => base64_encode($png)])
				: API::Image()->create([
					'name' => Branding::LOGO_NAME,
					'imagetype' => IMAGE_TYPE_BACKGROUND,
					'image' => base64_encode($png)
				]);
		}
		elseif ($remove && $current) {
			$result = API::Image()->delete([$current[0]['imageid']]);
		}
		else {
			return;
		}

		if ($result === false) {
			$messages = array_column(get_and_clear_messages(), 'message');

			throw new Exception($messages ? implode(' ', $messages) : _('Cannot save the logo.'));
		}
	}

	private function redirect(array $form = []): void {
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'reportpack.branding');

		foreach ($form as $field => $value) {
			$url->setArgument($field, $value);
		}

		$response = new CControllerResponseRedirect($url);

		if ($form) {
			$response->setFormData($form);
		}

		$this->setResponse($response);
	}
}
