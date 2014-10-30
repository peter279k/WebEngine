<?php
/**
 *
 * PHP.Gt (http://php.gt)
 * @copyright Copyright Ⓒ 2014 Bright Flair Ltd. (http://brightflair.com)
 * @license Apache Version 2.0, January 2004. http://www.apache.org/licenses
 */
namespace Gt\ClientSide;

use \Gt\Response\Response;
use \Gt\Core\Path;
use \Gt\Core\DirectoryIterator;

class FileOrganiser {

private $response;
private $manifest;
private $emptyHash;
private $assetWwwDir;
private $assetWwwFingerprintFile;

public function __construct($response, Manifest $manifest) {
	$this->response = $response;
	$this->manifest = $manifest;

	$wwwDir = Path::get(Path::WWW);
	$this->emptyHash = str_pad("", 32, "0");

	$assetPath = Path::get(Path::ASSET);
	$assetDirName = substr($assetPath, strrpos($assetPath, "/") + 1);

	$this->assetWwwDir = "$wwwDir/$assetDirName";
	$this->assetWwwFingerprintFile = $wwwDir . "/asset-fingerprint";
}

/**
 * @param PathDetails $pathDetails Representation of client-side paths
 *
 * @return bool True if organiser has copied any files, false if no files have
 * been copied
 */
public function organise($pathDetails = []) {
	$copyCount = 0;

	if(!$this->manifest->checkValid()) {
		$passThrough = null;
		$callback = null;
		if($this->response->getConfigOption("client_minified")) {
			// Minify everything in www
			$callback = [new Minifier(), "minify"];
		}

		// Do copying of files...
		$copyCount += $this->copyCompile($pathDetails, $callback);
	}

	if(!$this->checkAssetValid()) {
		$copyCount += $this->copyAsset();
	}

	return !!($copyCount);
}

/**
 * Performs the copying from source directories to the www directory, compiling
 * files as necessary. For example, source LESS files need to be compiled to
 * public CSS files in this process.
 *
 * @param PathDetails $pathDetails
 * @param callable|null $callback The callable to pass output through before
 * writing to disk
 *
 * @return int Number of files copied
 */
public function copyCompile($pathDetails, $callback = null) {
	$copyCount = 0;

	foreach ($pathDetails as $pathDetail) {
		if(!is_dir(dirname($pathDetail["destination"]))) {
			mkdir(dirname($pathDetail["destination"]), 0775, true);
		}

		$output = Compiler::parse($pathDetail["source"]);
		if(!is_null($callback)) {
			$output = call_user_func_array($callback, [$output]);
		}

		file_put_contents(
			$pathDetail["destination"],
			$output
		);
		++$copyCount;
	}

	return $copyCount;
}

/**
 * Fingerprints the source Asset directory contents and compares to the
 * fingerprint cache in the www directory.
 *
 * @return bool True if the www asset directory is valid, false if it is not
 * (or if it doesn't exist)
 */
public function checkAssetValid() {
	$wwwDir = Path::get(Path::WWW);
	$assetSrcDir = Path::get(Path::ASSET);
	$assetWwwDir = $wwwDir . "/" . substr($assetSrcDir, -strlen("asset"));

	if(!is_dir($assetSrcDir)) {
		return true;
	}

	if(!is_dir($assetWwwDir)
	|| !file_exists($this->assetWwwFingerprintFile)) {
		return false;
	}

	// Recursive fingerprint whole source directory.
	$assetWwwFingerprint = file_get_contents($this->assetWwwFingerprintFile);
	$assetSrcFingerprint = $this->recursiveFingerprint($assetSrcDir);

	return ($assetWwwFingerprint === $assetSrcFingerprint);
}

/**
 * Copies the source asset directory to the www directory and stores a
 * fingerprint of the source directory in a separate public file, for use in
 * checkAssetValid().
 */
public function copyAsset() {
	$copyCount = 0;

	$wwwDir = Path::get(Path::WWW);
	$assetSrcDir = Path::get(Path::ASSET);
	$assetWwwDir = $wwwDir . "/" . pathinfo($assetSrcDir, PATHINFO_BASENAME);
	$this->assetWwwFingerprintFile = $wwwDir . "/asset-fingerprint";

	if(!is_dir($assetSrcDir)) {
		return $copyCount;
	}

	$hash = "";
	$copyCount = 0;

	$hash = $this->recursiveFingerprint($assetSrcDir);
	DirectoryIterator::walk(
		$assetSrcDir,
		[$this, "copyAssetCallback"],
		$copyCount
	);

	if(!is_dir(dirname($this->assetWwwFingerprintFile))) {
		mkdir(dirname($this->assetWwwFingerprintFile), 0775, true);
	}

	file_put_contents($this->assetWwwFingerprintFile, $hash);
	return $copyCount;
}

/**
 *
 */
public function copyAssetCallback($file, $iterator, &$out) {
	if($file->isDir()) {
		return;
	}

	$source = $file->getPathname();
	$dest = $this->assetWwwDir . "/" . $iterator->getSubPathname();

	if(!is_dir(dirname($dest))) {
		mkdir(dirname($dest), 0775, true);
	}

	if(copy($source, $dest)) {
		$out++;
	}
}

/**
 * Recursively iterate over all files within given directory and build up a
 * hash of their contents and file names.
 *
 * @param string $dir Directory to iterate
 *
 * @return string 32 character hash of directory's contents, or 32 zeros
 * indicating an empty or non-existant directory
 */
private function recursiveFingerprint($dir) {
	if(!is_dir($dir)) {
		return $this->emptyHash;
	}

	$hash = DirectoryIterator::hash($dir);
	if($hash === md5("")) {
		return $this->emptyHash;
	}

	return $hash;
}

}#