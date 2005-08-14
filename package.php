<?php

require_once 'PEAR/PackageFileManager.php';

$version = '0.2.0';
$notes = <<<EOT
- fixed several incorrect calls to RDF::raiseError()
- propagate PEAR errors to the user
- cosmetic fixes
EOT;

$description =<<<EOT
This package is a port of the RDQL part of the RDF API for PHP (aka RAP):
http://www.wiwiss.fu-berlin.de/suhl/bizer/rdfapi/.
EOT;

$package =& new PEAR_PackageFileManager();

$result = $package->setOptions(array(
    'package'           => 'RDF_RDQL',
    'summary'           => 'Port of the RAP RDQL API',
    'description'       => $description,
    'version'           => $version,
    'state'             => 'alpha',
    'license'           => 'LGPL',
    'filelistgenerator' => 'cvs',
    'ignore'            => array('package.php', 'package.xml'),
    'notes'             => $notes,
    'changelogoldtonew' => false,
    'simpleoutput'      => true,
    'baseinstalldir'    => '/RDF',
    'packagedirectory'  => './',
    'dir_roles'         => array('docs' => 'doc', 'examples' => 'doc')
    ));

if (PEAR::isError($result)) {
    echo $result->getMessage();
    die();
}

$package->addMaintainer('davey', 'lead', 'Davey Shafik', 'davey@php.net');

$package->addDependency('php', '4.2.0', 'ge', 'php', false);
$package->addDependency('PEAR', '1.0b1', 'ge', 'pkg', false);
$package->addDependency('MDB', true, 'has', 'pkg', true);
$package->addDependency('RDF', true, 'has', 'pkg', false);

if (isset($_GET['make']) || (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 'make')) {
    $result = $package->writePackageFile();
} else {
    $result = $package->debugPackageFile();
}

if (PEAR::isError($result)) {
    echo $result->getMessage();
    die();
}
