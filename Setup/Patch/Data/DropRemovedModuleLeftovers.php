<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Clean\Model\LeftoverRemoval;
use Ben\Clean\Model\ModuleRemoval;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Drops the tables and setup_module rows left by extensions this shop no longer runs.
 *
 * Amazon, Dotdigital, Klarna, Vertex, Yotpo and a handful of Magento modules were taken out with composer, which
 * never runs an extension's own uninstall, so their tables and version rows have been carried through every
 * upgrade since. Ben_Clean works out which they are, conservatively: a table has to belong to a module with no
 * code left anywhere and be declared by no installed module. This is bin/magento config:remove-unused
 * --drop-leftovers, run once, and it is here rather than in a hand script because the migration module is what
 * carries the switchover and will meet the same leftovers on the other sites.
 *
 * Every table is written out as SQL under backups/work/removed-modules first, so nothing is actually lost, and a
 * table whose dump fails is left standing. Only the names and how many rows they held are logged, never what was
 * in them: these tables held customer names, addresses and order history.
 */
class DropRemovedModuleLeftovers implements DataPatchInterface
{
    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleRemoval $moduleRemoval,
    ) {
    }

    public static function getDependencies(): array
    {
        // The config purge reads the same removed modules to decide which saved settings are theirs, so it has to
        // have had its turn before their setup_module rows are gone
        return [PurgeRedundantConfig::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Clean')) {
            return;
        }

        foreach ($this->moduleRemoval->remove(false) as $removal) {
            $this->logger->info($this->getLine($removal));
        }
    }

    public function getAliases(): array
    {
        return [];
    }

    private function getLine(LeftoverRemoval $removal): string
    {
        if ($removal->isSkipped()) {
            return sprintf('Ben_Migration left %s %s alone: %s', $removal->getKind(), $removal->getName(), $removal->getSkippedReason());
        }

        if ($removal->getKind() === LeftoverRemoval::KIND_MODULE_ROW) {
            return sprintf('Ben_Migration removed the setup_module row for %s', $removal->getName());
        }

        return sprintf(
            'Ben_Migration dropped table %s (%s), %d row(s), saved to %s',
            $removal->getName(),
            $removal->getModules(),
            $removal->getRows(),
            $removal->getDumpFile(),
        );
    }
}
