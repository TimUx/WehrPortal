<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Services\OrganizationSettings;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;

/** Resolves an item code (from a QR label scan) back to the Item model. */
class CodeResolver
{
    /** Returns the item for the code, or null if not found (including soft-deleted). */
    public function resolve(string $code): ?Item
    {
        return Item::withTrashed()->where('code', $code)->first();
    }

    /** Guesses the item type from a code like WEHREJA0007: org prefix, then the longest matching type prefix. */
    public function guessItemType(string $code): ?ItemType
    {
        $orgPrefix = app(OrganizationSettings::class)->codePrefix();
        $rest = str_starts_with($code, $orgPrefix) ? substr($code, strlen($orgPrefix)) : $code;

        return ItemType::where('is_tracked', true)
            ->whereNotNull('code_prefix')
            ->where('code_prefix', '!=', '')
            ->get()
            ->filter(fn (ItemType $type) => str_starts_with($rest, $type->code_prefix))
            ->sortByDesc(fn (ItemType $type) => strlen($type->code_prefix))
            ->first();
    }

    /** Returns the live (non-deleted) item for the code, or null. */
    public function resolveActive(string $code): ?Item
    {
        return Item::where('code', $code)->first();
    }
}
