<?php

namespace Espo\Modules\PrtgIntegration;

use Espo\Core\Hook\Utils\Metadata;
use Espo\Core\InjectableFactory;

class Extension
{
    public function __construct(private InjectableFactory $injectableFactory) {}

    public function afterInstall(): void
    {
        // clear cache to register metadata
        $this->clearCache();
    }

    public function afterUninstall(): void
    {
        $this->clearCache();
    }

    public function afterUpgrade(): void
    {
        $this->clearCache();
    }

    private function clearCache(): void
    {
        /** @var Metadata $metadata */
        $metadata = $this->injectableFactory->create(Metadata::class);
        $metadata->clearCache();
    }
}
