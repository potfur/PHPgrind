<?php
error_reporting(-1);

const VERSION = '0.2';
const ENTRY_POINT = '{main}';
const RECURSION = '*RECURSION*';

require __DIR__ . '/functions.inc.php';

try {
    $files = gather();
    switch (get('op')) {
        case 'trace':
            $headers = headers(source($files, get('source')), get('hideInternals'));
            $nodes = trace(source($files, get('source')), get('hideInternals'), $headers['summary']);
            require './templates/trace.phtml';
            break;
        case 'graph':
            $headers = headers(source($files, get('source')), get('hideInternals'));
            $nodes = graph(source($files, get('source')), get('hideInternals'), $headers['summary']);
//echo '<pre>',print_r($nodes,1),'</pre>';

            require 'templates/graph.phtml';
            break;
        case 'file':
            if (get('filename') === null) {
                throw new InvalidArgumentException('Undefined file ' . get('filename.'));
            }

            if (!file_exists(get('filename'))) {
                throw new RuntimeException('File ' . get('filename') . ' does not exist.');
            }

            if (!is_readable(get('filename'))) {
                throw new RuntimeException('File ' . get('filename') . ' can not be read.');
            }

            if (is_dir(get('filename'))) {
                throw new RuntimeException(get('filename') . ' is directory.');
            }

            $source = highlight_file(get('filename'), true);
            $count = preg_match_all('/<br ?\/?>/im', $source) + 1;

            require 'templates/fileviewer.phtml';

            break;
        default:
            require './templates/index.phtml';
    }
} catch(Exception $e) {
    require './templates/index.phtml';
}