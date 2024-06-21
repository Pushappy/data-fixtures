<?php

declare(strict_types=1);

namespace Doctrine\Test\DataFixtures\Sorter;

use Doctrine\Common\DataFixtures\Exception\CircularReferenceException;
use Doctrine\Common\DataFixtures\Sorter\TopologicalSorter;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Common\DataFixtures\BaseTestCase;
use RuntimeException;

use function array_map;
use function array_search;
use function array_splice;
use function array_unshift;
use function count;
use function implode;

/**
 * TopologicalSorter tests.
 *
 * Note: When writing tests here consider that a lot of graph
 *       constellations can have many valid orderings, so you may want to
 *       build a graph that has only 1 valid order to simplify your tests
 *
 * @covers \Doctrine\Common\DataFixtures\Sorter\TopologicalSorter
 */
class TopologicalSorterTest extends BaseTestCase
{
    public function testSuccessSortLinearDependency(): void
    {
        $sorter = new TopologicalSorter();

        $node1 = new ClassMetadata('1');
        $node2 = new ClassMetadata('2');
        $node3 = new ClassMetadata('3');
        $node4 = new ClassMetadata('4');
        $node5 = new ClassMetadata('5');

        $sorter->addNode('1', $node1);
        $sorter->addNode('2', $node2);
        $sorter->addNode('3', $node3);
        $sorter->addNode('4', $node4);
        $sorter->addNode('5', $node5);

        $sorter->addDependency('1', '2');
        $sorter->addDependency('2', '3');
        $sorter->addDependency('3', '4');
        $sorter->addDependency('5', '1');

        $sortedList  = $sorter->sort();
        $correctList = [$node4, $node3, $node2, $node1, $node5];

        self::assertSame($correctList, $sortedList);
    }

    public function testSuccessSortMultiDependency(): void
    {
        $sorter = new TopologicalSorter();

        $node1 = new ClassMetadata('1');
        $node2 = new ClassMetadata('2');
        $node3 = new ClassMetadata('3');
        $node4 = new ClassMetadata('4');
        $node5 = new ClassMetadata('5');

        $sorter->addNode('1', $node1);
        $sorter->addNode('2', $node2);
        $sorter->addNode('3', $node3);
        $sorter->addNode('4', $node4);
        $sorter->addNode('5', $node5);

        $sorter->addDependency('3', '2');
        $sorter->addDependency('3', '4');
        $sorter->addDependency('3', '5');
        $sorter->addDependency('4', '1');
        $sorter->addDependency('5', '1');

        $sortedList  = $sorter->sort();
        $correctList = [$node1, $node2, $node4, $node5, $node3];

        self::assertSame($correctList, $sortedList);
    }

    public static function cyclicNodePermutationsDataProvider(): iterable
    {
        $list[] = new ClassMetadata('1');
        $list[] = new ClassMetadata('2');
        $list[] = new ClassMetadata('3');
        $list[] = new ClassMetadata('4');
        $list[] = new ClassMetadata('5');

        foreach (self::permute($list) as $list) {
            $label = 'Node order: ' . implode(',', self::nodeNames($list));

            yield $label => [$list];
        }
    }

    /** @param ClassMetadata[] $list */
    private static function nodeNames(array $list): array
    {
        return array_map(static function (ClassMetadata $node) {
            return $node->getName();
        }, $list);
    }

    private static function permute(array $items, array $permutation = [], array &$result = []): array
    {
        if (empty($items)) {
            $result[] = $permutation;
        } else {
            for ($i = count($items) - 1; $i >= 0; --$i) {
                $newItems       = $items;
                $newPermutation = $permutation;
                [$item]         = array_splice($newItems, $i, 1);
                array_unshift($newPermutation, $item);
                self::permute($newItems, $newPermutation, $result);
            }
        }

        return $result;
    }

    /** @dataProvider cyclicNodePermutationsDataProvider */
    public function testSortCyclicDependency(array $nodes): void
    {
        $sorter = new TopologicalSorter();

        foreach ($nodes as $node) {
            $sorter->addNode($node->getName(), $node);
        }

        $sorter->addDependency('1', '2');
        $sorter->addDependency('2', '3');
        $sorter->addDependency('3', '4');
        $sorter->addDependency('4', '2');
        $sorter->addDependency('2', '5');

        $sortedList    = self::nodeNames($sorter->sort());
        $node1Position = array_search('1', $sortedList);
        $node2Position = array_search('2', $sortedList);
        $node3Position = array_search('3', $sortedList);
        $node4Position = array_search('4', $sortedList);
        $node5Position = array_search('5', $sortedList);

        self::assertTrue($node5Position < $node2Position, '5 should come before 2');
        self::assertTrue($node5Position < $node3Position, '5 should come before 3');
        self::assertTrue($node5Position < $node4Position, '5 should come before 4');
        self::assertTrue($node1Position > $node5Position, '1 should come after 2');
        self::assertTrue($node1Position > $node3Position, '1 should come after 3');
        self::assertTrue($node1Position > $node4Position, '1 should come after 4');

        // these a cyclic and sort order is based on the order of the nodes
        self::assertContains('2', $sortedList);
        self::assertContains('3', $sortedList);
        self::assertContains('4', $sortedList);
    }

    public function testFailureSortCyclicDependency(): void
    {
        $sorter = new TopologicalSorter(false);

        $node1 = new ClassMetadata('1');
        $node2 = new ClassMetadata('2');
        $node3 = new ClassMetadata('3');

        $sorter->addNode('1', $node1);
        $sorter->addNode('2', $node2);
        $sorter->addNode('3', $node3);

        $sorter->addDependency('1', '2');
        $sorter->addDependency('2', '3');
        $sorter->addDependency('3', '1');

        $this->expectException(CircularReferenceException::class);

        $sorter->sort();
    }

    public function testNoFailureOnSelfReferencingDependency(): void
    {
        $sorter = new TopologicalSorter();

        $node1 = new ClassMetadata('1');
        $node2 = new ClassMetadata('2');
        $node3 = new ClassMetadata('3');
        $node4 = new ClassMetadata('4');
        $node5 = new ClassMetadata('5');

        $sorter->addNode('1', $node1);
        $sorter->addNode('2', $node2);
        $sorter->addNode('3', $node3);
        $sorter->addNode('4', $node4);
        $sorter->addNode('5', $node5);

        $sorter->addDependency('1', '2');
        $sorter->addDependency('1', '1');
        $sorter->addDependency('2', '3');
        $sorter->addDependency('3', '4');
        $sorter->addDependency('5', '1');

        $sortedList  = $sorter->sort();
        $correctList = [$node4, $node3, $node2, $node1, $node5];

        self::assertSame($correctList, $sortedList);
    }

    public function testFailureSortMissingDependency(): void
    {
        $sorter = new TopologicalSorter();

        $node1 = new ClassMetadata('1');

        $sorter->addNode('1', $node1);

        $sorter->addDependency('1', '2');

        $this->expectException(RuntimeException::class);

        $sorter->sort();
    }
}
