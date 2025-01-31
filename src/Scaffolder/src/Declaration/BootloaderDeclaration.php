<?php

declare(strict_types=1);

namespace Spiral\Scaffolder\Declaration;

use Nette\PhpGenerator\Literal;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\BootloadManager\Methods;
use Spiral\Bootloader\DomainBootloader;
use Spiral\Core\CoreInterface;
use Spiral\Scaffolder\Config\ScaffolderConfig;

class BootloaderDeclaration extends AbstractDeclaration
{
    public const TYPE = 'bootloader';

    public function __construct(
        ScaffolderConfig $config,
        string $name,
        ?string $comment = null,
        ?string $namespace = null,
        private readonly bool $isDomain = false,
    ) {
        parent::__construct($config, $name, $comment, $namespace);
    }

    /**
     * Declare constants and boot method.
     */
    public function declare(): void
    {
        $extends = $this->isDomain ? DomainBootloader::class : Bootloader::class;

        $this->namespace->addUse($extends);
        $this->class->setExtends($extends);

        $this->class->setFinal();

        $this->class->addConstant('BINDINGS', [])->setProtected();
        $this->class->addConstant('SINGLETONS', [])->setProtected();
        $this->class->addConstant('DEPENDENCIES', [])->setProtected();

        if ($this->isDomain) {
            $this->class->addConstant('INTERCEPTORS', [])->setProtected();
            $this->namespace->addUse(CoreInterface::class);
            $this->class->getConstant('SINGLETONS')->setValue([
                new Literal('CoreInterface::class => [self::class, \'domainCore\']'),
            ]);
        }

        $this->class->addMethod(Methods::INIT->value)->setReturnType('void');
        $this->class->addMethod(Methods::BOOT->value)->setReturnType('void');
    }
}
