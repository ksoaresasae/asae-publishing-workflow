<?php
/**
 * Build script — creates a release zip for the plugin.
 *
 * Usage: php build-zip.php
 * Output: releases/asae-publishing-workflow.zip
 *
 * @package ASAE_Publishing_Workflow
 */

$plugin_slug = 'asae-publishing-workflow';
$root_dir    = __DIR__;
$output_dir  = $root_dir . '/releases';
$zip_path    = $output_dir . '/' . $plugin_slug . '.zip';

// Ensure output directory exists.
if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// Remove old zip if it exists.
if (file_exists($zip_path)) {
    unlink($zip_path);
}

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "Error: Cannot create zip file.\n";
    exit(1);
}

// Files and directories to include.
$include = array(
    'asae-publishing-workflow.php',
    'uninstall.php',
    'includes/',
    'admin/',
    'assets/',
    'templates/',
);

// Files/directories to exclude.
$exclude = array(
    '.git',
    '.github',
    '.gitignore',
    'build-zip.php',
    'releases',
    'instructions',
    'node_modules',
    'vendor',
    '.DS_Store',
    'Thumbs.db',
);

foreach ($include as $item) {
    $path = $root_dir . '/' . $item;

    if (is_file($path)) {
        $zip->addFile($path, $plugin_slug . '/' . $item);
    } elseif (is_dir($path)) {
        add_directory_to_zip($zip, $path, $plugin_slug . '/' . $item, $exclude);
    }
}

$zip->close();

echo "Built: {$zip_path}\n";
echo "Files: " . (new ZipArchive())->open($zip_path) ? "OK\n" : "Error\n";

/**
 * Recursively add a directory to a zip archive.
 *
 * @param ZipArchive $zip
 * @param string     $dir       Absolute path to directory.
 * @param string     $zip_path  Path within the zip.
 * @param array      $exclude   Basenames to skip.
 */
function add_directory_to_zip(ZipArchive $zip, string $dir, string $zip_path, array $exclude): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $basename = $file->getBasename();

        // Skip excluded items.
        foreach ($exclude as $ex) {
            if ($basename === $ex) {
                continue 2;
            }
        }

        $relative = substr($file->getPathname(), strlen($dir));
        $relative = str_replace('\\', '/', $relative);

        if ($file->isDir()) {
            $zip->addEmptyDir($zip_path . $relative);
        } else {
            $zip->addFile($file->getPathname(), $zip_path . $relative);
        }
    }
}
