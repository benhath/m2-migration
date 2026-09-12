<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Zend_Db_Expr;

/**
 * Tool option schemas name their value type as "string"; the older rows still say "varchar", a database word that
 * leaked into the config. This rewrites every schema that carries it, and does nothing on a site already clean.
 */
class RenameVarcharSchemaTypeToString implements DataPatchInterface
{
    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable('ben_designer_tool_option')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('ben_designer_tool_option');

        $connection->update(
            $table,
            ['schema' => new Zend_Db_Expr("REPLACE(`schema`, 'varchar', 'string')")],
            ['`schema` LIKE ?' => '%varchar%']
        );

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\RenameVarcharSchemaTypeToString'];
    }
}
