<?php

declare (strict_types=1);
namespace Rector\Php55\Rector\String_;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Core\Contract\Rector\AllowEmptyConfigurableRectorInterface;
use Rector\Core\Rector\AbstractScopeAwareRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\Php55\NodeVisitor\ClassConstStringValueNodeVisitor;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use RectorPrefix202306\Webmozart\Assert\Assert;
/**
 * @changelog https://wiki.php.net/rfc/class_name_scalars https://github.com/symfony/symfony/blob/2.8/UPGRADE-2.8.md#form
 *
 * @see \Rector\Tests\Php55\Rector\String_\StringClassNameToClassConstantRector\StringClassNameToClassConstantRectorTest
 */
final class StringClassNameToClassConstantRector extends AbstractScopeAwareRector implements AllowEmptyConfigurableRectorInterface, MinPhpVersionInterface
{
    /**
     * @readonly
     * @var \PHPStan\Reflection\ReflectionProvider
     */
    private $reflectionProvider;
    /**
     * @var string
     */
    private const IS_UNDER_CLASS_CONST = 'is_under_class_const';
    /**
     * @var string[]
     */
    private $classesToSkip = [];
    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }
    public function getRuleDefinition() : RuleDefinition
    {
        return new RuleDefinition('Replace string class names by <class>::class constant', [new ConfiguredCodeSample(<<<'CODE_SAMPLE'
class AnotherClass
{
}

class SomeClass
{
    public function run()
    {
        return 'AnotherClass';
    }
}
CODE_SAMPLE
, <<<'CODE_SAMPLE'
class AnotherClass
{
}

class SomeClass
{
    public function run()
    {
        return \AnotherClass::class;
    }
}
CODE_SAMPLE
, ['ClassName', 'AnotherClassName'])]);
    }
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes() : array
    {
        return [String_::class, FuncCall::class, ClassConst::class];
    }
    /**
     * @param String_|FuncCall|ClassConst $node
     * @return \PhpParser\Node\Expr\BinaryOp\Concat|\PhpParser\Node\Expr\ClassConstFetch|null|int
     */
    public function refactorWithScope(Node $node, Scope $scope)
    {
        // allow class strings to be part of class const arrays, as probably on purpose
        if ($node instanceof ClassConst) {
            $this->fillIsUnderClassConstAttribute($node);
            return null;
        }
        // keep allowed string as condition
        if ($node instanceof FuncCall) {
            if ($this->isName($node, 'is_a')) {
                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }
            return null;
        }
        if ($node->getAttribute(self::IS_UNDER_CLASS_CONST) === \true) {
            return null;
        }
        $classLikeName = $node->value;
        // remove leading slash
        $classLikeName = \ltrim($classLikeName, '\\');
        if ($classLikeName === '') {
            return null;
        }
        if ($this->shouldSkip($classLikeName)) {
            return null;
        }
        $fullyQualified = new FullyQualified($classLikeName);
        $fullyQualifiedOrAliasName = new FullyQualified($scope->resolveName($fullyQualified));
        if ($classLikeName !== $node->value) {
            $preSlashCount = \strlen($node->value) - \strlen($classLikeName);
            $preSlash = \str_repeat('\\', $preSlashCount);
            $string = new String_($preSlash);
            return new Concat($string, new ClassConstFetch($fullyQualifiedOrAliasName, 'class'));
        }
        return new ClassConstFetch($fullyQualifiedOrAliasName, 'class');
    }
    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration) : void
    {
        Assert::allString($configuration);
        $this->classesToSkip = $configuration;
    }
    public function provideMinPhpVersion() : int
    {
        return PhpVersionFeature::CLASSNAME_CONSTANT;
    }
    private function fillIsUnderClassConstAttribute(ClassConst $classConst) : void
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new ClassConstStringValueNodeVisitor());
        $nodeTraverser->traverse([$classConst]);
    }
    private function shouldSkip(string $classLikeName) : bool
    {
        // skip short class names, mostly invalid use of strings
        if (\strpos($classLikeName, '\\') === \false) {
            return \true;
        }
        // possibly string
        if (\ctype_lower($classLikeName[0])) {
            return \true;
        }
        if (!$this->reflectionProvider->hasClass($classLikeName)) {
            return \true;
        }
        foreach ($this->classesToSkip as $classToSkip) {
            if ($this->nodeNameResolver->isStringName($classLikeName, $classToSkip)) {
                return \true;
            }
        }
        return \false;
    }
}
