<?php

namespace Ernestdefoe\Ladder\Tests\integration\api;

use AntoineFr\Money\Event\MoneyUpdated;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

/** Ranks by a member's balance from antoinefr/flarum-ext-money: "1000 money makes you VIP". */
class MoneyTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        // Money's own migration adds a NOT NULL column with no default, which
        // only MySQL and MariaDB accept on a table that already has rows.
        if (! in_array(getenv('DB_DRIVER') ?: 'mysql', ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Money's migration installs on MySQL and MariaDB only.");
        }

        parent::setUp();

        $this->extension('antoinefr-money', 'ernestdefoe-ladder');
        $this->setting('ernestdefoe-ladder.metric', 'money');

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'saver', 'email' => 'saver@machine.local', 'is_email_confirmed' => 1, 'money' => 999.5],
                ['id' => 3, 'username' => 'rich', 'email' => 'rich@machine.local', 'is_email_confirmed' => 1, 'money' => 2500],
            ],
            Group::class => [
                ['id' => 10, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0],
                ['id' => 11, 'name_singular' => 'VIP', 'name_plural' => 'VIPs', 'is_hidden' => 0],
            ],
            'ladder_rungs' => [
                ['id' => 1, 'group_id' => 10, 'min_posts' => 0, 'owns_group' => 1],
                ['id' => 2, 'group_id' => 11, 'min_posts' => 1000, 'owns_group' => 1],
            ],
        ]);
    }

    /** @return int[] */
    private function groupsOf(int $user): array
    {
        return $this->database()->table('group_user')->where('user_id', $user)->orderBy('group_id')->pluck('group_id')->map(fn ($id) => (int) $id)->all();
    }

    /** As Money and its add-ons do: save the balance, then announce it. */
    private function changeMoney(int $id, float $money): void
    {
        $user = User::query()->find($id);
        $user->money = $money;
        $user->save();

        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new MoneyUpdated($user));
    }

    #[Test]
    public function a_re_rank_places_everyone_by_their_balance()
    {
        $ladder = json_decode((string) $this->send($this->request('GET', '/api/ladder', ['authenticatedAs' => 1]))->getBody(), true);
        $this->assertSame('money', $ladder['metric']);
        $this->assertTrue($ladder['moneyAvailable']);

        $this->send($this->request('POST', '/api/ladder/sync', ['authenticatedAs' => 1, 'json' => ['after' => 0]]));

        $this->assertSame([10], $this->groupsOf(2), '999.5 is not 1000: part of a coin never reaches a rung');
        $this->assertSame([11], $this->groupsOf(3));
    }

    #[Test]
    public function a_member_becomes_vip_the_moment_their_balance_reaches_the_threshold()
    {
        $this->app();
        $this->changeMoney(2, 1000);

        $this->assertSame([11], $this->groupsOf(2));
    }

    #[Test]
    public function spending_keeps_the_rank_unless_demotion_is_on()
    {
        $this->app();
        $this->changeMoney(3, 2500);
        $this->changeMoney(3, 10);
        $this->assertSame([11], $this->groupsOf(3), 'Demotion is off by default');

        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('ernestdefoe-ladder.demote', '1');
        $this->changeMoney(3, 5);
        $this->assertSame([10], $this->groupsOf(3));
    }

    #[Test]
    public function a_taken_threshold_is_refused_in_money_wording()
    {
        $response = $this->send($this->request('POST', '/api/ladder/rungs', [
            'authenticatedAs' => 1,
            'json' => ['name' => 'Whale', 'minPosts' => 1000],
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('min_money_taken', (string) $response->getBody());
    }
}
