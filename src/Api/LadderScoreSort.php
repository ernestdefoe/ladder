<?php

namespace Ernestdefoe\Ladder\Api;

use Flarum\Api\Context;
use Flarum\Api\Sort\SortColumn;

/**
 * `sort=ladderScore` on /api/users. Offered only with the ladderRung filter,
 * which is what selects the score to sort by.
 */
class LadderScoreSort
{
    public function __invoke(): array
    {
        return [
            SortColumn::make('ladderScore')
                ->visible(fn (Context $context) => isset(((array) $context->queryParam('filter'))['ladderRung'])),
        ];
    }
}
