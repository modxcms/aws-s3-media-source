<?php
/**
 * Resolve creating db tables
 *
 * THIS RESOLVER IS AUTOMATICALLY GENERATED, NO CHANGES WILL APPLY
 *
 * @package awss3mediasource
 * @subpackage build
 *
 * @var mixed $object
 * @var modX $modx
 * @var array $options
 */

if ($object->xpdo) {
    $modx =& $object->xpdo;
    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $modelPath = $modx->getOption('awss3mediasource.core_path', null, $modx->getOption('core_path') . 'components/awss3mediasource/') . 'model/';
            
            $modx->addPackage('awss3mediasource', $modelPath, null);


            $manager = $modx->getManager();

            $manager->createObjectContainer('AwsS3MediaSource');

            break;
    }
}

return true;