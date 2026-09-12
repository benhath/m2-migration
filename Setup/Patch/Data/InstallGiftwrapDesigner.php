<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolInterface;
use Ben\Designer\Api\Data\ProductToolOptionInterface;
use Ben\Designer\Api\Data\ToolInterface;
use Ben\Designer\Api\Data\ToolOptionInterface;
use Ben\Designer\Model\DesignerTypeResolver;
use Ben\Designer\Model\ProductTool;
use Ben\Designer\Model\ProductToolFactory;
use Ben\Designer\Model\ProductToolOptionFactory;
use Ben\Designer\Model\ResourceModel\ProductTool as ProductToolResource;
use Ben\Designer\Model\ResourceModel\ProductTool\CollectionFactory as ProductToolCollectionFactory;
use Ben\Designer\Model\ResourceModel\ProductToolOption as ProductToolOptionResource;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ResourceModel\Tool as ToolResource;
use Ben\Designer\Model\ResourceModel\Tool\CollectionFactory as ToolCollectionFactory;
use Ben\Designer\Model\ResourceModel\ToolOption as ToolOptionResource;
use Ben\Designer\Model\ResourceModel\ToolOption\CollectionFactory as ToolOptionCollectionFactory;
use Ben\Designer\Model\Tool;
use Ben\Designer\Model\ToolFactory;
use Ben\Designer\Model\ToolOption;
use Ben\Designer\Model\ToolOptionFactory;
use Ben\Migration\Model\Gate;
use Exception;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\ActionFactory as ProductActionFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * Installs the whole giftwrap designer: the thirteen tools the roll is made with, the questions each of them
 * asks, and the answers the GIFTWRAP product gives.
 *
 * The shop that sells giftwrap was configured by hand in the admin years before any of this was written down,
 * so a fresh store - or the live database, which never met the React designer - has the product and none of
 * the tools, and /create/giftwrap opens on nothing. Every row here is the configuration that site has been
 * running, written out so a store can be brought up to it without a person clicking through the admin.
 *
 * Nothing already present is rewritten: a tool, an option, a product tool or a saved answer that exists is
 * left exactly as the admin has it, so the patch is a no-op on the store it was copied from
 */
class InstallGiftwrapDesigner implements DataPatchInterface
{
    // The product tools, in the order the customer meets them, and what each has saved against it
    private const array PRODUCT_TOOLS = [
        'Create' => [
            'sortOrder' => 0,
            'values' => [
                'resultDestination' => 'design',
                'placeholder' => 'Cute fluffy baby cats with Santa hats',
                'maxCharacters' => '200',
                'description' => 'Describe your masterpiece.',
                'busyMessage' => 'Assembling the pixels...',
            ],
        ],
        'GiftwrapDesign' => [
            'sortOrder' => 100,
            'values' => [
                'dynamicSource' => 'giftwrapDesign',
                'categorySource' => 'giftwrapCategory',
            ],
        ],
        'Gallery' => [
            'sortOrder' => 200,
            'values' => [
                'maxUploadWidthPx' => '10000',
                'maxUploadTotalFileSizeMb' => '64',
                'maxUploadFileSizeMb' => '64',
                'maxUploadHeightPx' => '10000',
                'allowedUploadFileType' => '[{"mime":"image\\/jpeg","hash":"7459c389a0a93b68729195ad2a38587b"},{"mime":"image\\/png","hash":"f331b39e7c4c17dfb65b0d205b2c8d5f"},{"mime":"image\\/heif","hash":"6cc126a7c259b59f1eed2a0117e57e8a"},{"mime":"image\\/heic","hash":"6d039fa6c96f603d8e879a7ac3b8f869"},{"mime":"image\\/webp","hash":"f61e4832a06a733fd631ac9e14de95b2"}]',
            ],
        ],
        'GiftwrapFace' => [
            'sortOrder' => 300,
            'values' => [],
        ],
        'GiftwrapAccessory' => [
            'sortOrder' => 400,
            'values' => [
                'accessory' => '[{"key":"NONE","title":"None","hash":"009448cd5a7e0fc0ad8fb4b5910c8c06"},{"key":"SANTA-HAT-1","title":"Santa Hat","hash":"50c39b6020316c0ce67e3724306a16ae"}]',
            ],
        ],
        'GiftwrapText' => [
            'sortOrder' => 500,
            'values' => [
                'maxCharacters' => '30',
                'tooltipTitle' => 'What goes in the message?',
                'tooltipDescription' => 'A short line printed across the paper, repeated along the roll. Names and a greeting work best - about forty characters keeps it readable.',
            ],
        ],
        'GiftwrapColor' => [
            'sortOrder' => 600,
            'values' => [
                'source' => 'Ben\\DesignerGiftwrap\\Model\\Source\\Color',
            ],
        ],
        'GiftwrapFont' => [
            'sortOrder' => 700,
            'values' => [
                'source' => 'Ben\\DesignerGiftwrap\\Model\\Source\\Font',
            ],
        ],
        'GiftwrapSize' => [
            'sortOrder' => 800,
            'values' => [
                'showTotals' => '1',
                'rollLength' => '[{"lengthM":"2","name":"2 Meters","fixedPrice":"8","areaPrice":"0","singlePrice":"0","hash":"3d4b9bf84dc5be0bad32e45302ce7c31"},{"lengthM":"5","name":"5 Meters","fixedPrice":"18","areaPrice":"0","singlePrice":"0","hash":"79784415da2add76f13b0b6331179bac"}]',
                'maxRolls' => '5',
            ],
        ],
        'Preview' => [
            'sortOrder' => 1500,
            'values' => [
                'showDimensions' => '0',
                'showFrame' => '0',
                'notice' => 'For illustration purposes only.',
                'prefetchFrameFinishes' => '0',
            ],
        ],
        'Summaries' => [
            'sortOrder' => 1800,
            'values' => [],
        ],
        'Purchase' => [
            'sortOrder' => 1900,
            'values' => [],
        ],
        'ToolErrors' => [
            'sortOrder' => 2000,
            'values' => [],
        ],
    ];

    private const string SKU = 'GIFTWRAP';

    // Every tool the giftwrap product runs, by component, with the options it asks the admin for
    private const array TOOLS = [
        'Create' => [
            'name' => 'Create',
            'description' => 'Allow the user to describe and build their artwork.',
            'title' => 'Design Your Own',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'resultDestination' => 'string',
                'placeholder' => 'string',
                'maxCharacters' => 'string',
                'description' => 'string',
                'busyMessage' => 'string',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
                'examples' => '{"text":"string"}',
            ],
        ],
        'GiftwrapDesign' => [
            'name' => 'Design',
            'description' => 'Allow the user to choose a giftwrap design.',
            'title' => 'Choose A Design',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'dynamicSource' => 'string',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
                'showListing' => 'bool',
                'listingPerPage' => 'int',
                'showListingSearch' => 'bool',
                'categorySource' => 'string',
            ],
        ],
        'Gallery' => [
            'name' => 'Gallery',
            'description' => 'Photo file upload tool.',
            'title' => 'Photo Upload',
            'cssClass' => 'gallery',
            'isExpanded' => false,
            'isGlobalUserData' => true,
            'hasPreview' => false,
            'options' => [
                'maxUploadWidthPx' => 'int',
                'maxUploadTotalFileSizeMb' => 'float',
                'maxUploadFileSizeMb' => 'float',
                'maxUploadHeightPx' => 'string',
                'allowedUploadFileType' => "{\n  \"mime\": \"string\"\n}",
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'GiftwrapFace' => [
            'name' => 'Face',
            'description' => 'Handles multiple faces from a single upload.',
            'title' => 'Choose A Face',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'GiftwrapAccessory' => [
            'name' => 'Accessory',
            'description' => 'Allow the user to personalise their designs with accessories.',
            'title' => 'Accessories',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'accessory' => "{\n  \"key\": \"string\",\n  \"title\": \"string\"\n}",
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'GiftwrapText' => [
            'name' => 'Text',
            'description' => 'Allow the user to enter their personalised text message.',
            'title' => 'Enter Your Message',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'maxCharacters' => 'int',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
                'defaultTextWarning' => 'string',
                'textSuggestions' => '{"text":"string"}',
            ],
        ],
        'GiftwrapColor' => [
            'name' => 'Color',
            'description' => 'Allow the user to choose a colour for their giftwrap design.',
            'title' => 'Choose A Colour',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'source' => 'source',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'GiftwrapFont' => [
            'name' => 'Font',
            'description' => 'A selection of fonts to be used in the giftwrap design.',
            'title' => 'Choose A Font',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'source' => 'source',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'GiftwrapSize' => [
            'name' => 'Roll',
            'description' => 'Allow the user to choose their giftwrap size and number of rolls.',
            'title' => 'Select Roll Length',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'showTotals' => 'bool',
                'rollLength' => '{"lengthM":"int","name":"string","price":"price"}',
                'maxRolls' => 'int',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'Preview' => [
            'name' => 'Preview',
            'description' => 'Product preview window.',
            'title' => null,
            'cssClass' => 'preview',
            'isExpanded' => true,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'showDimensions' => 'bool',
                'showFrame' => 'bool',
                'styles' => "{\n  \"key\": \"string\",\n  \"value\": \"string\"\n}",
                'notice' => 'string',
                'prefetchFrameFinishes' => 'bool',
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
                'showSides' => 'bool',
            ],
        ],
        'Summaries' => [
            'name' => 'Summaries',
            'description' => 'A summary of selected options from each tool.',
            'title' => 'Summary',
            'cssClass' => 'summaries',
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
                'showInTools' => 'bool',
                'showInPurchaseConfirmation' => 'bool',
            ],
        ],
        'Purchase' => [
            'name' => 'Purchase',
            'description' => 'Pricing and add to cart form.',
            'title' => null,
            'cssClass' => 'purchase',
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
        'ToolErrors' => [
            'name' => 'Tool Errors',
            'description' => 'Display a list of active tool errors.',
            'title' => 'Warning',
            'cssClass' => null,
            'isExpanded' => false,
            'isGlobalUserData' => false,
            'hasPreview' => false,
            'options' => [
                'tooltipTitle' => 'string',
                'tooltipDescription' => 'string',
            ],
        ],
    ];

    private const string TYPE_CODE = 'giftwrap';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductActionFactory $productActionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductToolCollectionFactory $productToolCollectionFactory,
        private readonly ProductToolFactory $productToolFactory,
        private readonly ProductToolOptionCollectionFactory $productToolOptionCollectionFactory,
        private readonly ProductToolOptionFactory $productToolOptionFactory,
        private readonly ProductToolOptionResource $productToolOptionResource,
        private readonly ProductToolResource $productToolResource,
        private readonly ToolCollectionFactory $toolCollectionFactory,
        private readonly ToolFactory $toolFactory,
        private readonly ToolOptionCollectionFactory $toolOptionCollectionFactory,
        private readonly ToolOptionFactory $toolOptionFactory,
        private readonly ToolOptionResource $toolOptionResource,
        private readonly ToolResource $toolResource,
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            RenameSourceClasses::class,
            SetGiftwrapDesignerType::class,
        ];
    }

    /**
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_DesignerGiftwrap')
            || !$this->gate->hasTable('ben_designer_tool')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        foreach (self::TOOLS as $component => $tool) {
            $this->addToolOptions($this->addTool($component, $tool), $tool['options']);
        }

        $product = $this->getProduct();

        if ($product) {
            foreach (self::PRODUCT_TOOLS as $component => $productTool) {
                $this->addProductTool($product->getId(), $component, $productTool['sortOrder'], $productTool['values']);
            }

            $this->setDesignerType($product);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\DesignerGiftwrap\Setup\Patch\Data\InstallGiftwrapDesigner'];
    }

    /**
     * @param array<string, string> $values option name to the answer saved against it
     *
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    private function addProductTool(mixed $productId, string $component, int $sortOrder, array $values): void
    {
        $tool = $this->getTool($component);

        if (!$tool->getId()) {
            return;
        }

        $productTool = $this->getProductTool($productId, (int)$tool->getId());

        if (!$productTool->getId()) {
            $productTool->setProductId($productId);
            $productTool->setToolId((string)$tool->getId());
            $productTool->setSortOrder($sortOrder);

            try {
                $this->productToolResource->save($productTool);
            } catch (Exception $exception) {
                throw new CouldNotSaveException(__('Could not save the %1 product tool.', $component), $exception);
            }
        }

        foreach ($this->getToolOptions((int)$tool->getId()) as $name => $toolOption) {
            if (!array_key_exists($name, $values) || $this->hasProductToolOption((int)$productTool->getId(), (int)$toolOption->getId())) {
                continue;
            }

            $productToolOption = $this->productToolOptionFactory->create();
            $productToolOption->setProductToolId($productTool->getId());
            $productToolOption->setToolOptionId($toolOption->getId());
            $productToolOption->setValue($values[$name]);

            try {
                $this->productToolOptionResource->save($productToolOption);
            } catch (Exception $exception) {
                throw new CouldNotSaveException(__('Could not save the %1 product tool option.', $name), $exception);
            }
        }
    }

    /**
     * @param array<string, mixed> $values the tool's row as this site has it
     *
     * @throws CouldNotSaveException
     */
    private function addTool(string $component, array $values): Tool
    {
        $tool = $this->getTool($component);

        if ($tool->getId()) {
            return $tool;
        }

        $tool->setComponent($component);
        $tool->setName($values['name']);
        $tool->setDescription($values['description']);
        $tool->setTitle($values['title']);
        $tool->setCssClass($values['cssClass']);
        $tool->setIsExpanded($values['isExpanded']);
        $tool->setIsGlobalUserData($values['isGlobalUserData']);
        $tool->setHasPreview($values['hasPreview']);

        try {
            $this->toolResource->save($tool);
        } catch (Exception $exception) {
            throw new CouldNotSaveException(__('Could not save the %1 tool.', $component), $exception);
        }

        return $tool;
    }

    /**
     * @param array<string, string> $options option name to its schema
     *
     * @throws CouldNotSaveException
     */
    private function addToolOptions(Tool $tool, array $options): void
    {
        $existing = $this->getToolOptions((int)$tool->getId());

        foreach ($options as $name => $schema) {
            if (isset($existing[$name])) {
                continue;
            }

            $toolOption = $this->toolOptionFactory->create();
            $toolOption->setToolId((int)$tool->getId());
            $toolOption->setName($name);
            $toolOption->setSchema($schema);

            try {
                $this->toolOptionResource->save($toolOption);
            } catch (Exception $exception) {
                throw new CouldNotSaveException(__('Could not save the %1 tool option.', $name), $exception);
            }
        }
    }

    private function getProduct(): ?ProductInterface
    {
        try {
            return $this->productRepository->get(self::SKU);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @throws LocalizedException
     */
    private function getProductTool(mixed $productId, int $toolId): ProductTool
    {
        $productToolCollection = $this->productToolCollectionFactory->create();
        $productToolCollection->addFieldToFilter(ProductToolInterface::PRODUCT_ID, $productId);
        $productToolCollection->addFieldToFilter(ProductToolInterface::TOOL_ID, $toolId);

        /** @var ProductTool $productTool */
        $productTool = $productToolCollection->getFirstItem();

        return $productTool->getId() ? $productTool : $this->productToolFactory->create();
    }

    /**
     * The tool with this component name, or an empty one to fill in
     */
    private function getTool(string $component): Tool
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, $component);

        /** @var Tool $tool */
        $tool = $toolCollection->getFirstItem();

        return $tool->getId() ? $tool : $this->toolFactory->create();
    }

    /**
     * @return array<string, ToolOption>
     */
    private function getToolOptions(int $toolId): array
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::TOOL_ID, $toolId);

        $toolOptions = [];

        /** @var ToolOption $toolOption */
        foreach ($toolOptionCollection->getItems() as $toolOption) {
            $toolOptions[(string)$toolOption->getName()] = $toolOption;
        }

        return $toolOptions;
    }

    private function hasProductToolOption(int $productToolId, int $toolOptionId): bool
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::PRODUCT_TOOL_ID, $productToolId);
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::TOOL_OPTION_ID, $toolOptionId);

        return $productToolOptionCollection->getSize() > 0;
    }

    /**
     * A store that has already named the product's designer keeps its answer
     */
    private function setDesignerType(ProductInterface $product): void
    {
        if ($product->getData(DesignerTypeResolver::ATTRIBUTE_CODE)) {
            return;
        }

        $this->productActionFactory->create()->updateAttributes(
            [(int)$product->getId()],
            [DesignerTypeResolver::ATTRIBUTE_CODE => self::TYPE_CODE],
            Store::DEFAULT_STORE_ID
        );
    }
}
