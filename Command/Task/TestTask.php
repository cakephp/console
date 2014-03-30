<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         1.3.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console\Command\Task;

use Cake\Console\Shell;
use Cake\Controller\Controller;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Error;
use Cake\ORM\Association;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Folder;
use Cake\Utility\Inflector;

/**
 * Task class for creating and updating test files.
 *
 */
class TestTask extends BakeTask {

/**
 * path to TESTS directory
 *
 * @var string
 */
	public $path = TESTS;

/**
 * Tasks used.
 *
 * @var array
 */
	public $tasks = ['Template'];

/**
 * class types that methods can be generated for
 *
 * @var array
 */
	public $classTypes = [
		'Entity' => 'Model\Entity',
		'Table' => 'Model\Table',
		'Controller' => 'Controller',
		'Component' => 'Controller\Component',
		'Behavior' => 'Model\Behavior',
		'Helper' => 'View\Helper'
	];

/**
 * class types that methods can be generated for
 *
 * @var array
 */
	public $classSuffixes = [
		'Entity' => '',
		'Table' => 'Table',
		'Controller' => 'Controller',
		'Component' => 'Component',
		'Behavior' => 'Behavior',
		'Helper' => 'Helper'
	];

/**
 * Internal list of fixtures that have been added so far.
 *
 * @var array
 */
	protected $_fixtures = [];

/**
 * Execution method always used for tasks
 *
 * @return void
 */
	public function execute() {
		parent::execute();
		if (empty($this->args)) {
			return $this->outputTypeChoices();
		}

		$count = count($this->args);
		if ($count === 1) {
			return $this->outputClassChoices($this->args[0]);
		}

		if ($this->bake($this->args[0], $this->args[1])) {
			$this->out('<success>Done</success>');
		}
	}

/**
 * Output a list of class types you can bake a test for.
 *
 * @return void
 */
	public function outputTypeChoices() {
		$this->out(
			__d('cake_console', 'You must provide a class type to bake a test for. The valid types are:'),
			2
		);
		$i = 0;
		foreach ($this->classTypes as $option => $package) {
			$this->out(++$i . '. ' . $option);
		}
		$this->out('');
		$this->out('Re-run your command as Console/cake bake <type> <classname>');
	}

/**
 * Output a list of possible classnames you might want to generate a test for.
 *
 * @param string $type The typename to get classes for.
 * @return void
 */
	public function outputClassChoices($type) {
		$type = $this->mapType($type);
		$plugin = null;
		if (!empty($this->plugin)) {
			$plugin = $this->plugin;
		}

		$this->out(
			__d('cake_console', 'You must provide a class to bake a test for. Some possible options are:'),
			2
		);
		$options = $this->_getClassOptions($type);
		$i = 0;
		foreach ($options as $option) {
			$this->out(++$i . '. ' . $option);
		}
		$this->out('');
		$this->out('Re-run your command as Console/cake bake ' . $type . ' <classname>');
	}

/**
 * Get the possible classes for a given type.
 *
 * @param string $namespace The namespace fragment to look for classes in.
 * @return array
 */
	protected function _getClassOptions($namespace) {
		$classes = [];
		$base = APP;
		if ($this->plugin) {
			$base = Plugin::path($this->plugin);
		}
		$path = $base . str_replace('\\', DS, $namespace);
		$folder = new Folder($path);
		list($dirs, $files) = $folder->read();
		foreach ($files as $file) {
			$classes[] = str_replace('.php', '', $file);
		}
		return $classes;
	}

/**
 * Handles interactive baking
 *
 * @param string $type
 * @return string|boolean
 */
	protected function _interactive($type = null) {
		$this->interactive = true;
		$this->hr();
		$this->out(__d('cake_console', 'Bake Tests'));
		$this->out(__d('cake_console', 'Path: %s', $this->getPath()));
		$this->hr();

		if ($type) {
			$type = Inflector::camelize($type);
			if (!isset($this->classTypes[$type])) {
				$this->error(__d('cake_console', 'Incorrect type provided. Please choose one of %s', implode(', ', array_keys($this->classTypes))));
			}
		} else {
			$type = $this->getObjectType();
		}
		$className = $this->getClassName($type);
		return $this->bake($type, $className);
	}

/**
 * Completes final steps for generating data to create test case.
 *
 * @param string $type Type of object to bake test case for ie. Model, Controller
 * @param string $className the 'cake name' for the class ie. Posts for the PostsController
 * @return string|boolean
 */
	public function bake($type, $className) {
		$fullClassName = $this->getRealClassName($type, $className);

		if (!empty($this->params['fixtures'])) {
			$fixtures = array_map('trim', explode(',', $this->params['fixtures']));
			$this->_fixtures = array_filter($fixtures);
		} elseif ($this->typeCanDetectFixtures($type) && class_exists($fullClassName)) {
			$this->out(__d('cake_console', 'Bake is detecting possible fixtures...'));
			$testSubject = $this->buildTestSubject($type, $fullClassName);
			$this->generateFixtureList($testSubject);
		}

		$methods = [];
		if (class_exists($fullClassName)) {
			$methods = $this->getTestableMethods($fullClassName);
		}
		$mock = $this->hasMockClass($type, $fullClassName);
		list($preConstruct, $construction, $postConstruct) = $this->generateConstructor($type, $fullClassName);
		$uses = $this->generateUses($type, $fullClassName);

		$subject = $className;
		list($namespace, $className) = namespaceSplit($fullClassName);
		list($baseNamespace, $subNamespace) = explode('\\', $namespace, 2);

		$this->out("\n" . __d('cake_console', 'Baking test case for %s ...', $fullClassName), 1, Shell::QUIET);

		$this->Template->set('fixtures', $this->_fixtures);
		$this->Template->set('plugin', $this->plugin);
		$this->Template->set(compact(
			'subject', 'className', 'methods', 'type', 'fullClassName', 'mock',
			'realType', 'preConstruct', 'postConstruct', 'construction',
			'uses', 'baseNamespace', 'subNamespace', 'namespace'
		));
		$out = $this->Template->generate('classes', 'test');

		$filename = $this->testCaseFileName($type, $className);
		if ($this->createFile($filename, $out)) {
			return $out;
		}
		return false;
	}

/**
 * Get the user chosen Class name for the chosen type
 *
 * @param string $objectType Type of object to list classes for i.e. Model, Controller.
 * @return string Class name the user chose.
 */
	public function getClassName($objectType) {
		$type = ucfirst(strtolower($objectType));
		$typeLength = strlen($type);
		$type = $this->classTypes[$type];
		if ($this->plugin) {
			$plugin = $this->plugin . '.';
			$options = App::objects($plugin . $type);
		} else {
			$options = App::objects($type);
		}
		$this->out(__d('cake_console', 'Choose a %s class', $objectType));
		$keys = [];
		foreach ($options as $key => $option) {
			$this->out(++$key . '. ' . $option);
			$keys[] = $key;
		}
		while (empty($selection)) {
			$selection = $this->in(__d('cake_console', 'Choose an existing class, or enter the name of a class that does not exist'));
			if (is_numeric($selection) && isset($options[$selection - 1])) {
				$selection = $options[$selection - 1];
			}
			if ($type !== 'Model') {
				$selection = substr($selection, 0, $typeLength * - 1);
			}
		}
		return $selection;
	}

/**
 * Checks whether the chosen type can find its own fixtures.
 * Currently only model, and controller are supported
 *
 * @param string $type The Type of object you are generating tests for eg. controller
 * @return boolean
 */
	public function typeCanDetectFixtures($type) {
		$type = strtolower($type);
		return in_array($type, ['controller', 'table']);
	}

/**
 * Construct an instance of the class to be tested.
 * So that fixtures can be detected
 *
 * @param string $type The type of object you are generating tests for eg. controller
 * @param string $class The classname of the class the test is being generated for.
 * @return object And instance of the class that is going to be tested.
 */
	public function buildTestSubject($type, $class) {
		TableRegistry::clear();
		if (strtolower($type) === 'table') {
			list($namespace, $name) = namespaceSplit($class);
			$name = str_replace('Table', '', $name);
			if ($this->plugin) {
				$name = $this->plugin . '.' . $name;
			}
			$instance = TableRegistry::get($name);
		} else {
			$instance = new $class();
		}
		return $instance;
	}

/**
 * Gets the real class name from the cake short form. If the class name is already
 * suffixed with the type, the type will not be duplicated.
 *
 * @param string $type The Type of object you are generating tests for eg. controller.
 * @param string $class the Classname of the class the test is being generated for.
 * @return string Real classname
 */
	public function getRealClassName($type, $class) {
		$namespace = Configure::read('App.namespace');
		if ($this->plugin) {
			$namespace = Plugin::getNamespace($this->plugin);
		}
		$suffix = $this->classSuffixes[$type];
		$subSpace = $this->mapType($type);
		if ($suffix && strpos($class, $suffix) === false) {
			$class .= $suffix;
		}
		return $namespace . '\\' . $subSpace . '\\' . $class;
	}

/**
 * Map the types that TestTask uses to concrete types that App::classname can use.
 *
 * @param string $type The type of thing having a test generated.
 * @return string
 * @throws \Cake\Error\Exception When invalid object types are requested.
 */
	public function mapType($type) {
		$type = ucfirst($type);
		if (empty($this->classTypes[$type])) {
			throw new Error\Exception('Invalid object type.');
		}
		return $this->classTypes[$type];
	}

/**
 * Get methods declared in the class given.
 * No parent methods will be returned
 *
 * @param string $className Name of class to look at.
 * @return array Array of method names.
 */
	public function getTestableMethods($className) {
		$classMethods = get_class_methods($className);
		$parentMethods = get_class_methods(get_parent_class($className));
		$thisMethods = array_diff($classMethods, $parentMethods);
		$out = [];
		foreach ($thisMethods as $method) {
			if (substr($method, 0, 1) !== '_' && $method != strtolower($className)) {
				$out[] = $method;
			}
		}
		return $out;
	}

/**
 * Generate the list of fixtures that will be required to run this test based on
 * loaded models.
 *
 * @param object $subject The object you want to generate fixtures for.
 * @return array Array of fixtures to be included in the test.
 */
	public function generateFixtureList($subject) {
		$this->_fixtures = [];
		if ($subject instanceof Table) {
			$this->_processModel($subject);
		} elseif ($subject instanceof Controller) {
			$this->_processController($subject);
		}
		return array_values($this->_fixtures);
	}

/**
 * Process a model recursively and pull out all the
 * model names converting them to fixture names.
 *
 * @param Model $subject A Model class to scan for associations and pull fixtures off of.
 * @return void
 */
	protected function _processModel($subject) {
		$this->_addFixture($subject->alias());
		foreach ($subject->associations()->keys() as $alias) {
			$assoc = $subject->association($alias);
			$name = $assoc->target()->alias();
			if (!isset($this->_fixtures[$name])) {
				$this->_processModel($assoc->target());
			}
			if ($assoc->type() === Association::MANY_TO_MANY) {
				$junction = $assoc->junction();
				if (!isset($this->_fixtures[$junction->alias()])) {
					$this->_processModel($junction);
				}
			}
		}
	}

/**
 * Process all the models attached to a controller
 * and generate a fixture list.
 *
 * @param \Cake\Controller\Controller $subject A controller to pull model names off of.
 * @return void
 */
	protected function _processController($subject) {
		$subject->constructClasses();
		$models = [$subject->modelClass];
		foreach ($models as $model) {
			list(, $model) = pluginSplit($model);
			$this->_processModel($subject->{$model});
		}
	}

/**
 * Add class name to the fixture list.
 * Sets the app. or plugin.plugin_name. prefix.
 *
 * @param string $name Name of the Model class that a fixture might be required for.
 * @return void
 */
	protected function _addFixture($name) {
		if ($this->plugin) {
			$prefix = 'plugin.' . Inflector::underscore($this->plugin) . '.';
		} else {
			$prefix = 'app.';
		}
		$fixture = $prefix . Inflector::underscore(Inflector::singularize($name));
		$this->_fixtures[$name] = $fixture;
	}

/**
 * Interact with the user to get additional fixtures they want to use.
 *
 * @return array Array of fixtures the user wants to add.
 */
	public function getUserFixtures() {
		$proceed = $this->in(__d('cake_console', 'Bake could not detect fixtures, would you like to add some?'), ['y', 'n'], 'n');
		$fixtures = [];
		if (strtolower($proceed) === 'y') {
			$fixtureList = $this->in(__d('cake_console', "Please provide a comma separated list of the fixtures names you'd like to use.\nExample: 'app.comment, app.post, plugin.forums.post'"));
			$fixtureListTrimmed = str_replace(' ', '', $fixtureList);
			$fixtures = explode(',', $fixtureListTrimmed);
		}
		$this->_fixtures = array_merge($this->_fixtures, $fixtures);
		return $fixtures;
	}

/**
 * Is a mock class required for this type of test?
 * Controllers require a mock class.
 *
 * @param string $type The type of object tests are being generated for eg. controller.
 * @return boolean
 */
	public function hasMockClass($type) {
		$type = strtolower($type);
		return $type === 'controller';
	}

/**
 * Generate a constructor code snippet for the type and class name
 *
 * @param string $type The Type of object you are generating tests for eg. controller
 * @param string $fullClassName The full classname of the class the test is being generated for.
 * @return array Constructor snippets for the thing you are building.
 */
	public function generateConstructor($type, $fullClassName) {
		list($namespace, $className) = namespaceSplit($fullClassName);
		$type = strtolower($type);
		$pre = $construct = $post = '';
		if ($type === 'table') {
			$className = str_replace('Table', '', $className);
			$construct = "TableRegistry::get('{$className}', ['className' => '{$fullClassName}']);\n";
		}
		if ($type === 'behavior' || $type === 'entity') {
			$construct = "new {$className}();\n";
		}
		if ($type === 'helper') {
			$pre = "\$view = new View();\n";
			$construct = "new {$className}(\$view);\n";
		}
		if ($type === 'component') {
			$pre = "\$registry = new ComponentRegistry();\n";
			$construct = "new {$className}(\$registry);\n";
		}
		return [$pre, $construct, $post];
	}

/**
 * Generate the uses() calls for a type & class name
 *
 * @param string $type The Type of object you are generating tests for eg. controller
 * @param string $realType The package name for the class.
 * @param string $fullClassName The Classname of the class the test is being generated for.
 * @return array An array containing used classes
 */
	public function generateUses($type, $fullClassName) {
		$uses = [];
		$type = strtolower($type);
		if ($type == 'component') {
			$uses[] = 'Cake\Controller\ComponentRegistry';
		}
		if ($type == 'table') {
			$uses[] = 'Cake\ORM\TableRegistry';
		}
		if ($type == 'helper') {
			$uses[] = 'Cake\View\View';
		}
		$uses[] = $fullClassName;
		return $uses;
	}

/**
 * Make the filename for the test case. resolve the suffixes for controllers
 * and get the plugin path if needed.
 *
 * @param string $type The Type of object you are generating tests for eg. controller
 * @param string $className the Classname of the class the test is being generated for.
 * @return string filename the test should be created on.
 */
	public function testCaseFileName($type, $className) {
		$path = $this->getPath() . 'TestCase/';
		$type = Inflector::camelize($type);
		if (isset($this->classTypes[$type])) {
			$path .= $this->classTypes[$type] . DS;
		}
		list($namespace, $className) = namespaceSplit($this->getRealClassName($type, $className));
		return str_replace(['/', '\\'], DS, $path) . Inflector::camelize($className) . 'Test.php';
	}

/**
 * Gets the option parser instance and configures it.
 *
 * @return \Cake\Console\ConsoleOptionParser
 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();

		$parser->description(
			__d('cake_console', 'Bake test case skeletons for classes.')
		)->addArgument('type', [
			'help' => __d('cake_console', 'Type of class to bake, can be any of the following: controller, model, helper, component or behavior.'),
			'choices' => [
				'Controller', 'controller',
				'Table', 'table',
				'Entity', 'entity',
				'Helper', 'helper',
				'Component', 'component',
				'Behavior', 'behavior'
			]
		])->addArgument('name', [
			'help' => __d('cake_console', 'An existing class to bake tests for.')
		])->addOption('theme', [
			'short' => 't',
			'help' => __d('cake_console', 'Theme to use when baking code.')
		])->addOption('plugin', [
			'short' => 'p',
			'help' => __d('cake_console', 'CamelCased name of the plugin to bake tests for.')
		])->addOption('force', [
			'short' => 'f',
			'help' => __d('cake_console', 'Force overwriting existing files without prompting.')
		])->addOption('fixtures', [
			'help' => __d('cake_console', 'A comma separated list of fixture names you want to include.')
		]);

		return $parser;
	}

}
