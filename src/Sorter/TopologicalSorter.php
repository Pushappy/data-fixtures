<?php

declare(strict_types=1);

namespace Doctrine\Common\DataFixtures\Sorter;

use Doctrine\Common\DataFixtures\Exception\CircularReferenceException;
use Doctrine\ORM\Mapping\ClassMetadata;
use RuntimeException;

use function count;
use function sprintf;
use function uasort;

/**
 * TopologicalSorter is an ordering algorithm for directed graphs (DG) and/or
 * directed acyclic graphs (DAG) by using a depth-first searching (DFS) to
 * traverse the graph built in memory.
 * This algorithm have a linear running time based on nodes (V) and dependency
 * between the nodes (E), resulting in a computational complexity of O(V + E).
 *
 * @internal this class is to be used only by data-fixtures internals: do not
 *           rely on it in your own libraries/applications.
 */
class TopologicalSorter
{
    /**
     * Matrix of nodes (aka. vertex).
     * Keys are provided hashes and values are the node definition objects.
     *
     * @var Vertex[]
     */
    private array $nodeList = [];

    /**
     * Volatile variable holding calculated nodes during sorting process.
     *
     * @var ClassMetadata[]
     */
    private array $sortedNodeList = [];

    /**
     * Allow or not cyclic dependencies
     */
    private bool $allowCyclicDependencies;

    /** @param bool $allowCyclicDependencies */
    public function __construct($allowCyclicDependencies = true)
    {
        $this->allowCyclicDependencies = (bool) $allowCyclicDependencies;
    }

    /**
     * Adds a new node (vertex) to the graph, assigning its hash and value.
     *
     * @param string $hash
     *
     * @return void
     */
    public function addNode($hash, ClassMetadata $node)
    {
        $this->nodeList[$hash] = new Vertex($node);
    }

    /**
     * Checks the existence of a node in the graph.
     *
     * @param string $hash
     *
     * @return bool
     */
    public function hasNode($hash)
    {
        return isset($this->nodeList[$hash]);
    }

    /**
     * Adds a new dependency (edge) to the graph using their hashes.
     *
     * @param string $fromHash
     * @param string $toHash
     *
     * @return void
     */
    public function addDependency($fromHash, $toHash)
    {
        $definition = $this->nodeList[$fromHash];

        $definition->dependencyList[] = $toHash;
    }

    /**
     * Return a valid order list of all current nodes.
     * The desired topological sorting is the postorder of these searches.
     *
     * Note: Highly performance-sensitive method.
     *
     * @return ClassMetadata[]
     *
     * @throws RuntimeException
     * @throws CircularReferenceException
     */
    public function sort()
    {
        uasort($this->nodeList, static function (Vertex $a, Vertex $b) {
            return count($a->dependencyList) > count($b->dependencyList) ? 1 : -1;
        });
        foreach ($this->nodeList as $definition) {
            if ($definition->state !== Vertex::NOT_VISITED) {
                continue;
            }

            $this->visit($definition);
        }

        $sortedList = $this->sortedNodeList;

        $this->nodeList       = [];
        $this->sortedNodeList = [];

        return $sortedList;
    }

    /**
     * Visit a given node definition for reordering.
     *
     * Note: Highly performance-sensitive method.
     *
     * @return void
     *
     * @throws RuntimeException
     * @throws CircularReferenceException
     */
    private function visit(Vertex $definition)
    {
        $definition->state = Vertex::IN_PROGRESS;

        foreach ($definition->dependencyList as $dependency) {
            $childDefinition = $this->getDefinition($dependency, $definition);

            // allow self referencing classes
            if ($definition === $childDefinition) {
                continue;
            }

            switch ($childDefinition->state) {
                case Vertex::VISITED:
                    break;
                case Vertex::IN_PROGRESS:
                    if (! $this->allowCyclicDependencies) {
                        throw new CircularReferenceException(
                            sprintf(
                                <<<'EXCEPTION'
Graph contains cyclic dependency between the classes "%s" and
 "%s". An example of this problem would be the following:
Class C has class B as its dependency. Then, class B has class A has its dependency.
Finally, class A has class C as its dependency.
EXCEPTION
                                ,
                                $definition->value->getName(),
                                $childDefinition->value->getName(),
                            ),
                        );
                    }

                    // first do the rest of the unvisited children of the "in_progress" child before we continue
                    // with the current one, to be sure that there are no other dependencies that need
                    // to be cleared before this ($definition) node
                    foreach ($childDefinition->dependencyList as $childDependency) {
                        $nestedChildDefinition = $this->getDefinition($childDependency, $childDefinition);
                        if ($nestedChildDefinition->state !== Vertex::NOT_VISITED) {
                            continue;
                        }

                        $this->visit($nestedChildDefinition);
                    }

                    break;
                case Vertex::NOT_VISITED:
                    $this->visit($childDefinition);
            }
        }

        $definition->state = Vertex::VISITED;

        $this->sortedNodeList[] = $definition->value;
    }

    private function getDefinition(string $dependency, Vertex $definition): Vertex
    {
        if (! isset($this->nodeList[$dependency])) {
            throw new RuntimeException(sprintf(
                'Fixture "%s" has a dependency of fixture "%s", but it\'s not listed to be loaded.',
                $definition->value->getName(),
                $dependency,
            ));
        }

        return $this->nodeList[$dependency];
    }
}
