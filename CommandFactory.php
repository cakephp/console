<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console;

use Cake\Core\ContainerInterface;

/**
 * This is a factory for creating Command instances.
 *
 * This factory can be replaced or extended if you need to customize building
 * your command objects.
 */
class CommandFactory implements CommandFactoryInterface
{
    /**
     * @var \Cake\Core\ContainerInterface|null
     */
    protected ?ContainerInterface $container = null;

    /**
     * Constructor
     *
     * @param \Cake\Core\ContainerInterface|null $container The container to use if available.
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @inheritDoc
     */
    public function create(string $className): CommandInterface
    {
        if ($this->container && $this->container->has($className)) {
            $command = $this->container->get($className);
        } else {
            $command = new $className();
        }

        return $command;
    }
}
