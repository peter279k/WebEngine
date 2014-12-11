<?php
/**
 *
 * PHP.Gt (http://php.gt)
 * @copyright Copyright Ⓒ 2014 Bright Flair Ltd. (http://brightflair.com)
 * @license Apache Version 2.0, January 2004. http://www.apache.org/licenses
 */
namespace Gt\Page;

use \Michelf\Markdown;

class Transformer {

const TYPE_MARKDOWN	= "md";
const TYPE_HAML		= "haml";

/**
 * @param string $source Plain-text source content
 * @param string $type Type of source content, must be one of this class's
 * type constants
 *
 * @return string HTML source transformed from provided source content
 */
public static function toHtml($source, $type) {
	$type = strtolower($type);

	switch($type) {
	case self::TYPE_MARKDOWN:
		return Markdown::defaultTransform($source);
	}

	throw new SourceNotValidException();
}

}#