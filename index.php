<?php
error_reporting(-1);

require './lib/Config.php';
require './lib/FileHandler.php';

$Config = new Config($_GET);
$FileHandler = new FileHandler($Config);
$files = $FileHandler->gather();

function get($param, $default = null) {
	return (isset($_GET[$param]) ? $_GET[$param] : $default);
}

function source($files, $source) {
	if($source === null) {
		throw new InvalidArgumentException('Undefined source index ' . $source . '.');
	}

	if(!isset($files[get('source')])) {
		throw new InvalidArgumentException('Unable to find file path for source index ' . $source . '.');
	}

	return $files[$source]['filename'];
}

function search($needle, $haystack) {
	foreach($haystack as $i => $node) {
		if($node['name'] == $needle) {
			return $i;
		}
	}

	return null;
}

try {
	switch(get('op')) {
		case 'trace':
			$trace = $FileHandler->read(source($files, get('source')), $Config->hideInternals);
			require './templates/trace.phtml';
			break;
		case 'treemap':
			$trace = $FileHandler->read(source($files, get('source')), $Config->hideInternals);

			$json = array();
			foreach($trace['functions'] as $key => $val) {
				$json[] = array(
					'name' => $key,
					'type' => $val['type'],
					'totalSelfCost' => $val['totalSelfCost'],
					'weight' => 1 + $val['totalSelfCost'],
					'url' => '?op=file&amp;filename=' . $val['filename'] . '#' . $val['line']
				);
			}

			$json = json_encode($json);
			require './templates/treemap.phtml';
			break;
		case 'graph':
			$trace = $FileHandler->read(source($files, get('source')), $Config->hideInternals);

			$json = array('nodes' => array(), 'links' => array());

			$calls = array();
			foreach($trace['functions'] as $key => $val) {
				$node = array(
					'name' => $key,
					'group' => $val['type'],
					'r' => 5 + $val['totalSelfCost'],
					'calls' => array()
				);

				foreach($val['calledFrom'] as $call => $cVal) {
					$node['calls'][substr($call, 0, strrpos($call, ':'))] = $cVal['callCount'];
				}

				$json['nodes'][] = $node;
			}

			foreach($json['nodes'] as $i => &$node) {
				foreach($node['calls'] as $call => $count) {
					$j = search($call, $json['nodes']);
					if($j === null) {
						continue;
					}

					$json['links'][] = array(
						'source' => $j,
						'target' => $i,
						'value' => $count
					);
				}

				unset($node['calls']);
				unset($node);
			}

			$json = json_encode($json);

			require 'templates/graph.phtml';
			break;
		case 'file':
			if(get('filename') === null) {
				throw new InvalidArgumentException('Undefined file ' . get('filename.'));
			}

			if(!file_exists(get('filename'))) {
				throw new RuntimeException('File ' . get('filename') . ' does not exist.');
			}

			if(!is_readable(get('filename'))) {
				throw new RuntimeException('File ' . get('filename') . ' can not be read.');
			}

			if(is_dir(get('filename'))) {
				throw new RuntimeException(get('filename') . ' is directory.');
			}

			$source = explode('<br />', highlight_file(get('filename'), true));
			require 'templates/fileviewer.phtml';

			break;
		default:
			require './templates/index.phtml';
	}
}
catch(Exception $e) {
	require './templates/index.phtml';
}