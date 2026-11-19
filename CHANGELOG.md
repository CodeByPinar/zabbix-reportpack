# Changelog

## 0.5.0 – 2026-11-19

First public version.

- Availability report per host with SLA target, downtime, outages, longest outage and a daily chart.
- Most frequent problems report.
- Export to branded PDF, Excel workbook and CSV.
- Branding: logo, accent colour, company name, footer, *Prepared for* line.
- Planned maintenance can be excluded from availability.
- Scheduled e-mail reports (daily, weekly, monthly) with a command-line sender run by cron.
- Logos are read without the PHP GD extension, so the sender needs no `php-gd`.
