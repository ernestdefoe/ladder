<?php

namespace Ernestdefoe\Ladder\Tests\integration\api;

use ErnestDefoe\Renown\Curve;
use ErnestDefoe\Renown\Ledger;
use Flarum\Group\Group;
use Flarum\Notification\Notification;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** Ranks by a member's Renown level: "Level 10 makes you a Regular". */
class RenownTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-renown', 'ernestdefoe-ladder');
        $this->setting('ernestdefoe-ladder.metric', 'renown');

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'newbie', 'email' => 'newbie@machine.local', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'veteran', 'email' => 'veteran@machine.local', 'is_email_confirmed' => 1, 'renown_level' => 42, 'renown_xp' => Curve::xpFor(42), 'renown_lifetime_xp' => Curve::xpFor(42)],
            ],
            Group::class => [
                ['id' => 10, 'name_singular' => 'Newcomer', 'name_plural' => 'Newcomers', 'is_hidden' => 0],
                ['id' => 11, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0],
            ],
            'ladder_rungs' => [
                ['id' => 1, 'group_id' => 10, 'min_posts' => 0, 'owns_group' => 1],
                ['id' => 2, 'group_id' => 11, 'min_posts' => 10, 'owns_group' => 1],
            ],
        ]);
    }

    /** @return int[] */
    private function groupsOf(int $user): array
    {
        return $this->database()->table('group_user')->where('user_id', $user)->whereIn('group_id', [10, 11])->orderBy('group_id')->pluck('group_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Through Renown's own ledger, which announces the level change. */
    private function earn(int $id, int $xp, bool $quiet = false): void
    {
        $ledger = $this->app()->getContainer()->make(Ledger::class);
        $user = User::query()->find($id);
        $quiet
            ? $ledger->award($user, 'reply', random_int(1, 1000000), 0, $xp, ['capped' => false])
            : $ledger->adjust($user, $xp, User::query()->find(1), 'Test');
    }

    #[Test]
    public function a_re_rank_places_everyone_by_their_renown_level()
    {
        $ladder = json_decode((string) $this->send($this->request('GET', '/api/ladder', ['authenticatedAs' => 1]))->getBody(), true);
        $this->assertSame('renown', $ladder['metric']);
        $this->assertTrue($ladder['renownAvailable']);

        $this->send($this->request('POST', '/api/ladder/sync', ['authenticatedAs' => 1, 'json' => ['after' => 0]]));

        $this->assertSame([10], $this->groupsOf(2));
        $this->assertSame([11], $this->groupsOf(3));
    }

    #[Test]
    public function a_member_moves_up_the_moment_their_level_reaches_the_threshold_and_is_told()
    {
        $this->app();
        $this->earn(2, Curve::xpFor(10));

        $this->assertSame([11], $this->groupsOf(2));
        $this->assertSame(1, Notification::query()->where('user_id', 2)->where('type', 'ladderPromoted')->count());
    }

    #[Test]
    public function a_quiet_level_change_re_ranks_without_an_alert()
    {
        $this->app();
        $this->earn(2, Curve::xpFor(10), quiet: true);

        $this->assertSame([11], $this->groupsOf(2));
        $this->assertSame(0, Notification::query()->where('user_id', 2)->count());
    }

    #[Test]
    public function a_threshold_past_level_100_is_refused()
    {
        $response = $this->send($this->request('POST', '/api/ladder/rungs', [
            'authenticatedAs' => 1,
            'json' => ['name' => 'Beyond', 'minPosts' => 101],
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('min_level_invalid', (string) $response->getBody());
    }
}
