# AI and Statistics ownership

## Codex

- `statistics-center-lib.php`
- `statistics-center.php`
- `statistics-center-partial.php`
- `ai-module-lib.php`
- `ai-section-page.php`
- `ai-module-partial.php`
- permission and route changes in `config.php` and `billing-ui.php`
- smoke, browser and regression tests

## Claude

- `views/statistics/**`
- `css/statistics-center.css`
- `script/statistics-center.js`
- `views/ai/**`
- `css/ai-center.css`
- `script/ai-center.js`

Claude must preserve the PHP data contract, permission checks, CSRF fields,
canonical URLs and AJAX endpoint names defined by Codex.
