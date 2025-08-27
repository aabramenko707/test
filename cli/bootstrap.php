<?php

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);
define('BX_WITH_ON_AFTER_EPILOG', true);
define('BX_NO_ACCELERATOR_RESET', true);

if (!str_starts_with(php_sapi_name(), 'cli')) {
    die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = dirname(realpath(__DIR__), 4) . '/';
