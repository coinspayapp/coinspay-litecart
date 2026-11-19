<?php
/**
 * Builds the release archive of the module:
 *   dist/coinspay-litecart.zip — public_html/ with the files to copy over the shop's own folder
 *   (the core in includes/coinspay, the payment module, the job, the pages, the icon), the
 *   installation guides and the license; and dist/build/ with the same files unpacked.
 *
 * The layout is the one of the LiteCart add-on store (litecart.net, "How To Pack Your Add-on"):
 * README.md with the installation guide, UPGRADE.md and LICENSE.md at the root, docs/ beside them.
 *
 *   php bin/build.php   (PHP with the zip extension, e.g. the composer:2 image)
 */
$root = dirname(__DIR__);
$dist = $root . '/dist';
$build = $dist . '/build';
$zipFile = $dist . '/coinspay-litecart.zip';

function remove_tree($dir) {
	if (!is_dir($dir)) {
		return;
	}
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($dir);
}

function copy_tree($from, $to) {
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
	foreach ($files as $file) {
		$target = $to . substr($file->getPathname(), strlen($from));
		if (!is_dir(dirname($target))) {
			mkdir(dirname($target), 0755, true);
		}
		copy($file->getPathname(), $target);
	}
}

preg_match("/const VERSION = '([^']+)'/", file_get_contents($root . '/core/Plugin.php'), $match);
$version = $match[1];

remove_tree($build);
@unlink($zipFile);
mkdir($build . '/public_html', 0755, true);

copy_tree($root . '/public_html', $build . '/public_html');
copy_tree($root . '/core', $build . '/public_html/includes/coinspay');
// LiteCart keeps an empty index.html in its folders, so a misconfigured server lists nothing.
foreach (array('includes/coinspay', 'includes/coinspay/lang', 'includes/coinspay/litecart') as $dir) {
	file_put_contents($build . '/public_html/' . $dir . '/index.html', '');
}
mkdir($build . '/docs', 0755, true);
copy($root . '/docs/install-en.md', $build . '/docs/install-en.md');
copy($root . '/docs/install-ru.md', $build . '/docs/install-ru.md');
copy($root . '/LICENSE', $build . '/LICENSE.md');
// The store wants the installation guide in README.md at the root of the archive.
file_put_contents($build . '/README.md', rtrim(file_get_contents($root . '/docs/install-en.md'))
	. "

The same guide in Russian: docs/install-ru.md. How to upgrade: UPGRADE.md.
");
copy($root . '/docs/upgrade-en.md', $build . '/UPGRADE.md');

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
	fwrite(STDERR, "cannot write $zipFile\n");
	exit(1);
}
$paths = array();
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	$paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($build) + 1));
}
sort($paths);
foreach ($paths as $path) {
	$zip->addFile($build . '/' . $path, $path);
}
$zip->close();
printf("dist/coinspay-litecart.zip: CoinsPay %s, %d files\n", $version, count($paths));
