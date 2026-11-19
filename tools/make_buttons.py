"""Draws the README badges, navigation buttons and contents cards as SVG files.

    python tools/make_buttons.py

Writes docs/images/buttons/*.svg. Edit the lists below and run again to change a label.
Text is drawn with the viewer's system font, so every shape leaves room for wider fonts.
"""
import pathlib
from xml.sax.saxutils import escape

OUT = pathlib.Path(__file__).resolve().parent.parent / 'docs' / 'images' / 'buttons'
FONT = "-apple-system, 'Segoe UI', Helvetica, Arial, sans-serif"

NAVY = '#0B3A5B'
MINT = '#7DDCC9'
TEAL = '#2BB5A0'
INK = '#17212B'
MUTED = '#5B7083'
LINE = '#D0D7DE'
CARD = '#F6F8FA'

# 24 x 24 stroke icons
ICONS = {
    'server': '<rect x="4" y="4" width="16" height="6" rx="1.5"/><rect x="4" y="14" width="16" height="6" rx="1.5"/>'
              '<path d="M8 7h.01M8 17h.01"/>',
    'tag': '<path d="M3 12V4h8l10 10-8 8z"/><path d="M7.5 7.5h.01"/>',
    'code': '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/>',
    'scale': '<path d="M12 4v16M6 20h12M4 8h16"/><path d="M7 8l-3 6a3 3 0 0 0 6 0zM17 8l-3 6a3 3 0 0 0 6 0z"/>',
    'box': '<path d="M3 7.5L12 3l9 4.5-9 4.5z"/><path d="M3 7.5v9L12 21l9-4.5v-9M12 12v9"/>',
    'download': '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
    'play': '<circle cx="12" cy="12" r="9"/><path d="M10 8.5l5.5 3.5-5.5 3.5z"/>',
    'clock': '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    'percent': '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
    'book': '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 0 6.5 23H20"/>',
    'star': '<path d="M12 3l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.8 6.6 19.7l1.1-6.1-4.5-4.2 6.1-.8z"/>',
    'list': '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
    'image': '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-9 8"/>',
    'flow': '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'
            '<path d="M10 6.5h4.5a3 3 0 0 1 3 3V14"/>',
    'check': '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M7.5 12.5l3 3 6-7"/>',
    'terminal': '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M12.5 15H17"/>',
    'form': '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'files': '<path d="M8 3h7l4 4v11H8z"/><path d="M15 3v4h4"/><path d="M5 7v14h11"/>',
    'palette': '<path d="M12 3a9 9 0 1 0 0 18c1.2 0 2-.8 2-1.8 0-1.4-1.3-1.6-1.3-2.9 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4C21 6.4 17 3 12 3z"/>'
               '<path d="M7.5 11.5h.01M9.5 7.5h.01M14.5 7.5h.01"/>',
    'mail': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/>',
    'sigma': '<path d="M18 5H6l6.5 7L6 19h12"/>',
    'shield': '<path d="M12 3l8 3v6c0 5-3.5 8.2-8 9-4.5-.8-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    'network': '<circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="19" r="2.5"/><circle cx="19" cy="19" r="2.5"/>'
               '<path d="M12 7.5v4M12 11.5l-6 5.2M12 11.5l6 5.2"/>',
    'wrench': '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5a2.1 2.1 0 0 0 3 3l5.8-5.8a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.6-.4-.4-2.6z"/>',
    'question': '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.2a2.6 2.6 0 1 1 3.6 2.4c-.7.3-1.1.9-1.1 1.6v.6M12 16.8h.01"/>',
    'alert': '<path d="M12 3.5L22 20H2z"/><path d="M12 10v4.5M12 17.2h.01"/>',
    'flag': '<path d="M5 21V4M5 4h12l-2.5 4.5L17 13H5"/>',
    'history': '<path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3.5V8h4.5M12 7.5V12l3 2"/>',
    'pdf': '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 13h6M9 17h4"/>',
}


def icon(name, x, y, size, color, width=2):
    scale = size / 24
    return (f'<g transform="translate({x} {y}) scale({scale:.4f})" fill="none" stroke="{color}" '
            f'stroke-width="{width / scale:.2f}" stroke-linecap="round" stroke-linejoin="round">{ICONS[name]}</g>')


def text_width(text, size, bold=True):
    # Generous estimate, so the label still fits when the viewer's font is wider than Segoe UI.
    wide = sum(1.25 if c in 'MWmw@%' else 0.62 if c.isupper() or c.isdigit() else 0.38 if c in ' .,:;|il!' else 0.56
               for c in text)
    return wide * size * (1.06 if bold else 1.0)


def svg(width, height, title, body):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" '
            f'role="img" aria-label="{escape(title)}"><title>{escape(title)}</title>'
            f'<g font-family="{FONT}">{body}</g></svg>\n')


def badge(name, ico, label, value, color):
    h = 30
    lw = round(36 + text_width(label.upper(), 11) + 12)
    vw = round(text_width(value, 12.5) + 26)
    body = (f'<rect width="{lw + vw}" height="{h}" rx="8" fill="{color}"/>'
            f'<path d="M8 0H{lw}V{h}H8A8 8 0 0 1 0 {h - 8}V8A8 8 0 0 1 8 0z" fill="{NAVY}"/>'
            + icon(ico, 11, 7, 16, MINT) +
            f'<text x="33" y="19.5" fill="#FFFFFF" font-size="11" font-weight="700" letter-spacing=".6">{escape(label.upper())}</text>'
            f'<text x="{lw + vw / 2}" y="19.8" fill="#FFFFFF" font-size="12.5" font-weight="700" text-anchor="middle">{escape(value)}</text>')
    (OUT / f'badge-{name}.svg').write_text(svg(lw + vw, h, f'{label}: {value}', body), encoding='utf-8')


def button(name, ico, label, primary=True):
    h = 46
    w = round(58 + text_width(label, 15) * 0.94 + 18)
    if primary:
        body = (f'<rect x=".75" y=".75" width="{w - 1.5}" height="{h - 1.5}" rx="22.25" fill="{NAVY}" stroke="{MINT}" stroke-opacity=".45" stroke-width="1.5"/>'
                f'<circle cx="23" cy="23" r="15" fill="{MINT}" fill-opacity=".16"/>' + icon(ico, 14, 14, 18, MINT) +
                f'<text x="46" y="28.5" fill="#FFFFFF" font-size="15" font-weight="700">{escape(label)}</text>')
    else:
        body = (f'<rect x="1" y="1" width="{w - 2}" height="{h - 2}" rx="22" fill="#FFFFFF" stroke="{TEAL}" stroke-width="2"/>'
                f'<circle cx="23" cy="23" r="15" fill="{TEAL}" fill-opacity=".14"/>' + icon(ico, 14, 14, 18, '#1E8C7B') +
                f'<text x="46" y="28.5" fill="{NAVY}" font-size="15" font-weight="700">{escape(label)}</text>')
    (OUT / f'nav-{name}.svg').write_text(svg(w, h, label, body), encoding='utf-8')


def card(number, ico, title, hint):
    w, h = 440, 76
    body = (f'<rect x="1" y="1" width="{w - 2}" height="{h - 2}" rx="14" fill="{CARD}" stroke="{LINE}" stroke-width="1.5"/>'
            f'<rect x="16" y="16" width="44" height="44" rx="11" fill="{NAVY}"/>' + icon(ico, 26, 26, 24, MINT) +
            f'<text x="76" y="34" fill="{INK}" font-size="16.5" font-weight="700">{escape(title)}</text>'
            f'<text x="76" y="55" fill="{MUTED}" font-size="13">{escape(hint)}</text>'
            f'<text x="{w - 18}" y="47" fill="{TEAL}" fill-opacity=".55" font-size="24" font-weight="800" '
            f'text-anchor="end">{number:02d}</text>')
    slug = title.lower().replace(',', '').replace(' ', '-')
    (OUT / f'toc-{number:02d}-{slug}.svg').write_text(svg(w, h, f'{number:02d} {title}', body), encoding='utf-8')


BADGES = [
    ('zabbix', 'server', 'Zabbix', '7.0 LTS', TEAL),
    ('version', 'tag', 'Version', '0.5.0', '#3B82C4'),
    ('php', 'code', 'PHP', '8.0+', '#6E5BB8'),
    ('license', 'scale', 'License', 'AGPL-3.0', '#5B7083'),
    ('dependencies', 'box', 'Dependencies', 'none', '#1F9D55'),
]

BUTTONS = [
    ('installation', 'download', 'Installation', True),
    ('usage', 'play', 'Usage', True),
    ('scheduled', 'clock', 'Scheduled reports', True),
    ('sla', 'percent', 'SLA calculation', True),
    ('guide-en', 'book', 'User guide (PDF)', False),
    ('guide-tr', 'book', 'Türkçe kılavuz', False),
]

CONTENTS = [
    ('star', 'Why Report Pack', 'Compared with the built-in Zabbix reports'),
    ('list', 'Features', 'Reports, formats and delivery'),
    ('image', 'Screenshots', 'PDF, report page, schedules, branding'),
    ('flow', 'How it works', 'From Zabbix data to a finished document'),
    ('check', 'Requirements', 'Zabbix, rights, fonts and PHP'),
    ('terminal', 'Installation', 'Copy, enable, upgrade, remove'),
    ('form', 'Creating a report', 'The report form, field by field'),
    ('files', 'What is in the files', 'Contents of the PDF, Excel and CSV'),
    ('palette', 'Branding', 'Logo, colour, company name, footer'),
    ('mail', 'Scheduled reports', 'Schedules, API token, sender, cron'),
    ('sigma', 'How the numbers are calculated', 'Downtime, outages, SLA, maintenance'),
    ('shield', 'Permissions and security', 'Who sees what, keeping secrets safe'),
    ('network', 'Proxies, HA and multiple sites', 'Running it in larger setups'),
    ('wrench', 'Troubleshooting', 'Symptoms, causes and fixes'),
    ('question', 'FAQ', 'Questions buyers and admins ask'),
    ('alert', 'Known limitations', 'What version 0.5.0 does not do yet'),
    ('flag', 'Roadmap', 'What is planned next'),
    ('code', 'Development and tests', 'Code layout and running the tests'),
    ('history', 'Changelog', 'Changes in every version'),
    ('scale', 'License', 'AGPL-3.0, the same as Zabbix 7.0'),
]


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob('*.svg'):
        old.unlink()
    for args in BADGES:
        badge(*args)
    for args in BUTTONS:
        button(*args)
    for number, (ico, title, hint) in enumerate(CONTENTS, 1):
        card(number, ico, title, hint)
    print(len(list(OUT.glob('*.svg'))), 'files in', OUT)


main()
