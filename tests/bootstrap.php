<?php

/**
 * Test bootstrap for running this module on its own.
 *
 * A Silverstripe module normally runs its tests from a project. This lets it
 * run from the module directory instead, against an SQLite database held in a
 * temporary file, so the path rules, the batching and the adaptor
 * can be exercised without standing a site up first.
 */

$root = __DIR__ . '/..';

require_once $root . '/vendor/autoload.php';

// A project skeleton is what the kernel looks for. The module is mounted as
// the project so its _config and src are in the manifest.
if (!file_exists($root . '/public/index.php')) {
    @mkdir($root . '/public', 0o777, true);
    file_put_contents($root . '/public/index.php', '<?php');
}

$dbPath = sys_get_temp_dir() . '/blueo-purge-cloudfront-tests';

if (!is_dir($dbPath)) {
    mkdir($dbPath, 0o777, true);
}

putenv('SS_ENVIRONMENT_TYPE=dev');
putenv('SS_DATABASE_CLASS=SQLite3Database');
putenv('SS_DATABASE_NAME=purgecftest');
putenv('SS_SQLITE_DATABASE_PATH=' . $dbPath);
putenv('SS_DATABASE_SERVER=localhost');
putenv('SS_DATABASE_USERNAME=root');
putenv('SS_DATABASE_PASSWORD=');
putenv('SS_BASE_URL=http://localhost/');

// The Injector block in _config/purge-cloudfront.yml is gated on this
// variable, so it has to be set before the config is read. ConfigBindingTest
// asserts that the gate and the binding work.
putenv('PURGE_CLOUDFRONT_DISTRIBUTION_ID=E1TESTDIST');

$_ENV['SS_ENVIRONMENT_TYPE'] = 'dev';
$_ENV['SS_DATABASE_CLASS'] = 'SQLite3Database';
$_ENV['SS_DATABASE_NAME'] = 'purgecftest';
$_ENV['SS_SQLITE_DATABASE_PATH'] = $dbPath;
$_ENV['SS_BASE_URL'] = 'http://localhost/';
$_ENV['PURGE_CLOUDFRONT_DISTRIBUTION_ID'] = 'E1TESTDIST';

// The class manifest is cached between runs, and a test file added since the
// last run is otherwise invisible — which surfaces as
// "getItemPath returned null" rather than as a missing test.
$_GET['flush'] = 1;
$_REQUEST['flush'] = 1;

require_once $root . '/vendor/silverstripe/framework/tests/bootstrap/init.php';
require_once $root . '/vendor/silverstripe/framework/tests/bootstrap/cli.php';
require_once $root . '/vendor/silverstripe/framework/tests/bootstrap/environment.php';
