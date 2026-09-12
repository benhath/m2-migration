<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Model\ProductToolOption;
use Ben\Designer\Model\ResourceModel\ProductToolOption as ProductToolOptionResource;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ResourceModel\ToolOption as ToolOptionResource;
use Ben\Designer\Model\ResourceModel\ToolOption\CollectionFactory as ToolOptionCollectionFactory;
use Ben\Designer\Model\ToolOption;
use Ben\Migration\Model\Gate;
use Ben\Utils\Model\JsonValidator;
use Exception;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Tooltips only belong on a tool's own heading (AddToolTooltipOptions), never on an individual option row -
 * a frame finish, a mount size, a roll length. Those row-level tooltipTitle/tooltipDescription fields were
 * added by hand through the admin rather than by a patch, so this strips them from every dynamic-row option's
 * schema and from the rows already saved against a product, leaving everything else in the row alone.
 */
class RemoveToolOptionRowTooltips implements DataPatchInterface
{
    private const array ROW_TOOLTIP_KEYS = ['tooltipTitle', 'tooltipDescription'];

    public function __construct(
        private readonly Gate $gate,
        private readonly Json $json,
        private readonly JsonValidator $jsonValidator,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductToolOptionCollectionFactory $productToolOptionCollectionFactory,
        private readonly ProductToolOptionResource $productToolOptionResource,
        private readonly ToolOptionCollectionFactory $toolOptionCollectionFactory,
        private readonly ToolOptionResource $toolOptionResource,
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

        $this->cleanSchemas();
        $this->cleanRowValues();

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\RemoveToolOptionRowTooltips'];
    }

    /**
     * Strips the two row-level keys out of every configured row already saved against a product
     *
     * @throws Exception
     */
    private function cleanRowValues(): void
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();

        /** @var ProductToolOption $productToolOption */
        foreach ($productToolOptionCollection->getItems() as $productToolOption) {
            $value = trim((string)$productToolOption->getValue());

            if (!str_starts_with($value, '[') || !$this->jsonValidator->isValid($value)) {
                continue;
            }

            $rows = $this->json->unserialize($value);

            if (!is_array($rows)) {
                continue;
            }

            $cleaned = array_map(
                static fn (mixed $row) => is_array($row) ? array_diff_key($row, array_flip(self::ROW_TOOLTIP_KEYS)) : $row,
                $rows
            );

            if ($cleaned === $rows) {
                continue;
            }

            $productToolOption->setValue($this->json->serialize($cleaned));
            $this->productToolOptionResource->save($productToolOption);
        }
    }

    /**
     * Strips the two row-level keys out of every dynamic-row option's JSON schema; a scalar schema (a tool's
     * own tooltipTitle/tooltipDescription options included) is not JSON and is left untouched
     *
     * @throws Exception
     */
    private function cleanSchemas(): void
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();

        /** @var ToolOption $toolOption */
        foreach ($toolOptionCollection->getItems() as $toolOption) {
            $schema = (string)$toolOption->getSchema();

            if (!str_starts_with($schema, '{') || !$this->jsonValidator->isValid($schema)) {
                continue;
            }

            $decoded = $this->json->unserialize($schema);
            $cleaned = array_diff_key($decoded, array_flip(self::ROW_TOOLTIP_KEYS));

            if ($cleaned === $decoded) {
                continue;
            }

            $toolOption->setSchema($this->json->serialize($cleaned));
            $this->toolOptionResource->save($toolOption);
        }
    }
}
