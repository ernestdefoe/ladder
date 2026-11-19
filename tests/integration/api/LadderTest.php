<?php

namespace Ernestdefoe\Ladder\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\UploadedFile;
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
        $data = ['type' => 'ladder-rungs', 'attributes' => $body] + ($id ? ['id' => (string) $id] : []);

        return $this->send($this->request($id ? 'PATCH' : 'POST', '/api/ladder-rungs'.($id ? "/$id" : ''), ['authenticatedAs' => $actor, 'json' => ['data' => $data]]));
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
        $this->assertSame(403, $this->send($this->request('DELETE', '/api/ladder-rungs/2', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/ladder/sync', ['authenticatedAs' => 2, 'json' => []]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', '/api/ladder/rungs/2/image', ['authenticatedAs' => 2]))->getStatusCode());

        $this->assertSame(3, $this->database()->table('ladder_rungs')->count());
        $this->assertSame(5, (int) $this->database()->table('ladder_rungs')->where('id', 2)->value('min_posts'));
    }

    #[Test]
    public function an_admin_adds_a_rung_with_a_group_of_its_own()
    {
        $response = $this->save(1, ['name' => 'Elite', 'namePlural' => 'Elites', 'minPosts' => 50, 'color' => '#ff0000']);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $rung = $this->database()->table('ladder_rungs')->where('min_posts', 50)->first();
        $this->assertSame((string) $rung->id, $this->json($response)['data']['id']);
        $group = Group::query()->findOrFail($rung->group_id);
        $this->assertSame(['Elite', 'Elites', '#ff0000'], [$group->name_singular, $group->name_plural, $group->color]);
        $this->assertTrue((bool) $rung->owns_group);
    }

    #[Test]
    public function an_admin_edits_a_rung_and_its_group_and_can_adopt_an_existing_group()
    {
        $response = $this->save(1, ['name' => 'Starter', 'namePlural' => null, 'icon' => 'fas fa-seedling', 'minPosts' => 1, 'description' => '  Just joined. '], 1);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $group = Group::query()->findOrFail(10);
        $this->assertSame(['Starter', 'Starter', 'fas fa-seedling'], [$group->name_singular, $group->name_plural, $group->icon], 'No plural: named the same both ways');
        $rung = $this->database()->table('ladder_rungs')->where('id', 1)->first();
        $this->assertSame([1, 'Just joined.'], [(int) $rung->min_posts, $rung->description]);

        $this->assertSame(200, $this->save(1, ['minPosts' => 1], 1)->getStatusCode(), 'Keeping its own threshold is not a clash');

        $adopted = $this->save(1, ['groupId' => 13, 'minPosts' => 40]);
        $this->assertSame(201, $adopted->getStatusCode(), (string) $adopted->getBody());
        $this->assertSame('Unrelated', Group::query()->findOrFail(13)->name_singular, 'An adopted group keeps its name');
        $this->assertFalse((bool) $this->database()->table('ladder_rungs')->where('group_id', 13)->value('owns_group'));
    }

    #[Test]
    public function a_rejected_rung_leaves_nothing_behind()
    {
        $this->app();
        $groups = Group::query()->count();

        $twin = $this->save(1, ['name' => 'Twin', 'minPosts' => 5]);
        $this->assertSame(422, $twin->getStatusCode(), 'A threshold already taken');
        $this->assertStringContainsString('min_posts_taken', (string) $twin->getBody(), 'In the ladder\'s own words');
        $this->assertSame(422, $this->save(1, ['name' => 'Bad', 'minPosts' => 60, 'color' => 'red; x'])->getStatusCode(), 'Not a colour');
        $this->assertSame(422, $this->save(1, ['groupId' => Group::ADMINISTRATOR_ID, 'minPosts' => 70])->getStatusCode(), 'A core group');
        $this->assertSame(422, $this->save(1, ['groupId' => 11, 'minPosts' => 80])->getStatusCode(), 'A group already on the ladder');
        $this->assertSame(422, $this->save(1, ['name' => 'Neg', 'minPosts' => -1])->getStatusCode());
        $this->assertSame(422, $this->save(1, ['minPosts' => 90])->getStatusCode(), 'A new group needs a name');
        $this->assertSame(422, $this->save(1, ['name' => 'Half', 'minPosts' => 'ten'])->getStatusCode(), 'Not a whole number');

        $this->assertSame($groups, Group::query()->count(), 'No orphan group');
        $this->assertSame(3, $this->database()->table('ladder_rungs')->count());
    }

    #[Test]
    public function a_new_picture_replaces_the_old_one_and_removing_it_leaves_no_file()
    {
        $disk = $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');
        $upload = function (): ResponseInterface {
            $png = tempnam(sys_get_temp_dir(), 'ladder');
            $img = imagecreatetruecolor(4, 4);
            imagepng($img, $png);

            return $this->send($this->request('POST', '/api/ladder/rungs/2/image', ['authenticatedAs' => 1])
                ->withUploadedFiles(['image' => new UploadedFile($png, filesize($png), UPLOAD_ERR_OK, 'r.png', 'image/png')]));
        };
        $path = fn () => $this->database()->table('ladder_rungs')->where('id', 2)->value('image_path');

        $this->assertSame(204, $upload()->getStatusCode());
        $first = $path();
        $this->assertTrue($disk->exists($first));

        $this->assertSame(204, $upload()->getStatusCode());
        $second = $path();
        $this->assertNotSame($first, $second);
        $this->assertTrue($disk->exists($second));
        $this->assertFalse($disk->exists($first), 'The old picture is deleted');

        $text = tempnam(sys_get_temp_dir(), 'ladder');
        file_put_contents($text, 'not an image');
        $this->assertSame(422, $this->send($this->request('POST', '/api/ladder/rungs/2/image', ['authenticatedAs' => 1])
            ->withUploadedFiles(['image' => new UploadedFile($text, 12, UPLOAD_ERR_OK, 'r.png', 'image/png')]))->getStatusCode(), 'A type sniffed from the file, not claimed');

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/ladder/rungs/2/image', ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertNull($path());
        $this->assertFalse($disk->exists($second));
    }

    #[Test]
    public function the_queued_full_re_rank_runs_on_a_forum_without_a_worker()
    {
        $this->app();
        $before = $this->groupsOf(3);

        // Flarum's default sync queue runs the job on the spot. Outside points
        // mode it stands down and touches nobody.
        $this->app()->getContainer()->make(\Illuminate\Contracts\Queue\Queue::class)->push(new \Ernestdefoe\Ladder\Job\SyncLadder());

        $this->assertSame($before, $this->groupsOf(3));
    }

    #[Test]
    public function deleting_a_rung_deletes_only_a_group_it_created()
    {
        $this->assertSame(204, $this->send($this->request('DELETE', '/api/ladder-rungs/2', ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertNotNull(Group::query()->find(11), 'An existing group adopted as a rung is kept');

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/ladder-rungs/1', ['authenticatedAs' => 1]))->getStatusCode());
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
        $members = fn (int $rung, ?int $actor) => $this->send($this->request('GET', '/api/users', $actor ? ['authenticatedAs' => $actor] : [])
            ->withQueryParams(['filter' => ['ladderRung' => (string) $rung], 'sort' => '-ladderScore', 'page' => ['limit' => 30]]));

        $this->assertContains($members(2, null)->getStatusCode(), [401, 403], 'Core\'s searchUsers gate');

        $response = $members(2, 2);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $this->assertSame(9, $body['meta']['page']['total'] ?? count($body['data']));
        $this->assertSame([6], array_values(array_unique(array_column(array_column($body['data'], 'attributes'), 'ladderScore'))));

        $hidden = $members(3, 2);
        $this->assertSame([], $this->json($hidden)['data'] ?? null, (string) $hidden->getBody());
        $this->assertNotEmpty($this->json($members(3, 1))['data'], 'except to admins');

        $plain = $this->json($this->send($this->request('GET', '/api/users', ['authenticatedAs' => 1])));
        $this->assertArrayNotHasKey('ladderScore', $plain['data'][0]['attributes'], 'No score outside a rank\'s list');
        $this->assertSame(400, $this->send($this->request('GET', '/api/users', ['authenticatedAs' => 1])->withQueryParams(['sort' => 'ladderScore']))->getStatusCode(), 'The sort needs the filter');
    }

}
