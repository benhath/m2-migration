<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\DesignerGiftwrap\Model\Source\Color;
use Ben\DesignerGiftwrap\Model\Source\Font;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The admin tool config names its source classes; they moved here from Ben_Giftwrap
 */
class RenameSourceClasses implements DataPatchInterface
{
    private const array RENAMES
        = [
            'Ben\\Giftwrap\\Model\\Designer\\Source\\Color' => Color::class,
            'Ben\\Giftwrap\\Model\\Designer\\Source\\Font' => Font::class,
        ];

    private const string TABLE = 'ben_designer_product_tool_option';

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

        foreach (self::RENAMES as $old => $new) {
            $connection->update($table, ['value' => $new], ['value = ?' => $old]);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\DesignerGiftwrap\Setup\Patch\Data\RenameSourceClasses'];
    }
}
