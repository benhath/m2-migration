<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolOptionInterface;
use Ben\Designer\Api\Data\ToolInterface;
use Ben\Designer\Api\Data\ToolOptionInterface;
use Ben\Designer\Model\ProductToolOption;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ResourceModel\Tool\CollectionFactory as ToolCollectionFactory;
use Ben\Designer\Model\ResourceModel\ToolOption as ToolOptionResource;
use Ben\Designer\Model\ResourceModel\ToolOption\CollectionFactory as ToolOptionCollectionFactory;
use Ben\Designer\Model\ToolOption;
use Ben\Migration\Model\Gate;
use Exception;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the GiftwrapSize tool's freeShippingThreshold option and the value every product carried for it,
 * carrying the number itself over to the shop's one threshold first.
 *
 * The threshold is the shop's, not the product's: it comes from Magento's free shipping carrier now and
 * reaches the designer in the config payload under shipping.freeShippingThreshold, added by Ben_Shipping's
 * designer config plugin. Every product carried the same number, so the most common one is
 * written to the carrier before the rows go - unless the carrier already holds a number of its own, which is
 * the admin's later decision and outranks a value being retired. The carrier's on/off flag is left alone.
 */
class RemoveGiftwrapSizeFreeShippingThresholdOption implements DataPatchInterface
{
    // The one threshold the shop has now
    private const string CONFIG_XML_PATH_FREE_SHIPPING_SUBTOTAL = 'carriers/freeshipping/free_shipping_subtotal';

    // The option name held on the size tool until the threshold became a single shop-wide setting
    private const string OPTION_NAME = 'freeShippingThreshold';

    private const string TOOL_COMPONENT = 'GiftwrapSize';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly ProductToolOptionCollectionFactory $productToolOptionCollectionFactory,
        private readonly ToolCollectionFactory $toolCollectionFactory,
        private readonly ToolOptionCollectionFactory $toolOptionCollectionFactory,
        private readonly ToolOptionResource $toolOptionResource,
        private readonly WriterInterface $configWriter,
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

        $toolIds = $this->getSizeToolIds();

        if ($toolIds) {
            $toolOptions = $this->getToolOptions($toolIds);
            $toolOptionIds = array_keys($toolOptions);

            if ($toolOptionIds) {
                $this->migrateThreshold($toolOptionIds);
                $this->removeProductToolOptions($toolOptionIds);
                $this->removeToolOptions($toolOptions);
            }
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\DesignerGiftwrap\Setup\Patch\Data\RemoveGiftwrapSizeFreeShippingThresholdOption'];
    }

    /**
     * @return ProductToolOption[]
     */
    private function getProductToolOptions(array $toolOptionIds): array
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::TOOL_OPTION_ID, ['in' => $toolOptionIds]);

        return $productToolOptionCollection->getItems();
    }

    /**
     * The value explicitly saved at the default scope, ignoring what config.xml declares; null when there is
     * no row of its own
     */
    private function getSavedSubtotal(): ?string
    {
        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', self::CONFIG_XML_PATH_FREE_SHIPPING_SUBTOTAL);
        $collection->addFieldToFilter('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $collection->addFieldToFilter('scope_id', 0);
        $collection->setPageSize(1);

        $configData = $collection->getFirstItem();

        return $configData->getId() ? (string)$configData->getValue() : null;
    }

    /**
     * A store may run more than one tool on the size component, so every one of them is cleaned
     */
    private function getSizeToolIds(): array
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, self::TOOL_COMPONENT);

        return array_map('intval', $toolCollection->getAllIds());
    }

    /**
     * @return ToolOption[] keyed by tool option id
     */
    private function getToolOptions(array $toolIds): array
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::TOOL_ID, ['in' => $toolIds]);
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::NAME, self::OPTION_NAME);

        return $toolOptionCollection->getItems();
    }

    /**
     * Writes the number the products carried to the carrier, so a shop that promised free delivery over it
     * keeps promising it. The most common value wins where products disagree; a carrier that already holds
     * one is left as it is
     */
    private function migrateThreshold(array $toolOptionIds): void
    {
        $subtotal = $this->getSavedSubtotal();

        if ($subtotal !== null) {
            return;
        }

        $values = [];

        foreach ($this->getProductToolOptions($toolOptionIds) as $productToolOption) {
            $value = trim((string)$productToolOption->getValue());

            if ($value !== '' && (float)$value > 0) {
                $values[] = $value;
            }
        }

        if (!$values) {
            return;
        }

        $counts = array_count_values($values);
        arsort($counts);

        $this->configWriter->save(
            self::CONFIG_XML_PATH_FREE_SHIPPING_SUBTOTAL,
            (string)array_key_first($counts),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0,
        );
    }

    private function removeProductToolOptions(array $toolOptionIds): void
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::TOOL_OPTION_ID, ['in' => $toolOptionIds]);
        $productToolOptionCollection->walk('delete');
    }

    /**
     * @param ToolOption[] $toolOptions
     * @throws Exception
     */
    private function removeToolOptions(array $toolOptions): void
    {
        foreach ($toolOptions as $toolOption) {
            $this->toolOptionResource->delete($toolOption);
        }
    }
}
