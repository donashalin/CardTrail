<?php

namespace App\Imports;

use App\Models\CardIdentifier;
use Illuminate\Support\Str;

/**
 * Finds which cardholder a statement row belongs to.
 * Last four digits win; the name is only used when the digits match nobody.
 * Anything ambiguous returns null so the line goes to Unassigned instead of the wrong person.
 * Leavers and non-cardholders count when judging ambiguity but are never matched themselves,
 * so a leaver's charges on a shared last four can't land on an active colleague.
 */
final class CardholderMatcher
{
    /** @var array<string, list<int>> */
    private array $byLast4 = [];

    /** @var array<string, list<int>> */
    private array $byName = [];

    /** @var array<int, bool> */
    private array $eligible = [];

    /** @param  iterable<array{user_id: int, last4: ?string, cardholder_name: ?string, eligible?: bool}>  $identifiers */
    public function __construct(iterable $identifiers)
    {
        foreach ($identifiers as $identifier) {
            $this->eligible[(int) $identifier['user_id']] = (bool) ($identifier['eligible'] ?? true);

            if ($identifier['last4'] !== null && $identifier['last4'] !== '') {
                $this->byLast4[$identifier['last4']][] = (int) $identifier['user_id'];
            }

            $name = self::normaliseName($identifier['cardholder_name']);
            if ($name !== '') {
                $this->byName[$name][] = (int) $identifier['user_id'];
            }
        }
    }

    public static function forActiveCardholders(): self
    {
        return new self(
            CardIdentifier::query()
                ->join('users', 'users.id', '=', 'card_identifiers.user_id')
                ->get(['card_identifiers.user_id', 'card_identifiers.last4', 'card_identifiers.cardholder_name', 'users.active', 'users.is_cardholder'])
                ->map(fn (CardIdentifier $identifier) => [
                    'user_id' => $identifier->user_id,
                    'last4' => $identifier->last4,
                    'cardholder_name' => $identifier->cardholder_name,
                    'eligible' => (bool) $identifier->active && (bool) $identifier->is_cardholder,
                ]),
        );
    }

    public function match(?string $last4, ?string $name): ?int
    {
        if ($last4 !== null && isset($this->byLast4[$last4])) {
            return $this->single($this->byLast4[$last4]);
        }

        $name = self::normaliseName($name);

        return $name !== '' && isset($this->byName[$name]) ? $this->single($this->byName[$name]) : null;
    }

    private function single(array $userIds): ?int
    {
        $unique = array_values(array_unique($userIds));

        return count($unique) === 1 && $this->eligible[$unique[0]] ? $unique[0] : null;
    }

    private static function normaliseName(?string $name): string
    {
        return Str::lower(Str::squish($name ?? ''));
    }
}
