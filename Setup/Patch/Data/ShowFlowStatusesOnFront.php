<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Ben\OrderFlow\Model\Order\Status;
use Ben\OrderFlow\Setup\Patch\Data\AddOrderStatuses;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * The processing statuses were created hidden from the storefront, which dropped every order sitting on them
 * out of the customer order history, so make them visible on installs that already have them.
 *
 * Only the visible_on_front flag of the rows this module created is touched: the state a status is assigned to
 * and whether it is that state's default are the shop's to change, and a status an admin has since removed or
 * moved to another state is left where they put it rather than being reinstated.
 */
class ShowFlowStatusesOnFront implements DataPatchInterface
{
    // The statuses this module created hidden, with the state it assigned each to
    private const array STATES_BY_STATUS = [
        Status::STATUS_INVOICE_PRINTED => Order::STATE_PROCESSING,
        Status::STATUS_UNSHIPPED => Order::STATE_PROCESSING,
    ];

    private const string TABLE = 'sales_order_status_state';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            AddOrderStatuses::class,
        ];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_OrderFlow')) {
            return;
        }

        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $table = $this->moduleDataSetup->getTable(self::TABLE);

        foreach (self::STATES_BY_STATUS as $status => $state) {
            $connection->update(
                $table,
                ['visible_on_front' => 1],
                [
                    'status = ?' => $status,
                    'state = ?' => $state,
                    'visible_on_front = ?' => 0,
                ],
            );
        }

        $connection->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\OrderFlow\Setup\Patch\Data\ShowFlowStatusesOnFront'];
    }
}
