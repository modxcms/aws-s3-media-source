<?php
/**
 * @package swift
 */
require_once (strtr(realpath(dirname(dirname(__FILE__))), '\\', '/') . '/awss3mediasource.class.php');
class AwsS3MediaSource_mysql extends AwsS3MediaSource {}
?>