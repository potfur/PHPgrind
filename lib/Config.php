<?php
class Config {
	public $version = 0.1;
	public $dir = './';

	public $fraction;
	public $hideInternals = true;

	public $outputFormat;
	public $outputDir;

	public $listFormat = '%i (%f) [%s]';

	public function __construct($iArr = array()) {
		$this->fraction = isset($iArr['fraction']) && $iArr['fraction'] >= 0.1 ? round($iArr['fraction'], 1) : 0.9;
		$this->hideInternals = isset($iArr['hideInternals']);

		$this->xdebugOutputDir();
		$this->xdebugOutputFormat();
	}

	protected function xdebugOutputFormat() {
		$outputName = ini_get('xdebug.profiler_output_name');
		if(empty($outputName)) {
			$outputName = '/^cachegrind\.out\..+$/';
		}

		$this->outputFormat = '/^' . preg_replace('/(%[^%])+/', '.+', $outputName) . '.*$/';
	}

	protected function xdebugOutputDir() {
		$dir = ini_get('xdebug.profiler_output_dir');
		if(empty($dir)) {
			$this->outputDir = realpath($this->dir) . '/';
			return;
		}

		$this->outputDir = realpath($dir) . '/';
	}
}