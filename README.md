# VSAC-Email

**WARNING**: this package is _experimental_.

This is a collection of tools for working with email. It includes:

 * An html-to-email converter, that will take an html page and convert it to email-safe html by: inlining styles, converting non-email-safe CSS declarations, and inlining images.  Additionally, it will provide a plain-text fallback.
 * An email push service, providing a simplified interface to multiple email providers.
 * An email pull service, that can be called via cron to pick up emails from client applications.

##Installation

Download either the whole extension or just the [PHAR](./email.phar) archive. Upload it to your web host and modify your front controller to use it. It should look something like this:

    <?php
    set_include_path(
        '/path/to/data/__application_phar__'
        . PATH_SEPARATOR .
        'phar:///path/to/vsac/application.phar'
    );
    require_once "application.php";
    VSAC\set_data_directory('/path/to/data');
    // this is the line you should add, after data directory and before bootstrap
    VSAC\add_include_path('phar://path/to/vsac/email.phar');

    VSAC\bootstrap_web($debug = false);
    VSAC\front_controller_dispatch();

