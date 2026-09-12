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

    /**
     * Whether any scope has saved a row at the path. A default that only exists in config.xml is not a saved
     * row and there is nothing to migrate from it
     */
    public function hasConfig(string $path): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('core_config_data');

        $found = (bool)$connection->fetchOne(
            $connection->select()
                ->from($table, 'config_id')
                ->where('path = ?', $path)
                ->limit(1)
        );

        if (!$found) {
            $this->skip(sprintf('no site has saved config %s', $path));
        }

        return $found;
    }

    public function hasModule(string $name): bool
    {
        if ($this->moduleManager->isEnabled($name)) {
            return true;
        }

        $this->skip(sprintf('%s is not enabled on this site', $name));

        return false;
    }

    /**
     * Whether the catalogue holds the SKU, read straight off the entity table so a product in any state counts
     */
    public function hasProduct(string $sku): bool
    {
        if (!$this->hasTable('catalog_product_entity')) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');

        $found = (bool)$connection->fetchOne(
            $connection->select()
                ->from($table, 'entity_id')
                ->where('sku = ?', $sku)
                ->limit(1)
        );

        if (!$found) {
            $this->skip(sprintf('product %s is not in this catalogue', $sku));
        }

        return $found;
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
