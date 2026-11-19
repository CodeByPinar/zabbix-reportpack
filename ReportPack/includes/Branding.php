<?php

namespace Modules\ReportPack\Includes;

use APP;

/**
 * Branding of PDF reports: company name, accent colour, footer text and logo.
 *
 * The text settings are stored in the module's configuration (Administration › Modules keeps it in the
 * database); the logo is stored as a Zabbix image named LOGO_NAME, so it can also be managed under
 * Administration › General › Images.
 */
class Branding {

	public const MODULE_ID = 'reportpack';
	public const LOGO_NAME = 'Report Pack logo';
	public const DEFAULT_ACCENT = '#0B3A5B';

	public const DEFAULTS = [
		'company' => '',
		'accent' => self::DEFAULT_ACCENT,
		'footer' => ''
	];

	public static function get(): array {
		$module = APP::ModuleManager()->getModule(self::MODULE_ID);

		return self::fromConfig($module !== null ? $module->getConfig() : []);
	}

	/**
	 * Branding from a module configuration (the runner reads it through the API).
	 */
	public static function fromConfig(array $config): array {
		$branding = array_merge(self::DEFAULTS, array_intersect_key($config, self::DEFAULTS));

		if (!self::isColour($branding['accent'])) {
			$branding['accent'] = self::DEFAULT_ACCENT;
		}

		return $branding;
	}

	/**
	 * Stores the text settings. Super admin only (Zabbix allows module.update to Super admins).
	 */
	public static function save(array $branding): void {
		$module = APP::ModuleManager()->getModule(self::MODULE_ID);

		if ($module === null) {
			return;
		}

		$module->setConfig(array_merge($module->getConfig(), array_intersect_key($branding, self::DEFAULTS)));
	}

	/**
	 * @return array|null  ['imageid' => string, 'bytes' => string] or null when no logo is set.
	 */
	public static function logo(): ?array {
		$images = ZabbixApi::current()->call('image.get', [
			'output' => ['imageid'],
			'select_image' => true,
			'filter' => ['name' => self::LOGO_NAME]
		]);

		if (!$images) {
			return null;
		}

		$bytes = base64_decode($images[0]['image'], true);

		return $bytes !== false && $bytes !== '' ? ['imageid' => $images[0]['imageid'], 'bytes' => $bytes] : null;
	}

	public static function isColour(string $value): bool {
		return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
	}

	public static function rgb(string $hex): array {
		return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
	}

	/**
	 * Font files for the PDF: Zabbix's own graph font (DejaVu Sans on every package and the appliance)
	 * and, if it sits next to it, its bold variant.
	 *
	 * @return array  ['regular' => path, 'bold' => path|null]
	 */
	public static function fonts(?string $regular_path = null): array {
		$regular = $regular_path !== null
			? (realpath($regular_path) ?: null)
			: (realpath(ZBX_FONTPATH.'/'.ZBX_GRAPH_FONT_NAME.'.ttf') ?: null);
		$bold = null;

		if ($regular !== null) {
			$candidate = preg_replace('/(\.ttf)$/i', '-Bold$1', $regular);

			if ($candidate !== $regular && is_readable($candidate)) {
				$bold = $candidate;
			}
		}

		return ['regular' => $regular, 'bold' => $bold];
	}
}
