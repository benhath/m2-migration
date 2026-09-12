<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Giftwrap\Api\Data\DesignInterface;
use Ben\Giftwrap\Ui\Component\Form\Type\Options;
use Ben\Migration\Model\Gate;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Marks the art designs already on the shop as personal and gives each one a key.
 *
 * An art design was made from one customer's description and used to come back to anyone who asked for its id.
 * From this release it is fetched by its key instead, so every row made before the column existed needs one.
 * Only rows still without a key are touched, so running this again keys nothing a second time and a catalogue
 * design, which is nobody's in particular, is left as it is
 */
class KeyPersonalDesigns implements DataPatchInterface
{
    private const string TABLE = 'ben_giftwrap_design';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Random $random,
    ) {
    }

    public static function getDependencies(): array
    {
        return [RenameDesignTypes::class];
    }

    /**
     * @throws LocalizedException
     */
    public function apply(): void
    {
        if (!$this->gate->hasColumn(self::TABLE, 'key')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        $select = $connection->select()
            ->from($table, DesignInterface::DESIGN_ID)
            ->where(DesignInterface::TYPE . ' = ?', Options::TYPE_AI)
            ->where($connection->quoteIdentifier(DesignInterface::KEY) . ' IS NULL');

        foreach ($connection->fetchCol($select) as $designId) {
            $connection->update(
                $table,
                [DesignInterface::IS_PERSONAL => 1, DesignInterface::KEY => $this->random->getUniqueHash()],
                [DesignInterface::DESIGN_ID . ' = ?' => $designId]
            );
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Giftwrap\Setup\Patch\Data\KeyPersonalDesigns'];
    }
}
