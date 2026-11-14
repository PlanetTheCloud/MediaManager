<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

function readBundleFile(string $path): string
{
    $contents = @file_get_contents(__DIR__ . '/' . $path);
    if ($contents === false || $contents === '') {
        throw new RuntimeException('Cannot read ' . $path . '.');
    }
    return $contents;
}

function inlineBundlePhp(string $path): string
{
    $source = readBundleFile($path);
    if (!preg_match('/\A<\?php\r?\n/', $source, $openingTag)) {
        throw new RuntimeException('Expected an opening PHP tag in ' . $path . '.');
    }
    return '// ' . $path . "\n" . substr($source, strlen($openingTag[0]));
}

function replaceBundleSource(string $source, string $statement, string $replacement): string
{
    $source = str_replace($statement, $replacement, $source, $count);
    if ($count !== 1) {
        throw new RuntimeException('Expected exactly one match for ' . $statement);
    }
    return $source;
}

try {
    $router = readBundleFile('router.php');
    $loader = replaceBundleSource(
        inlineBundlePhp('loader.php'),
        "require_once __DIR__ . '/metadata.php';",
        inlineBundlePhp('metadata.php')
    );
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/search.php';", inlineBundlePhp('search.php'));
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/external-moves.php';", inlineBundlePhp('external-moves.php'));
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/tags.php';", inlineBundlePhp('tags.php'));
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/tag-rules.php';", inlineBundlePhp('tag-rules.php'));
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/bulk-editing.php';", inlineBundlePhp('bulk-editing.php'));
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/bulk-moves.php';", inlineBundlePhp('bulk-moves.php'));
    $loader = replaceBundleSource($loader, "require_once __DIR__ . '/duplicate-review.php';", inlineBundlePhp('duplicate-review.php'));
    $router = replaceBundleSource($router, "require_once __DIR__ . '/loader.php';", $loader);
    $router = replaceBundleSource($router, "require_once __DIR__ . '/manage-tags.php';", inlineBundlePhp('manage-tags.php'));
    $router = replaceBundleSource($router, "require_once __DIR__ . '/review-duplicates.php';", inlineBundlePhp('review-duplicates.php'));
    $router = replaceBundleSource($router, "    require __DIR__ . '/index.php';", inlineBundlePhp('index.php') . "\n<?php");

    $documentRootCheck = <<<'PHP'
    function hasBundleDocumentRoot(): bool
    {
        if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
            return false;
        }

        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $configured = getenv('MEDIA_LIBRARY_DIR');
        $library = $configured === '' ? false : realpath($configured === false ? __DIR__ : $configured);
        if ($root === false || @scandir($root) !== ['.', '..']) {
            return false;
        }
        if ($library === false || !is_dir($library)) {
            stopRequest(503, 'The media library is unavailable.', 'library-unavailable');
        }

        $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $library = rtrim($library, '/\\') . DIRECTORY_SEPARATOR;
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $library = strtolower($library);
        }
        return !str_starts_with($root, $library) && !str_starts_with($library, $root);
    }

    if (!hasBundleDocumentRoot()) {
    PHP;
    $router = replaceBundleSource(
        $router,
        'if (realpath($_SERVER[\'DOCUMENT_ROOT\'] ?? \'\') !== __DIR__ . DIRECTORY_SEPARATOR . \'assets\') {',
        $documentRootCheck
    );
    $router = replaceBundleSource(
        $router,
        "stopRequest(503, 'Start MediaManager with the command in the README.');",
        "stopRequest(503, 'Use php -S 127.0.0.1:8000 -t <empty directory outside the library> MediaManager.php.');"
    );

    $assetResponse = "echo match (\$route) {\n";
    foreach (['bootstrap.min.css', 'video-js.css', 'bootstrap.bundle.min.js', 'video.min.js', 'gallery.css', 'gallery.js', 'search.js', 'duplicate-review.js'] as $asset) {
        $path = 'assets/' . $asset;
        $assetResponse .= '            ' . var_export('/' . $path, true) . ' => '
            . var_export(readBundleFile($path), true) . ",\n";
    }
    $assetResponse .= '        };';
    $bundle = replaceBundleSource($router, 'readfile(__DIR__ . $route);', $assetResponse);

    foreach (token_get_all($bundle, TOKEN_PARSE) as $token) {
        if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            throw new RuntimeException('Unresolved PHP include on bundle line ' . $token[2] . '.');
        }
    }

    $output = __DIR__ . '/MediaManager.php';
    $temporary = tempnam(__DIR__, 'bundle-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create the temporary bundle.');
    }
    try {
        if (@file_put_contents($temporary, $bundle) !== strlen($bundle) || !@rename($temporary, $output)) {
            throw new RuntimeException('Cannot write ' . $output . '.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    fwrite(STDOUT, 'Built ' . $output . PHP_EOL);
} catch (RuntimeException | ParseError $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
