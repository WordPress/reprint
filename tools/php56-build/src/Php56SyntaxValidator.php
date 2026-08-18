<?php

declare(strict_types=1);

namespace WordPress\Reprint\Build;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

final class Php56SyntaxValidator
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param string[] $paths Files and directories containing generated PHP.
     */
    public function assertPaths(array $paths): void
    {
        foreach ($this->phpFiles($paths) as $file) {
            $this->assertFile($file);
        }
    }

    public function assertFile(string $file): void
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new RuntimeException(sprintf('Could not read generated PHP file %s.', $file));
        }

        try {
            $statements = $this->parser->parse($code);
        } catch (\Throwable $throwable) {
            throw new RuntimeException(sprintf(
                'Generated PHP could not be parsed in %s: %s',
                $file,
                $throwable->getMessage()
            ), 0, $throwable);
        }

        if ($statements === null) {
            return;
        }

        $name_resolver = new NodeTraverser();
        $name_resolver->addVisitor(new NameResolver());
        $statements = $name_resolver->traverse($statements);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($file) extends NodeVisitorAbstract {
            private string $file;

            public function __construct(string $file)
            {
                $this->file = $file;
            }

            public function enterNode(Node $node): ?Node
            {
                $reason = $this->unsupportedReason($node);
                if ($reason !== null) {
                    throw new RuntimeException(sprintf(
                        'Generated PHP still contains %s in %s on line %d.',
                        $reason,
                        $this->file,
                        $node->getStartLine()
                    ));
                }

                return null;
            }

            private function unsupportedReason(Node $node): ?string
            {
                if ($node instanceof FunctionLike && $node->getReturnType() !== null) {
                    return 'a return type declaration';
                }
                if ($node instanceof Param && $node->flags !== 0) {
                    return 'a promoted parameter';
                }
                if (
                    $node instanceof Param
                    && $node->type !== null
                    && !$this->isPhp56ParameterType($node->type)
                ) {
                    return 'a parameter type which PHP 5.6 cannot parse';
                }
                if ($node instanceof Property && $node->type !== null) {
                    return 'a typed property';
                }
                if ($node instanceof ClassConst && $node->flags !== 0) {
                    return 'a class-constant modifier';
                }
                if ($node instanceof Coalesce || $node instanceof AssignCoalesce) {
                    return 'a null-coalescing operator';
                }
                if ($node instanceof Spaceship) {
                    return 'a spaceship operator';
                }
                if ($node instanceof YieldFrom) {
                    return 'a yield-from expression';
                }
                if ($node instanceof ArrowFunction) {
                    return 'an arrow function';
                }
                if ($node instanceof GroupUse) {
                    return 'a grouped use declaration';
                }
                if ($node instanceof Catch_ && count($node->types) !== 1) {
                    return 'a multi-catch declaration';
                }
                if ($node instanceof New_ && $node->class instanceof Class_) {
                    return 'an anonymous class';
                }
                if ($node instanceof List_) {
                    if ($node->getAttribute('kind') === List_::KIND_ARRAY) {
                        return 'short array destructuring';
                    }
                    foreach ($node->items as $item) {
                        if ($item !== null && $item->key !== null) {
                            return 'keyed array destructuring';
                        }
                    }
                }
                if ($node instanceof Arg && $node->name !== null) {
                    return 'a named argument';
                }
                if (property_exists($node, 'attrGroups') && $node->attrGroups !== []) {
                    return 'an attribute';
                }

                return null;
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
        });
        $traverser->traverse($statements);
    }

    /**
     * @param string[] $paths Files and directories to inspect.
     * @return string[]
     */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                if (substr($path, -4) === '.php') {
                    $files[] = $path;
                }
                continue;
            }
            if (!is_dir($path)) {
                throw new RuntimeException(sprintf('Generated PHP path does not exist: %s.', $path));
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException(sprintf('Generated PHP path contains a symbolic link: %s.', $file->getPathname()));
                }
                if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }
}
