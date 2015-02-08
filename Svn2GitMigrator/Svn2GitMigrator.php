<?php

/**
 * Exports Svn commits to git repository.
 *
 * Command-line arguments:
 *
 * Specify repositories locations:
 *   --svn=Svn root directory (default to .)
 *   --git=Git root directory (default to .)
 *
 * Extract log from Svn repository
 *   --from=starting revision
 *   --to=ending revision
 *
 * Provide already exported logs and commits
 *   --log=XML file with Svn log
 *   --patches=Directory containing parselog_%(R-1)-%(R).diff files
 *
 * Miscellaneous
 *   --authors=Provide authors.txt
 *
 * @created   2015-02-05 16:12:08
 * @author    Michał Kowalik <michal.kowalik@slct.pl>
 * @copyright (c) 2014, Select Sp. z o.o.
 */

namespace org\majkel\Svn2GitMigrator;

use DOMDocument;
use Exception;
use DOMElement;

class AbstractException extends Exception {
}

class ParserException extends AbstractException {
}

class ScmException extends AbstractException {
}

class DiffStrategyException extends AbstractException {
}

/**
 * @property string $log
 * @property string $authors
 * @property string $from
 * @property string $to
 * @property string $svn
 * @property string $git
 * @property string $patches
 */
class CmdArgs {

    /** @var array */
    protected $options = array();

    /** @var array */
    protected $arguments = array(
        'log' => 'l',
        'authors' => 'a',
        'from' => 'f',
        'to' => 't',
        'svn' => 's',
        'git' => 'g',
        'patches' => 'p'
    );

    public function __construct() {
        $long = array_keys($this->arguments);
        foreach ($long as $i => $val) {
            $long[$i] = $val.':';
        }
        $short = array_values($this->arguments);
        foreach ($short as $i => $val) {
            if (empty($val)) {
                unset($short[$i]);
            }
            else {
                $short[$i] = $val.':';
            }
        }
        $this->options = getopt(implode('',$short), $long);
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return string
     */
    public function get($name, $default = null) {
        $options = $this->options;
        $args = $this->arguments;
        if (isset($options[$name])) {
            return $options[$name];
        }
        else if (isset($args[$name])) {
            $arg = $args[$name];
            if (isset($options[$arg])) {
                return $options[$arg];
            }
        }
        return $default;
    }

    /**
     * @param string $name
     * @return string
     */
    public function __get($name) {
        return $this->get($name);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value) {
        $this->options[$name] = $value;
    }

    public function dumpArguments() {
        var_dump($this->options);
    }
}

abstract class DiffStrategy {

    /**
     * @param Revision $revA
     * @param Revision $revB
     * @throws AbstractException
     */
    abstract function getDiff($revA, $revB);
}

class DiffStrategyFiles extends DiffStrategy {

    /** @var string */
    protected $directory;

    /** @var string */
    protected $filePattern;

    /**
     * @return string
     */
    public function getDirectory() {
        return $this->directory;
    }

    /**
     * @param string $directory
     * @return DiffStrategyFiles
     * @throws DiffStrategyException
     */
    public function setDirectory($directory) {
        if (!is_dir($directory)) {
            throw new DiffStrategyException("Directory `$directory` does not exists!");
        }
        $this->directory = $directory;
        return $this;
    }

    /**
     * @return string
     */
    public function getFilePattern() {
        return $this->filePattern;
    }

    /**
     * @param string $filePattern
     * @return DiffStrategyFiles
     */
    public function setFilepattern($filePattern) {
        $this->filePattern = $filePattern;
        return $this;
    }

    /**
     * @param integer $revA
     * @param integer $revB
     * @return string
     */
    public function getFile($revA, $revB) {
        return $this->getDirectory() . '/' .
            sprintf($this->getFilePattern(), $revA, $revB);
    }

    /**
     * @param Revision $revA
     * @param Revision $revB
     * @return string
     * @throws DiffStrategyException
     */
    public function getDiff($revA, $revB) {
        $file = $this->getFile($revA->revision, $revB->revision);
        if (!file_exists($file)) {
            throw new DiffStrategyException("Cannot find fiff file `$file`!");
        }
        return file_get_contents($file);
    }
}

class DiffStrategySvn extends DiffStrategy {

    /** @var Svn */
    protected $svn;

    /**
     * @param Svn $svn
     * @return DiffStrategySvn
     */
    public function setSvn($svn) {
        $this->svn = $svn;
        return $this;
    }

    /**
     * @return Svn
     */
    public function getSvn() {
        return $this->svn;
    }

    /**
     * @param Revision $revA
     * @param Revision $revB
     * @return string
     */
    public function getDiff($revA, $revB) {
        return $this->getSvn()->getDiff($revA->revision, $revB->revision);
    }
}

class Manager {

    /** @var CmdArgs */
    protected $cmdArgs;

    /** @var Parser */
    protected $parser;

    /** @var ScmSvn */
    protected $svn;

    /** @var ScmGit */
    protected $git;

    /** @var DiffStrategy */
    protected $diffStrategy;

    /**
     * @return DiffStrategy
     */
    public function getDiffStrategy() {
        if (is_null($this->diffStrategy)) {
            $cmdArgs = $this->cmdArgs;
            if ($cmdArgs->patches) {
                $strategy = new DiffStrategyFiles();
                $strategy->setDirectory($cmdArgs->patches);
                $strategy->setFilepattern('parselog_%d-%d.diff');
            }
            else {
                $strategy = new DiffStrategySvn();
                $strategy->setSvn($this->getSvn());
            }
            $this->diffStrategy = $strategy;
        }
        return $this->diffStrategy;
    }

    /**
     * @param Scm $var
     * @param string $type
     * @return Scm
     */
    protected function getScm($var, $type) {
        if (is_null($var)) {
            $cls = __NAMESPACE__ . '\Scm' . $type;
            $var = new $cls();
            $workingDir = $this->getCmdArgs()->get(strtolower($type), '.');
            $var->setWorkingDir($workingDir);
            $var->setLog(true);
        }
        return $var;
    }

    /**
     * @return ScmSvn
     */
    public function getSvn() {
        return $this->getScm($this->svn, 'Svn');
    }

    /**
     * @return ScmGit
     */
    public function getGit() {
        return $this->getScm($this->git, 'Git');
    }

    /**
     * @return CmdArgs
     */
    public function getCmdArgs() {
        return $this->cmdArgs;
    }

    /**
     * @param CmdArgs $cmdArgs
     * @return Manager
     */
    public function setCmdArgs($cmdArgs) {
        $this->cmdArgs = $cmdArgs;
        return $this;
    }

    /**
     * @return Parser
     */
    public function getParser() {
        if (is_null($this->parser)) {
            $cmdArgs = $this->getCmdArgs();
            $parser = new Parser();
            if ($cmdArgs->log) {
                $parser->setLogFromFile($cmdArgs->log);
            }
            else {
                $log = $this->getSvn()->getLog($cmdArgs->from, $cmdArgs->to);
                $parser->setLogFromString($log);
            }
            if ($cmdArgs->authors) {
                $parser->setAuthorsFromFile($cmdArgs->authors);
            }
            $this->parser = $parser;
        }
        return $this->parser;
    }

}

class Revision {

    /** @var string */
    public $revision;

    /** @var string */
    public $author;

    /** @var string */
    public $date;

    /** @var string */
    public $message;

    /**
     * @return string
     */
    public function __toString() {
        return "r{$this->revision} {$this->author} [{$this->date}] {$this->message}";
    }
}

abstract class Scm {

    /** @var string */
    protected $workingDir = '.';

    /** @var integer */
    protected $errorCode = 0;

    /** @var boolean */
    protected $log;

    /**
     * @param boolean $log
     * @return Scm
     */
    public function setLog($log) {
        $this->log = $log;
        return $this;
    }

    public function isLog() {
        return $this->log;
    }

    /**
     * @return boolean
     */
    public function hasErrors() {
        return $this->errorCode !== 0;
    }

    /**
     * @param string $workingDir
     * @return Scm
     * @throws ScmException
     */
    public function setWorkingDir($workingDir) {
        if (!file_exists($workingDir)) {
            throw new ScmException("Invalid working directory `$workingDir`");
        }
        $this->workingDir = $workingDir;
        return $this;
    }

    /**
     * @return string
     */
    public function getWorkingDir() {
        return $this->workingDir;
    }

    /**
     * @param string $cmd
     * @return string
     * @throws ScmException
     */
    public function execute($cmd) {
        $currDir = getcwd();
        chdir($this->getWorkingDir());
        if ($this->isLog()) {
            echo " > $cmd\n";
        }
        $result = system($cmd, $this->errorCode);
        if ($this->hasErrors()) {
            throw new ScmException("Command `$cmd` exited with ($this->errorCode)");
        }
        chdir($currDir);
        return $result;
    }
}

class ScmSvn extends Scm {

    /**
     * @param integer $from
     * @param integer $to
     * @return string
     */
    public function getLog($from = null, $to = null) {
        if (!is_null($from)) {
            $arr[] = $from;
        }
        if (!is_null($from)) {
            $arr[] = $to;
        }
        if (count($arr)) {
            $rev = implode(':', $arr);
            return $this->execute(`svn log -r $rev --xml`);
        }
        else {
            return $this->execute(`svn log --xml`);
        }
    }

    /**
     * @param integer $revA
     * @param integer $revB
     * @return string
     */
    public function getDiff($revA, $revB) {
        return $this->execute(`svn diff -r $revA:$revB`);
    }
}

class ScmGit extends Scm {

    public function setWorkingDir($workingDir) {
        parent::setWorkingDir($workingDir);
        try {
            $this->execute('git status');
        }
        catch (AbstractException $_) {
            throw new ScmException("`$workingDir` is not valid git repo!");
        }
        return $this;
    }

    public function revertAll() {
        $this->execute('git reset --hard');
    }

    /**
     * @param Revision $rev
     */
    public function applyPatch($rev, $content) {
        $tmpFile = tempnam(realpath('.'), 'ScmGit');
        file_put_contents($tmpFile, $content);
        $this->execute("git apply -p0 --whitespace=nowarn $tmpFile");
        unlink($tmpFile);
        $this->execute("git add -A");
        $this->execute("git commit --allow-empty-message -m \"{$rev->message}\" --author=\"{$rev->author}\" --date=\"{$rev->date}\"");
    }
}

class Parser {

    /** @var string[] */
    protected $authors;

    /** @var DOMDocument */
    protected $log;

    public function __construct() {
        $this->log = new DOMDocument();
        $this->log->encoding = 'UTF-8';
    }

    /**
     * @param string $path
     * @return string
     * @throws ParserException
     */
    protected function loadFile($path) {
        if (!file_exists($path)) {
            throw new ParserException("Unable to open file `$path`");
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new ParserException("File `$path` is invalid");
        }
        return $content;
    }

    /**
     * @param string $authorsFile
     * @return Generator
     * @throws ParserException
     */
    public function setAuthorsFromFile($authorsFile) {
        $authorsTxt = $this->loadFile($authorsFile);
        $this->authors = [];
        foreach (explode("\n", $authorsTxt) as $line) {
            $line = trim($line);
            $i = strpos($line, ' ');
            $svnAuthor = trim(substr($line, 0, $i));
            $gitAuthor = trim(substr($line, $i));
            if ($svnAuthor) {
                $this->authors[$svnAuthor] = $gitAuthor;
            }
        }
        return $this;
    }

    /**
     * @param string $logFile
     * @return Parser
     */
    public function setLogFromFile($logFile) {
        $this->getLog()->load($logFile);
        return $this;
    }

    /**
     * @param string $log
     * @return Parser
     */
    public function setLogFromString($log) {
        $this->getLog()->loadXML($log);
        return $this;
    }

    /**
     * @return string[]
     */
    public function getAuthors() {
        return $this->authors;
    }

    /**
     * @return DOMDocument
     */
    public function getLog() {
        return $this->log;
    }

    /**
     * @param DOMElement $logentry
     * @return Revision
     */
    protected function generate($logentry) {
        $revison = new Revision();
        $revison->revision = $logentry->getAttribute('revision');
        $author = $logentry->getElementsByTagName('author')->item(0)->nodeValue;
        if (isset($this->authors[$author])) {
            $revison->author = $this->authors[$author];
        } else {
            $revison->author = "$author <$author@slct.pl>";
        }
        $revison->date = $logentry->getElementsByTagName('date')->item(0)->nodeValue;
        $revison->message = $logentry->getElementsByTagName('msg')->item(0)->nodeValue;
        return $revison;
    }

    /**
     * @return Revision[]
     */
    public function parse() {
        $logentries = $this->getLog()->getElementsByTagName('logentry');
        $revisions = [];
        foreach ($logentries as $logentry) {
            $revison = $this->generate($logentry);
            $revisions[] = $revison;
        }
        return $revisions;
    }
}

$cmdArgs = new CmdArgs();
$cmdArgs->dumpArguments();

$manager = new Manager();
$manager->setCmdArgs($cmdArgs);

$parser = $manager->getParser();
$revisions = $parser->parse();
$diffStrategy = $manager->getDiffStrategy();

$manager->getGit()->revertAll();
for ($i = 0; $i < count($revisions) - 1; $i++) {
    $revA = $revisions[$i];
    $revB = $revisions[$i+1];
    echo ($i+1) . ') ' . $revB . "\n";
    $diff = $diffStrategy->getDiff($revA, $revB);
    $manager->getGit()->applyPatch($revB, $diff);
}
