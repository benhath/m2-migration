<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Ben\Migration\Model\ToolOptions;
use Exception;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the prompt length and redraw limits a product carried, now that they are shop settings.
 *
 * How long a description may be and how many further takes a customer is offered are the shop's rules, set
 * once under Stores > Configuration > Designer > Drawing and read by both the browser and the server. Every
 * product held the same numbers as those settings, so nothing is carried over: the rows are only removed, and
 * the tools keep the options that really do differ between products.
 *
 * The text boxes a customer types into - the message on the paper, the words on a print, the note and the
 * inside of a card - keep their own maxCharacters. That is how long a printed message may be, which is a
 * property of the product being printed, not a rule about what may be asked of the model.
 */
class RemoveAiDrawingLimitToolOptions implements DataPatchInterface
{
    /**
     * The option each tool no longer sets, by the component it belongs to
     */
    private const array OPTION_NAME_BY_TOOL_COMPONENT = [
        'AiPortrait' => 'maxVariations',
        'Create' => 'maxCharacters',
        'StickerPrompt' => 'maxVariations',
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

        foreach (self::OPTION_NAME_BY_TOOL_COMPONENT as $component => $optionName) {
            $this->toolOptions->remove($this->toolOptions->find($component, [$optionName]));
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }
}
