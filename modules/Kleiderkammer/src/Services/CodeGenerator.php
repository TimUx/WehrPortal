<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Services\OrganizationSettings;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;

/**
 * Generates unique item codes in the format {orgPrefix}{typePrefix}{NNNN}.
 * Example: WEHREJA0001 (org=WEHR, typePrefix=EJA, number=1).
 * The org prefix is configurable via OrganizationSettings (key: org_code_prefix).
 */
class CodeGenerator
{
    public function __construct(
        private readonly OrganizationSettings $settings,
    ) {}

    /** Generate the next available code for the given item type. */
    public function generate(ItemType $itemType): string
    {
        $prefix = $this->buildPrefix($itemType);
        $next = $this->nextNumber($prefix);

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Return true if a code looks like it belongs to this org+type. */
    public function matches(string $code, ItemType $itemType): bool
    {
        return str_starts_with($code, $this->buildPrefix($itemType));
    }

    private function buildPrefix(ItemType $itemType): string
    {
        $org = $this->settings->codePrefix();
        $type = $itemType->code_prefix ?? '';

        return $org.$type;
    }

    private function nextNumber(string $prefix): int
    {
        $last = Item::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(code) DESC, code DESC')
            ->value('code');

        if ($last === null) {
            return 1;
        }

        $number = (int) substr($last, strlen($prefix));

        return $number + 1;
    }
}
