<?php

/**
 * @var array $files
 * @var array $folders
 * @var array $metadata
 */
include 'loader.php';

// For URI handling
$seed_uri = (isset($_GET['random'])) ? "random&seed={$metadata['seed']}&" : '';
$folder_uri = (isset($_GET['folder'])) ? "folder={$_GET['folder']}&" : '';

?>
<!doctype html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediaManager</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet" />
    <link href="assets/video-js.css" rel="stylesheet" />
</head>

<body>
    <header class="py-3 mb-3 border-bottom">
        <div class="container-fluid d-grid gap-3 align-items-center" style="grid-template-columns: 1fr 2fr;">
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center col-lg-4 mb-2 mb-lg-0 link-body-emphasis text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <b>MediaManager</b>
                </a>
                <ul class="dropdown-menu text-small shadow">
                    <li><a class="dropdown-item" href="?" aria-current="page">All files</a></li>
                    <?php if ($folders) { ?>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                    <?php } ?>
                    <?php foreach ($folders as $folder) { ?>
                        <li><a class="dropdown-item" href="?folder=<?= $folder ?>"><?= $folder ?></a></li>
                    <?php } ?>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="?<?= $folder_uri ?>random">Randomize!</a></li>
                </ul>
            </div>
            <div class="d-flex align-items-center">
                <form class="w-100 me-3" role="search" action="" method="GET">
                    <input type="search" class="form-control" name="query" placeholder="Search..." aria-label="Search">
                </form>
            </div>
        </div>
    </header>
    <div class="container">
        <div class="row mt-4">
            <?php foreach ($files as $file) { ?>
                <div class="col-lg-4 col-sm-12 mb-2">
                    <div class="card">
                        <div class="card-body">
                            <?= $file['media'] ?>
                            <?php if ($file['type'] == 'gif') { ?>
                                <img style="width: auto; max-height:240px;" class="img" src="<?= $file['media'] ?>" />
                            <?php } else { ?>
                                <video class="video-js" controls preload="auto" width="auto" height="auto" data-setup="{&quot;fluid&quot;: true}">
                                    <source src="<?= $file['media'] ?>" type="video/<?= $file['type'] ?>" />
                                    <p class="vjs-no-js">
                                        To view this video please enable JavaScript, and consider upgrading to a web browser that
                                        <a href="https://videojs.com/html5-video-support/" target="_blank">supports HTML5 video</a>
                                    </p>
                                </video>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
        <div class="row mt-2">
            <div class="col-12">
                <nav class="nav d-flex justify-content-center">
                    <ul class="pagination flex-wrap">
                        <?php for ($i = 1; $i <= $metadata['page_count']; $i++) { ?>
                            <li class="page-item <?= ((int) @$metadata['current_page'] === $i) ? 'active' : null ?>"><a class="page-link" href="?<?= $seed_uri ?><?= $folder_uri ?>page=<?= $i ?>"><?= $i ?></a></li>
                        <?php } ?>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/video.min.js"></script>
</body>

</html>