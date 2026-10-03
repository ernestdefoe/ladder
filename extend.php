<?php

/*
 * Ladder: post-count ranks, one rung at a time.
 *
 * Each rung is an ordinary Flarum group with a post threshold. A member holds
 * exactly one rung group, the one their post count has earned, and moves up
 * when they cross the next threshold. Every other group they belong to is
 * left alone.
 */

use Ernestdefoe\Ladder\Api;
use Ernestdefoe\Ladder\Console\SyncCommand;
use Ernestdefoe\Ladder\InheritPermissions;
use Ernestdefoe\Ladder\Ladder;
use Ernestdefoe\Ladder\Listener\RankMember;
use Ernestdefoe\Ladder\Notification\PromotedBlueprint;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/common.less')
        ->css(__DIR__.'/less/forum.less')
        ->route('/ranks', 'ladder.ranks'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/common.less')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/ladder', 'ladder.show', Api\ShowLadder::class)
        ->post('/ladder/rungs', 'ladder.rungs.create', Api\SaveRung::class)
        ->patch('/ladder/rungs/{id}', 'ladder.rungs.update', Api\SaveRung::class)
        ->delete('/ladder/rungs/{id}', 'ladder.rungs.delete', Api\DeleteRung::class)
        ->post('/ladder/rungs/{id}/image', 'ladder.rungs.image', Api\RungImage::class)
        ->delete('/ladder/rungs/{id}/image', 'ladder.rungs.image.delete', Api\RungImage::class)
        ->post('/ladder/sync', 'ladder.sync', Api\SyncLadder::class),

    (new Extend\Event())
        ->subscribe(RankMember::class),

    // 🚨 Alert only. A promotion is worth a badge lighting up; an email for
    // each one teaches members to filter the forum's mail.
    (new Extend\Notification())
        ->type(PromotedBlueprint::class, ['alert']),

    // One badge, but the permissions of every rung below it too. See the
    // class for why.
    (new Extend\User())
        ->permissionGroups(InheritPermissions::class),

    (new Extend\Console())
        ->command(SyncCommand::class),

    (new Extend\Settings())
        ->default(Ladder::DEMOTE, false)
        ->default(Ladder::EXEMPT_GROUPS, '[]')
        ->default(Ladder::SHOW_NAV, true)
        ->default(InheritPermissions::SETTING, true)
        ->default(Ladder::BANNER_ON_INDEX, false)
        ->serializeToForum('ladderShowNav', Ladder::SHOW_NAV, 'boolval')
        ->serializeToForum('ladderBannerOnIndex', Ladder::BANNER_ON_INDEX, 'boolval'),
];
