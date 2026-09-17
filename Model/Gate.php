<?php
declare(strict_types=1);

namespace Ben\Migration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager;
use Psr\Log\LoggerInterface;

/**
 * What a migration asks before it touches anything.
 *
 * The three sites run different sets of modules, so a patch written for one of them meets tables, columns,
 * config rows and products that are simply not there on another. Every answer here is a plain look at the
 * database rather than a guess from a module list, because a module can be enabled on a site that never ran
 * its schema, and a missing answer is logged once by name so the upgrade log says what was skipped and why.
 *
 * Nothing here throws: a migration that cannot run is not a failed upgrade, it is a migration with nothing
 * to migrate.
 */
class Gate
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Manager $moduleManager,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Whether the column is on the table, the table itself having to be there first
     */
    public function hasColumn(string $table, string $column): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();

        if ($connection->tableColumnExists($this->resourceConnection->getTableName($table), $column)) {
            return true;
        }

        $this->skip(sprintf('column %s.%s is not on this site', $table, $column));

        return false;
    }

    public function hasModule(string $name): bool
    {
        if ($this->moduleManager->isEnabled($name)) {
            return true;
        }

        $this->skip(sprintf('%s is not enabled on this site', $name));

        return false;
    }

    public function hasTable(string $table): bool
    {
        $connection = $this->resourceConnection->getConnection();

        if ($connection->isTableExists($this->resourceConnection->getTableName($table))) {
            return true;
        }

        $this->skip(sprintf('table %s is not on this site', $table));

        return false;
    }

    /**
     * One line per skipped migration, so the upgrade log reads as a list of what was not needed here
     */
    private function skip(string $reason): void
    {
        $this->logger->info(sprintf('Ben_Migration skipped: %s', $reason));
    }
}
