<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The AI tool draws pets and elves, but also banners and posters, and its one title is read on all of them: a
 * banner headed "Your Portrait" asks for the wrong thing. The title now says artwork, which every preset is.
 * A site that has already renamed it keeps its own words
 */
class RenameAiPortraitToolTitle implements DataPatchInterface
{
    private const string COMPONENT = 'AiPortrait';

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
        if (!$this->gate->hasTable('ben_designer_tool')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $this->moduleDataSetup->getConnection()->update(
            $this->moduleDataSetup->getTable('ben_designer_tool'),
            [
                'name' => 'AI Artwork',
                'description' => 'A photograph drawn as artwork, from the answers the customer gives.',
                'title' => 'Your Artwork',
            ],
            ['component = ?' => self::COMPONENT, 'title = ?' => 'Your Portrait']
        );

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }
}
