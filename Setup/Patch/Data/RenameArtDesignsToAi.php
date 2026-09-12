<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Giftwrap\Api\Data\DesignInterface;
use Ben\Giftwrap\Ui\Component\Form\Type\Options;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The prompt-made design type was briefly called art during 3.0 development, which clashed with the drawn art
 * prints of Ben_DesignerArt; it is ai now, after the module that makes it. Only rows still carrying the old
 * word are touched, so a live installation that never had it does nothing here
 */
class RenameArtDesignsToAi implements DataPatchInterface
{
    private const string OLD_TYPE = 'art';

    private const string TABLE = 'ben_giftwrap_design';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [RenameDesignTypes::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $this->moduleDataSetup->getConnection()->update(
            $this->moduleDataSetup->getTable(self::TABLE),
            [DesignInterface::TYPE => Options::TYPE_AI],
            [DesignInterface::TYPE . ' = ?' => self::OLD_TYPE]
        );

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Giftwrap\Setup\Patch\Data\RenameArtDesignsToAi'];
    }
}
