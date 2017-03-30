<?php
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))).DIRECTORY_SEPARATOR.'config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

/**
 * Created by PhpStorm.
 * User: jgulledge
 * Date: 3/28/2017
 * Time: 6:23 AM
 */
class AwsS3Cli
{
    public $modx;

    /** @var bool  */
    protected $run = false;

    protected $climate;

    /**
     * @param DECIMAL $begin_time
     */
    protected $begin_time = null;

    function __construct()
    {
        $this->begin_time = microtime(true);

        $this->modx = new modX();

        $this->modx->initialize('mgr');

        $corePath = $this->modx->getOption('awss3mediasource.core_path', null, $this->modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/awss3mediasource/');

        require_once $corePath . 'model/vendor/autoload.php';

        $this->climate = new League\CLImate\CLImate;

        $this->climate->description('AWS S3 transfer local files to S3');
        $this->buildAllowableArgs();
        //$this->climate->arguments->parse();
    }

    /**
     *
     */
    public function run()
    {
        if ( $this->climate->arguments->get('display') ) {
            $this->displayMediaSources();

        } elseif ( $this->climate->arguments->get('displayInfo') > 0 ) {
            $this->displayMediaSourceInfo($this->climate->arguments->get('displayInfo'));

        } elseif ($this->run) {
            $run = true;
            try {
                $this->climate->arguments->parse();
            }  catch (Exception $e) {
                $this->climate->to('error')->red($e->getMessage());
                $run = false;
            }
            if ( $run ) {
                // transfer:
                $this->transferFiles(
                    $this->climate->arguments->get('method'),
                    $this->climate->arguments->get('from'),
                    $this->climate->arguments->get('to'),
                    $this->climate->arguments->get('fromPath'),
                    $this->climate->arguments->get('toPath')
                );
            }

        } else {
            $this->getUsage();
        }

        $this->climate->out('Completed in '.(microtime(true)-$this->begin_time).' seconds')->br();
    }

    /**
     * @param string $method
     * @param int $from
     * @param int $to
     * @param string $from_path
     * @param string $to_path
     */
    protected function transferFiles($method, $from, $to, $from_path, $to_path)
    {
        $this->climate->out(__METHOD__);
        $this->climate->table([
            ['Type', 'From', 'FromPath', 'To', 'ToPath'],
            [$method, $from, $from_path, $to, $to_path]
        ]);

        $coreSourceClasses = $this->modx->getOption('core_media_sources',null,'modFileMediaSource,modS3MediaSource');
        $coreSourceClasses = explode(',',$coreSourceClasses);

        // load xpdo from:
        $fromXPDOSource = $this->modx->getObject('modMediaSource', $from);

        // load actual from fromSource:
        $classKey = $fromXPDOSource->get('class_key');
        $classKey = in_array($classKey, $coreSourceClasses) ? 'sources.'.$classKey : $classKey;
        /** @var modMediaSource $fromSource */
        $fromSource = $this->modx->newObject($classKey);
        $fromSource->fromArray($fromXPDOSource->toArray(),'',true,true);
        $fromSource->initialize();

        $list = $fromSource->getContainerList($from_path);
        $object_type = 'dir';
        if ( count($list) > 0) {
            $this->listDirectory($list);
            if ( $fromSource->hasErrors()) {
                $this->climate->to('error')->red(print_r($fromSource->errors, true));
                return;
            }
        } else {
            // is it a file?
            $file = $fromSource->getObjectContents($from_path);
            if ( $fromSource->hasErrors()) {
                // MODX gives wrong err message for file does not exist
                $this->climate->to('error')->red(print_r($fromSource->errors, true));
                return;
            }
            $object_type = 'file';
            $this->listFile($file);
        }

        $input = $this->climate->input('Do you want to proceed? (Y)/n');

        $response = $input->prompt();
        if (strtolower($response) == 'y') {
            $this->climate->out('Begin transfer');

        } else {
            $this->climate->out('END transfer');
            return;
        }

        // load xpdo to:
        $toXPDOSource = $this->modx->getObject('modMediaSource', $to);

        $classKey = $toXPDOSource->get('class_key');
        $classKey = in_array($classKey, $coreSourceClasses) ? 'sources.'.$classKey : $classKey;
        /** @var modMediaSource $source */
        $toSource = $this->modx->newObject($classKey);
        $toSource->fromArray($toXPDOSource->toArray(),'',true,true);
        $toSource->initialize();

        if ( !method_exists($toSource, 'transferObjects')) {
            $this->climate->to('error')->red('The TO media fromSource does not support the transfer method');
            return;
        }
        $this->climate->out('Begin transfer '.__LINE__);
        // copy of the base folder as well:
        $container = trim(trim(trim($toSource->getBaseDir(), '/').'/'.trim($to_path, '/'), '/').'/'.$from_path, '/');
        if ( $object_type == 'dir') {
            $container .= '/';
        }
        $this->climate->out($toSource->getBaseDir().'||'.$from_path.'||'.ltrim($to_path, '/').' || Container: '.$container);
        $toSource->transferObjects($fromSource, $from_path, $this->climate, $object_type, $container, $method);

        if ( $method == 'move') {
            // log to redirector:
            $this->logRedirect(
                $fromSource->prepareOutputUrl($from_path),
                //rtrim($toSource->properties['url'], '/') . '/'.$toSource->prepareOutputUrl($container),
                $toSource->getObjectUrl($container),
                $object_type
            );
        }
    }

    /**
     * @param string $old Needs to be full relative path
     * @param string $new needs to be full URL
     * @param string $type
     *
     * @return mixed
     */
    protected function logRedirect($old, $new, $type='file')
    {
        $old = trim($old, '/');
        $new = trim($new, '/');
        if ($type == 'dir') {
            $old = '^'.str_replace('/', '\/', $old).'(.*)$';
            //$new = str_replace('/', '\/', $new).'$1';
            $new .= '$1';
        } else {
            // @TODO temp fix: Redirect is giving SSL errors and not validating
            $new = str_replace('https:', 'http:', $new);
        }
        //return;
        $corePath = $this->modx->getOption(
            'redirector.core_path',
            array(),
            $this->modx->getOption('core_path') . 'components/redirector/'
        );
        // @depends on https://github.com/modxcms/Redirector but will not run if not installed
        $redirector = $this->modx->getService('redirector', 'Redirector', $corePath . 'model/redirector/', array());
        if ($redirector instanceof Redirector) {
            $response = $this->modx->runProcessor(
                'mgr/redirect/create',
                array(
                    'active' => 1,
                    'pattern' => $old,
                    'target' => $new
                ),
                array('processors_path' => $corePath.'processors/')
            );
            $this->climate->out('Class: '.get_class($response));
            if ($response->isError()) {
                $this->climate->to('error')->red('Error creating redirect rule: '.$response->getMessage());
                $data = [];
                foreach ($response->getResponse()['errors'] as $error){
                    $data[] = [$error['id'], $error['msg']];
                }
                $this->climate->to('error')->red()->table($data);
                //$this->climate->to('error')->red(print_r($response->getResponse(), true))->table();
            } else {
                $this->climate->out('Redirector rule has been created');
            }
            $this->climate->table(
                [
                    [
                        'Pattern', 'Target'
                    ],
                    [
                        $old, $new
                    ]
                ]
            );
        }
    }

    /**
     * @param $list array
     */
    protected function listDirectory($list)
    {
        $data = [
            [
                'id',
                'type',
                'path'
            ]
        ];
        foreach ($list as $folder) {
            $data[] = [
                $folder['id'],
                $folder['type'],
                $folder['path']
            ];
            //$this->climate->out(print_r($folder, true));break;
        }
        $this->climate->table($data);
        //$this->climate->out(print_r($folder, true));
    }

    protected function listFile($file)
    {
        $data = [
            [
                'basename',
                'type',
                'path',
                'size',
                'last_modified'
            ],
            [
                $file['basename'],
                'file',
                $file['path'],
                $file['size'],
                $file['last_modified']
            ]
        ];
        $this->climate->table($data);
        //$this->climate->out(print_r($folder, true));
    }

    /**
     *
     */
    protected function displayMediaSources()
    {
        $data = [
            ['ID', 'Class', 'Name', 'Desc']
        ];
        $query = $this->modx->newQuery('modMediaSource');
        $query->sortBy('id');

        $sources = $this->modx->getCollection('modMediaSource', $query);
        foreach ($sources as $source) {
            $data[] = [
                $source->get('id'),
                $source->get('class_key'),
                $source->get('name'),
                $source->get('description'),
            ];
        }
        $this->climate->table($data);
    }

    /**
     * @param int $id MediaSourceID
     */
    protected function displayMediaSourceInfo($id)
    {
        // id, name, desc, type
        $source = $this->modx->getObject('modMediaSource', $id);
        if ($source) {
            $data = [
                ['ID', 'Class', 'Name', 'Desc'],
                [
                    $source->get('id'),
                    $source->get('class_key'),
                    $source->get('name'),
                    $source->get('description')
                ]
            ];
            $this->climate->table($data);

            // option: value
            $options = [['Property', 'Value']];
            $properties = $source->get('properties');

            foreach ($properties as $name => $value) {
                switch ($name) {
                    case 'url':
                        // no break
                    case 'bucket':
                        // no break
                    case 'baseDir':
                        // no break
                    case 'baseUrl':
                        // no break
                    case 'basePath':
                    $options[] = [$name, $value['value']];
                        break;
                }
            }
            $this->climate->table($options);
        } else {
            $this->climate->to('error')->red('Invalid ID passed');
        }
    }

    /**
     *
     */
    protected function buildAllowableArgs()
    {
        // Help menu:
        $this->climate->arguments->add([
            'display' => [
                'prefix'      => 'd',
                'longPrefix'  => 'display',
                'description' => 'Display Media Sources set, no other args needed',
                'noValue'     => true,
            ],
            'displayInfo' => [
                'prefix'      => 'i',
                'longPrefix'  => 'info',
                'description' => 'Display Media Source info, pass ID to see, no other args needed',
                'castTo'      => 'int',
            ],
            'runTransfer' => [
                'prefix'      => 'r',
                'longPrefix'  => 'run',
                'description' => 'Run Media Source transfer, do -h -s to see required fields',
                'noValue'     => true,
            ],
            /*
            'verbose' => [
                'prefix'      => 'v',
                'longPrefix'  => 'verbose',
                'description' => 'Verbose output',
                'noValue'     => true,
            ],
            */
            'help' => [
                'prefix'      => 'h',
                'longPrefix'  => 'help',
                'description' => 'Prints a usage statement',
                'noValue'     => true,
            ],
            'runHelp' => [
                'prefix'       => 's',
                'longPrefix'   => 'sRun',
                'description'  => 'Prints a usage statement for the Transfer commands',
                'noValue'     => true,
            ]
        ]);
        $this->climate->arguments->parse();
        if ( $this->climate->arguments->get('runTransfer')) {
            $this->run = true;
        }
        if (!empty($this->climate->arguments->get('runHelp')) || $this->run){

            // can't clear args list so reload the object:
            $this->climate = new League\CLImate\CLImate;

            $this->climate->description('AWS S3 transfer local files to S3');
            // add the args:
            $this->climate->arguments->add([
                'runTransfer' => [
                    'prefix'      => 'r',
                    'longPrefix'  => 'run',
                    'description' => 'Run Media Source transfer, do -h -s to see required fields',
                    'noValue'     => true,
                    'required'    => true
                ],
                'from' => [
                    'prefix'      => 'f',
                    'longPrefix'  => 'from',
                    'description' => 'From Media Source ID',
                    'castTo'      => 'int',
                    'required'    => true,
                ],
                'to' => [
                    'prefix'      => 't',
                    'longPrefix'  => 'to',
                    'description' => 'To Media Source ID',
                    'castTo'      => 'int',
                    'required'    => true,
                ],
                'fromPath' => [
                    'prefix'      => 'p',
                    'longPrefix'  => 'fromPath',
                    'description' => 'Relative path related to the From Media Source ID',
                    //'required'    => true,
                ],
                'toPath' => [
                    'prefix'      => 'x',
                    'longPrefix'  => 'toPath',
                    'description' => 'Relative path related to the To Media Source ID',
                    //'required'    => true,
                ],
                'method' => [
                    'prefix'       => 'm',
                    'longPrefix'   => 'method',
                    'description'  => 'Transfer Method, copy or move',
                    'defaultValue' => 'copy',
                ],
                'help' => [
                    'prefix'      => 'h',
                    'longPrefix'  => 'help',
                    'description' => 'Prints a usage statement, back to main help',
                    'noValue'     => true,
                ],
            ]);
        }
    }

    /**
     *
     */
    protected function getUsage()
    {
        $this->climate->usage();
    }

}

$awS3Cli = new AwsS3Cli();
$awS3Cli->run();