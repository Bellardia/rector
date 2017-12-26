<?php declare(strict_types=1);

namespace Rector\Rector\Dynamic;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use Rector\NodeAnalyzer\ClassConstAnalyzer;
use Rector\NodeChanger\ClassConstFetchNameChanger;
use Rector\Rector\AbstractRector;

final class ClassConstantReplacerRector extends AbstractRector
{
    /**
     * class => [
     *      OLD_CONSTANT => NEW_CONSTANT
     * ]
     *
     * @var string[]
     */
    private $oldToNewConstantsByClass = [];

    /**
     * @var ClassConstAnalyzer
     */
    private $classConstAnalyzer;

    /**
     * @var ClassConstFetchNameChanger
     */
    private $classConstFetchNameChanger;

    /**
     * @var string|null
     */
    private $activeType;

    /**
     * @param string[] $oldToNewConstantsByClass
     */
    public function __construct(
        array $oldToNewConstantsByClass,
        ClassConstAnalyzer $classConstAnalyzer,
        ClassConstFetchNameChanger $classConstFetchNameChanger
    ) {
        $this->oldToNewConstantsByClass = $oldToNewConstantsByClass;
        $this->classConstAnalyzer = $classConstAnalyzer;
        $this->classConstFetchNameChanger = $classConstFetchNameChanger;
    }

    public function isCandidate(Node $node): bool
    {
        $this->activeType = null;

        foreach ($this->oldToNewConstantsByClass as $type => $oldToNewConstants) {
            $matchedType = $this->classConstAnalyzer->matchTypes($node, $this->getTypes());
            if ($matchedType) {
                $this->activeType = $matchedType;

                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassConstFetch $classConstFetchNode
     */
    public function refactor(Node $classConstFetchNode): ?Node
    {
        $configuration = $this->oldToNewConstantsByClass[$this->activeType];

        /** @var Identifier $identifierNode */
        $identifierNode = $classConstFetchNode->name;

        $constantName = $identifierNode->toString();

        $newConstantName = $configuration[$constantName];

        if (! isset($newConstantName)) {
            return $classConstFetchNode;
        }

        $this->classConstFetchNameChanger->renameNode($classConstFetchNode, $newConstantName);

        return $classConstFetchNode;
    }

    /**
     * @return string[]
     */
    private function getTypes(): array
    {
        return array_keys($this->oldToNewConstantsByClass);
    }
}
