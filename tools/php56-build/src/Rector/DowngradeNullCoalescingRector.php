<?php

declare(strict_types=1);

namespace WordPress\Reprint\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use Rector\Exception\ShouldNotHappenException;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class DowngradeNullCoalescingRector extends AbstractRector
{
    private const TEMPORARY_VARIABLE_PREFIX = '__reprint_php56_coalesce_';

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Coalesce::class];
    }

    /**
     * @param Coalesce $node
     */
    public function refactor(Node $node): Node
    {
        if ($this->canRepeatInsideIsset($node->left)) {
            $replacement = new Ternary(
                new Isset_([$this->cloneExpression($node->left)]),
                $this->cloneExpression($node->left),
                $node->right
            );
            $this->mirrorComments($replacement, $node);

            return $replacement;
        }

        $temporary_variable_name = $this->temporaryVariableName($node);

        if ($node->left instanceof FuncCall || $node->left instanceof Coalesce) {
            $replacement = new Ternary(
                new NotIdentical(
                    new Assign(new Variable($temporary_variable_name), $node->left),
                    new ConstFetch(new Name('null'))
                ),
                new Variable($temporary_variable_name),
                $node->right
            );
            $this->mirrorComments($replacement, $node);

            return $replacement;
        }

        if (
            $node->left instanceof ArrayDimFetch
            && !$this->canRepeatInsideIsset($node->left->var)
            && $this->isRepeatableDimension($node->left->dim)
        ) {
            // PHP 5.6 cannot parse isset(($temporary = make_array())['key']),
            // so assign before fetching the key through the plain variable.
            $condition_fetch = new ArrayDimFetch(
                new Variable($temporary_variable_name),
                $this->cloneNullableExpression($node->left->dim)
            );
            $value_fetch = new ArrayDimFetch(
                new Variable($temporary_variable_name),
                $this->cloneNullableExpression($node->left->dim)
            );
            $replacement = new Ternary(
                new BooleanAnd(
                    new NotIdentical(
                        new Assign(new Variable($temporary_variable_name), $node->left->var),
                        new ConstFetch(new Name('null'))
                    ),
                    new Isset_([$condition_fetch])
                ),
                $value_fetch,
                $node->right
            );
            $this->mirrorComments($replacement, $node);

            return $replacement;
        }

        throw new ShouldNotHappenException(sprintf(
            'Cannot safely downgrade the null-coalescing expression on line %d in %s.',
            $node->getStartLine(),
            $this->getFile()->getFilePath()
        ));
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace null coalescing with a PHP 5.6 conditional without evaluating an effectful expression twice.',
            [new CodeSample('$value = $items["key"] ?? "default";', '$value = isset($items["key"]) ? $items["key"] : "default";')]
        );
    }

    private function canRepeatInsideIsset(Expr $expression): bool
    {
        if ($expression instanceof Variable) {
            return is_string($expression->name);
        }

        if ($expression instanceof ArrayDimFetch) {
            return $this->canRepeatInsideIsset($expression->var)
                && $this->isRepeatableDimension($expression->dim);
        }

        if ($expression instanceof PropertyFetch) {
            return $this->canRepeatInsideIsset($expression->var)
                && $expression->name instanceof Node\Identifier;
        }

        return false;
    }

    private function isRepeatableDimension(?Expr $expression): bool
    {
        return $expression instanceof Scalar
            || $expression instanceof ConstFetch
            || $expression instanceof ClassConstFetch
            || ($expression instanceof Variable && is_string($expression->name));
    }

    private function temporaryVariableName(Coalesce $node): string
    {
        return self::TEMPORARY_VARIABLE_PREFIX
            . $node->getStartLine()
            . '_'
            . max(0, $node->getStartFilePos());
    }

    private function cloneExpression(Expr $expression): Expr
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        /** @var Expr $clone */
        $clone = $traverser->traverse([$expression])[0];

        return $clone;
    }

    private function cloneNullableExpression(?Expr $expression): ?Expr
    {
        return $expression === null ? null : $this->cloneExpression($expression);
    }
}
