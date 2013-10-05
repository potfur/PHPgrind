<?php

function get($param, $default = null)
{
    return (isset($_GET[$param]) ? $_GET[$param] : $default);
}

function source($files, $source)
{
    if ($source === null) {
        throw new InvalidArgumentException('Undefined source index ' . $source . '.');
    }

    if (!isset($files[get('source')])) {
        throw new InvalidArgumentException('Unable to find file path for source index ' . $source . '.');
    }

    return $files[$source]['filename'];
}

function search($needle, $haystack)
{
    foreach ($haystack as $i => $node) {
        if ($node['name'] == $needle) {
            return $i;
        }
    }

    return null;
}

function xdebugOutputFormat()
{
    return '/^' . preg_replace('/(%[^%])+/', '.+', ini_get('xdebug.profiler_output_name')) . '.*$/';
}

function xdebugOutputDir()
{
    return realpath(ini_get('xdebug.profiler_output_dir')) . '/';
}

function gather()
{
    $list = preg_grep(xdebugOutputFormat(), scandir(xdebugOutputDir()));
    $files = array();

    foreach ($list as $file) {
        $absoluteFilename = xdebugOutputDir() . $file;

        $invokeUrl = rtrim(getInvokeUrl($absoluteFilename));

        $files[] = array(
            'filename' => $absoluteFilename,
            'mtime' => filemtime($absoluteFilename),
            'preprocessed' => false,
            'invokeUrl' => $invokeUrl,
            'filesize' => bytesToString(filesize($absoluteFilename))
        );
    }

    usort(
        $files, function ($a, $b) {
            return $a['mtime'] - $b['mtime'];
        }
    );

    return $files;
}

function getInvokeUrl($file)
{
    $fp = fopen($file, 'r');
    $invokeUrl = '';
    while ((($line = fgets($fp)) !== false) && !strlen($invokeUrl)) {
        if (preg_match('/^cmd: (.*)$/', $line, $parts)) {
            $invokeUrl = isset($parts[1]) ? $parts[1] : '';
        }
    }
    fclose($fp);
    if (!strlen($invokeUrl)) {
        $invokeUrl = 'Unknown!';
    }

    return $invokeUrl;
}

function bytesToString($size, $precision = 0)
{
    $sizes = array('GB', 'MB', 'KB', 'B');
    $total = count($sizes);

    while ($total-- && $size > 1024) {
        $size /= 1024;
    }
    return round($size, $precision) . $sizes[$total];
}

function funcType($function)
{
    if (strpos($function, ENTRY_POINT) !== false) {
        return 'procedural';
    }

    if (strpos($function, 'php::') !== false) {
        return 'internal';
    }

    if (strpos($function, 'require::') !== false || strpos($function, 'include::') !== false) {
        return 'require';
    }

    if(strpos($function, RECURSION) !== false) {
        return 'recursion';
    }

    return 'class';
}

function formatCost($cost, $total)
{
    $result = ($total == 0) ? 0 : ($cost * 100) / $total;
    return number_format($result, 2, '.', '');
}

function headers($source, $internals = false)
{
    $in = fopen($source, 'rb');
    if (!$in) {
        throw new Exception('Could not open ' . $source . ' for reading.');
    }

    $headers = array(
        'filename' => $source,
        'mtime' => filemtime($source),
        'count' => 0,
        'types' => array(
            'procedural' => 0,
            'internal' => 0,
            'require' => 0,
            'class' => 0
        )
    );

    $functions = array();

    while (($line = fgets($in))) {
        if (substr($line, 0, 3) === 'fl=') {
            list($function) = fscanf($in, "fn=%[^\n\r]s");

            if ($internals && strpos($function, 'php:') !== false) {
                $function = null;
                continue;
            }

            if (empty($functions[$function])) {
                $headers['count']++;
                $headers['types'][funcType($function)]++;
                $functions[$function] = true;
            }

            if ($function == ENTRY_POINT) {
                fgets($in);
                $t = explode(': ', fgets($in));
                $headers[$t[0]] = trim($t[1]);
                fgets($in);
            }
        } elseif (strpos($line, ': ') !== false) {
            $t = explode(': ', $line);
            $headers[$t[0]] = trim($t[1]);
        }
    }

    $fCount = array_sum($headers['types']);
    foreach ($headers['types'] as &$type) {
        $type = formatCost($type, $fCount);
        unset($type);
    }

    return $headers;
}

function trace($source, $internals = false, $totalCost = 0)
{
    $in = fopen($source, 'rb');
    if (!$in) {
        throw new Exception('Could not open ' . $source . ' for reading.');
    }

    $nextFuncNr = 0;
    $function = null;
    $functions = array();

    while (($line = fgets($in))) {
        if (substr($line, 0, 3) === 'fl=') {
            list($function) = fscanf($in, "fn=%[^\n\r]s");

            if ($internals && strpos($function, 'php:') !== false) {
                $function = null;
                continue;
            }

            if (empty($functions[$function])) {
                $functions[$function] = array(
                    'nr' => $nextFuncNr++,
                    'filename' => substr(trim($line), 3),
                    'type' => funcType($function),
                    'line' => null,
                    'invocationCount' => 0,
                    'totalSelfCost' => 0,
                    'totalInclusiveCost' => 0,
                    'calledFrom' => array(),
                    'subCall' => array()
                );
            }

            $functions[$function]['invocationCount']++;

            list($lnr, $cost) = fscanf($in, "%d %d");

            $functions[$function]['line'] = $lnr;
            $functions[$function]['totalSelfCost'] += $cost;
            $functions[$function]['totalInclusiveCost'] += $cost;
        } elseif (substr($line, 0, 4) === 'cfn=') {
            $calledFunctionName = substr(trim($line), 4);

            if (empty($function) || empty($functions[$calledFunctionName])) {
                continue;
            }

            fgets($in);
            list($lnr, $cost) = fscanf($in, "%d %d");

            $functions[$function]['totalInclusiveCost'] += $cost;

            if (empty($functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr])) {
                $functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr] = array('filename' => $functions[$function]['filename'], 'line' => $lnr, 'callCount' => 0, 'summedCallCost' => 0);
            }

            $functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr]['callCount']++;
            $functions[$calledFunctionName]['calledFrom'][$function . ':' . $lnr]['summedCallCost'] += $cost;

            if (empty($functions[$function]['subCall'][$calledFunctionName . ':' . $lnr])) {
                $functions[$function]['subCall'][$calledFunctionName . ':' . $lnr] = array('filename' => $functions[$function]['filename'], 'line' => $lnr, 'callCount' => 0, 'summedCallCost' => 0);
            }

            $functions[$function]['subCall'][$calledFunctionName . ':' . $lnr]['callCount']++;
            $functions[$function]['subCall'][$calledFunctionName . ':' . $lnr]['summedCallCost'] += $cost;
        }
    }

    foreach ($functions as $key => $function) {
        $function['totalSelfCost'] = formatCost($function['totalSelfCost'], $totalCost);
        $function['totalInclusiveCost'] = formatCost($function['totalInclusiveCost'], $totalCost);

        $functions[$key] = $function;
    }

    uasort(
        $functions, function ($a, $b) {
            return $b['totalSelfCost'] - $a['totalSelfCost'];
        }
    );

    return array_slice($functions, 0, count($functions) * get('fraction'));
}

function graph($source, $internals = false)
{
    $in = fopen($source, 'rb');
    if (!$in) {
        throw new Exception('Could not open ' . $source . ' for reading.');
    }

    $functions = array();
    $costs = array();

    while (($line = fgets($in))) {
        if (substr($line, 0, 3) === 'fl=') {
            list($function) = fscanf($in, "fn=%[^\n\r]s");

            if ($internals && strpos($function, 'php:') !== false) {
                continue;
            }

//            list($lnr, $cost) = fscanf($in, "%d %d");

            $key = trim($function);

            if (!isset($functions[$key])) {
                $functions[$key] = array();
            }
        } elseif (substr($line, 0, 4) === 'cfl=') {
            list($function) = fscanf($in, "cfn=%[^\n\r]s");

            if ($internals && strpos($function, 'php:') !== false) {
                continue;
            }

//            fgets($in);
//            list($lnr, $cost) = fscanf($in, "%d %d");

            $skey = trim($function);

            if (isset($key)) {
                if (!isset($functions[$key])) {
                    $functions[$key] = array();
                }

                if (in_array($skey, $functions[$key])) {
                    continue;
                }

                $functions[$key][$skey] = array();
            }

            if (!isset($functions[$skey])) {
                $functions[$skey] = array();
            }
        }
    }

    foreach ($functions as $function => &$fArr) {
        foreach ($fArr as $call => $cArr) {
            if (!isset($functions[$call])) {
                continue;
            }

            if ($function == $call) {
                $fArr[$call] = array(RECURSION => array());
                continue;
            }

            $fArr[$call] = & $functions[$call];
            unset($functions[$call]);
        }

        unset($fArr);
    }

    return $functions;
}