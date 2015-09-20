<?php

/*
 * This file is part of PhpSpec, A php toolset to drive emergent
 * design by specification.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpSpec\Runner\Maintainer;

use PhpSpec\Exception\Fracture\CollaboratorNotFoundException;
use PhpSpec\Exception\Wrapper\CollaboratorException;
use PhpSpec\Exception\Wrapper\InvalidCollaboratorTypeException;
use PhpSpec\Loader\Node\ExampleNode;
use PhpSpec\Loader\Transformer\TypeHintIndex;
use PhpSpec\SpecificationInterface;
use PhpSpec\Runner\MatcherManager;
use PhpSpec\Runner\CollaboratorManager;
use PhpSpec\Wrapper\Collaborator;
use PhpSpec\Wrapper\Unwrapper;
use Prophecy\Prophet;
use ReflectionException;

class CollaboratorsMaintainer implements MaintainerInterface
{
    /**
     * @var string
     */
    private static $docex = '#@param *([^ ]*) *\$([^ ]*)#';
    /**
     * @var Unwrapper
     */
    private $unwrapper;
    /**
     * @var Prophet
     */
    private $prophet;

    /**
     * @var TypeHintIndex
     */
    private $typeHintIndex;

    /**
     * @param Unwrapper $unwrapper
     * @param TypeHintIndex $typeHintIndex
     */
    public function __construct(Unwrapper $unwrapper, TypeHintIndex $typeHintIndex = null)
    {
        $this->unwrapper = $unwrapper;
        $this->typeHintIndex = $typeHintIndex;
    }

    /**
     * @param ExampleNode $example
     *
     * @return bool
     */
    public function supports(ExampleNode $example)
    {
        return true;
    }

    /**
     * @param ExampleNode            $example
     * @param SpecificationInterface $context
     * @param MatcherManager         $matchers
     * @param CollaboratorManager    $collaborators
     */
    public function prepare(
        ExampleNode $example,
        SpecificationInterface $context,
        MatcherManager $matchers,
        CollaboratorManager $collaborators
    ) {
        $this->prophet = new Prophet(null, $this->unwrapper, null);

        $classRefl = $example->getSpecification()->getClassReflection();

        if ($classRefl->hasMethod('let')) {
            $this->generateCollaborators($collaborators, $classRefl->getMethod('let'), $classRefl);
        }

        $this->generateCollaborators($collaborators, $example->getFunctionReflection(), $classRefl);
    }

    /**
     * @param ExampleNode            $example
     * @param SpecificationInterface $context
     * @param MatcherManager         $matchers
     * @param CollaboratorManager    $collaborators
     */
    public function teardown(
        ExampleNode $example,
        SpecificationInterface $context,
        MatcherManager $matchers,
        CollaboratorManager $collaborators
    ) {
        $this->prophet->checkPredictions();
    }

    /**
     * @return int
     */
    public function getPriority()
    {
        return 50;
    }

    /**
     * @param CollaboratorManager         $collaborators
     * @param \ReflectionFunctionAbstract $function
     * @param \ReflectionClass            $classRefl
     */
    private function generateCollaborators(CollaboratorManager $collaborators, \ReflectionFunctionAbstract $function, \ReflectionClass $classRefl)
    {
        if ($comment = $function->getDocComment()) {
            $comment = str_replace("\r\n", "\n", $comment);
            foreach (explode("\n", trim($comment)) as $line) {
                if (preg_match(self::$docex, $line, $match)) {
                    $collaborator = $this->getOrCreateCollaborator($collaborators, $match[2]);
                    $collaborator->beADoubleOf($match[1]);
                }
            }
        }

        foreach ($function->getParameters() as $parameter) {
            if ($this->isUnsupportedTypeHinting($parameter)) {
                throw new InvalidCollaboratorTypeException($parameter, $function);
            }

            $collaborator = $this->getOrCreateCollaborator($collaborators, $parameter->getName());
            try {
                if (null !== $class = $parameter->getClass()) {
                    $collaborator->beADoubleOf($class->getName());
                }
                elseif ($this->typeHintIndex && $indexedClass = $this->typeHintIndex->lookup($classRefl->getName(), $parameter->getDeclaringFunction()->getName(), '$'.$parameter->getName())) {
                    $collaborator->beADoubleOf($indexedClass);
                }
            } catch (ReflectionException $e) {
                throw new CollaboratorNotFoundException(
                    sprintf('Collaborator does not exist '),
                    0, $e,
                    $parameter
                );
            }
        }
    }

    private function isUnsupportedTypeHinting(\ReflectionParameter $parameter)
    {
        $isUnsupportedTypeHinting = $parameter->isArray();
        if (PHP_VERSION_ID >= 70000) {
            $isUnsupportedTypeHinting = $isUnsupportedTypeHinting || $parameter->getType()->isBuiltin();
        } else if (PHP_VERSION_ID >= 50400) {
            $isUnsupportedTypeHinting = $isUnsupportedTypeHinting || $parameter->isCallable();
        }

        return $isUnsupportedTypeHinting;
    }

    /**
     * @param CollaboratorManager $collaborators
     * @param string              $name
     *
     * @return Collaborator
     */
    private function getOrCreateCollaborator(CollaboratorManager $collaborators, $name)
    {
        if (!$collaborators->has($name)) {
            $collaborator = new Collaborator($this->prophet->prophesize());
            $collaborators->set($name, $collaborator);
        }

        return $collaborators->get($name);
    }
}
