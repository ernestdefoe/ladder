<?php

/*
 * Ladder: ranks, one rung at a time.
 *
 * Each rung is an ordinary Flarum group with a threshold: posts by default, or
 * points, votes, money or a Renown level from another extension. A member
 * holds exactly one rung group, the one their score has earned, and moves up
 * when they cross the next threshold. Every other group they belong to is
 * left alone.
 */

use Ernestdefoe\Ladder\Api;
use Ernestdefoe\Ladder\Search;
use Ernestdefoe\Ladder\Console\SeedCommand;
use Ernestdefoe\Ladder\Console\SyncCommand;
use Ernestdefoe\Ladder\InheritPermissions;
use Ernestdefoe\Ladder\Listener\RankMember;
use Ernestdefoe\Ladder\Middleware\RecheckActiveMember;
use Ernestdefoe\Ladder\Notification\PromotedBlueprint;
use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\User\Search\UserSearcher;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the Ranks page is a chunk in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/common.less')
        ->css(__DIR__.'/less/forum.less')
        ->route('/ranks', 'ladder.ranks'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/common.less')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    // Rungs are created, edited and removed through their resource.
    new Extend\ApiResource(Api\Resource\RungResource::class),

    // Plain handlers, each for something that isn't a record: the computed
    // ladder, a re-rank slice, and a file upload. See each class.
    (new Extend\Routes('api'))
        ->get('/ladder', 'ladder.show', Api\ShowLadder::class)
        ->post('/ladder/sync', 'ladder.sync', Api\SyncLadder::class)
        ->post('/ladder/rungs/{id}/image', 'ladder.rungs.image', Api\UploadRungImage::class)
        ->delete('/ladder/rungs/{id}/image', 'ladder.rungs.image.delete', Api\DeleteRungImage::class),

    // A rank's members: /api/users?filter[ladderRung]=<id>&sort=-ladderScore.
    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\UserStanding::class)
        ->sorts(Api\LadderScoreSort::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(UserSearcher::class, Search\RungFilter::class),

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
        ->command(SyncCommand::class)
        ->command(SeedCommand::class),

    // 🚨 String literals, not Ladder:: constants: extend.php runs while a
    // deploy swaps vendor/, and a class constant read here fatals every
    // request until it finishes.
    (new Extend\Settings())
        ->default('ernestdefoe-ladder.demote', false)
        ->default('ernestdefoe-ladder.exempt_groups', '[]')
        ->default('ernestdefoe-ladder.show_nav', true)
        ->default('ernestdefoe-ladder.inherit_permissions', true)
        ->default('ernestdefoe-ladder.banner_on_index', false)
        ->default('ernestdefoe-ladder.metric', 'posts')
        ->serializeToForum('ladderShowNav', 'ernestdefoe-ladder.show_nav', 'boolval')
        ->serializeToForum('ladderBannerOnIndex', 'ernestdefoe-ladder.banner_on_index', 'boolval'),

    // The other extensions a ladder can rank by, each wired only while it is
    // enabled. Ladder falls back to post counts when the chosen one is off.
    (new Extend\Conditional())
        ->whenExtensionEnabled('huseyinfiliz-leaderboard', fn () => [
            (new Extend\Event())
                ->listen(\HuseyinFiliz\Leaderboard\Event\PointsUpdated::class, [RankMember::class, 'pointsUpdated'])
                ->listen(\HuseyinFiliz\Leaderboard\Event\PointsRecalculated::class, [RankMember::class, 'pointsRecalculated']),
            // Points mode: re-rank a member as they use the forum. See the class.
            (new Extend\Middleware('forum'))->add(RecheckActiveMember::class),
            (new Extend\Middleware('api'))->add(RecheckActiveMember::class),
        ])
        ->whenExtensionEnabled('fof-gamification', fn () => [
            (new Extend\Event())->listen(\FoF\Gamification\Events\UserPointsUpdated::class, [RankMember::class, 'votesUpdated']),
        ])
        ->whenExtensionEnabled('antoinefr-money', fn () => [
            (new Extend\Event())->listen(\AntoineFr\Money\Event\MoneyUpdated::class, [RankMember::class, 'moneyUpdated']),
        ])
        ->whenExtensionEnabled('ernestdefoe-renown', fn () => [
            (new Extend\Event())->listen(\ErnestDefoe\Renown\Event\LevelChanged::class, [RankMember::class, 'levelChanged']),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())->listen(\Flarum\Approval\Event\PostWasApproved::class, [RankMember::class, 'postApproved']),
        ]),
];
