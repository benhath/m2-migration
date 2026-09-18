<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Model\Upload\FormatPool;
use Ben\Designer\Api\Data\ToolOptionInterface;
use Ben\Designer\Model\ProductToolOption;
use Ben\Designer\Model\ResourceModel\ProductToolOption as ProductToolOptionResource;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ResourceModel\ToolOption as ToolOptionResource;
use Ben\Designer\Model\ResourceModel\ToolOption\CollectionFactory as ToolOptionCollectionFactory;
use Ben\Designer\Model\ToolOption;
use Ben\DesignerGiftwrap\Setup\Patch\Data\InstallGiftwrapDesigner;
use Ben\Migration\Model\Gate;
use Ben\Utils\Model\JsonValidator;
use Exception;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The Gallery tool's file types become a choice from the format registry rather than typed-in media types
 *
 * The option held rows an admin wrote by hand - image/heic, application/x-pdf - which nothing checked and which
 * said nothing about whether the server could read them. It is a multiselect of the registry's formats now, so
 * the saved rows have to become format names or the form would offer a list with nothing selected and lose what
 * was there on the next save. A media type the registry does not recognise is dropped and the option is left
 * empty, which falls back to the upload profile's own ceiling: the same files, chosen by the shop rather than
 * by a product nobody has looked at in two years.
 */
class ConvertGalleryFileTypesToFormats implements DataPatchInterface
{
    private const string OPTION_NAME = 'allowedUploadFileType';

    private const string OPTION_SCHEMA = 'multiselect:Ben\Asset\Model\Source\UploadFormat';

    public function __construct(
        private readonly FormatPool $formatPool,
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

    /**
     * The giftwrap install writes this option in the row shape it had before, so it has to have written it before
     * this converts it; otherwise the option is created dead on a live giftwrap database and the admin form shows
     * an empty multiselect that loses the value on the next save
     */
    public static function getDependencies(): array
    {
        return [InstallGiftwrapDesigner::class];
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

        $toolOptionIds = $this->convertSchemas();

        if ($toolOptionIds) {
            $this->convertValues($toolOptionIds);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * The option's schema on every tool that has it, and the ids of those options so only their values are read
     *
     * @return int[]
     *
     * @throws Exception
     */
    private function convertSchemas(): array
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::NAME, self::OPTION_NAME);

        $toolOptionIds = [];

        /** @var ToolOption $toolOption */
        foreach ($toolOptionCollection->getItems() as $toolOption) {
            $toolOptionIds[] = (int)$toolOption->getId();

            if ((string)$toolOption->getSchema() === self::OPTION_SCHEMA) {
                continue;
            }

            $toolOption->setSchema(self::OPTION_SCHEMA);
            $this->toolOptionResource->save($toolOption);
        }

        return $toolOptionIds;
    }

    /**
     * @param int[] $toolOptionIds
     *
     * @throws Exception
     */
    private function convertValues(array $toolOptionIds): void
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter('tool_option_id', ['in' => $toolOptionIds]);

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

            $names = $this->getFormatNames($rows);

            if ($names === $rows) {
                continue;
            }

            $productToolOption->setValue($this->json->serialize($names));
            $this->productToolOptionResource->save($productToolOption);
        }
    }

    /**
     * The format each saved row names, with anything the registry does not recognise left out
     *
     * @return string[]
     */
    private function getFormatNames(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            $name = is_array($row)
                ? $this->formatPool->getByMime($row['mime'] ?? null)?->getName()
                : trim((string)$row);

            if ($name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
