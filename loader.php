<?php

/**
 * @var int Items to show per page
 */
define('ITEMS_PER_PAGE', 15);

/**
 * Returns a list of media files
 * 
 * @param string $path
 * @param bool $recursive
 * @return array
 */
function gatherMediaFiles($path = '.', $recursive = true)
{
    $files = [];
    // Credit to samjco (https://stackoverflow.com/a/69920323/8428965)
    $iterator = new RecursiveDirectoryIterator($path);
    if ($recursive) {
        $iterator = new RecursiveIteratorIterator($iterator);
    }
    $regex = new RegexIterator($iterator, '/^.+(.webm|.mp4|.gif|.mkv|.m4v)$/i', RecursiveRegexIterator::GET_MATCH);
    foreach ($regex as $val => $regex) {
        $files[] = $val;
    }
    return $files;
}

/**
 * Returns a list of media folders
 * 
 * @param string $path
 * @return array
 */
function gatherMediaFolders(string $path = '*')
{
    // Exclude assets folder
    return array_diff(glob($path, GLOB_ONLYDIR), ['assets']);
}

/**
 * Shuffles an array in a repeatable manner, if the same $seed is provided.
 * 
 * @author deceze (https://stackoverflow.com/a/19658344/8428965)
 * @param array &$items The array to be shuffled.
 * @param integer $seed The result of the shuffle will be the same for the same input ($items and $seed). If not given, uses the current time as seed.
 * @return array
 */
function seeded_shuffle(array $items, $seed = false)
{
    $items = array_values($items);
    mt_srand($seed ? $seed : time());
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        list($items[$i], $items[$j]) = array($items[$j], $items[$i]);
    }
    return $items;
}

/**
 * Search an array
 * 
 * @param string $needle
 * @param array $array
 * @return array
 */
function search(string $needle, array $array)
{
    $input = preg_quote(ltrim($needle, '-'), '~');
    $filtered = preg_filter('~' . $input . '~', '$0', $array);
    if (@$needle[0] === '-') {
        return array_diff($array, $filtered);
    }
    return $filtered;
}

/**
 * Recursive search
 * 
 * @param string $query
 * @param array $array
 * @return array
 */
function recursiveSearch(string $query, array $array)
{
    $query = explode(' ', $query);
    foreach ($query as $q) {
        $array = search($q, $array);
    }
    return $array;
}

/**
 * Get the current state metadata
 * 
 * @param array $data
 * @return array
 */
function getMetadata(array $data)
{
    $count = count($data);
    return [
        'file_count' => (int) $count,
        'current_page' => (int) ($_GET['page'] ?? 1),
        'page_count' => (int) ceil($count / ITEMS_PER_PAGE),
        'seed' => $_SESSION['seed'] ?? null
    ];
}

// Begin processing
session_start();

// Sanitize inputs
$acceptable = ['folder', 'seed', 'random', 'page', 'query'];
foreach ($acceptable as $key) {
    if (isset($_GET[$key]) && @!is_string($_GET[$key])) {
        die("Error 400: BAD REQUEST! {$key} parameter is malformated.");
    }
}

// Gather and sort files
$files = gatherMediaFiles($_GET['folder'] ?? '.');
$folders = gatherMediaFolders();
if (isset($_GET['random'])) {
    if (!isset($_GET['seed'])) {
        $seed = rand(0, 999999999);
        $location = "{$_SERVER['REQUEST_URI']}&seed={$seed}";
        header("Location: $location");
        echo "Redirecting you to $location";
        die;
    } else if ($_GET['seed'] !== @$_SESSION['seed']) {
        $_SESSION['seed'] = $_GET['seed'];
        $_SESSION['files'] = seeded_shuffle($files, $_SESSION['seed']);
    }
    $files = $_SESSION['files'];
}

// Perform query
if (isset($_GET['query'])) {
    $files = recursiveSearch(htmlspecialchars($_GET['query']), $files);
}

// Finalize
$metadata = getMetadata($files);
$files = array_slice($files, ($metadata['current_page'] - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
foreach ($files as $key => $file) {
    $files[$key] = [
        'media' => ltrim($file, '.\\'),
        'type' => @end(explode('.', $file))
    ];
}
