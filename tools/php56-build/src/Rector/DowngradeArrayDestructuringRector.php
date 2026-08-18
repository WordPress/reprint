<?php

declare(strict_types=1);

namespace WordPress\Reprint\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use Rector\Exception\ShouldNotHappenException;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class DowngradeArrayDestructuringRector extends AbstractRector
{
    private const TEMPORARY_VARIABLE_PREFIX = '__reprint_php56_destructure_';

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [List_::class, Expression::class];
    }

    /**
     * @param List_|Expression $node
     * @return Node|Node[]|null
     */
    public function refactor(Node $node)
    {
        if ($node instanceof List_) {
            return $this->downgradeUnkeyedShortDestructuring($node);
        }

        if (!$node->expr instanceof Assign || !$node->expr->var instanceof List_) {
            return null;
        }

        $list = $node->expr->var;
        if (!$this->hasKeys($list)) {
            return null;
        }

        $temporary_variable_name = self::TEMPORARY_VARIABLE_PREFIX
            . $node->getStartLine()
            . '_'
            . max(0, $node->getStartFilePos());
        $temporary_assignment = new Expression(
            new Assign(new Variable($temporary_variable_name), $node->expr->expr)
        );
        $this->mirrorComments($temporary_assignment, $node);
        $statements = [$temporary_assignment];

        foreach ($list->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->key === null || $item->byRef || $item->unpack) {
                throw new ShouldNotHappenException(sprintf(
                    'Cannot safely downgrade the array destructuring on line %d in %s.',
                    $node->getStartLine(),
                    $this->getFile()->getFilePath()
                ));
            }

            $assignment = new Expression(new Assign(
                $item->value,
                new ArrayDimFetch(
                    new Variable($temporary_variable_name),
                    $this->cloneExpression($item->key)
                )
            ));
            if ($item->getComments() !== []) {
                $assignment->setAttribute('comments', $item->getComments());
            }
            $statements[] = $assignment;
        }

        return $statements;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace PHP 7 array destructuring with PHP 5.6 list or assignment statements.',
            [new CodeSample('[$host, $port] = $address;', 'list($host, $port) = $address;')]
        );
    }

    private function downgradeUnkeyedShortDestructuring(List_ $list): ?List_
    {
        if ($list->getAttribute('kind') !== List_::KIND_ARRAY || $this->hasKeys($list)) {
            return null;
        }

        $replacement = new List_($list->items, ['kind' => List_::KIND_LIST]);
        $this->mirrorComments($replacement, $list);

        return $replacement;
    }

    private function hasKeys(List_ $list): bool
    {
        foreach ($list->items as $item) {
            if ($item !== null && $item->key !== null) {
                return true;
            }
        }

        return false;
    }

    private function cloneExpression(Expr $expression): Expr
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        /** @var Expr $clone */
        $clone = $traverser->traverse([$expression])[0];

        return $clone;
    }
}
