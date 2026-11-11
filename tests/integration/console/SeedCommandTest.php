<?php

namespace Ernestdefoe\Ladder\Tests\integration\console;

use Flarum\Notification\Notification;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * php flarum ladder:seed: the starter ladder, a ladder from a file, what it
 * refuses (a ladder already built, a bad file, a level Renown can't reach),
 * and that members are ranked on it quietly.
 */
class SeedCommandTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-renown', 'ernestdefoe-ladder');

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'quiet', 'email' => 'quiet@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 0],
                ['id' => 3, 'username' => 'chatty', 'email' => 'chatty@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 60],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function seed(array $options = []): string
    {
        return $this->runCommand(['command' => 'ladder:seed'] + $options);
    }

    /** @return list<int> */
    private function thresholds(): array
    {
        return $this->database()->table('ladder_rungs')->orderBy('min_posts')->pluck('min_posts')->map(fn ($n) => (int) $n)->all();
    }

    private function json(mixed $rungs): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'ladder').'.json';
        file_put_contents($this->file, is_string($rungs) ? $rungs : json_encode($rungs));

        return $this->file;
    }

    #[Test]
    public function the_starter_ladder_is_laid_down_on_an_empty_forum_and_members_are_ranked_quietly(): void
    {
        $output = $this->seed();

        $this->assertStringContainsString('Created 6 rungs', $output);
        $this->assertSame([0, 10, 50, 150, 500, 1500], $this->thresholds());

        // 60 posts: the 50-post rung ("Regular"), and nobody was told.
        $regular = $this->database()->table('ladder_rungs')->where('min_posts', 50)->value('group_id');
        $this->assertTrue($this->database()->table('group_user')->where('user_id', 3)->where('group_id', $regular)->exists());
        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function a_built_ladder_is_refused_without_force(): void
    {
        $this->seed();
        $output = $this->seed();

        $this->assertStringContainsString('already has 6 rungs', $output);
        $this->assertCount(6, $this->thresholds());
    }

    #[Test]
    public function force_adds_only_the_thresholds_not_taken(): void
    {
        $this->seed();
        $output = $this->seed(['--force' => true, '--file' => $this->json([
            ['name' => 'Elder', 'minPosts' => 3000, 'color' => '#123456'],
            ['name' => 'Duplicate member', 'minPosts' => 10],
        ])]);

        $this->assertStringContainsString('Skipping Duplicate member', $output);
        $this->assertStringContainsString('Created 1 rung', $output);
        $this->assertSame([0, 10, 50, 150, 500, 1500, 3000], $this->thresholds());
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $groups = $this->database()->table('groups')->count();
        $output = $this->seed(['--dry-run' => true]);

        $this->assertStringContainsString('nothing written', $output);
        $this->assertSame([], $this->thresholds());
        $this->assertSame($groups, $this->database()->table('groups')->count());
    }

    #[Test]
    public function a_bad_file_is_refused_and_writes_nothing(): void
    {
        $this->assertStringContainsString('Could not read', $this->seed(['--file' => $this->json('{ not json')]));
        $this->assertStringContainsString('hex colour', $this->seed(['--file' => $this->json([['name' => 'Gold', 'minPosts' => 5, 'color' => 'gold']])]));
        $this->assertStringContainsString('two rungs both sit at 5', $this->seed(['--file' => $this->json([['name' => 'A', 'minPosts' => 5], ['name' => 'B', 'minPosts' => 5]])]));
        $this->assertSame([], $this->thresholds());
    }

    #[Test]
    public function ranking_by_renown_seeds_levels_and_refuses_one_above_100(): void
    {
        $this->setting('ernestdefoe-ladder.metric', 'renown');

        $this->assertStringContainsString('from 0 to 100', $this->seed(['--file' => $this->json([['name' => 'Beyond', 'minPosts' => 150]])]));
        $this->assertSame([], $this->thresholds());

        $this->assertStringContainsString('Created 6 rungs', $this->seed());
        $this->assertSame([1, 10, 25, 50, 75, 100], $this->thresholds());
    }

    #[Test]
    public function ranking_by_money_needs_a_file(): void
    {
        // Money's migration installs on MySQL and MariaDB only (see MoneyTest).
        if (! in_array(getenv('DB_DRIVER') ?: 'mysql', ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Money's migration installs on MySQL and MariaDB only.");
        }
        $this->extension('antoinefr-money');
        $this->setting('ernestdefoe-ladder.metric', 'money');

        $this->assertStringContainsString('--file', $this->seed());
        $this->assertSame([], $this->thresholds());
        $this->assertStringContainsString('Created 1 rung', $this->seed(['--file' => $this->json([['name' => 'VIP', 'minPosts' => 1000]])]));
    }
}
