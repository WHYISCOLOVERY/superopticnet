# AI and Statistics backend contract

## Statistics

- Canonical page: `statistics-center.php`
- Partial endpoint: `statistics-center-partial.php?statistics_fragment=results`
- Access gate: `billing_require_employee(false, 'statistics')`
- Supported periods: `today`, `yesterday`, `week`, `month`, `year`, `custom`
- Supported metrics are role-filtered. Admins receive every metric; employees
  receive only metrics belonging to modules available to their role.
- Every dataset row uses `label`, `value` and `count`.
- Changing metric, period or date must update only `[data-statistics-results]`.
- Custom date inputs remain editable. Editing either date switches the period to
  `custom` automatically.
- The `all` selection renders each allowed metric as a separately titled block.

## AI

- General users can use daily analysis, risk and recommendation views.
- `automations`, `audit` and `model-status` are admin-only.
- Finance analytics is visible only to users that can access Finance.
- Hidden sections are also rejected by direct and partial requests.
- AI navigation and content transitions remain AJAX based.
