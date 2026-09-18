<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Ben\Migration\Model\ToolOptions;
use Exception;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the tool options that are the shop's setting rather than a product's.
 *
 * The endpoints were the same URLs on every product and had to be retyped whenever a site changed domain or
 * turned https on; they are named once in Stores > Configuration > Designer > Endpoints now and read through
 * Ben\Designer\Model\Endpoints, so nothing is carried over for them. The older Quality tool's thresholds and
 * wording are gone with the tool itself, which the points scoring in Ben\Designer\Model\Quality\Scorer replaced,
 * so nothing is carried over for those either. Everything a product genuinely differs on - the Preview's notice
 * and styles, the Position flags, the Create wording - is left alone.
 */
class RemoveGlobalDesignerToolOptions implements DataPatchInterface
{
    /**
     * The option names that are no longer a product's to set, by the tool component that carried them. Keyed
     * by component because a name such as "endpoint" is only global on the tool it belongs to
     */
    private const array OPTION_NAMES_BY_COMPONENT = [
        'Create' => ['endpoint'],
        'Position' => ['imageGenerateEndpoint'],
        'Preview' => ['imageGenerateEndpoint'],
        'Purchase' => ['addToCartEndpoint', 'cartUrl', 'priceFetchEndpoint'],
        'Quality' => [
            'fileSizeBadMb',
            'imageBadMbPerM2',
            'imageGoodMbPerM2',
            'qualityFair',
            'qualityOk',
            'qualityWeak',
        ],
    ];

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ToolOptions $toolOptions,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @throws Exception
     */
    public function apply(): void
    {
        if (!$this->gate->hasTable('ben_designer_tool_option')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        foreach (self::OPTION_NAMES_BY_COMPONENT as $component => $optionNames) {
            $this->toolOptions->remove($this->toolOptions->find($component, $optionNames));
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\RemoveGlobalDesignerToolOptions'];
    }
}
