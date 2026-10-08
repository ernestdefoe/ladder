<?php

namespace Ernestdefoe\Ladder\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class LadderTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-ladder');

        $users = [
            ['id' => 2, 'username' => 'normal', 'email' => 'normal@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 7],
            ['id' => 3, 'username' => 'veteran', 'email' => 'veteran@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 25],
        ];
        $groupUser = [
            ['user_id' => 2, 'group_id' => 10],
            ['user_id' => 3, 'group_id' => 12],
        ];
        // Enough members on one rung to show up as an N+1 when listed.
        for ($id = 4; $id <= 12; $id++) {
            $users[] = ['id' => $id, 'username' => "member$id", 'email' => "member$id@machine.local", 'is_email_confirmed' => 1, 'comment_count' => 6];
            $groupUser[] = ['user_id' => $id, 'group_id' => 11];
        }

        // Posts to match each comment_count: the ladder recounts them.
        $posts = [];
        $n = 0;
        foreach ($users as $user) {
            for ($i = 0; $i < $user['comment_count']; $i++) {
                $n++;
                $posts[] = ['id' => $n, 'discussion_id' => 1, 'number' => $n, 'created_at' => Carbon::now(), 'user_id' => $user['id'], 'type' => 'comment', 'content' => '<t><p>Post</p></t>'];
            }
        }

        $this->prepareDatabase([
            User::class => $users,
            Discussion::class => [
                ['id' => 1, 'title' => 'Thread', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => $n],
            ],
            Post::class => $posts,
            Group::class => [
                ['id' => 10, 'name_singular' => 'Rookie', 'name_plural' => 'Rookies', 'is_hidden' => 0],
                ['id' => 11, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0],
                ['id' => 12, 'name_singular' => 'Veteran', 'name_plural' => 'Veterans', 'is_hidden' => 1],
                ['id' => 13, 'name_singular' => 'Unrelated', 'name_plural' => 'Unrelated', 'is_hidden' => 0],
            ],
            'group_user' => $groupUser,
            'ladder_rungs' => [
                ['id' => 1, 'group_id' => 10, 'min_posts' => 0, 'owns_group' => 1],
                ['id' => 2, 'group_id' => 11, 'min_posts' => 5, 'owns_group' => 0],
                ['id' => 3, 'group_id' => 12, 'min_posts' => 20, 'owns_group' => 1],
            ],
        ]);
    }

    private function get(string $path, ?int $actor = null): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : []));
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function save(int $actor, array $body, ?int $id = null): ResponseInterface
    {
        return $this->send($this->request($id ? 'PATCH' : 'POST', '/api/ladder/rungs'.($id ? "/$id" : ''), ['authenticatedAs' => $actor, 'json' => $body]));
    }

    /** @return int[] */
    private function groupsOf(int $user): array
    {
        return $this->database()->table('group_user')->where('user_id', $user)->orderBy('group_id')->pluck('group_id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function the_ranks_page_hides_a_hidden_rung_from_all_but_admins()
    {
        $guest = $this->json($this->get('/api/ladder'));
        $this->assertSame(['Rookie', 'Regular'], array_column($guest['rungs'], 'name'));
        $this->assertNull($guest['viewer']);

        $admin = $this->json($this->get('/api/ladder', 1));
        $this->assertSame(['Rookie', 'Regular', 'Veteran'], array_column($admin['rungs'], 'name'));
    }

    #[Test]
    public function a_member_holds_exactly_the_rung_their_posts_earned()
    {
        $body = $this->json($this->get('/api/ladder', 2));

        $this->assertSame([11], $this->groupsOf(2), '7 posts: Regular, and the Rookie badge is gone');
        $this->assertSame(['score' => 7, 'groupId' => 11, 'exempt' => false], $body['viewer']);
    }

    #[Test]
    public function every_change_to_the_ladder_is_for_admins_only()
    {
        $this->assertSame(403, $this->save(2, ['name' => 'Elite', 'minPosts' => 50])->getStatusCode());
        $this->assertSame(403, $this->save(2, ['minPosts' => 1], 2)->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', '/api/ladder/rungs/2', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/ladder/sync', ['authenticatedAs' => 2, 'json' => []]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', '/api/ladder/rungs/2/image', ['authenticatedAs' => 2]))->getStatusCode());

        $this->assertSame(3, $this->database()->table('ladder_rungs')->count());
        $this->assertSame(5, (int) $this->database()->table('ladder_rungs')->where('id', 2)->value('min_posts'));
    }

    #[Test]
    public function an_admin_adds_a_rung_with_a_group_of_its_own()
    {
        $response = $this->save(1, ['name' => 'Elite', 'namePlural' => 'Elites', 'minPosts' => 50, 'color' => '#ff0000']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rung = $this->database()->table('ladder_rungs')->where('min_posts', 50)->first();
        $group = Group::query()->findOrFail($rung->group_id);
        $this->assertSame(['Elite', 'Elites', '#ff0000'], [$group->name_singular, $group->name_plural, $group->color]);
        $this->assertTrue((bool) $rung->owns_group);
    }

    #[Test]
    public function a_rejected_rung_leaves_nothing_behind()
    {
        $this->app();
        $groups = Group::query()->count();

        $this->assertSame(422, $this->save(1, ['name' => 'Twin', 'minPosts' => 5])->getStatusCode(), 'A threshold already taken');
        $this->assertSame(422, $this->save(1, ['name' => 'Bad', 'minPosts' => 60, 'color' => 'red; x'])->getStatusCode(), 'Not a colour');
        $this->assertSame(422, $this->save(1, ['groupId' => Group::ADMINISTRATOR_ID, 'minPosts' => 70])->getStatusCode(), 'A core group');
        $this->assertSame(422, $this->save(1, ['groupId' => 11, 'minPosts' => 80])->getStatusCode(), 'A group already on the ladder');
        $this->assertSame(422, $this->save(1, ['name' => 'Neg', 'minPosts' => -1])->getStatusCode());

        $this->assertSame($groups, Group::query()->count(), 'No orphan group');
        $this->assertSame(3, $this->database()->table('ladder_rungs')->count());
    }

    #[Test]
    public function deleting_a_rung_deletes_only_a_group_it_created()
    {
        $this->assertSame(200, $this->send($this->request('DELETE', '/api/ladder/rungs/2', ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertNotNull(Group::query()->find(11), 'An existing group adopted as a rung is kept');

        $this->assertSame(200, $this->send($this->request('DELETE', '/api/ladder/rungs/1', ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertNull(Group::query()->find(10), 'A group the rung created goes with it');
    }

    #[Test]
    public function a_profile_shows_its_standing_and_a_list_does_not()
    {
        $this->get('/api/ladder', 2);

        $member = $this->json($this->get('/api/users/2', 2))['data']['attributes']['ladderStanding'];
        $this->assertSame(7, $member['score']);
        $this->assertSame('Regular', $member['current']['name']);
        $this->assertNull($member['next'], 'The next rung is hidden from a member');

        $admin = $this->json($this->get('/api/users/2', 1))['data']['attributes']['ladderStanding'];
        $this->assertSame('Veteran', $admin['next']['name']);

        $list = $this->json($this->get('/api/users', 1))['data'];
        $this->assertGreaterThan(10, count($list));
        foreach ($list as $user) {
            $this->assertArrayNotHasKey('ladderStanding', $user['attributes']);
        }
    }

    #[Test]
    public function a_rung_carries_the_permissions_of_the_rungs_below_it()
    {
        $this->app();
        $this->database()->table('group_permission')->insert(['group_id' => 10, 'permission' => 'ladder.test.rookie']);

        $this->assertTrue(User::query()->findOrFail(3)->hasPermission('ladder.test.rookie'), 'A Veteran keeps what a Rookie may do');
        $this->assertFalse(User::query()->findOrFail(4)->hasPermission('ladder.test.nothing'));
    }

    #[Test]
    public function inherited_permissions_can_be_turned_off()
    {
        $this->setting('ernestdefoe-ladder.inherit_permissions', false);
        $this->app();
        $this->database()->table('group_permission')->insert(['group_id' => 10, 'permission' => 'ladder.test.rookie']);

        $this->assertFalse(User::query()->findOrFail(3)->hasPermission('ladder.test.rookie'));
    }

    #[Test]
    public function a_rungs_members_are_listed_for_those_who_may_search_users()
    {
        $this->assertSame(403, $this->get('/api/ladder/rungs/2/members')->getStatusCode());

        $response = $this->get('/api/ladder/rungs/2/members', 2);
        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(9, $body['total']);
        $this->assertSame([6], array_values(array_unique(array_column($body['members'], 'score'))));

        $this->assertSame(404, $this->get('/api/ladder/rungs/3/members', 2)->getStatusCode(), 'A hidden rung');
        $this->assertSame(200, $this->get('/api/ladder/rungs/3/members', 1)->getStatusCode());
    }
}
