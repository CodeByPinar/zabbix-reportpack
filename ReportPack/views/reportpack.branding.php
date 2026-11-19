<?php

/**
 * Report Pack branding settings.
 *
 * @var CView $this
 * @var array $data
 */

$branding = $data['branding'];

$html_page = (new CHtmlPage())
	->setTitle(_('Report Pack branding'))
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(new CRedirectButton(_('Back to reports'),
				(new CUrl('zabbix.php'))->setArgument('action', 'reportpack.view')->getUrl()
			))
		))->setAttribute('aria-label', _('Content controls'))
	);

$form = (new CForm('post'))
	->setId('reportpack-branding')
	->setName('reportpack_branding')
	->setAttribute('enctype', 'multipart/form-data')
	->setAction((new CUrl('zabbix.php'))->setArgument('action', 'reportpack.branding.update')->getUrl())
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('reportpack.branding.update')))->removeId())
	->setAttribute('aria-labelledby', CHtmlPage::PAGE_TITLE_ID);

$logo_cell = [
	(new CFile('logo'))->setAttribute('accept', 'image/png,image/jpeg,image/gif'),
	(new CDiv(_s('PNG, JPEG or GIF, up to %1$s. Shown at the top left of every PDF, at most 160 × 44 points; a transparent PNG about 600 pixels wide looks best.',
		convertUnits(['value' => $data['max_upload'], 'units' => 'B'])
	)))->addClass(ZBX_STYLE_GREY)
];

if ($data['logo_id'] !== null) {
	array_unshift($logo_cell,
		(new CDiv(
			(new CImg((new CUrl('imgstore.php'))
				->setArgument('iconid', $data['logo_id'])
				// imgstore.php accepts at most 200 × 200.
				->setArgument('width', 200)
				->setArgument('height', 60)
				->getUrl()
			))->setAttribute('alt', _('Current logo'))
		))->addStyle('margin-bottom: 6px;'),
		(new CDiv([
			(new CCheckBox('remove_logo'))->setLabel(_('Remove logo'))
		]))->addStyle('margin-bottom: 6px;')
	);
}

$form_list = (new CFormList())
	->addRow(
		new CLabel(_('Company name'), 'company'),
		[
			(new CTextBox('company', $branding['company'], false, 128))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('autofocus', 'autofocus'),
			(new CDiv(_('Shown at the top right of every PDF. For an MSP, your own company; the customer goes in "Prepared for" on the report form.')))
				->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('Accent colour'), 'accent'),
		[
			(new CTextBox('accent', $branding['accent'], false, 7))
				->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
				->setAttribute('placeholder', '#0B3A5B')
				->setAttribute('pattern', '#?[0-9A-Fa-f]{6}'),
			' ',
			(new CSpan())
				->setId('reportpack-swatch')
				->addStyle('display:inline-block; width:22px; height:22px; vertical-align:middle; border-radius:3px; background:'
					.(Modules\ReportPack\Includes\Branding::isColour($branding['accent'])
						? $branding['accent']
						: Modules\ReportPack\Includes\Branding::DEFAULT_ACCENT)
				),
			(new CDiv(_('Hex colour of the top bar, headings and table headers.')))->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(
		new CLabel(_('Footer text'), 'footer'),
		[
			(new CTextBox('footer', $branding['footer'], false, 255))->setWidth(ZBX_TEXTAREA_BIG_WIDTH),
			(new CDiv(_('Bottom left of every page, for example contact details or "Confidential". Empty: the company name.')))
				->addClass(ZBX_STYLE_GREY)
		]
	)
	->addRow(new CLabel(_('Logo'), 'logo'), $logo_cell);

if ($data['fonts']['regular'] === null) {
	$form_list->addRow(_('Font'),
		(new CSpan(_('The Zabbix graph font (assets/fonts/graphfont.ttf) was not found, so PDFs cannot be created.')))
			->addClass(ZBX_STYLE_RED)
	);
}
elseif ($data['fonts']['bold'] === null) {
	$form_list->addRow(_('Font'),
		(new CSpan(_('No bold variant of the graph font was found; headings are drawn bold from the regular font.')))
			->addClass(ZBX_STYLE_GREY)
	);
}

$form->addItem(
	(new CTabView())
		->addTab('branding', _('Branding'), $form_list)
		->setFooter(makeFormFooter(new CSubmit('save', _('Save'))))
);

$html_page->addItem($form)->show();

?>
<script>
	(function () {
		const input = document.getElementById('accent');
		const swatch = document.getElementById('reportpack-swatch');

		input.addEventListener('input', function () {
			const value = this.value.trim().replace(/^#?/, '#');

			if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
				swatch.style.background = value;
			}
		});
	})();
</script>
