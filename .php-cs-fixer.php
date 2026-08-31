<?php

// Reuses lib/pkp's ruleset and custom fixers directly, since this plugin is
// always developed nested inside a full ojs checkout at
// plugins/generic/pubmedCentral/ (never usefully standalone).

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude(['vendor']);

$rules = include __DIR__ . '/../../../lib/pkp/.php_cs_rules';

require(__DIR__ . '/../../../lib/pkp/classes/dev/fixers/bootstrap.php');

$config = new PhpCsFixer\Config();
$config->registerCustomFixers(new PKP\dev\fixers\Fixers())
    ->setRules($rules)
    ->setFinder($finder);

return $config;
