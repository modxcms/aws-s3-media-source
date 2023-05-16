<?php
switch ($modx->event->name) {
    case 'FredOnElfinderRoots':
        $params = $modx->getOption('params', $scriptProperties);
        if (empty($params)) {
            return false;
        }

        $mediaSourceIDs = $modx->getOption('mediaSource', $_GET, '');
        $mediaSourceIDs = explode(',', $mediaSourceIDs);
        $mediaSourceIDs = array_map('trim', $mediaSourceIDs);
        $mediaSourceIDs = array_keys(array_flip($mediaSourceIDs));
        $mediaSourceIDs = array_filter($mediaSourceIDs);

        $c = $modx->newQuery('modMediaSource');
        $where = [
            'class_key' => 'AwsS3MediaSource'
        ];

        if (!empty($mediaSourceIDs)) {
            $where['name:IN'] = $mediaSourceIDs;
        }

        $c->where($where);

        /** @var \modFileMediaSource[] $mediaSources */
        $mediaSources = $modx->getIterator('modMediaSource', $c);
        foreach ($mediaSources as $mediaSource) {
            $mediaSource->initialize();
            if (!$mediaSource->checkPolicy('list')) {
                continue;
            }

            $properties = $mediaSource->properties;
            if (isset($properties['fred']) && ($properties['fred'] === true)) {
                $readOnly = false;
                if (isset($properties['fredReadOnly']) && ($properties['fredReadOnly'] === true)) {
                    $readOnly = true;
                }
                $baseDir = $modx->getOption('baseDir', $properties, '');
                $path = trim($baseDir, '/');
                $endpoint = $modx->getOption('endpoint', $properties, '');
                $bucket = $modx->getOption('bucket', $properties, '');
                $awsUrl = $modx->getOption('url', $properties, '');
                $awsUrl = $path ? $awsUrl.'/'.$path : $awsUrl;

                $config = [
                    'version' => 'latest',
                    'region' => $modx->getOption('region', $properties, ''),
                    'credentials' => [
                        'key' => $modx->getOption('key', $properties, ''),
                        'secret' => $modx->getOption('secret_key', $properties, '')
                    ]
                ];

                if (!empty($endpoint)) {
                    $config['endpoint'] = $endpoint;
                }
                try {
                    $client = new Aws\S3\S3Client($config);
                    if (!$client->doesBucketExist($bucket)) {
                        $modx->log(modX::LOG_LEVEL_ERROR, '[AWSS3MediaSource] Bucket does not exist: ' . $bucket);
                        continue;
                    }
                    $adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter(
                        new Aws\S3\S3Client($config),
                        $bucket,
                        $path
                    );
                    $params->roots[] = [
                        'id' => 'ms' . $mediaSource->id,
                        'driver' => 'AwsS3Flysystem',
                        'alias' => 'AwsS3',
                        'filesystem' => new League\Flysystem\Filesystem($adapter),
                        'URL' => $awsUrl,
                        'tmbURL' => 'self',
                    ];
                } catch (Exception $e) {
                    $modx->log(modX::LOG_LEVEL_ERROR, '[AWSS3MediaSource] Error: ' . $e->getMessage());
                }
            }
        }
        break;
}

return true;
