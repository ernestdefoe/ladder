<?php

namespace Ernestdefoe\Ladder\Search;

use Ernestdefoe\Ladder\Ladder;
use Ernestdefoe\Ladder\Rung;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * `filter[ladderRung]=<rung id>` on /api/users: who holds a rank, each with
 * their score, so the Ranks page can list them highest first with
 * `sort=-ladderScore`. Core's user list already requires `searchUsers` and
 * only returns members the viewer may see.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class RungFilter implements FilterInterface
{
    public function __construct(private Ladder $ladder)
    {
    }

    public function getFilterKey(): string
    {
        return 'ladderRung';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        // Negated ("everyone not on this rank") isn't a list the Ranks page has.
        $rung = $negate ? null : Rung::query()->with('group')->find((int) (is_array($value) ? reset($value) : $value));

        $this->ladder->scopeToRung($state->getQuery(), $rung, $state->getActor());
    }
}
