<?php

declare(strict_types=1);

namespace WordPress\Reprint\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class DowngradeFunctionTypeDeclarationsRector extends AbstractRector
{
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Function_::class, ClassMethod::class, Closure::class];
    }

    /**
     * @param Function_|ClassMethod|Closure $node
     */
    public function refactor(Node $node): ?Node
    {
        $changed = false;

        if ($node->returnType !== null) {
            $node->returnType = null;
            $changed = true;
        }

        foreach ($node->params as $param) {
            if ($param->type === null || $this->isPhp56ParameterType($param->type)) {
                continue;
            }

            $param->type = null;
            $changed = true;
        }

        return $changed ? $node : null;
    }

    private function isPhp56ParameterType(Node $type): bool
    {
        if ($type instanceof Name) {
            $resolved_name = $type->getAttribute('resolvedName');
            $type_name = $resolved_name instanceof Name
                ? $resolved_name->toString()
                : $type->toString();

            return strtolower($type_name) !== 'throwable';
        }

        return $type instanceof Identifier
            && in_array(strtolower($type->name), ['array', 'callable'], true);
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove parameter and return type declarations which PHP 5.6 cannot parse.',
            [
                new CodeSample(
                    'function render(string $value): string { return $value; }',
                    'function render($value) { return $value; }'
                ),
            ]
        );
    }
}
