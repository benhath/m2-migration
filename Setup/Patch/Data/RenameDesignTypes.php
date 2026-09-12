<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Giftwrap\Api\Data\DesignInterface;
use Ben\Giftwrap\Ui\Component\Form\Type\Options;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Design types renamed for 3.0: tile became original, face became photo and generated became ai.
 *
 * Only the three old names are touched, so a row already carrying a new name, or one carrying something
 * nobody here knows about, is left exactly as it is. The empty string is included because the column became
 * NOT NULL in this release: the schema step runs first and turns a row that had no type at all into an empty
 * one, which belongs on the type an untyped design has always behaved as
 */
class RenameDesignTypes implements DataPatchInterface
{
    private const string TABLE = 'ben_giftwrap_design';

    private const array RENAMES = [
        '' => Options::TYPE_ORIGINAL,
        'tile' => Options::TYPE_ORIGINAL,
        'face' => Options::TYPE_PHOTO,
        'generated' => Options::TYPE_AI,
    ];

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
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        foreach (self::RENAMES as $from => $to) {
            $connection->update($table, [DesignInterface::TYPE => $to], [DesignInterface::TYPE . ' = ?' => $from]);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Giftwrap\Setup\Patch\Data\RenameDesignTypes'];
    }
}
