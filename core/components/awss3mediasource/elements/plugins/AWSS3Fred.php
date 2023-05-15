<?php
$corePath = $modx->getOption('awss3mediasource.core_path', null, $modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/awss3mediasource/');
/** @var FredWebdam $FredWebdam */
$FredWebdam = $modx->getService(
    'awss3mediasource',
    'AwsS3Fred',
    $corePath . 'model/awss3mediasource/',
    array(
        'core_path' => $corePath
    )
);

switch ($modx->event->name) {
    case 'FredOnElfinderRoots':
        $params = $modx->getOption('params', $scriptProperties);
        if (empty($params)) {
            return false;
        }

        $mediaSourceIDs = $this->modx->getOption('mediaSource', $_GET, '');
        $mediaSourceIDs = explode(',', $mediaSourceIDs);
        $mediaSourceIDs = array_map('trim', $mediaSourceIDs);
        $mediaSourceIDs = array_keys(array_flip($mediaSourceIDs));
        $mediaSourceIDs = array_filter($mediaSourceIDs);

        $c = $this->modx->newQuery('modMediaSource');
        $where = [
            'class_key' => 'AwsS3MediaSource'
        ];

        if (!empty($mediaSourceIDs)) {
            $where['name:IN'] = $mediaSourceIDs;
        }

        $c->where($where);

        /** @var \modFileMediaSource[] $mediaSources */
        $mediaSources = $this->modx->getIterator('modMediaSource', $c);
        include_once $FredWebdam->getOption('modelPath') . 'awss3mediasource/src/elFinderVolumeWebDam.php';
        foreach ($mediaSources as $mediaSource) {
            $mediaSource->initialize();
            if (!$mediaSource->checkPolicy('list')) {
                continue;
            }

            $properties = $mediaSource->getProperties();
            if (isset($properties['fred']) && ($properties['fred']['value'] === true)) {
                $readOnly = false;
                if (isset($properties['fredReadOnly']) && ($properties['fredReadOnly']['value'] === true)) {
                    $readOnly = true;
                }
                $prefix = $properties['baseDir'];
                $awsUrl = $properties['endpoint'];
                $awsUrl = $prefix ? $awsUrl.'/'.$prefix : $awsUrl;
                $adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter(
                    $mediaSource->driver,
                    $mediaSource->bucket,
                    $prefix
                );
                $filesystem = new League\Flysystem\Filesystem(
                    $adapter,
                    ['url' => $awsUrl]
                );

                $params->roots[] = [
                    'id' => 'ms' . $mediaSource->id,
                    'driver' => 'AwsS3Flysystem',
                    'alias' => 'AwsS3',
                    'filesystem' => $filesystem,
                    'URL' => $awsUrl,
                    'tmbURL' => 'self',
                ];
            }
        }
        break;
}

return true;
