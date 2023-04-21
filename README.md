# MediaManager

View and manage videos and gifs in the browser. Designed to be standalone and works without internet connection.  
If you have a lot of video files and would like to view it from the browser in grid view, this is the repo you're looking for.  
This is mostly a PoC and can be implemented in other languages of your liking.  

## Setup
Just drop the files into the folder you want to view and manage, then start a PHP server.  

## Configuration
The `loader.php` is the main logic file where all the processing happens. At the top you can modify the following settings:
- `ITEMS_PER_PAGE`: Controls how many files to show per page.  

## Structure
```
.
├── assets/
│   ├── bootstrap.bundle.min.js
│   ├── bootstrap.min.css
│   ├── video-js.css
│   └── video.min.js
├── index.php
└── loader.php
```

## Dependencies
- Bootstrap - v5.3.0-alpha3
- VideoJS - v4.0.1

## Supported Formats
The following extension are supported:
- .webm
- .mp4
- .gif
- .mkv
- .m4v

Easily add more by modifying `gatherMediaFiles()` in `loader.php`.  
**Note**: Compability dependent on [VideoJS](https://videojs.com/).  

## Search Feature
Search using keywords separated by space. It will be evaluated recursively. Use a minus sign (`-`) to exclude files with said keyword.

## Randomize Feature
Click on "Ranzomize!" in the menu to randomize the result. A seed will be generated and attached to the URL, which can be used to consistently have the same sorted page.
