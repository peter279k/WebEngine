<?php
namespace Gt\WebEngine\Logic;
use DirectoryIterator;
use Gt\WebEngine\FileSystem\Path;

/**
 * Logic files use their own autoloader to allow loading classes that are web-mapped.
 * @see https://github.com/PhpGt/StyleGuide/blob/master/directories-files-namespaces/path-mapping.md#web-mapping-example
 */
class Autoloader {
	const ALLOWED_SUFFIXES = [
		"Api",
		"Page",
	];

	protected $appNamespace;
	protected $docRoot;

	public function __construct(string $appNamespace, string $docRoot) {
		$this->appNamespace = $appNamespace;
		$this->docRoot = $docRoot;
	}

	public function autoload(string $absoluteClassName):void {
		$classSuffix = $this->getClassSuffix($absoluteClassName);
		if(is_null($classSuffix)) {
			return;
		}

		$absoluteClassName = trim($absoluteClassName, "\\");
		list(
			$appNamespace,
			$logicType
		) = explode("\\", $absoluteClassName);

		if($appNamespace !== $this->appNamespace) {
			return;
		}

		$path = $this->getPathForLogicType($logicType);
		if(is_null($path)) {
			return;
		}

		$relativeClassName = $this->getRelativeClassName(
			$absoluteClassName,
			$appNamespace,
			$logicType
		);

		$directoryPath = $this->buildDirectoryPathFromRelativeClassName(
			$path,
			$relativeClassName
		);

		$fileName = $this->findFileName(
			$directoryPath,
			$relativeClassName,
			$classSuffix
		);
		if(is_null($fileName)) {
			return;
		}

		$autoloadPath = implode(DIRECTORY_SEPARATOR, [
			$directoryPath,
			$fileName,
		]);
		$autoloadPath = Path::fixPathCase($autoloadPath);

		$this->requireAndCheck($autoloadPath, $absoluteClassName);
	}

	protected function requireAndCheck(string $filePath, string $className):void {
		require($filePath);

		if($className[0] !== "\\") {
			$className = "\\" . $className;
		}

		if(!class_exists($className)) {
			throw new AutoloadedClassDoesNotExistException($className);
		}
	}

	protected function getClassSuffix($className):?string {
		$classSuffix = null;

		foreach(self::ALLOWED_SUFFIXES as $suffix) {
			if($this->classHasSuffix($className, $suffix)) {
				$classSuffix = $suffix;
			}
		}

		return $classSuffix ;
	}

	protected function classHasSuffix($className, $endsWith):bool {
		$length = strlen($endsWith);

		return $length === 0
		|| (substr($className, -$length) === $endsWith);
	}

	protected function getPathForLogicType(string $type) {
		$type = strtolower($type);
		$path = null;

		switch($type) {
		case "api":
			$path = Path::getApiDirectory($this->docRoot);
			break;

		case "page":
			$path = Path::getPageDirectory($this->docRoot);
			break;
		}

		return $path;
	}

	protected function getRelativeClassName(string $absoluteClassName, string...$toRemove) {
		$parts = explode("\\", $absoluteClassName);
		foreach($toRemove as $remove) {
			if($remove === $parts[0]) {
				array_shift($parts);
			}
		}

		return implode("\\", $parts);
	}

	protected function buildDirectoryPathFromRelativeClassName(
		string $path,
		string $relativeClassName
	):string {
		$parts = explode("\\", $relativeClassName);
		array_pop($parts);

		$pageDir = Path::getPageDirectory();

		foreach($parts as $part) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if(!is_dir($path)) {
				$path = str_replace(
					DIRECTORY_SEPARATOR . "_",
					DIRECTORY_SEPARATOR . "@",
					$path
				);
			}
		}

		return $path;
	}

	protected function findFileName(
		string $directoryPath,
		string $relativeClassName,
		string $classSuffix
	):?string {
		$matchingFileName = null;

		$parts = explode("\\", $relativeClassName);
		$className = array_pop($parts);
		$suffixPosition = strrpos(
			$className,
			$classSuffix
		);
		$searchFileName = substr($className,0, $suffixPosition);
		$searchFileName = "$searchFileName.php";

		$directoryPath = str_replace(
			DIRECTORY_SEPARATOR . "_",
			DIRECTORY_SEPARATOR . "@",
			$directoryPath
		);

		$searchFileNameLowerCase = strtolower($searchFileName);
		$searchFileNameHyphenatedLowerCase = strtolower(
			$this->hyphenate($searchFileName)
		);
		$searchList = [
			$searchFileNameLowerCase,
			$searchFileNameHyphenatedLowerCase,
			str_replace("_", "@", $searchFileNameLowerCase),
			str_replace("_", "@", $searchFileNameHyphenatedLowerCase),
		];

		foreach(new DirectoryIterator($directoryPath) as $fileInfo) {
			if(!$fileInfo->isFile()) {
				continue;
			}

			$fileName = $fileInfo->getFilename();
			$fileNameLowerCase = strtolower($fileName);
			if(!in_array($fileNameLowerCase, $searchList)) {
				continue;
			}

			$matchingFileName = $fileName;
		}

		return $matchingFileName;
	}

	protected function hyphenate(string $fileName):string {
		$file = pathinfo(
			$fileName,
			PATHINFO_FILENAME
		);
		$extension = pathinfo(
			$fileName,
			PATHINFO_EXTENSION
		);

		for($i = strlen($file) - 1; $i > 0; $i--) {
			if(!ctype_upper($file[$i])) {
				continue;
			}

			$fileName = substr_replace(
				$fileName,
				"-",
				$i,
				0
			);
		}

		return $fileName;
	}
}