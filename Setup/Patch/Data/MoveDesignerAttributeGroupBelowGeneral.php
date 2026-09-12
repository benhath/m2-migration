<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Setup\Patch\Data\AddDesignerAttributeGroup;
use Ben\Designer\Setup\Patch\Data\AddDesignerShowProductSwitcherAttribute;
use Ben\Designer\Setup\Patch\Data\AddDesignerShowPurchaseConfirmationAttribute;
use Ben\Designer\Setup\Patch\Data\AddDesignerVisibleInProductSwitcherAttribute;
use Ben\Migration\Model\Gate;
use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Zend_Db_Expr;

/**
 * Sorts the "Designer" section directly under General on the product form in every attribute set, moving the
 * groups that were there down one, and places every designer attribute in it. Stores that took the group at
 * its old sort order need this, and a store whose attribute patches ran after the group patch is missing the
 * later attributes from the group; fresh installs get both from the group patch. The attribute notes are
 * refreshed too, so wording changes reach stores that already have the attributes
 */
class MoveDesignerAttributeGroupBelowGeneral implements DataPatchInterface
{
    // The hint under each field, keyed by attribute code, where the attribute has one
    private const array NOTES = [
        AddDesignerShowProductSwitcherAttribute::ATTRIBUTE_CODE =>
            'Show the product switcher in the designer while this product is active.',
        AddDesignerShowPurchaseConfirmationAttribute::ATTRIBUTE_CODE =>
            'Show the confirmation popup before adding to the cart. No adds to the cart straight from the Purchase button.',
        AddDesignerVisibleInProductSwitcherAttribute::ATTRIBUTE_CODE =>
            "Offer this product in the designer's product switcher. Off hides it from the switcher on every other product.",
    ];

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            AddDesignerAttributeGroup::class,
            AddDesignerVisibleInProductSwitcherAttribute::class,
        ];
    }

    /**
     * @throws LocalizedException
     */
    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Designer')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        $connection = $this->moduleDataSetup->getConnection();
        $groupTable = $this->moduleDataSetup->getTable('eav_attribute_group');

        foreach ($eavSetup->getAllAttributeSetIds($entityTypeId) as $attributeSetId) {
            $groupId = (int)$eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, AddDesignerAttributeGroup::GROUP_NAME);
            $currentSortOrder = (int)$eavSetup->getAttributeGroup($entityTypeId, $attributeSetId, $groupId, 'sort_order');

            // Already in place: shifting the other groups again would only open gaps in their numbering
            if ($currentSortOrder !== AddDesignerAttributeGroup::GROUP_SORT_ORDER) {
                $connection->update(
                    $groupTable,
                    ['sort_order' => new Zend_Db_Expr('sort_order + 1')],
                    [
                        'attribute_set_id = ?' => $attributeSetId,
                        'attribute_group_id != ?' => $groupId,
                        'sort_order >= ?' => AddDesignerAttributeGroup::GROUP_SORT_ORDER,
                    ]
                );
                $eavSetup->updateAttributeGroup(
                    $entityTypeId,
                    $attributeSetId,
                    $groupId,
                    'sort_order',
                    AddDesignerAttributeGroup::GROUP_SORT_ORDER
                );
            }

            foreach (AddDesignerAttributeGroup::ATTRIBUTE_CODES as $sortOrder => $attributeCode) {
                $eavSetup->addAttributeToGroup($entityTypeId, $attributeSetId, $groupId, $attributeCode, ($sortOrder + 1) * 10);
            }
        }

        foreach (self::NOTES as $attributeCode => $note) {
            $eavSetup->updateAttribute($entityTypeId, $attributeCode, 'note', $note);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\MoveDesignerAttributeGroupBelowGeneral'];
    }
}
