<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderItemCancellationService
{
    /**
     * Cancel a pending order item and give its quantity back to the stock.
     * Returns false when the item is not pending.
     */
    public function cancel(OrderItem $item): bool
    {
        return DB::transaction(function () use ($item) {
            // Re-read the row with a lock, so a double click can't restore the stock twice.
            $locked = OrderItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->status !== 'pending') {
                return false;
            }

            $locked->status = 'cancelled';
            $locked->save();

            // withTrashed: the stock goes back even if the seller deleted the product.
            Product::withTrashed()
                ->whereKey($locked->product_id)
                ->increment('stock', $locked->quantity);

            return true;
        });
    }
}
