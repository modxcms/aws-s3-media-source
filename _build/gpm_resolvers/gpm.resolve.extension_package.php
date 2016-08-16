<?php
/**
 * Resolve creating db tables
 *
 * THIS RESOLVER IS AUTOMATICALLY GENERATED, NO CHANGES WILL APPLY
 *
 * @package awss3mediasource
 * @subpackage build
 */

if ($object->xpdo) {
    /** @var modX $modx */
    $modx =& $object->xpdo;

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $modelPath = $modx->getOption('awss3mediasource.core_path');

            if (empty($modelPath)) {
                $modelPath = '[[++core_path]]components/awss3mediasource/model/';
            }

            if ($modx instanceof modX) {

                $modx->addExtensionPackage('awss3mediasource', $modelPath, array (
));

            }

            break;
        case xPDOTransport::ACTION_UNINSTALL:
            if ($modx instanceof modX) {
                $modx->removeExtensionPackage('awss3mediasource');
            }

            break;
    }
}
return true;