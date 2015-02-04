#!php
<?php
/**
 * Created by PhpStorm.
 * User: Michał (majkel) Kowalik <maf.michal@gmail.com>
 * Date: 1/2/2015
 * Time: 17:57
 *
 * This program adds type-hinting comments to Google API Client for PHP.
 *
 * -h         --help            Prints help
 * -v         --version         Prints version and exits
 * -s<Source> --source=<Source> Specifies Google API source directory
 *                              Be default current working directory (.)
 * -o<Output> --output=<Output> Specifies output directory for modified services.
 *                              Be default same as source
 *
 * Example usage:
 *
 *   php GoogleApiAddHints.php --source=vendor/google/apiclient
 */

namespace org\majkel\GoogleApiAddHints;

/**
 * Class ClassParser
 *
 * Base class for Google Api Service classes parsers
 *
 * @package org\majkel\GoogleApiAddHints
 */
abstract class ClassParser {

	/** @var integer */
	protected $startLine;
	/** @var integer */
	protected $endLine;
	/** @var Source  */
	protected $source;
	/** @var \ReflectionClass */
	protected $class;

	/**
	 * @param Source $source
	 * @param \ReflectionClass $class
	 */
	public function __construct($source, $class) {
		$this->source = $source;
		$this->class = $class;
		$this->startLine = $class->getStartLine();
		$this->endLine = $class->getEndLine();
	}

	/**
	 * @return string
	 */
	protected function getPhpSource() {
		return $this->source->get($this->startLine, $this->endLine);
	}

	/**
	 * @return \ReflectionClass
	 */
	public function getClass() {
		return $this->class;
	}

	/**
	 * @return Source
	 */
	public function getSource() {
		return $this->source;
	}

	/**
	 * @return integer
	 */
	public function getStartLine() {
		return $this->startLine;
	}

	/**
	 * @return integer
	 */
	public function getEndLine() {
		return $this->endLine;
	}

	/**
	 * @param string $comment
	 * @param string $type
	 * @return boolean
	 */
	protected static function commentHasVar($comment, $type) {
		return preg_match('#\s+@var\s+'.$type.'\s+#', $comment) > 0;
	}

	/**
	 * @param string $comment
	 * @param string $name
	 * @param string $type
	 * @return bool
	 */
	protected static function commentHasProperty($comment, $name, $type) {
		$type = preg_quote($type);
		return preg_match("#\s@property\s+$type\s+\\\$$name\s#", $comment) > 0;
	}

	/**
	 * @param string $comment
	 * @param string $property
	 * @param string $type
	 * @return bool
	 */
	protected static function commentHasParam($comment, $property, $type) {
		$type = preg_quote($type);
		return preg_match("#\s@param\s+$type\s+\\\$$property\s#", $comment) > 0;
	}

	/**
	 * @param string $comment
	 * @param string $type
	 * @return boolean
	 */
	protected static function commentHasReturn($comment, $type) {
		$type = preg_quote($type);
		return preg_match("#\s@return\s+$type\s#", $comment) > 0;
	}

	abstract public function fix();
}

/**
 * Class ClassParserService
 *
 * Parses service classes
 *
 * @package org\majkel\GoogleApiAddHints
 */
class ClassParserService extends ClassParser {

	public static $RESERVED_PROPS = array('version', 'servicePath', 'availableScopes', 'resource');

	/**
	 * @var array
	 */
	protected $properties;

	/**
	 * @var \ReflectionProperty[]
	 */
	protected $propsToModify = array();

	/**
	 * @return array
	 */
	public function getProperties() {
		if (is_null($this->properties)) {
			$this->properties = array();

			$constructor = $this->class->getConstructor();
			$start = $constructor->getStartLine();
			$end = $constructor->getEndLine();
			$source = $this->source->get($start, $end);

			preg_match_all(
					'#\$this\s*->\s*([A-Za-z0-9_]+)\s*=\s*new\s+(Google_Service_[A-Za-z_0-9]+_Resource)#',
					$source, $m);

			foreach ($m[1] as $index => $property) {
				$className = $m[2][$index];
				$this->properties[$property] = $className;
			}
		}
		return $this->properties;
	}

	public function analyze() {
		$this->propsToModify = array();

		$classProperties = $this->getProperties();

		$properties = $this->class->getProperties(\ReflectionProperty::IS_PUBLIC);
		foreach ($properties as $property) {
			$propName = $property->getName();
			if (!in_array($propName, self::$RESERVED_PROPS) && isset($classProperties[$propName])) {
				$comment = $property->getDocComment();
				if (!self::commentHasVar($comment, $classProperties[$propName])) {
					$this->propsToModify[] = $property;
				}
			}
		}
	}

	public function fix()
	{
		$this->endLine = $this->class->getConstructor()->getStartLine();

		$this->analyze();

		$patterns = array();
		$overrides = array();

		$source = $this->getPhpSource();
		foreach ($this->propsToModify as $property) {
			$name = $property->getName();
			$comment = $property->getDocComment();

			$newProperty = "/** @var {$this->properties[$name]} */\n  public $$name;";

			$oldComment = preg_quote($comment);
			$oldData = '#' . ($oldComment ? $oldComment . '\s*' : '') . "(var|public)\s+\\\$$name\s*;#";

			$patterns[] = $oldData;
			$overrides[] = $newProperty;
		}

		$source = preg_replace($patterns, $overrides, $source);

		return $source;
	}
}

/**
 * Class ClassParserModel
 *
 * Parses model classes
 *
 * @package org\majkel\GoogleApiAddHints
 */
class ClassParserModel extends ClassParser {

	/**
	 * @var array
	 */
	protected $properties;

	/**
	 * @var \ReflectionProperty[]
	 */
	protected $propsToModify = array();

	/**
	 * @param \ReflectionProperty $property
	 * @return array() $name, $class, $type
	 */
	protected function getRelProperty($property) {
		if ($property->isDefault()) {
			$len = strlen($property->getName());
			$offset = strpos($property->getName(), 'Type');
			$name = substr($property->getName(), 0, $len - 4);
			$dataTypePropname = $name . 'DataType';
			if ($offset === $len - 4 && $this->class->hasProperty($dataTypePropname)) {
				$def = $this->class->getDefaultProperties();
				return array(
						$name,
						$def[$name.'Type'],
						isset($def[$dataTypePropname]) ? $def[$dataTypePropname] : ''
				);
			}
		}
		return array(false, false, false);
	}

	/**
	 * @return array
	 */
	public function getProperties() {
		if (is_null($this->properties)) {
			$this->properties = array();
			$PROPERTY_FILTER = \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE;
			foreach ($this->class->getProperties($PROPERTY_FILTER) as $property) {
				list($name, $class, $type) = $this->getRelProperty($property);
				if ($name) {
					$this->properties[$name] = array($type, $class);
				}
			}
		}
		return $this->properties;
	}

	/**
	 * @param string $class
	 * @param string $type
	 * @return string
	 * @throws Exception
	 */
	protected static function getRealRelName($class, $type) {
		if (!empty($type)) {
			if (in_array($type, array('array', 'map'))) {
				return $class . '[]';
			}
			else {
				throw new Exception("Unsupported relation type `$type`");
			}
		}
		return $class;
	}

	public function fix()
	{
		$relProperties = $this->getProperties();

		$thisClass = $this->class->getShortName();
		$classDoc = (string)$this->class->getDocComment();

		$classDocHelper = new CommentHelper($classDoc);

		$classDocNls = substr_count($classDoc, "\n");
		if ($classDocNls) {
			$this->startLine -= $classDocNls + 1;
		}
		$this->startLine -= 1;

		$source = $this->getPhpSource();

		$patterns = array();
		$overrides = array();

		foreach ($relProperties as $name => $relProperty) {
			list($type, $class) = $relProperty;
			$Name = ucfirst($name);

			$realType = self::getRealRelName($class, $type);

			if (!self::commentHasProperty($classDocHelper->getOriginalComment(), $name, $realType)) {
				$classDocHelper->add(" * @property $realType \$$name");
			}

			$setter = $this->class->getMethod('set'.ucfirst($name));
			$setterComment = $setter->getDocComment();
			if (!self::commentHasParam($setterComment, $name, $realType)) {
				$oldMethod = '#';
				if ($setterComment) {
					$oldMethod .= preg_quote($setterComment) . '\s*';
				}
				$oldMethod .= "public\s+function\s+set$Name\s*\(\s*($class\s+)?\\\$$name\s*\)#";
				$newMethod = "/**\n   * @param $realType \$$name\n   */\n  public function set$Name("
						. ($realType == $class ? "$class " : '') . "\$$name)";

				$patterns[] = $oldMethod;
				$overrides[] = $newMethod;
			}

			$getter = $this->class->getMethod('get'.ucfirst($name));
			$getterComment = $getter->getDocComment();
			if (!self::commentHasReturn($getterComment, $realType)) {
				$oldMethod = '#';
				if ($getterComment) {
					$oldMethod .= preg_quote($getterComment) . '\s*';
				}
				$oldMethod .= "public\s+function\s+get$Name\s*\(\s*\)#";
				$newMethod = "/**\n   * @return $realType\n   */\n  public function get$Name()";

				$patterns[] = $oldMethod;
				$overrides[] = $newMethod;
			}

		}

		$comment = (string)$classDocHelper;
		if ($comment) {
			$oldMethod = '#';
			if ($classDocHelper->getOriginalComment()) {
				$oldMethod .= preg_quote($classDocHelper->getOriginalComment()) . '\s*';
			}
			$oldMethod .= "class\s+$thisClass\s+extends\s+Google_#";
			$patterns[] = $oldMethod;
			$overrides[] = "$comment\nclass $thisClass extends Google_";
		}

		$source = preg_replace($patterns, $overrides, $source);

		return $source;
	}
}

/**
 * Class ClassParserCollection
 *
 * Parses collection classes
 *
 * @package org\majkel\GoogleApiAddHints
 */
class ClassParserCollection extends ClassParserModel {

	/** @var string */
	protected $collectionField;

	/** @var string */
	protected $collectionFieldType;

	/**
	 * @return string
	 */
	protected function getCollectionField() {
		if (!isset($this->collectionField)) {
			$defaults = $this->class->getDefaultProperties();
			$this->collectionField = isset($defaults['collection_key'])
					? $defaults['collection_key']
					: 'items';
		}
		return $this->collectionField;
	}

	/**
	 * @return string
	 */
	protected function getCollectionFieldType() {
		if (!isset($this->collectionFieldType)) {
			$field = $this->getCollectionField() . 'Type';
			$defaults = $this->class->getDefaultProperties();
			$this->collectionFieldType = isset($defaults[$field])
					? $defaults[$field]
					: $this->class->getShortName();
		}
		return $this->collectionFieldType;
	}

	/**
	 * @return mixed|string
	 * @throws Exception
	 */
	public function fix() {
		$source = parent::fix();

		$collectionType = $this->getCollectionFieldType();

		$commentEnd = strpos($source, 'class');

		$commentHelper = new CommentHelper(substr($source, 0, $commentEnd));

		if (!$commentHelper->math(
				"#\s+@method\s+$collectionType\s+next\s*\(\s*\)\s+#"
		)) {
			$commentHelper->add(" * @method $collectionType next()");
		}
		if (!$commentHelper->math(
				"#\s+@method\s+$collectionType\s+current\s*\(\s*\)\s+#"
		)) {
			$commentHelper->add(" * @method $collectionType current()");
		}
		if (!$commentHelper->math(
				"#\s+@method\s+$collectionType\s+offsetGet\s*\(\s*(\w+\s+)\\\$\w+\s*\)#"
		)) {
			$commentHelper->add(" * @method $collectionType offsetGet(integer \$offset)");
		}
		if (!$commentHelper->math(
				"#\s+@method\s+void\s+offsetSet\s*\(\s*(\w+\s+)\\\$\w+\s*,\s*(\w+\s+)\\\$\w+\s*\)#"
		)) {
			$commentHelper->add(" * @method void offsetSet(integer \$offset, $collectionType \$value)");
		}

		return $commentHelper . "\n" . substr($source, $commentEnd);
	}

}

/**
 * Class ClassParserFactory
 *
 * Base class for parsers factories
 *
 * @package org\majkel\GoogleApiAddHints
 */
abstract class ClassParserFactory {

	/**
	 * Decides weather particular parser can handle the class.
	 * @param $class
	 * @return bool
	 */
	abstract public function canHandle($class);

	/**
	 * Generates new parser for class in source
	 * @param \ReflectionClass $class
	 * @param Source $source
	 * @return ClassParser
	 */
	abstract public function get($class, $source);

}

/**
 * Class ClassParserServiceFactory
 *
 * Generates services parser
 *
 * @package org\majkel\GoogleApiAddHints
 */
class ClassParserServiceFactory extends ClassParserFactory {

	/**
	 * @param \ReflectionClass $class
	 * @return bool
	 */
	public function canHandle($class)
	{
		return $class->isSubclassOf('Google_Service');
	}

	/**
	 * @param \ReflectionClass $class
	 * @param Source $source
	 * @return ClassParser
	 */
	public function get($class, $source)
	{
		return new ClassParserService($source, $class);
	}
}

/**
 * Class ClassParserModelFactory
 *
 * Generates model parsers
 *
 * @package org\majkel\GoogleApiAddHints
 */
class ClassParserModelFactory extends ClassParserFactory {

	/**
	 * @param \ReflectionClass $class
	 * @return bool
	 */
	public function canHandle($class)
	{
		return $class->isSubclassOf('Google_Model') && !$class->isSubclassOf('Google_Collection');
	}

	/**
	 * @param \ReflectionClass $class
	 * @param Source $source
	 * @return ClassParser
	 */
	public function get($class, $source)
	{
		return new ClassParserModel($source, $class);
	}
}

/**
 * Class ClassParserCollectionFactory
 *
 * Generates collection parsers
 *
 * @package org\majkel\GoogleApiAddHints
 */
class ClassParserCollectionFactory extends ClassParserFactory {

	/**
	 * @param \ReflectionClass $class
	 * @return bool
	 */
	public function canHandle($class)
	{
		return $class->isSubclassOf('Google_Collection');
	}

	/**
	 * @param \ReflectionClass $class
	 * @param Source $source
	 * @return ClassParser
	 */
	public function get($class, $source)
	{
		return new ClassParserCollection($source, $class);
	}
}

/**
 * Class CommentHelper
 *
 * handles modifications of class docs
 *
 * @package org\majkel\GoogleApiAddHints
 */
class CommentHelper {

	/** @var string[] */
	protected $commentArr;

	/** @var string */
	protected $originalComment;

	public function __construct($comment) {
		$this->originalComment = trim($comment);
		$this->commentArr = explode("\n", $this->originalComment);
		if (count($this->commentArr)) {
			array_pop($this->commentArr);
		}
		if (!count($this->commentArr)) {
			$this->commentArr[] = '/**';
		}
	}

	/**
	 * @return string
	 */
	public function getOriginalComment() {
		return $this->originalComment;
	}

	/**
	 * @param string $regex
	 * @return boolean
	 */
	public function math($regex) {
		return preg_match($regex, $this->originalComment) > 0;
	}

	/**
	 * @param string $line
	 * @return $this
	 */
	public function add($line) {
		$this->commentArr[] = $line;
		return $this;
	}

	/**
	 * @return string
	 */
	public function __toString() {
		if (end($this->commentArr) != ' */') {
			$this->add(' */');
		}
		return count($this->commentArr) > 2 ? implode("\n", $this->commentArr) : '';
	}

}

/**
 * Class Exception
 *
 * Basic exception
 *
 * @package org\majkel\GoogleApiAddHints
 */
class Exception extends \Exception {

}

/**
 * Class Source
 *
 * Holds source file. Allows to fetch sources by line numbers
 *
 * @package org\majkel\GoogleApiAddHints
 */
class Source {

	/** @var string */
	protected $source;
	/** @var integer[] */
	protected $offsets;

	/**
	 * @param string $filePath
	 */
	public function __construct($filePath) {
		$this->load($filePath);
	}

	/**
	 * @param string $filePath
	 */
	protected function load($filePath) {
		$this->source = '';
		$this->offsets = array();

		$offset = 0;
		$f = fopen($filePath, 'r');
		if ($f) {
			try {
				while (!feof($f)) {
					$line = fgets($f);
					$len = strlen($line);
					$this->source .= $line;
					$this->offsets[] = $offset;
					$offset += $len;
				}
			} catch (Exception $e) {
			}
		}
		if ($f) {
			fclose($f);
		}
		$this->offsets[] = $offset;
	}

	/**
	 * @param integer $startLine
	 * @param integer $endLine
	 * @return string
	 */
	public function get($startLine, $endLine = -1) {
		$start = $this->offsets[$startLine];
		$end = $endLine > 0 ? $this->offsets[$endLine] : end($this->offsets);
		return substr($this->source, $start, $end - $start);
	}
}

/**
 * Class FixHints
 *
 * Fixes comments in Google Client API Services
 *
 * @package org\majkel\GoogleApiAddHints
 */
class FixHints {

	const SERVICES_PATH = '/src/Google/Service';

	/** @var  string */
	protected $source;

	/** @var  string */
	protected $outputDirectory;

	/** @var ClassParserFactory[] */
	protected $tasks;

	/**
	 * @param string $source
	 * @param string $outputDirectory
	 * @throws Exception
	 */
	public function __construct($source, $outputDirectory = null) {
		$this->source = realpath($source.'/'.self::SERVICES_PATH);
		if (!is_dir($this->source)) {
			throw new Exception("Invalid input directory `{$source}`");
		}
		$this->outputDirectory = $outputDirectory ? $outputDirectory : $this->source;
		if (!file_exists($this->outputDirectory)) {
			mkdir($this->outputDirectory, 0777, true);
		}
		$this->outputDirectory = realpath($this->outputDirectory);
		if (!is_dir($this->outputDirectory)) {
			throw new Exception("Invalid output directory `{$outputDirectory}`");
		}
	}

	/**
	 * @return string
	 */
	public function getSource() {
		return $this->source;
	}

	/**
	 * @return string
	 */
	public function getOutput() {
		return $this->outputDirectory;
	}

	/**
	 * @return ClassParserFactory[]
	 */
	public function getParsersFactories() {
		if (is_null($this->tasks)) {
			$this->tasks = array(
					new ClassParserServiceFactory,
					new ClassParserModelFactory,
					new ClassParserCollectionFactory,
			);
		}
		return $this->tasks;
	}

	/**
	 * @return string[]
	 */
	protected function getFiles() {
		$paths = glob($this->source.'/*.php');
		foreach ((array)$paths as $i => $path) {
			$paths[$i] = realpath($path);
		}
		return $paths;
	}

	/**
	 * @param string $fileName
	 * @return \ReflectionClass[]
	 */
	protected function getClasses($fileName) {
		require_once $fileName;
		$dir = realpath($fileName);
		$classes = array();
		foreach (array_reverse(get_declared_classes()) as $className) {
			if (strpos($className, 'Google_Service_') === 0) {
				$class = new \ReflectionClass($className);
				if ($class->getFileName() === $dir) {
					$classes[] = $class;
				}
			}
		}
		usort($classes, function(\ReflectionClass $a, \ReflectionClass $b) {
			return $a->getStartLine() - $b->getStartLine();
		});
		return $classes;
	}

	public function execute() {
		foreach ($this->getFiles() as $filePath) {
			echo "$filePath";
			$classes = $this->getClasses($filePath);
			$source = new Source($filePath);
			$outFile = $this->outputDirectory.'/'.basename($filePath);
			file_put_contents($outFile, '');
			$outFile = realpath($outFile);
			if ($outFile != $filePath) {
				echo " => $outFile";
			}
			echo "\n";
			$offsetLine = 0;
			foreach ($classes as $index => $class) {
				foreach ($this->getParsersFactories() as $parserFactory) {
					if ($parserFactory->canHandle($class)) {
						$parser = $parserFactory->get($class, $source);
						$newSource = $parser->fix();
						file_put_contents($outFile, $source->get($offsetLine, $parser->getStartLine()), FILE_APPEND);
						file_put_contents($outFile, $newSource, FILE_APPEND);
						$offsetLine = $parser->getEndLine();
					}
				}
			}
			file_put_contents($outFile, $source->get($offsetLine), FILE_APPEND);
		}

	}

}

/**
 * @param \stdClass $obj
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function objGet(\stdClass $obj, $key, $default = null) {
	$keys = explode('/', $key);
	$last = array_pop($keys);
	foreach ($keys as $k) {
		if (isset($obj->$k) && is_object($obj->$k)) {
			$obj = $obj->$k;
		}
		else {
			return $default;
		}
	}
	return isset($obj->$last) ? $obj->$last : $default;
}

/**
 * Class ComposerParser
 * @package org\majkel\GoogleApiAddHints
 */
class ComposerParser {

	/** @var array */
	protected $config;

	/** @var string */
	protected $vendorDir;

	/** @var string */
	protected $libPath;

	public function __construct() {
		$this->config = new \stdClass();
		if (file_exists('composer.json')) {
			$config = file_get_contents('composer.json');
			if (is_string($config)) {
				$config = json_decode($config);
				if (is_object($config)) {
					$this->config = $config;
				}
			}
		}
	}

	/**
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	public function get($key, $default = null) {
		$result = objGet($this->config, $key, $default);
		return $result;
	}

	/**
	 * @return string
	 */
	public function getVendorDir() {
		if (is_null($this->vendorDir)) {
			$this->vendorDir = $this->get('config/vendor-dir', 'vendor');
		}
		return $this->vendorDir;
	}

	/**
	 * @return string
	 */
	public function getAutoLoadFile() {
		return $this->getVendorDir() . '/autoload.php';
	}

	/**
	 * @return string
	 */
	public function getLibPath() {
		if (is_null($this->libPath)) {
			$this->libPath = $this->getVendorDir() . '/google/apiclient';
		}
		return $this->libPath;
	}
}

/**
 * Class CmdArgs
 *
 * Handles command line arguments
 *
 * @package org\majkel\GoogleApiAddHints
 */
class CmdArgs {

	/** @var array */
	protected $args = array(
			's:' => 'source:',
			'o:' => 'output:',
			'v' => 'version',
			'h' => 'help',
	);

	/** @var array */
	protected $options;

	/** @var string */
	protected $source;

	public function __construct() {
		$short = implode('', array_keys($this->args));
		$long = array_values($this->args);
		$this->options = getopt($short, $long);
	}

	/**
	 * @param string $short
	 * @param string $long
	 * @param string $default
	 * @return string
	 */
	protected function getArg($short, $long, $default = null) {
		return isset($this->options[$short])
				? $this->options[$short]
				: (isset($this->options[$long])
						? $this->options[$long]
						: $default);
	}

	/**
	 * @param string $short
	 * @param string $long
	 * @return boolean
	 */
	protected function getBool($short, $long) {
		return isset($this->options[$short]) ? true
				: (isset($this->options[$long]) ? true : false);
	}

	/**
	 * @param string $source
	 */
	public function setSource($source) {
		$this->source = $source;
	}

	/**
	 * @return string
	 */
	public function getSource() {
		if (is_null($this->source)) {
			$this->source = $this->getArg('s', 'source');
		}
		return $this->source;
	}

	/**
	 * @return string
	 */
	public function getOutput() {
		return $this->getArg('o', 'output');
	}

	/**
	 * @return boolean
	 */
	public function isHelp() {
		return $this->getBool('h', 'help');
	}

	/**
	 * @return boolean
	 */
	public function isVersion() {
		return $this->getBool('v', 'version');
	}
}

function main() {
	try {
		echo "PHP Google Api Client Comment Fixer by majkel v0.2 (2015-02-04)\n";

		$cmdArgs = new CmdArgs();

		if ($cmdArgs->isHelp()) {
			echo <<<EOF

  This program adds type-hinting comments to Google API Client for PHP.

  -h         --help            Prints help
  -v         --version         Prints version and exits
  -s<Source> --source=<Source> Specifies Google API source directory
                               Be default current working directory (.)
  -o<Output> --output=<Output> Specifies output directory for modified services.
                               Be default same as source

  Example usage:

    php GoogleApiAddHints.php --source=vendor/google/apiclient

EOF;
		}

		if ($cmdArgs->isVersion() || $cmdArgs->isHelp()) {
			return 0;
		}

		$composer = new ComposerParser();
		$source = $cmdArgs->getSource();
		if (is_null($source)) {
			$source = $composer->getLibPath();
			echo "\n\tAssumed source path: $source\n";
			$cmdArgs->setSource($source);
		}

		$fixHints = new FixHints($cmdArgs->getSource(), $cmdArgs->getOutput());

		echo <<<EOF

  Source = {$fixHints->getSource()}
  Output = {$fixHints->getOutput()}


EOF;

		require_once $composer->getAutoLoadFile();
		unset($composer);
		$fixHints->execute();

		return 0;
	}
	catch (\Exception $e) {
		echo "\n\tError: {$e->getMessage()}\n";
		return -1 * $e->getCode();
	}
}

exit(main());
