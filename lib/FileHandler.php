<?php
class FileHandler {

	const ENTRY_POINT = '{main}';

	protected $Config;

	public function __construct(Config $Config) {
		$this->Config = & $Config;
	}

	public function gather() {
		$list = preg_grep($this->Config->outputFormat, scandir($this->Config->outputDir));
		$files = array();

		foreach($list as $file) {
			$absoluteFilename = $this->Config->outputDir . $file;

			$invokeUrl = rtrim($this->getInvokeUrl($absoluteFilename));

			$files[] = array(
				'filename' => $absoluteFilename,
				'mtime' => filemtime($absoluteFilename),
				'preprocessed' => false,
				'invokeUrl' => $invokeUrl,
				'filesize' => $this->bytestostring(filesize($absoluteFilename))
			);
		}

		usort($files, function ($a, $b) {
			return $a['mtime'] - $b['mtime'];
		});

		return $files;
	}

	protected function getInvokeUrl($file) {
		$fp = fopen($file, 'r');
		$invokeUrl = '';
		while((($line = fgets($fp)) !== FALSE) && !strlen($invokeUrl)) {
			if(preg_match('/^cmd: (.*)$/', $line, $parts)) {
				$invokeUrl = isset($parts[1]) ? $parts[1] : '';
			}
		}
		fclose($fp);
		if(!strlen($invokeUrl)) {
			$invokeUrl = 'Unknown!';
		}

		return $invokeUrl;
	}

	protected function bytestostring($size, $precision = 0) {
		$sizes = array('GB', 'MB', 'KB', 'B');
		$total = count($sizes);

		while($total-- && $size > 1024) {
			$size /= 1024;
		}
		return round($size, $precision) . $sizes[$total];
	}

	public function read($source, $internals = false) {
		$in = fopen($source, 'rb');
		if(!$in) {
			throw new Exception('Could not open ' . $source . ' for reading.');
		}

		$nextFuncNr = 0;
		$functions = array();
		$headers = array();

		$result = array(
			'headers' => array(
				'filename' => $source,
				'mtime' => filemtime($source),
				'types' => array(
					'procedural' => 0,
					'internal' => 0,
					'require' => 0,
					'class' => 0
				)
			),
			'functions' => array()
		);

		// Read information into memory
		while(($line = fgets($in))) {
			if(substr($line, 0, 3) === 'fl=') {
				// Found invocation of function. Read functionname
				list($function) = fscanf($in, "fn=%[^\n\r]s");

				if($internals && strpos($function, 'php:') !== false) {
					$function = null;
					continue;
				}

				if(empty($functions[$function])) {
					$functions[$function] = array(
						'nr' => $nextFuncNr++,
						'filename' => substr(trim($line), 3),
						'type' => $this->funcType($function),
						'line' => null,
						'invocationCount' => 0,
						'count' => 0,
						'totalSelfCost' => 0,
						'totalInclusiveCost' => 0,
						'calledFrom' => array(),
						'subCall' => array()
					);

					$result['headers']['types'][$functions[$function]['type']]++;
				}

				$functions[$function]['invocationCount']++;

				// Special case for ENTRY_POINT - it contains summary header
				if(self::ENTRY_POINT == $function) {
					fgets($in);
					$headers[] = fgets($in);
					fgets($in);
				}

				// Cost line
				list($lnr, $cost) = fscanf($in, "%d %d");

				$functions[$function]['line'] = $lnr;
				$functions[$function]['totalSelfCost'] += $cost;
				$functions[$function]['totalInclusiveCost'] += $cost;
			}
			elseif(substr($line, 0, 4) === 'cfn=') {

				// Found call to function. ($function should contain function call originates from)
				$calledFunctionName = substr(trim($line), 4);

				if(empty($function) || empty($functions[$calledFunctionName])) {
					continue;
				}

				// Skip call line
				fgets($in);

				// Cost line
				list($lnr, $cost) = fscanf($in, "%d %d");

				$functions[$function]['totalInclusiveCost'] += $cost;

				if(empty($functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr])) {
					$functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr] = array('filename' => $functions[$function]['filename'], 'line' => $lnr, 'callCount' => 0, 'summedCallCost' => 0);
				}

				$functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr]['callCount']++;
				$functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr]['summedCallCost'] += $cost;

				if(empty($functions[$function]['subCall'][$calledFunctionName . ':' . $lnr])) {
					$functions[$function]['subCall'][$calledFunctionName . ':' . $lnr] = array('filename' => $functions[$function]['filename'], 'line' => $lnr, 'callCount' => 0, 'summedCallCost' => 0);
				}

				$functions[$function]['subCall'][$calledFunctionName . ':' . $lnr]['callCount']++;
				$functions[$function]['subCall'][$calledFunctionName . ':' . $lnr]['summedCallCost'] += $cost;
			}
			else if(strpos($line, ': ') !== false) {
				$headers[] = $line;
			}
		}

		foreach($headers as $header) {
			$t = explode(': ', $header);
			$result['headers'][$t[0]] = trim($t[1]);
		}

		$fCount = count($functions);
		foreach($result['headers']['types'] as &$type) {
			$type = $this->formatCost($type, $fCount);
			unset($type);
		}

		foreach($functions as $key => $function) {
			$function['totalSelfCost'] = $this->formatCost($function['totalSelfCost'], $result['headers']['summary']);
			$function['totalInclusiveCost'] = $this->formatCost($function['totalInclusiveCost'], $result['headers']['summary']);

			$result['functions'][$key] = $function;
		}

		uasort($result['functions'], function($a, $b) {
			return $b['totalSelfCost'] - $a['totalSelfCost'];
		});

		$result['functions'] = array_slice($result['functions'], 0, $fCount * $this->Config->fraction);

		return $result;
	}

	protected function funcType($function) {
		if(strpos($function, self::ENTRY_POINT) !== false) {
			return 'procedural';
		}

		if(strpos($function, 'php::') !== false) {
			return 'internal';
		}

		if(strpos($function, 'require::') !== false || strpos($function, 'include::') !== false) {
			return 'require';
		}

		return 'class';
	}

	protected function formatCost($cost, $total) {
		$result = ($total == 0) ? 0 : ($cost * 100) / $total;
		return number_format($result, 2, '.', '');
	}

}