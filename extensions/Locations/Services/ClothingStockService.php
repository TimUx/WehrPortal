<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Kleiderkammer\src\Models\Stock;

class ClothingStockService
{
    public function adjust(User $actor, int $stockId, int $quantity): Stock
    {
        abort_unless(app(ClothingStockAccess::class)->enabled(), 403);

        return app(ClothingStockAccess::class)->asActor($actor, fn () => DB::transaction(function () use ($actor, $stockId, $quantity) {
            $stock = Stock::lockForUpdate()->findOrFail($stockId);
            app(ClothingStockAccess::class)->authorizeStock($actor, $stock);
            validator(['quantity' => $quantity], ['quantity' => ['required', 'integer', 'min:0']])->validate();
            $stock->update(['quantity' => $quantity]);

            return $stock;
        }));
    }
}
