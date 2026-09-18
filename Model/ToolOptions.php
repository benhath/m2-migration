<?php
declare(strict_types=1);

namespace Ben\Migration\Model;

use Ben\Designer\Api\Data\ProductToolOptionInterface;
use Ben\Designer\Api\Data\ToolInterface;
use Ben\Designer\Api\Data\ToolOptionInterface;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ResourceModel\Tool\CollectionFactory as ToolCollectionFactory;
use Ben\Designer\Model\ResourceModel\ToolOption as ToolOptionResource;
use Ben\Designer\Model\ResourceModel\ToolOption\CollectionFactory as ToolOptionCollectionFactory;
use Ben\Designer\Model\ToolOption;
use Exception;

/**
 * Finding and taking away the options a designer tool no longer carries.
 *
 * Several migrations retire an option that turned out to be the shop's setting rather than the product's, and
 * each of them has the same work to do first: find every tool built from the component, because a store may
 * run more than one, find the option rows on them by name, then take away the products' answers as well as the
 * rows themselves. What a patch does with the values before they go is its own business and stays in the patch.
 */
class ToolOptions
{
    public function __construct(
        private readonly ProductToolOptionCollectionFactory $productToolOptionCollectionFactory,
        private readonly ToolCollectionFactory $toolCollectionFactory,
        private readonly ToolOptionCollectionFactory $toolOptionCollectionFactory,
        private readonly ToolOptionResource $toolOptionResource,
    ) {
    }

    /**
     * @param string[] $names
     *
     * @return ToolOption[] keyed by tool option id
     */
    public function find(string $component, array $names): array
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, $component);
        $toolIds = array_map('intval', $toolCollection->getAllIds());

        if (!$toolIds) {
            return [];
        }

        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::TOOL_ID, ['in' => $toolIds]);
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::NAME, ['in' => $names]);

        return $toolOptionCollection->getItems();
    }

    /**
     * The products' answers first, so nothing is left pointing at an option row that has gone
     *
     * @param ToolOption[] $toolOptions
     *
     * @throws Exception
     */
    public function remove(array $toolOptions): void
    {
        if (!$toolOptions) {
            return;
        }

        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(
            ProductToolOptionInterface::TOOL_OPTION_ID,
            ['in' => array_keys($toolOptions)]
        );
        $productToolOptionCollection->walk('delete');

        foreach ($toolOptions as $toolOption) {
            $this->toolOptionResource->delete($toolOption);
        }
    }
}
