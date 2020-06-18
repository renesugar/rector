<?php

declare(strict_types=1);

namespace Rector\Autodiscovery\Rector\FileSystem;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeWithClassName;
use Rector\Autodiscovery\FileMover\FileMover;
use Rector\Autodiscovery\ValueObject\NodesWithFileDestinationValueObject;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\FileSystemRector\Rector\AbstractFileSystemRector;
use Rector\TypeDeclaration\TypeInferer\ReturnTypeInferer;
use Symplify\SmartFileSystem\SmartFileInfo;

/**
 * @sponsor Thanks https://spaceflow.io/ for sponsoring this rule - visit them on https://github.com/SpaceFlow-app
 *
 * Inspiration @see https://github.com/rectorphp/rector/pull/1865/files#diff-0d18e660cdb626958662641b491623f8
 *
 * @see \Rector\Autodiscovery\Tests\Rector\FileSystem\MoveInterfacesToContractNamespaceDirectoryRector\MoveInterfacesToContractNamespaceDirectoryRectorTest
 */
final class MoveInterfacesToContractNamespaceDirectoryRector extends AbstractFileSystemRector
{
    /**
     * @var FileMover
     */
    private $fileMover;

    /**
     * @var ReturnTypeInferer
     */
    private $returnTypeInferer;

    public function __construct(FileMover $fileMover, ReturnTypeInferer $returnTypeInferer)
    {
        $this->fileMover = $fileMover;
        $this->returnTypeInferer = $returnTypeInferer;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Move interface to "Contract" namespace', [new CodeSample(
<<<'PHP'
// file: app/Exception/Rule.php

namespace App\Exception;

interface Rule
{
}
PHP
            ,
            <<<'PHP'
// file: app/Contract/Rule.php

namespace App\Contract;

interface Rule
{
}
PHP
        )]);
    }

    public function refactor(SmartFileInfo $smartFileInfo): void
    {
        $nodes = $this->parseFileInfoToNodes($smartFileInfo);

        $this->processInterfacesToContract($smartFileInfo, $nodes);
    }

    /**
     * @param Node[] $nodes
     */
    private function processInterfacesToContract(SmartFileInfo $smartFileInfo, array $nodes): void
    {
        /** @var Interface_|null $interface */
        $interface = $this->betterNodeFinder->findFirstInstanceOf($nodes, Interface_::class);
        if ($interface === null) {
            return;
        }

        $oldInterfaceName = $this->getName($interface);
        if ($oldInterfaceName === null) {
            throw new ShouldNotHappenException();
        }

        if ($this->isNetteMagicGeneratedFactory($interface)) {
            return;
        }

        $nodesWithFileDestination = $this->fileMover->createMovedNodesAndFilePath($smartFileInfo, $nodes, 'Contract');

        // nothing to move
        if ($nodesWithFileDestination === null) {
            return;
        }

        $newInterfaceName = $this->resolveNewClassLikeName($nodesWithFileDestination);

        $this->removeFile($smartFileInfo);
        $this->addClassRename($oldInterfaceName, $newInterfaceName);

        $this->printNodesWithFileDestination($nodesWithFileDestination);
    }

    private function resolveNewClassLikeName(
        NodesWithFileDestinationValueObject $nodesWithFileDestinationValueObject
    ): string {
        /** @var ClassLike $classLike */
        $classLike = $this->betterNodeFinder->findFirstInstanceOf(
            $nodesWithFileDestinationValueObject->getNodes(),
            ClassLike::class
        );

        $classLikeName = $this->getName($classLike);
        if ($classLikeName === null) {
            throw new ShouldNotHappenException();
        }

        return $classLikeName;
    }

    /**
     * @see https://doc.nette.org/en/3.0/components#toc-components-with-dependencies
     */
    private function isNetteMagicGeneratedFactory(ClassLike $classLike): bool
    {
        foreach ($classLike->getMethods() as $classMethod) {
            $returnType = $this->returnTypeInferer->inferFunctionLike($classMethod);
            if (! $returnType instanceof TypeWithClassName) {
                continue;
            }

            if ($returnType->isSuperTypeOf(new ObjectType('Nette\Application\UI\Control'))) {
                return true;
            }

            if ($returnType->isSuperTypeOf(new ObjectType('Nette\Application\UI\Form'))) {
                return true;
            }
        }

        return false;
    }
}
