<?php

declare(strict_types=1);

namespace WordPress\Reprint\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class DowngradeClassConstantVisibilityRector extends AbstractRector
{
    private const VISIBILITY_FLAGS = Class_::MODIFIER_PUBLIC
        | Class_::MODIFIER_PROTECTED
        | Class_::MODIFIER_PRIVATE;

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassConst::class];
    }

    /**
     * @param ClassConst $node
     */
    public function refactor(Node $node): ?Node
    {
        if (($node->flags & self::VISIBILITY_FLAGS) === 0) {
            return null;
        }

        $node->flags &= ~self::VISIBILITY_FLAGS;

        return $node;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove class-constant visibility which PHP 5.6 cannot parse.',
            [new CodeSample('private const TOKEN = 1;', 'const TOKEN = 1;')]
        );
    }
}
