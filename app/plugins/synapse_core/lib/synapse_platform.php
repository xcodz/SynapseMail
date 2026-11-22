<?php

/*!
 * SynapseMail
 * Copyright (c) 2026 SynapseCyber.io. All rights reserved.
 * Developed by Milad Ahmadipour (https://milad.io).
 *
 * Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0
 * International License (CC BY-NC-ND 4.0). You may use and share it free of charge
 * with attribution. You may not modify it, distribute modified versions, or sell it.
 * https://creativecommons.org/licenses/by-nc-nd/4.0/
 */

/**
 * Hosting panel and web server detection plus "how to fix" steps per panel, for the installer and the admin
 * panel. Detection uses only the install path and $_SERVER, so it works under open_basedir.
 * The same text is in docs/HOSTING-PANELS.md and docs/TROUBLESHOOTING.md.
 */
class synapse_platform
{
    public const PANELS = [
        'cpanel' => 'cPanel', 'plesk' => 'Plesk', 'directadmin' => 'DirectAdmin', 'hestia' => 'HestiaCP / VestaCP',
        'cloudpanel' => 'CloudPanel', 'aapanel' => 'aaPanel', 'ispconfig' => 'ISPConfig', 'plain' => 'Linux server (no panel)',
    ];

    public const DOCS = 'https://github.com/xcodz/SynapseMail/blob/main/docs/TROUBLESHOOTING.md';

    /** Panel id from the install path (most specific layouts first) */
    public static function panel($path)
    {
        $p = rtrim(str_replace('\\', '/', (string) $path), '/') . '/';
        $rules = [
            'directadmin' => '#^/home\d*/[^/]+/domains/[^/]+/(public_html|private_html)/#',
            'hestia' => '#^/home\d*/[^/]+/web/[^/]+/public_(html|shtml)/#',
            'cloudpanel' => '#^/home\d*/[^/]+/htdocs/[^/]+/#',
            'plesk' => '#^/var/www/vhosts/[^/]+/#',
            'ispconfig' => '#^/var/www/clients/client\d+/web\d+/#',
            'aapanel' => '#^/www/wwwroot/[^/]+/#',
            'cpanel' => '#^/home\d*/[^/]+/public_html/#',
        ];
        foreach ($rules as $id => $re) {
            if (preg_match($re, $p)) {
                return $id;
            }
        }

        return 'plain';
    }

    /** apache | litespeed | nginx | other, from SERVER_SOFTWARE (a proxy in front may hide nginx; the probe decides) */
    public static function web_server($software)
    {
        $s = strtolower((string) $software);
        foreach (['litespeed', 'nginx', 'apache'] as $w) {
            if (strpos($s, $w) !== false) {
                return $w;
            }
        }

        return 'other';
    }

    /** URL path of the install folder (the one holding config/, db/, public_html/) from the running script */
    public static function base_url($script_name)
    {
        $dir = rtrim(str_replace('\\', '/', dirname((string) $script_name)), '/');

        return preg_replace('#/public_html$#', '', $dir);
    }

    /**
     * nginx rules that keep everything outside public_html private. ^~ prefix locations win over the regex
     * PHP location, so the order inside the server block does not matter.
     */
    public static function nginx_snippet($base)
    {
        $b = rtrim((string) $base, '/');
        $out = "# SynapseMail: nginx ignores .htaccess, so block the private folders here\n";
        foreach (['config', 'db', 'logs', 'temp', 'bin', 'SQL', 'program', 'vendor', 'plugins', 'skins'] as $d) {
            $out .= "location ^~ $b/$d/ { return 404; }\n";
        }
        $out .= "location ~ ^$b/[^/]+\\.(key|txt|md|json|lock|sql)\$ { return 404; }\n";
        $out .= "# then open SynapseMail at $b/public_html/ (or point a subdomain's document root to public_html)\n";

        return $out;
    }

    /** Package names for a PHP extension: [Debian/Ubuntu php8.x-<pkg>, RHEL family php-<pkg>] */
    public static function package($ext)
    {
        $map = ['pdo_sqlite' => ['sqlite3', 'pdo'], 'dom' => ['xml', 'xml'], 'xml' => ['xml', 'xml'], 'zip' => ['zip', 'pecl-zip'],
            'fileinfo' => ['common', 'common'], 'openssl' => ['common', 'common'], 'ctype' => ['common', 'common'],
            'json' => ['common', 'common'], 'session' => ['common', 'common'], 'filter' => ['common', 'common']];

        return $map[$ext] ?? [$ext, $ext];
    }

    /**
     * Steps to fix one problem on one panel.
     * $topic: php | ext | perms | https | protect | mail. $arg: the extension or folder name.
     */
    public static function help($topic, $panel, $arg = '')
    {
        [$deb, $rhel] = self::package($arg);
        $apt = "Server admin (SSH, root): Debian/Ubuntu `apt install php8.3-$deb`, RHEL/Alma/Rocky `dnf install php-$rhel`, then restart PHP-FPM or Apache.";
        $chmod = "Over SSH: `chmod 755 $arg` inside the SynapseMail folder, and make sure the folder belongs to the account that runs PHP.";
        $nginx = 'Add the nginx rules shown below.';
        $h = [
            'cpanel' => [
                'php' => 'cPanel → Software → Select PHP Version (or MultiPHP Manager): choose PHP 8.1 or newer for this domain.',
                'ext' => "cPanel → Software → Select PHP Version → Extensions: tick $arg. If you only have MultiPHP Manager, ask your host to enable $arg.",
                'perms' => "cPanel → File Manager: right-click $arg → Change Permissions → 755.",
                'https' => 'cPanel → Security → SSL/TLS Status → Run AutoSSL, then Domains → turn on Force HTTPS Redirect.',
                'protect' => 'cPanel servers read .htaccess. File Manager → Settings → Show Hidden Files, check that .htaccess exists in the SynapseMail folder and in db/, config/, logs/ and temp/. If not, extract the zip again.',
                'mail' => 'cPanel → Email → Email Accounts → Connect Devices shows the mail server name and ports.',
            ],
            'plesk' => [
                'php' => 'Plesk → Websites & Domains → your domain → PHP: choose PHP 8.1 or newer (FPM served by Apache or nginx).',
                'ext' => "Plesk → Tools & Settings → PHP Settings → your PHP version: enable $arg (server admin), or ask your host.",
                'perms' => "Plesk → Files: select $arg → Change Permissions: give the owner read, write and execute.",
                'https' => 'Plesk → Websites & Domains → SSL/TLS Certificates → install a free Let\'s Encrypt certificate and turn on "Redirect from HTTP to HTTPS".',
                'protect' => 'Plesk with "nginx only" ignores .htaccess. Websites & Domains → Apache & nginx Settings: enable Apache (proxy mode), or paste the nginx rules below into "Additional nginx directives".',
                'mail' => 'Plesk → Mail → Mail Settings shows the mail server; it is usually your domain name or the server host name.',
            ],
            'directadmin' => [
                'php' => 'DirectAdmin → Extra Features → Select PHP Version (or Domain Setup → PHP version): choose 8.1 or newer.',
                'ext' => "DirectAdmin → Select PHP Version → Extensions: tick $arg. Server admins: CustomBuild → PHP extensions.",
                'perms' => "DirectAdmin → File Manager: select $arg → Set Permission → 755.",
                'https' => 'DirectAdmin → Account Manager → SSL Certificates → free certificate from Let\'s Encrypt, then tick "Force SSL with https redirect".',
                'protect' => 'DirectAdmin runs Apache or LiteSpeed by default and reads .htaccess. On an nginx-only server, the admin adds the nginx rules below under Custom HTTPD Configurations.',
                'mail' => 'DirectAdmin → E-Mail Manager → E-Mail Accounts: the server is usually mail.<your domain>.',
            ],
            'hestia' => [
                'php' => 'Hestia → Web → edit the domain → Advanced Options → Backend Template: choose PHP 8.1 or newer.',
                'ext' => $apt,
                'perms' => $chmod,
                'https' => 'Hestia → Web → edit the domain → Enable SSL → Use Let\'s Encrypt, and tick "Enable automatic HTTPS redirection".',
                'protect' => 'Hestia\'s default nginx + Apache setup reads .htaccess. With an nginx-only (php-fpm) proxy template it does not: switch the Proxy Template back to "default" with Apache, or add the nginx rules below to a custom template.',
                'mail' => 'Hestia → Mail: the server is usually mail.<your domain>.',
            ],
            'cloudpanel' => [
                'php' => 'CloudPanel → Sites → your site → Settings → PHP Version: choose 8.1 or newer.',
                'ext' => $apt,
                'perms' => $chmod,
                'https' => 'CloudPanel → Sites → your site → SSL/TLS → Actions → New Let\'s Encrypt Certificate.',
                'protect' => 'CloudPanel uses nginx only, so .htaccess is ignored. Sites → your site → Vhost: add the nginx rules below inside the server block and save.',
                'mail' => 'CloudPanel has no mail server: use the IMAP and SMTP host of your mail provider.',
            ],
            'aapanel' => [
                'php' => 'aaPanel → Website → your site → PHP Version: choose 8.1 or newer.',
                'ext' => "aaPanel → App Store → your PHP version → Setting → Install extensions: install $arg.",
                'perms' => "aaPanel → Files: $arg → Permission 755, owner www.",
                'https' => 'aaPanel → Website → your site → SSL → Let\'s Encrypt, then turn on Force HTTPS.',
                'protect' => 'aaPanel uses nginx by default, so .htaccess is ignored. Website → your site → Config: add the nginx rules below and save.',
                'mail' => 'Use the host of the aaPanel Mail Server plugin or of your mail provider.',
            ],
            'ispconfig' => [
                'php' => 'ISPConfig → Sites → Website → PHP Version: choose 8.1 or newer (PHP-FPM).',
                'ext' => $apt,
                'perms' => $chmod,
                'https' => 'ISPConfig → Sites → Website: tick SSL and Let\'s Encrypt SSL, then save.',
                'protect' => 'On an nginx server, ISPConfig → Sites → Website → Options → nginx Directives: add the nginx rules below.',
                'mail' => 'ISPConfig → Email → Domain: the server is usually the host name of the mail server.',
            ],
            'plain' => [
                'php' => 'Debian/Ubuntu: `apt install php8.3 php8.3-fpm` (or newer). RHEL/Alma/Rocky: `dnf module enable php:8.2 && dnf install php php-fpm`.',
                'ext' => $apt,
                'perms' => "`chown -R www-data: config db logs temp` (Debian/Ubuntu; `apache:` on RHEL) and `chmod 755 $arg`.",
                'https' => 'Install certbot and run `certbot --apache -d your-domain` (or `--nginx`).',
                'protect' => 'Apache: `a2enmod rewrite` and `AllowOverride All` for the SynapseMail folder, then reload Apache. nginx: ' . $nginx,
                'mail' => 'Check that the mail server accepts connections on 993, 465 or 587 from this server, e.g. `nc -vz mail.example.com 993`, and open those ports in the firewall.',
            ],
        ];

        return $h[$panel][$topic] ?? $h['plain'][$topic] ?? '';
    }
}
