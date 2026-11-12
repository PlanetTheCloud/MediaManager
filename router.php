<?php

ini_set('display_errors', '0');
ini_set('log_errors', '0');

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');

function sendGalleryJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function stopRequest(int $status, string $message, ?string $code = null): never
{
    if ($code !== null) {
        header('X-MediaManager-Error: ' . $code);
    }
    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
        sendGalleryJson(['message' => $message, 'error' => $code], $status);
        exit;
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function serveMedia(string $directory, string $relative, bool $head): void
{
    $file = resolveLibraryPath($directory, $relative);
    $type = mediaType($relative);
    if ($file === null || $type === null || !is_file($file) || !($stream = @fopen($file, 'rb'))) {
        stopRequest(404, 'Media file unavailable.');
    }

    $size = fstat($stream)['size'];
    header('Content-Type: ' . $type);
    header('Accept-Ranges: bytes');
    $start = 0;
    $end = $size - 1;
    if (!$head && preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'] ?? '', $range)
        && ($range[1] !== '' || $range[2] !== '')) {
        if ($range[1] === '') {
            $start = max(0, $size - (int) $range[2]);
        } else {
            $start = (int) $range[1];
            if ($range[2] !== '') {
                $end = min($end, (int) $range[2]);
            }
        }
        if ($start > $end) {
            fclose($stream);
            header('Content-Range: bytes */' . $size);
            stopRequest(416, 'Requested range unavailable.');
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    $remaining = $end - $start + 1;
    header('Content-Length: ' . $remaining);
    if (!$head && fseek($stream, $start) === 0) {
        while ($remaining > 0 && !connection_aborted()) {
            $chunk = fread($stream, min($remaining, 65536));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    }
    fclose($stream);
}

if (realpath($_SERVER['DOCUMENT_ROOT'] ?? '') !== __DIR__ . DIRECTORY_SEPARATOR . 'assets') {
    stopRequest(503, 'Start MediaManager with the command in the README.');
}

$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^(127\.0\.0\.1|localhost)(:[0-9]+)?$/Di', $host)) {
    stopRequest(403, 'MediaManager is available on localhost only.');
}

$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    if (!in_array($route, ['/', '/index.php'], true)) {
        stopRequest(405, 'Method not allowed.');
    }
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'http://' . $host) {
        stopRequest(403, 'Changes require a request from the gallery.');
    }
} elseif ($method !== 'GET' && $method !== 'HEAD') {
    stopRequest(405, 'Method not allowed.');
}

$assets = [
    '/assets/bootstrap.min.css' => 'text/css',
    '/assets/video-js.css' => 'text/css',
    '/assets/bootstrap.bundle.min.js' => 'text/javascript',
    '/assets/video.min.js' => 'text/javascript',
    '/assets/gallery.css' => 'text/css',
    '/assets/gallery.js' => 'text/javascript',
];
if (isset($assets[$route])) {
    header('Content-Type: ' . $assets[$route]);
    if ($method !== 'HEAD') {
        readfile(__DIR__ . $route);
    }
    return;
}

if (!in_array($route, ['/', '/index.php', '/media'], true)) {
    stopRequest(404, 'Page not found.');
}

require_once __DIR__ . '/loader.php';
try {
    $libraryDirectory = getMediaLibraryDirectory();
    if ($route === '/media') {
        if (!is_string($_GET['file'] ?? null)) {
            stopRequest(400, 'Invalid media file.');
        }
        serveMedia($libraryDirectory, $_GET['file'], $method === 'HEAD');
        return;
    }
    require __DIR__ . '/index.php';
} catch (InvalidArgumentException $error) {
    stopRequest(400, 'The media file, filters, or metadata are invalid.');
} catch (MediaLibraryUnavailable $error) {
    stopRequest(503, 'The media library is unavailable.', 'library-unavailable');
} catch (RuntimeException $error) {
    stopRequest(503, 'The media library or its SQLite database is unavailable.');
}
