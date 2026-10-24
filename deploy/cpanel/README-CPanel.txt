SUPEROPTIC PUBLIC WEBSITE - CPANEL PACKAGE

1. Upload the contents of public_html to the document root of superoptic.net.
2. Place the superoptic-private directory outside public_html.
3. Rename superoptic-private/public-site.local.example.php to public-site.local.php.
4. Configure these PHP environment variables in cPanel:
   PUBLIC_SITE_INGRESS_KEY_ID
   PUBLIC_SITE_INGRESS_SECRET
   RECAPTCHA_SECRET (only when reCAPTCHA is enabled)
5. Make superoptic-private/data writable by the PHP-FPM user and inaccessible from the web.
6. Keep the portal link pointed at https://mgt.superoptic.net/portal-login.php.

Do not upload the MGS config.php, database dumps, .env files, bot credentials, tools, or internal API code.
