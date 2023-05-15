<?php

class AwsS3Fred
{
    public $modx = null;
    public $namespace = 'awss3mediasource';
    public $cache = null;
    public $options = [];

    public function __construct(modX &$modx, array $options = []) {
        $this->modx =& $modx;

        $corePath = $this->getOption('core_path', $options, $this->modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/awss3mediasource/');
        $assetsPath = $this->getOption('assets_path', $options, $this->modx->getOption('assets_path', null, MODX_ASSETS_PATH) . 'components/awss3mediasource/');
        $assetsUrl = $this->getOption('assets_url', $options, $this->modx->getOption('assets_url', null, MODX_ASSETS_URL) . 'components/awss3mediasource/');

        /* loads some default paths for easier management */
        $this->options = array_merge([
            'namespace' => $this->namespace,
            'corePath' => $corePath,
            'modelPath' => $corePath . 'model/',
            'assetsPath' => $assetsPath,
            'assetsUrl' => $assetsUrl,
            'webAssetsUrl' => $assetsUrl . 'web/',
        ], $options);

        $this->modx->addPackage('awss3mediasource', $this->getOption('modelPath'));
        $this->modx->lexicon->load('awss3mediasource:default');
        $this->autoload();
    }

    /**
     * Get a local configuration option or a namespaced system setting by key.
     *
     * @param string $key The option key to search for.
     * @param array $options An array of options that override local options.
     * @param mixed $default The default value returned if the option is not found locally or as a
     * namespaced system setting; by default this value is null.
     * @return mixed The option value or the default value specified.
     */
    public function getOption($key, $options = [], $default = null) {
        $option = $default;
        if (!empty($key) && is_string($key)) {
            if ($options != null && array_key_exists($key, $options)) {
                $option = $options[$key];
            } elseif (array_key_exists($key, $this->options)) {
                $option = $this->options[$key];
            } elseif (array_key_exists("{$this->namespace}.{$key}", $this->modx->config)) {
                $option = $this->modx->getOption("{$this->namespace}.{$key}");
            }
        }
        return $option;
    }

    protected function autoload()
    {
        require_once $this->getOption('modelPath') . 'vendor/autoload.php';
    }

}
