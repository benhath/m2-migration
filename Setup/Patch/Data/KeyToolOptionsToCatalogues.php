<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolOptionInterface;
use Ben\Designer\Api\Data\ToolInterface;
use Ben\Designer\Api\Data\ToolOptionInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * The colour and font tools stop naming a PHP class and name a catalogue instead.
 *
 * Each of these tools carried an option holding the name of a class that listed the shop's colours or fonts at
 * request time, which meant a product could only ever offer all of them and only a release could change that.
 * The option is keyed to the catalogue now: it holds the ids the product was given, and holds nothing where the
 * product takes everything the shop sells, which is what every one of these products does today. So the class
 * name is replaced with nothing and no customer sees a different list.
 *
 * What each product offers instead is written by its own range's package: nothing at all for a roll of wrapping
 * paper, which is every colour and font the shop sells, and a short list for a card and for words over a
 * photograph. Those lists are named in the package rather than numbered here, because the same colour is a
 * different id on every shop.
 */
class KeyToolOptionsToCatalogues implements DataPatchInterface
{
    // Tool component to the option that held the class, and what that option is called and keyed to now
    private const array CONVERSIONS
        = [
            'ArtTemplate' => ['fonts', 'fonts', 'fonts'],
            'CardInside' => ['source', 'fonts', 'fonts'],
            'GiftwrapColor' => ['source', 'colors', 'colors'],
            'GiftwrapFont' => ['source', 'fonts', 'fonts'],
            'Text' => ['source', 'fonts', 'fonts'],
        ];

    private const string TABLE_PRODUCT_TOOL_OPTION = 'ben_designer_product_tool_option';

    private const string TABLE_TOOL = 'ben_designer_tool';

    private const string TABLE_TOOL_OPTION = 'ben_designer_tool_option';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE_TOOL_OPTION)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        foreach (self::CONVERSIONS as $component => [$oldName, $newName, $schema]) {
            $this->convert((string)$component, $oldName, $newName, $schema);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * One tool's option renamed and keyed to its catalogue, and every product's answer for it cleared away,
     * because the answer it held was the name of a class. What each product offers instead is written by its own
     * range's package, which is where a shop's decisions about a range are kept
     */
    private function convert(string $component, string $oldName, string $newName, string $schema): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $toolOptionTable = $this->moduleDataSetup->getTable(self::TABLE_TOOL_OPTION);

        $toolIds = $connection->fetchCol(
            $connection->select()
                ->from($this->moduleDataSetup->getTable(self::TABLE_TOOL), ToolInterface::TOOL_ID)
                ->where(ToolInterface::COMPONENT . ' = ?', $component)
        );

        if (!$toolIds) {
            return;
        }

        $toolOptionIds = $connection->fetchCol(
            $connection->select()
                ->from($toolOptionTable, ToolOptionInterface::TOOL_OPTION_ID)
                ->where(ToolOptionInterface::TOOL_ID . ' IN (?)', $toolIds)
                ->where(ToolOptionInterface::NAME . ' = ?', $oldName)
                // schema is a word MySQL keeps for itself, so the column is quoted
                ->where($connection->quoteIdentifier(ToolOptionInterface::SCHEMA) . ' = ?', 'source')
        );

        if (!$toolOptionIds) {
            return;
        }

        $connection->update(
            $toolOptionTable,
            [ToolOptionInterface::NAME => $newName, ToolOptionInterface::SCHEMA => $schema],
            [ToolOptionInterface::TOOL_OPTION_ID . ' IN (?)' => $toolOptionIds],
        );

        $cleared = $connection->delete(
            $this->moduleDataSetup->getTable(self::TABLE_PRODUCT_TOOL_OPTION),
            [ProductToolOptionInterface::TOOL_OPTION_ID . ' IN (?)' => $toolOptionIds],
        );

        $this->logger->info(sprintf(
            'Designer catalogues: %s.%s is now %s keyed to %s, and %d product answers naming the old class were '
            . 'cleared for each range to write what its products offer',
            $component,
            $oldName,
            $newName,
            $schema,
            $cleared,
        ));
    }
}
