<?php

namespace App\Models\Concerns;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * `matchingKeyword()` scope shared by the order lists (customer and shopper
 * apps), so orders are searched the same way everywhere: the keyword is trimmed
 * and matched anywhere, LIKE wildcards in it are literal, and a phone-looking
 * keyword ("0555 123 456") is also tried in the stored 9665XXXXXXXX form.
 *
 * The model only says which fields are searched (keywordConditions()),
 * which may depend on who searches: a customer finds a custom order by its
 * shopper's name, the shopper finds it by the customer's name.
 */
trait SearchableByKeyword
{
    public const KEYWORD_VIEWER_CUSTOMER = 'customer';

    public const KEYWORD_VIEWER_SHOPPER = 'shopper';

    public function scopeMatchingKeyword(Builder $query, ?string $keyword, string $viewer = self::KEYWORD_VIEWER_CUSTOMER): Builder
    {
        $keyword = trim((string) preg_replace('/\s+/u', ' ', (string) $keyword));

        if ($keyword === '') {
            return $query;
        }

        $like  = '%'.addcslashes($keyword, '\\%_').'%';
        $phone = preg_match('/^\+?[\d\s-]{6,}$/', $keyword) ? PhoneNumber::normalize($keyword) : '';

        return $query->where(fn (Builder $q) => $this->keywordConditions($q, $like, $phone !== '' ? '%'.$phone.'%' : null, $viewer));
    }

    /**
     * OR-ed conditions matching `$like` (and `$phoneLike` when the keyword
     * looks like a phone number), applied inside a single where group.
     */
    abstract protected function keywordConditions(Builder $query, string $like, ?string $phoneLike, string $viewer): void;
}
