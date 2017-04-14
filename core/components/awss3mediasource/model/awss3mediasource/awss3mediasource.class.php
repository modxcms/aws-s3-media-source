<?php

if (!class_exists('modMediaSource')) {
    require MODX_CORE_PATH . 'model/modx/sources/modmediasource.class.php';
}

class AwsS3MediaSource extends modMediaSource implements modMediaSourceInterface
{
    /** @var Aws\S3\S3Client */
    protected $driver;

    /** @var string */
    protected $bucket;

    /**
     * SwiftMediaSource constructor.
     *
     * @param xPDO $xpdo
     */
    public function __construct(xPDO & $xpdo)
    {
        parent::__construct($xpdo);

        $this->set('is_stream', false);

        $this->autoload();
    }

    protected function autoload()
    {
        $corePath = $this->xpdo->getOption('awss3mediasource.core_path', null, $this->xpdo->getOption('core_path', null, MODX_CORE_PATH) . 'components/awss3mediasource/');

        require_once $corePath . 'model/vendor/autoload.php';
    }

    /**
     * Initializes Swift media class, connect and get container
     * @return boolean
     */
    public function initialize()
    {
        parent::initialize();

        $this->xpdo->lexicon->load('core:source');
        $this->properties = $this->getPropertyList();

        try {
            $this->driver = new Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $this->xpdo->getOption('region', $this->properties, ''),
                'credentials' => [
                    'key' => $this->xpdo->getOption('key', $this->properties, ''),
                    'secret' => $this->xpdo->getOption('secret_key', $this->properties, '')
                ]
            ]);
        } catch (Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] ' . $e->getMessage());
            return false;
        }

        $this->bucket = $this->xpdo->getOption('bucket', $this->properties, '');

        return true;
    }

    /**
     * Get the name of this source type
     * @return string
     */
    public function getTypeName()
    {
        $this->xpdo->lexicon->load('awss3mediasource:default');

        return $this->xpdo->lexicon('source_type.awss3mediasource');
    }

    /**
     * Get the description of this source type
     * @return string
     */
    public function getTypeDescription()
    {
        $this->xpdo->lexicon->load('awss3mediasource:default');

        return $this->xpdo->lexicon('source_type.awss3mediasource_desc');
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function getContainerList($path)
    {
        /** Need to check for the root or first loaded Media Source to add the proper baseDir if set. */
        if ( empty(trim($path, '/'))) {
            $base_dir = $this->xpdo->getOption('baseDir', $this->properties, '');
            $path = trim($base_dir, '/') . '/' . ltrim($path, '/');
        }
        list($listFiles, $listDirectories) = $this->listDirectory($path);
        $editAction = $this->getEditActionId();

        $useMultiByte = $this->ctx->getOption('use_multibyte', false);
        $encoding = $this->ctx->getOption('modx_charset', 'UTF-8');
        $hideTooltips = !empty($this->properties['hideTooltips']) && $this->properties['hideTooltips'] != 'false' ? true : false;

        $imageExtensions = $this->getOption('imageExtensions', $this->properties, 'jpg,jpeg,png,gif');
        $imageExtensions = explode(',', $imageExtensions);

        $directories = array();
        $dirNames = array();
        $files = array();
        $fileNames = array();

        foreach ($listDirectories as $idx => $currentPath) {
            if ($currentPath == $path) continue;

            $fileName = basename($currentPath);
            $dirNames[] = strtoupper($fileName);

            $text = $fileName;
            if ($this->getOption('allowFolderCopy', $this->properties, false)) {
                $text = substr($currentPath, 0, strlen($currentPath) - (strlen($fileName)+1)).$fileName;
            }
            $directories[$currentPath] = array(
                'id' => $currentPath,
                //'text' => '<span style="display:none;">'.substr($currentPath, 0, strlen($currentPath) - (strlen($fileName)+1)).'</span>'.$fileName,
                'text' => $fileName,
                'cls' => 'folder',
                'iconCls' => 'icon icon-folder',
                'type' => 'dir',
                'leaf' => false,
                'path' => $currentPath,
                'pathRelative' => $currentPath,
                'perms' => '',
            );

            $directories[$currentPath]['menu'] = array('items' => $this->getDirectoriesContextMenu());
        }

        foreach ($listFiles as $idx => $currentPath) {
            if ($currentPath == $path) continue;

            $fileName = basename($currentPath);

            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $ext = $useMultiByte ? mb_strtolower($ext, $encoding) : strtolower($ext);

            $cls = array();

            $encoded = explode('/', $currentPath);
            $encoded = array_map('urlencode', $encoded);
            $encoded = implode('/', $encoded);
            $encoded = rtrim($this->properties['url'], '/') . '/' . $encoded;

            $url = rtrim($this->properties['url'], '/') . '/' . $currentPath;
            $page = '?a=' . $editAction . '&file=' . $currentPath . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id');

            if ($this->hasPermission('file_remove')) $cls[] = 'premove';
            if ($this->hasPermission('file_update')) $cls[] = 'pupdate';

            $fileNames[] = strtoupper($fileName);
            $files[$currentPath] = array(
                'id' => $currentPath,
                'text' => $fileName,
                'cls' => implode(' ', $cls),
                'iconCls' => 'icon icon-file icon-' . $ext,
                'type' => 'file',
                'leaf' => true,
                'path' => $currentPath,
                'page' => $this->isBinary($encoded) ? $page : null,
                'pathRelative' => $url,
                'directory' => $currentPath,
                'url' => $url,
                'file' => $currentPath,
            );
            $files[$currentPath]['menu'] = array('items' => $this->getFilesContextMenu($files[$currentPath]));

            if (!$hideTooltips) {
                $files[$currentPath]['qtip'] = in_array($ext, $imageExtensions) ? '<img src="' . $url . '" alt="' . $fileName . '" />' : '';
            }
        }

        $list = [];

        array_multisort($dirNames, SORT_ASC, SORT_STRING, $directories);
        foreach ($directories as $dir) {
            $list[] = $dir;
        }

        array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);
        foreach ($files as $file) {
            $list[] = $file;
        }

        return $list;
    }

    /**
     * Get a list of objects from within a bucket
     * @param string $dir
     * @return array
     */
    public function listDirectory($dir)
    {
        $c['delimiter'] = '/';
        if (!empty($dir) && $dir != '/') {
            $c['prefix'] = $dir;
        }

        try {
            $result = $this->driver->listObjects([
                'Bucket' => $this->bucket,
                'Prefix' => ltrim($dir, '/'),
                'Delimiter' => '/'
            ]);
        } catch (Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] ' . $e->getMessage());
            return [[], []];
        }

        $directories = [];
        $files = [];

        $prefixes = $result->get('CommonPrefixes');
        if ( is_array($prefixes) ) {
            foreach ($prefixes as $folder) {
                $directories[] = $folder['Prefix'];
            }
        }

        $contents = $result->get('Contents');
        if ( is_array($contents)) {
            foreach ($contents as $file) {
                $files[] = $file['Key'];
            }
        }

        return [$files, $directories];
    }

    /**
     * Get the ID of the edit file action
     *
     * @return boolean|int
     */
    public function getEditActionId()
    {
        return 'system/file/edit';
    }

    /**
     * Get the context menu for directories when viewing the source as a tree
     *
     * @return array
     */
    public function getDirectoriesContextMenu()
    {
        $menu = [];

        if ($this->hasPermission('directory_create')) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_folder_create_here'),
                'handler' => 'this.createDirectory',
            ];
        }

        if ($this->hasPermission('directory_update') && $this->getOption('allowFolderCopy', $this->properties, false) ) {//&& $this->checkPolicy('save')) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('rename'),
                'handler' => 'this.renameDirectory',
            ];
        }

        $menu[] = [
            'text' => $this->xpdo->lexicon('directory_refresh'),
            'handler' => 'this.refreshActiveNode',
        ];

        if ($this->hasPermission('file_upload')) {
            $menu[] = '-';

            $menu[] = [
                'text' => $this->xpdo->lexicon('upload_files'),
                'handler' => 'this.uploadFiles',
            ];
        }

        if ($this->hasPermission('file_create')) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_create'),
                'handler' => 'this.createFile',
            ];

            $menu[] = [
                'text' => $this->xpdo->lexicon('quick_create_file'),
                'handler' => 'this.quickCreateFile',
            ];
        }

        if ($this->hasPermission('directory_remove')) {
            $menu[] = '-';

            $menu[] = [
                'text' => $this->xpdo->lexicon('file_folder_remove'),
                'handler' => 'this.removeDirectory',
            ];
        }

        return $menu;
    }

    /**
     * Tells if a file is a binary file or not.
     *
     * @param string $file
     * @param boolean $isContent If the passed string in $file is actual file content
     *
     * @return boolean True if a binary file.
     */
    public function isBinary($file, $isContent = false)
    {
        if (!$isContent) {
            $fh = @fopen($file, 'r');
            $blk = @fread($fh, 512);

            @fclose($fh);
            @clearstatcache();

            return (substr_count($blk, "^ -~" /*. "^\r\n"*/) / 512 > 0.3) || (substr_count($blk, "\x00") > 0) ? false : true;
        }

        $content = str_replace(array("\n", "\r", "\t"), '', $file);

        return ctype_print($content) ? false : true;
    }

    /**
     * Get the context menu for files when viewing the source as a tree
     *
     * @param array $fileArray
     * @return array
     */
    public function getFilesContextMenu(array $fileArray)
    {
        $menu = [];

        if ($this->hasPermission('file_update')) {
            if ($fileArray['page'] != null) {
                $menu[] = [
                    'text' => $this->xpdo->lexicon('file_edit'),
                    'handler' => 'this.editFile',
                ];

                $menu[] = [
                    'text' => $this->xpdo->lexicon('quick_update_file'),
                    'handler' => 'this.quickUpdateFile',
                ];
            }

            $menu[] = [
                'text' => $this->xpdo->lexicon('rename'),
                'handler' => 'this.renameFile',
            ];
        }

        if ($this->hasPermission('file_view')) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_download'),
                'handler' => 'this.downloadFile',
            ];
        }

        if ($this->hasPermission('file_remove')) {
            if (!empty($menu)) $menu[] = '-';

            $menu[] = [
                'text' => $this->xpdo->lexicon('file_remove'),
                'handler' => 'this.removeFile',
            ];
        }

        return $menu;
    }

    /**
     * Get all files in the directory and prepare thumbnail views
     *
     * @param string $path
     *
     * @return array
     */
    public function getObjectsInContainer($path)
    {
        $properties = $this->getPropertyList();
        /** Need to check for the root/parent of Media Source to add the proper baseDir if set. */
        if ( empty(trim($path, '/'))) {
            $base_dir = $this->xpdo->getOption('baseDir', $this->properties, '');
            $path = trim($base_dir, '/') . '/' ;
        }
        list($listFiles) = $this->listDirectory($path);
        $editAction = $this->getEditActionId();

        $modAuth = $this->xpdo->user->getUserToken($this->xpdo->context->get('key'));

        /* get default settings */
        $use_multibyte = $this->ctx->getOption('use_multibyte', false);
        $encoding = $this->ctx->getOption('modx_charset', 'UTF-8');
        $bucketUrl = rtrim($properties['url'], '/') . '/';
        $allowedFileTypes = $this->getOption('allowedFileTypes', $this->properties, '');
        $allowedFileTypes = !empty($allowedFileTypes) && is_string($allowedFileTypes) ? explode(',', $allowedFileTypes) : $allowedFileTypes;
        $imageExtensions = $this->getOption('imageExtensions', $this->properties, 'jpg,jpeg,png,gif');
        $imageExtensions = explode(',', $imageExtensions);
        $thumbnailType = $this->getOption('thumbnailType', $this->properties, 'png');
        $thumbnailQuality = $this->getOption('thumbnailQuality', $this->properties, 90);
        $skipFiles = $this->getOption('skipFiles', $this->properties, '.svn,.git,_notes,nbproject,.idea,.DS_Store');
        $skipFiles = explode(',', $skipFiles);
        $skipFiles[] = '.';
        $skipFiles[] = '..';

        /* iterate */
        $files = array();
        $fileNames = array();

        foreach ($listFiles as $idx => $currentPath) {
            if ($currentPath == $path) continue;
            if (in_array($currentPath, $skipFiles)) continue;
            
            $url = $bucketUrl . trim($currentPath, '/');
            $fileName = basename($currentPath);

            $encoded = explode('/', $currentPath);
            $encoded = array_map('urlencode', $encoded);
            $encoded = implode('/', $encoded);
            $encoded = rtrim($this->properties['url'], '/') . '/' . $encoded;

            $page = '?a=' . $editAction . '&file=' . $currentPath . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id');

            $fileNames[] = strtoupper($fileName);
            $fileArray = array(
                'id' => $currentPath,
                'name' => $fileName,
                'url' => $url,
                'relativeUrl' => $url,
                'fullRelativeUrl' => $url,
                'pathname' => $url,
                'pathRelative' => $currentPath,
                'size' => 0,
                'page' => $this->isBinary($encoded) ? $page : null,
                'leaf' => true,
            );

            $fileArray['ext'] = pathinfo($fileName, PATHINFO_EXTENSION);
            $fileArray['ext'] = $use_multibyte ? mb_strtolower($fileArray['ext'], $encoding) : strtolower($fileArray['ext']);
            $fileArray['cls'] = 'icon-' . $fileArray['ext'];

            if (!empty($allowedFileTypes) && !in_array($fileArray['ext'], $allowedFileTypes)) continue;

            if (in_array($fileArray['ext'], $imageExtensions)) {
                $imageWidth = $this->ctx->getOption('filemanager_image_width', 400);
                $imageHeight = $this->ctx->getOption('filemanager_image_height', 300);
                $thumbWidth = $this->ctx->getOption('filemanager_thumb_width', 100);
                $thumbHeight = $this->ctx->getOption('filemanager_thumb_height', 80);

                $size = @getimagesize($url);
                if (is_array($size)) {
                    $imageWidth = $size[0] > 800 ? 800 : $size[0];
                    $imageHeight = $size[1] > 600 ? 600 : $size[1];
                }

                if ($thumbWidth > $imageWidth) $thumbWidth = $imageWidth;
                if ($thumbHeight > $imageHeight) $thumbHeight = $imageHeight;

                $thumbQuery = http_build_query(array(
                    'src' => $url,
                    'w' => $thumbWidth,
                    'h' => $thumbHeight,
                    'f' => $thumbnailType,
                    'q' => $thumbnailQuality,
                    'HTTP_MODAUTH' => $modAuth,
                    'wctx' => $this->ctx->get('key'),
                    'source' => $this->get('id'),
                ));
                
                $imageQuery = http_build_query(array(
                    'src' => $url,
                    'w' => $imageWidth,
                    'h' => $imageHeight,
                    'HTTP_MODAUTH' => $modAuth,
                    'f' => $thumbnailType,
                    'q' => $thumbnailQuality,
                    'wctx' => $this->ctx->get('key'),
                    'source' => $this->get('id'),
                ));
                
                $fileArray['thumb'] = $this->ctx->getOption('connectors_url', MODX_CONNECTORS_URL).'system/phpthumb.php?'.urldecode($thumbQuery);
                $fileArray['thumb_width'] = $thumbWidth;
                $fileArray['thumb_height'] = $thumbHeight;
                
//                $fileArray['image'] = $this->ctx->getOption('connectors_url', MODX_CONNECTORS_URL).'system/phpthumb.php?'.urldecode($imageQuery);
                $fileArray['image'] = $url;
                $fileArray['image_width'] = is_array($size) ? $size[0] : $imageWidth;
                $fileArray['image_height'] = is_array($size) ? $size[1] : $imageHeight;
                
                $fileArray['preview'] = 1;
            } else {
                $fileArray['thumb'] = $fileArray['image'] = $this->ctx->getOption('manager_url', MODX_MANAGER_URL).'templates/default/images/restyle/nopreview.jpg';
                $fileArray['thumb_width'] = $fileArray['image_width'] = $this->ctx->getOption('filemanager_thumb_width', 100);
                $fileArray['thumb_height'] = $fileArray['image_height'] = $this->ctx->getOption('filemanager_thumb_height', 80);
                $fileArray['preview'] = 0;
            }

            $files[$fileName] = $fileArray;
            $files[$fileName]['menu'] = $this->getFilesContextMenu($files[$fileName]);

        }

        $list = array();
        array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);

        foreach ($files as $file) {
            $list[] = $file;
        }

        return $list;
    }

    /**
     * Create a Container
     *
     * @param string $name
     * @param string $parentContainer
     *
     * @return boolean
     */
    public function createContainer($name, $parentContainer)
    {
        /** Need to check for the root/parent of Media Source to add the proper baseDir if set. */
        if ( empty(trim($parentContainer, '/'))) {
            $base_dir = $this->xpdo->getOption('baseDir', $this->properties, '');
            $parentContainer = trim($base_dir, '/') . '/' ;
        }

        $newPath = ltrim($parentContainer . rtrim($name, '/') . '/', '/');

        try {
            $exists = $this->driver->doesObjectExist($this->bucket, $newPath);
            if ($exists) {
                $this->addError('file', $this->xpdo->lexicon('file_folder_err_ae') . ': ' . $newPath);
                return false;
            }

            $this->driver->putObject([
                'Bucket' => $this->bucket,
                'Key' => $newPath,
                'ACL' => 'public-read',
                'Body' => ''
            ]);
        } catch (Exception $e) {
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_create') . $newPath);

            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when creating container: ' . $e->getMessage());

            return false;
        }

        $this->xpdo->logManagerAction('directory_create', '', $newPath);

        return true;
    }

    /**
     * Rename a container
     *
     * @param string $oldPath
     * @param string $newName
     * @param boolean $newNameFullPath false
     * @param boolean $delete if true will delete the oldPath, otherwise creates a duplicate copy
     *
     * @return boolean
     */
    public function renameContainer($oldPath, $newName, $newNameFullPath=false, $delete=true)
    {
        if (!$this->getOption('allowFolderCopy', $this->properties, false)) {
            return false;
        }
        $source_key = $this->cleanKey($oldPath);
        if (!$newNameFullPath) {
            // get the base from the old path:
            $newName = substr($oldPath, 0, strlen($oldPath) - (strlen(basename($oldPath))+1)).''.$newName;
        }
        /** @var  $new_key ~ needs the full path! */
        $new_key = trim($newName).'/';

        $base_same = '';
        $chars = str_split($source_key);
        $new_chars = str_split($new_key);
        foreach ($chars as $x => $char) {
            if ($char = $new_chars[$x]) {
                $base_same .= $char;
            }
            break;
        }

        try {
            if (!$this->driver->doesObjectExist($this->bucket, $oldPath)) {
                $this->addError('file', $this->xpdo->lexicon('file_folder_err_ns') . ': ' . $oldPath);
                return false;
            }
            $use_batch = false;
            // Copy the main object, single:
            if ( $use_batch ) {
                $this->driver->copyObject(array(
                    'Bucket' => $this->bucket,
                    'Key' => $new_key,
                    'CopySource' => "{$this->bucket}/{$source_key}",
                ));
            }
            // now copy any children:
            // Perform a batch of CopyObject operations.
            $batch = $this->copyDirectory($source_key, $new_key, $use_batch);

            if ($use_batch) {
                /**
                 * This appears to be the preferred method BUT Batch caused:
                 * PHP Fatal error: Call to a member function getHandlerList() on array in
                 * core/components/awss3mediasource/model/vendor/aws/aws-sdk-php/src/AwsClientTrait.php
                 * on line 64
                 */
                try {
                    $successful = $this->driver->execute($batch);
                    $failed = array();
                    $delete = true;
                } catch (\Guzzle\Service\Exception\CommandTransferException $e) {
                    $successful = $e->getSuccessfulCommands();
                    $failed = $e->getFailedCommands();
                    $this->addError('file', 'Error occurred when renaming folder, failed: ' . $e->getFailedCommands() .
                        PHP_EOL . '    Successful: ' . $e->getSuccessfulCommands() .
                        PHP_EOL . '    Source: ' . $source_key . ' New: ' . $new_key);
                    $delete = false;
                }
            }
            //$this->xpdo->logManagerAction('directory_copy', '', $path);
            if ( $delete ) {
                // delete the folder:
                return $this->removeContainer($oldPath);
            }

        } catch (Exception $e) {
            $this->addError('file', 'Error occurred when renaming container (copy and delete old): ' . $e->getMessage().
                PHP_EOL.' Path: '. $source_key.' New: '.$new_key);
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when renaming container (copy and delete old): ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param $key
     * @param $new_key
     * @param bool $batch
     * @param array $batch_commands
     *
     * @return array
     */
    protected function copyDirectory($key, $new_key, $batch=false, $batch_commands=array())
    {
        // copy the folder object:
        if ( $batch ) {
            $batch_commands[] = $this->driver->getCommand('CopyObject', array(
                'Bucket' => $this->driver,
                'Key' => "{$new_key}",
                'CopySource' => "{$this->driver}/{$key}",
            ));
        } else {
            $this->driver->copyObject(array(
                'Bucket'     => $this->bucket,
                'Key'        => $new_key,
                'CopySource' => "{$this->bucket}/{$key}",
            ));
        }

        list($listFiles, $listDirectories) = $this->listDirectory($key);

        // copy child directories:
        foreach ($listDirectories as $idx => $child_key) {
            // folder/child/ to
            if ($child_key == $key) continue;

            $new_child_key = substr_replace($child_key, $new_key, 0, strlen($key));
            $batch_commands = $this->copyDirectory($child_key, $new_child_key, $batch, $batch_commands);
        }
        // copy files:
        foreach ($listFiles as $idx => $child_key) {
            if ($child_key == $key) continue;

            $new_child_key = substr_replace($child_key, $new_key, 0, strlen($key));
            if ( $batch ) {
                $batch_commands[] = $this->driver->getCommand('CopyObject', array(
                    'Bucket' => $this->driver,
                    'Key' => "{$new_child_key}",
                    'CopySource' => "{$this->driver}/{$child_key}",
                ));
            } else {
                $this->driver->copyObject(array(
                    'Bucket'     => $this->bucket,
                    'Key'        => $new_child_key,
                    'CopySource' => "{$this->bucket}/{$child_key}",
                ));
            }
        }

        return $batch_commands;
    }

    protected function makeNewKey($base, $key, $new)
    {

    }
    /**
     * Remove an empty folder
     *
     * @param $path
     *
     * @return boolean
     */
    public function removeContainer($path)
    {
        /**
         * Need the trailing delimiter
         */
        $path = trim($this->cleanKey($path), '/').'/';

        try {
            /** Since S3 is flat file, the "folder" may not exist but it may have matching prefixes so search for them */
            $exists = false;
            $iterator = $this->driver->getIterator(
                'ListObjects',
                array(
                    'Bucket' => $this->bucket,
                    'Prefix' => $path
                ),
                array('limit'  => 1)
            );
            foreach ($iterator as $object) {
                $exists = true;
            }
            // this most find an exact match: if (!$this->driver->doesObjectExist($this->bucket, $path)) {
            if (!$exists) {
                $this->addError('file', $this->xpdo->lexicon('file_folder_err_ns') . ': ' . $path);
                return false;
            }
        
            $this->driver->deleteMatchingObjects($this->bucket, $path);
        } catch (Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when deleting container: ' . $e->getMessage());
            return false;
        }

        $this->xpdo->logManagerAction('directory_remove', '', $path);

        return true;
    }

    /**
     * @return string
     */
    public function getBaseDir()
    {
        return trim($this->xpdo->getOption('baseDir', $this->properties, ''), '/');
    }

    /**
     * @param modMediaSource $fromSource
     * @param string $from_path
     * @param bool $cli
     * @param string $object_type
     * @param string $container
     * @param string $method
     */
    public function transferObjects($fromSource, $from_path, $cli=false, $object_type='file', $container='', $method='copy')
    {
        if ($container == '/' || $container == '.') $container = '';

        if ( $object_type == 'file') {
            $file = $fromSource->getObjectContents($from_path);
            $new_path = $container;

            if ($this->transferFile($file['path'], $new_path, $cli, $method) && $method == 'move') {
                // remove from the source:
                /** @var modFile $file */
                $remove_file = $fromSource->fileHandler->make($file['path']);
                $error = false;
                /* verify file exists and is writable */
                if (!$remove_file->exists()) {
                    $error = $this->xpdo->lexicon('file_err_nf').': '.$remove_file->getPath();
                } else if (!$remove_file->isReadable() || !$remove_file->isWritable()) {
                    $error = $this->xpdo->lexicon('file_err_perms_remove');
                } else if (!($remove_file instanceof modFile)) {
                    $error = $this->xpdo->lexicon('file_err_invalid');
                } else if (!$remove_file->remove()) {
                    $error = $this->xpdo->lexicon('file_err_remove');
                }

                if ($error && is_object($cli)) {
                    $cli->to('error')->red('Error occurred when attempting to remove file: ' . $error);
                    return false;
                }
            }
        } else {
            $list = $fromSource->getContainerList($from_path);
            if ( count($list) > 0) {
                foreach ($list as $object) {
                    $new_dir = rtrim($container, '/').'/';
                    if ( $object['type'] == 'dir' ) {
                        // recursion:
                        $new_dir = ltrim($new_dir.basename($object['id']), '/');

                        $this->transferObjects($fromSource, $object['id'], $cli, 'dir', $new_dir, $method);
                        continue;
                    }
                    // copy/move file:
                    $new_path = trim($new_dir. $object['text'], '/');

                    if ($this->transferFile($object['path'], $new_path, $cli, $method) && $method == 'move') {
                        // remove from the source:
                        /** @var modFile $file */
                        $remove_file = $fromSource->fileHandler->make($object['path']);
                        $error = false;
                        /* verify file exists and is writable */
                        if (!$remove_file->exists()) {
                            $error = $this->xpdo->lexicon('file_err_nf').': '.$remove_file->getPath();
                        } else if (!$remove_file->isReadable() || !$remove_file->isWritable()) {
                            $error = $this->xpdo->lexicon('file_err_perms_remove');
                        } else if (!($remove_file instanceof modFile)) {
                            $error = $this->xpdo->lexicon('file_err_invalid');
                        } else if (!$remove_file->remove()) {
                            $error = $this->xpdo->lexicon('file_err_remove');
                        }

                        if ($error && is_object($cli)) {
                            $cli->to('error')->red('Error occurred when attempting to remove file: ' . $error);
                            //return false;
                        }
                    }
                }
            }
        }

    }

    /**
     * @param string $source_file complete file path
     * @param string $new_path
     * @param object $cli
     * @param string $method copy or move
     *
     * @return bool
     */
    protected function transferFile($source_file, $new_path, $cli, $method)
    {
        $ext = @pathinfo($source_file, PATHINFO_EXTENSION);
        $ext = strtolower($ext);

        $contentType = $this->getContentType($ext);

        try {
            $this->driver->putObject([
                'Bucket' => $this->bucket,
                'Key' => $new_path,
                'ACL' => 'public-read',
                'ContentType' => $contentType,
                'SourceFile' => $source_file
            ]);
            // if index.html:
            $org_file_name = @pathinfo($new_path, PATHINFO_BASENAME);
            if ( $org_file_name == 'index.html' || $org_file_name == 'index.htm') {
                // make it the root:
                $cli->out('Make Index: '.ucfirst($method).' file from: '.$source_file.' to '.substr($new_path, 0, strlen($new_path) - strlen($org_file_name)).' ');
                $this->transferFile($source_file, substr($new_path, 0, strlen($new_path) - strlen($org_file_name)), $cli, $method);
            }
        } catch (Exception $e) {
            if (is_object($cli)) {
                $cli->to('error')->red('Error occurred when attempting to ' . $method . ' file: ' . $e->getMessage());
            }
            return false;
        }

        if (is_object($cli)) {
            $cli->out(ucfirst($method).' file from: '.$source_file.' to '.$new_path.' complete ');
        }
        return true;
    }
    /**
     * Upload files to Swift
     *
     * @param string $container
     * @param array $files
     *
     * @return bool
     */
    public function uploadObjectsToContainer($container, array $files = array())
    {
        if ($container == '/' || $container == '.') $container = '';
        $container = ltrim(trim($this->xpdo->getOption('baseDir', $this->properties, ''), '/') . '/'.$container, '/');

        $allowedFileTypes = explode(',', $this->xpdo->getOption('upload_files', null, ''));
        $allowedFileTypes = array_merge(explode(',', $this->xpdo->getOption('upload_images')), explode(',', $this->xpdo->getOption('upload_media')), explode(',', $this->xpdo->getOption('upload_flash')), $allowedFileTypes);
        $allowedFileTypes = array_unique($allowedFileTypes);
        $maxFileSize = $this->xpdo->getOption('upload_maxsize', null, 1048576);

        foreach ($files as $file) {
            if ($file['error'] != 0) continue;
            if (empty($file['name'])) continue;
            $ext = @pathinfo($file['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);

            if (empty($ext) || !in_array($ext, $allowedFileTypes)) {
                $this->addError('path', $this->xpdo->lexicon('file_err_ext_not_allowed', array(
                    'ext' => $ext,
                )));

                continue;
            }

            $size = @filesize($file['tmp_name']);

            if ($size > $maxFileSize) {
                $this->addError('path', $this->xpdo->lexicon('file_err_too_large', array(
                    'size' => $size,
                    'allowed' => $maxFileSize,
                )));

                continue;
            }

            $newPath = $container . $file['name'];

            $contentType = $this->getContentType($ext);

            try {
                $this->driver->putObject([
                    'Bucket' => $this->bucket,
                    'Key' => $newPath,
                    'ACL' => 'public-read',
                    'ContentType' => $contentType,
                    'SourceFile' => $file['tmp_name']
                ]);
            } catch (Exception $e) {
                $this->addError('path', $this->xpdo->lexicon('file_err_upload'));

                $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when uploading file: ' . $e->getMessage());
            }
        }

        if ($this->hasErrors() == true) return false;

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerUpload', array(
            'files' => &$files,
            'directory' => $container,
            'source' => &$this,
        ));

        $this->xpdo->logManagerAction('file_upload', '', $container);

        return true;
    }

    /**
     * Get the content type of the file based on extension
     * @param string $ext
     * @return string
     */
    protected function getContentType($ext)
    {
        $mimeTypes = array(
            '323' => 'text/h323',
            'acx' => 'application/internet-property-stream',
            'ai' => 'application/postscript',
            'aif' => 'audio/x-aiff',
            'aifc' => 'audio/x-aiff',
            'aiff' => 'audio/x-aiff',
            'asf' => 'video/x-ms-asf',
            'asr' => 'video/x-ms-asf',
            'asx' => 'video/x-ms-asf',
            'au' => 'audio/basic',
            'avi' => 'video/x-msvideo',
            'axs' => 'application/olescript',
            'bas' => 'text/plain',
            'bcpio' => 'application/x-bcpio',
            'bin' => 'application/octet-stream',
            'bmp' => 'image/bmp',
            'c' => 'text/plain',
            'cat' => 'application/vnd.ms-pkiseccat',
            'cdf' => 'application/x-cdf',
            'cer' => 'application/x-x509-ca-cert',
            'class' => 'application/octet-stream',
            'clp' => 'application/x-msclip',
            'cmx' => 'image/x-cmx',
            'cod' => 'image/cis-cod',
            'cpio' => 'application/x-cpio',
            'crd' => 'application/x-mscardfile',
            'crl' => 'application/pkix-crl',
            'crt' => 'application/x-x509-ca-cert',
            'csh' => 'application/x-csh',
            'css' => 'text/css',
            'dcr' => 'application/x-director',
            'der' => 'application/x-x509-ca-cert',
            'dir' => 'application/x-director',
            'dll' => 'application/x-msdownload',
            'dms' => 'application/octet-stream',
            'doc' => 'application/msword',
            'dot' => 'application/msword',
            'dvi' => 'application/x-dvi',
            'dxr' => 'application/x-director',
            'eps' => 'application/postscript',
            'etx' => 'text/x-setext',
            'evy' => 'application/envoy',
            'exe' => 'application/octet-stream',
            'fif' => 'application/fractals',
            'flr' => 'x-world/x-vrml',
            'gif' => 'image/gif',
            'gtar' => 'application/x-gtar',
            'gz' => 'application/x-gzip',
            'h' => 'text/plain',
            'hdf' => 'application/x-hdf',
            'hlp' => 'application/winhlp',
            'hqx' => 'application/mac-binhex40',
            'hta' => 'application/hta',
            'htc' => 'text/x-component',
            'htm' => 'text/html',
            'html' => 'text/html',
            'htt' => 'text/webviewhtml',
            'ico' => 'image/x-icon',
            'ief' => 'image/ief',
            'iii' => 'application/x-iphone',
            'ins' => 'application/x-internet-signup',
            'isp' => 'application/x-internet-signup',
            'jfif' => 'image/pipeg',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'js' => 'application/x-javascript',
            'latex' => 'application/x-latex',
            'lha' => 'application/octet-stream',
            'lsf' => 'video/x-la-asf',
            'lsx' => 'video/x-la-asf',
            'lzh' => 'application/octet-stream',
            'm13' => 'application/x-msmediaview',
            'm14' => 'application/x-msmediaview',
            'm3u' => 'audio/x-mpegurl',
            'man' => 'application/x-troff-man',
            'mdb' => 'application/x-msaccess',
            'me' => 'application/x-troff-me',
            'mht' => 'message/rfc822',
            'mhtml' => 'message/rfc822',
            'mid' => 'audio/mid',
            'mny' => 'application/x-msmoney',
            'mov' => 'video/quicktime',
            'movie' => 'video/x-sgi-movie',
            'mp2' => 'video/mpeg',
            'mp3' => 'audio/mpeg',
            'mpa' => 'video/mpeg',
            'mpe' => 'video/mpeg',
            'mpeg' => 'video/mpeg',
            'mpg' => 'video/mpeg',
            'mpp' => 'application/vnd.ms-project',
            'mpv2' => 'video/mpeg',
            'ms' => 'application/x-troff-ms',
            'mvb' => 'application/x-msmediaview',
            'nws' => 'message/rfc822',
            'oda' => 'application/oda',
            'p10' => 'application/pkcs10',
            'p12' => 'application/x-pkcs12',
            'p7b' => 'application/x-pkcs7-certificates',
            'p7c' => 'application/x-pkcs7-mime',
            'p7m' => 'application/x-pkcs7-mime',
            'p7r' => 'application/x-pkcs7-certreqresp',
            'p7s' => 'application/x-pkcs7-signature',
            'pbm' => 'image/x-portable-bitmap',
            'pdf' => 'application/pdf',
            'pfx' => 'application/x-pkcs12',
            'pgm' => 'image/x-portable-graymap',
            'pko' => 'application/ynd.ms-pkipko',
            'pma' => 'application/x-perfmon',
            'pmc' => 'application/x-perfmon',
            'pml' => 'application/x-perfmon',
            'pmr' => 'application/x-perfmon',
            'pmw' => 'application/x-perfmon',
            'png' => 'image/png',
            'pnm' => 'image/x-portable-anymap',
            'pot' => 'application/vnd.ms-powerpoint',
            'ppm' => 'image/x-portable-pixmap',
            'pps' => 'application/vnd.ms-powerpoint',
            'ppt' => 'application/vnd.ms-powerpoint',
            'prf' => 'application/pics-rules',
            'ps' => 'application/postscript',
            'pub' => 'application/x-mspublisher',
            'qt' => 'video/quicktime',
            'ra' => 'audio/x-pn-realaudio',
            'ram' => 'audio/x-pn-realaudio',
            'ras' => 'image/x-cmu-raster',
            'rgb' => 'image/x-rgb',
            'rmi' => 'audio/mid',
            'roff' => 'application/x-troff',
            'rtf' => 'application/rtf',
            'rtx' => 'text/richtext',
            'scd' => 'application/x-msschedule',
            'sct' => 'text/scriptlet',
            'setpay' => 'application/set-payment-initiation',
            'setreg' => 'application/set-registration-initiation',
            'sh' => 'application/x-sh',
            'shar' => 'application/x-shar',
            'sit' => 'application/x-stuffit',
            'snd' => 'audio/basic',
            'spc' => 'application/x-pkcs7-certificates',
            'spl' => 'application/futuresplash',
            'src' => 'application/x-wais-source',
            'sst' => 'application/vnd.ms-pkicertstore',
            'stl' => 'application/vnd.ms-pkistl',
            'stm' => 'text/html',
            'svg' => 'image/svg+xml',
            'sv4cpio' => 'application/x-sv4cpio',
            'sv4crc' => 'application/x-sv4crc',
            't' => 'application/x-troff',
            'tar' => 'application/x-tar',
            'tcl' => 'application/x-tcl',
            'tex' => 'application/x-tex',
            'texi' => 'application/x-texinfo',
            'texinfo' => 'application/x-texinfo',
            'tgz' => 'application/x-compressed',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'tr' => 'application/x-troff',
            'trm' => 'application/x-msterminal',
            'tsv' => 'text/tab-separated-values',
            'txt' => 'text/plain',
            'uls' => 'text/iuls',
            'ustar' => 'application/x-ustar',
            'vcf' => 'text/x-vcard',
            'vrml' => 'x-world/x-vrml',
            'wav' => 'audio/x-wav',
            'wcm' => 'application/vnd.ms-works',
            'wdb' => 'application/vnd.ms-works',
            'wks' => 'application/vnd.ms-works',
            'wmf' => 'application/x-msmetafile',
            'wps' => 'application/vnd.ms-works',
            'wri' => 'application/x-mswrite',
            'wrl' => 'x-world/x-vrml',
            'wrz' => 'x-world/x-vrml',
            'xaf' => 'x-world/x-vrml',
            'xbm' => 'image/x-xbitmap',
            'xla' => 'application/vnd.ms-excel',
            'xlc' => 'application/vnd.ms-excel',
            'xlm' => 'application/vnd.ms-excel',
            'xls' => 'application/vnd.ms-excel',
            'xlt' => 'application/vnd.ms-excel',
            'xlw' => 'application/vnd.ms-excel',
            'xof' => 'x-world/x-vrml',
            'xpm' => 'image/x-xpixmap',
            'xwd' => 'image/x-xwindowdump',
            'z' => 'application/x-compress',
            'zip' => 'application/zip'
        );
        
        if (isset($mimeTypes[strtolower($ext)])) {
            $contentType = $mimeTypes[strtolower($ext)];
        } else {
            $contentType = 'octet/application-stream';
        }
        
        return $contentType;
    }

    /**
     * Create an object from a path
     *
     * @param string $path
     * @param string $name
     * @param string $content
     *
     * @return boolean|string
     */
    public function createObject($path, $name, $content)
    {
        /** Need to check for the root/parent of Media Source to add the proper baseDir if set. */
        if ( empty(trim($path, '/'))) {
            $base_dir = $this->xpdo->getOption('baseDir', $this->properties, '');
            $path = trim($base_dir, '/') . '/' ;
        }

        $key = ltrim($path . trim($name, '/'), '/');
        $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, 'Key ' . $key);

        $key = $path . $name;
        $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, 'Key ' . $key);

        try {
            $exists = $this->driver->doesObjectExist($this->bucket, $key);
            if ($exists) {
                $this->addError('file', $this->xpdo->lexicon('file_folder_err_ae') . ': ' . $key);
                return false;
            }
        
            $this->driver->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ACL' => 'public-read',
                'Body' => $content
            ]);
        } catch (Exception $e) {
            $this->addError('name', $this->xpdo->lexicon('file_err_create') . $key);

            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when creating object: ' . $e->getMessage());

            return false;
        }

        $this->xpdo->logManagerAction('file_create', '', $key);

        return true;
    }

    /**
     * Update the contents of a specific object
     *
     * @param string $path
     * @param string $content
     *
     * @return boolean
     */
    public function updateObject($path, $content)
    {
        try {
            $this->driver->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'ACL' => 'public-read',
                'Body' => $content
            ]);
        } catch (Exception $e) {
            $this->addError('name', $this->xpdo->lexicon('file_err_update') . $path);

            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when updating object: ' . $e->getMessage());

            return false;
        }

        $this->xpdo->logManagerAction('file_update', '', $path);
        return true;
    }

    /**
     * Rename/move a file
     *
     * @param string $oldPath
     * @param string $newName
     *
     * @return bool
     */
    public function renameObject($oldPath, $newName)
    {
        $oldPath = $this->cleanKey($oldPath);
        try {
            $exists = $this->driver->doesObjectExist($this->bucket, $oldPath);
            if (!$exists) {
                $this->addError('file', $this->xpdo->lexicon('file_folder_err_ns') . ': ' . $oldPath);
                return false;
            }
    
            $dir = dirname($oldPath);
            $newPath = ($dir != '.' ? $dir . '/' : '') . $newName;
        
            $this->driver->copyObject([
                'ACL' => 'public-read',
                'Bucket' => $this->bucket,
                'CopySource' => $this->bucket . '/' . $oldPath,
                'Key' => $newPath
            ]);

            $this->driver->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $oldPath
            ]);
        } catch (Exception $e) {
            $this->addError('file', $this->xpdo->lexicon('file_folder_err_rename') . ': ' . $oldPath);

            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when renaming object: ' . $e->getMessage());

            return false;
        }

        $this->xpdo->logManagerAction('file_rename', '', $oldPath);

        return true;
    }

    /**
     * Move a file or folder to a specific location
     *
     * @param string $from The location to move from, full path
     * @param string $to The location to move to, new directory path only
     * @param string $point
     *
     * @return boolean
     */
    public function moveObject($from, $to, $point = 'append')
    {
        // Does the file/folder to be moved exist?
        $existsFrom = $this->driver->doesObjectExist($this->bucket, $from);
        if (!$existsFrom) {
            $this->xpdo->error->message = $this->xpdo->lexicon('file_err_ns') . ': ' . $from;

            return false;
        }

        if ($to != '/') {
            // Does the destination folder exist?
            $existsTo = $this->driver->doesObjectExist($this->bucket, $to);
            if (!$existsTo) {
                $this->xpdo->error->message = $this->xpdo->lexicon('file_err_ns') . ': ' . $to;

                return false;
            }

            if ($point != 'append') {
                $dir = dirname(rtrim($to, '/'));
                $dir = ($dir == '.') ? '' : $dir . '/';

                $toPath = $dir . basename($from);
            } else {
                $toPath = rtrim($to, '/') . '/' . basename($from);
            }
        } else {
            $toPath = basename($from);
        }

        try {
            // This is a folder:
            if (substr(strrev($from), 0, 1) == '/') {
                if (!$this->getOption('allowFolderCopy', $this->properties, false)) {
                    $this->xpdo->error->message = $this->xpdo->lexicon('s3_no_move_folder', array(
                        'from' => $from
                    ));
                    return false;
                } else {
                    return $this->renameContainer($from, $toPath, true);
                }
            } else {
                // This is a file
                $this->driver->copyObject([
                    'ACL' => 'public-read',
                    'Bucket' => $this->bucket,
                    'CopySource' => $this->bucket . '/' . $from,
                    'Key' => $toPath
                ]);

                $this->driver->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $from
                ]);
            }
        } catch (Exception $e) {
            $this->xpdo->error->message = $this->xpdo->lexicon('file_folder_err_rename') . ': ' . $to . ' -> ' . $from;

            return false;
        }

        return true;
    }

    /**
     * @param string $path
     *
     * @return string $path
     */
    protected function cleanKey($path)
    {
        /**
         * Files need only the Key which appears to be the relative path.
         * So the URL property that MODX adds to the passed parameter needs to be removed.
         *
         * http://docs.aws.amazon.com/AmazonS3/latest/dev/UsingMetadata.html#object-keys
         */
        $path = str_replace($this->getOption('url', $this->properties, ''), '', $path);
        return $path;
    }
    /**
     * Delete a file
     *
     * @param string $path
     *
     * @return boolean
     */
    public function removeObject($path)
    {
        $path = $this->cleanKey($path);
        try {
            $exists = $this->driver->doesObjectExist($this->bucket, $path);
            if (!$exists) {
                $this->addError('file', $this->xpdo->lexicon('file_folder_err_ns') . ': ' . $path);
                return false;
            }
        
            $this->driver->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path
            ]);
        } catch (Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when deleting object: ' . $e->getMessage());
            return false;
        }

        $this->xpdo->logManagerAction('file_remove', '', $path);

        return true;
    }

    /**
     * Get the contents of a specified file
     *
     * @param string $objectPath
     * @param boolean $resend_on_error if true will call on itself and attach the baseDir on 404
     *
     * @return array
     */
    public function getObjectContents($objectPath, $resend_on_error=true)
    {
        $imageExtensions = $this->getOption('imageExtensions', $this->properties, 'jpg,jpeg,png,gif');
        $imageExtensions = explode(',', $imageExtensions);
        $fileExtension = pathinfo($objectPath, PATHINFO_EXTENSION);

        try {
            $object = $this->driver->getObject([
                'Bucket' => $this->bucket,
                'Key' => $objectPath
            ]);
        } catch (Exception $e) {
            /** Need to check for the root/parent of Media Source to add the proper baseDir if set for the root Create File. */
            $base_dir = $this->xpdo->getOption('baseDir', $this->properties, '');
            if (!empty(trim($base_dir, '/')) && $resend_on_error) {
                $path = trim($base_dir, '/') . '/' .$objectPath ;
                return $this->getObjectContents($path, false);
            } else {
                $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[AWS S3 MS] Error occurred when retrieving object: ' . $objectPath . ' msg: ' . $e->getMessage());
                return false;
            }
        }

        $lastModified = $object->get('LastModified');
        $timeFormat = $this->ctx->getOption('manager_time_format');
        $dateFormat = $this->ctx->getOption('manager_date_format');

        if ($lastModified instanceof \DateTime) {
            $lastModified = $lastModified->format($dateFormat . ' ' . $timeFormat);
        }

        $contents = $object->get('Body')->getContents();
        $isBinary = $this->isBinary($contents, true);
        return [
            'name' => $objectPath,
            'basename' => basename($objectPath),
            'path' => $objectPath,
            'size' => $object->get('ContentLength'),
            'last_accessed' => '',
            'last_modified' => $lastModified,
            'content' => !$isBinary ? $contents : '',
            'image' => in_array($fileExtension, $imageExtensions) ? true : false,
            'is_writable' => !$isBinary,
            'is_readable' => true,
        ];
    }

    /**
     * @return array
     */
    public function getDefaultProperties()
    {
        return array(
            'url' => array(
                'name' => 'url',
                'desc' => 'prop_s3.url_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 'http://mysite.s3.amazonaws.com/',
                'lexicon' => 'core:source',
            ),
            'region' => array(
                'name' => 'region',
                'desc' => 'prop_s3.region_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'bucket' => array(
                'name' => 'bucket',
                'desc' => 'prop_s3.bucket_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'baseDir' => array(
                'name' => 'baseDir',
                'desc' => 'prop_s3.baseDir_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'awss3mediasource:source',
            ),
            'key' => array(
                'name' => 'key',
                'desc' => 'prop_s3.key_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'secret_key' => array(
                'name' => 'secret_key',
                'desc' => 'prop_s3.secret_key_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'imageExtensions' => array(
                'name' => 'imageExtensions',
                'desc' => 'prop_s3.imageExtensions_desc',
                'type' => 'textfield',
                'value' => 'jpg,jpeg,png,gif',
                'lexicon' => 'core:source',
            ),
            'thumbnailType' => array(
                'name' => 'thumbnailType',
                'desc' => 'prop_s3.thumbnailType_desc',
                'type' => 'list',
                'options' => array(
                    array('name' => 'PNG', 'value' => 'png'),
                    array('name' => 'JPG', 'value' => 'jpg'),
                    array('name' => 'GIF', 'value' => 'gif'),
                ),
                'value' => 'png',
                'lexicon' => 'core:source',
            ),
            'thumbnailQuality' => array(
                'name' => 'thumbnailQuality',
                'desc' => 'prop_s3.thumbnailQuality_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 90,
                'lexicon' => 'core:source',
            ),
            'skipFiles' => array(
                'name' => 'skipFiles',
                'desc' => 'prop_s3.skipFiles_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '.svn,.git,_notes,nbproject,.idea,.DS_Store',
                'lexicon' => 'core:source',
            ),
            'allowFolderCopy' => array(
                'name' => 'allowFolderCopy',
                'desc' => 'prop_s3.allowFolderCopy_desc',
                'type' => 'combo-boolean',
                'options' => '',
                'value' => '1',
                'lexicon' => 'core:source',
            ),
        );
    }

    /**
     * Get the base URL for this source. Only applicable to sources that are streams.
     *
     * @param string $object An optional object to find the base url of
     *
     * @return string
     */
    public function getBaseUrl($object = '')
    {
        return $this->properties['url'];
    }

    /**
     * @param string $object
     *
     * @return string
     */
    public function getObjectUrl($object = '')
    {
        $url = trim($this->properties['url'].$this->xpdo->getOption('baseDir', $this->properties, ''), '/');

        return $url . '/' . ltrim(str_replace($url, '', $object), '/');
    }
}