<?php

namespace Ernestdefoe\Ladder\Console;

use Ernestdefoe\Ladder\InheritPermissions;
use Ernestdefoe\Ladder\Ladder;
use Ernestdefoe\Ladder\Rung;
use Flarum\Console\AbstractCommand;
use Flarum\Group\Group;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;

/**
 * php flarum ladder:seed — build a ladder from a terminal.
 *
 * The admin page is where a ladder is normally made, one rung at a time with
 * a preview. This is for the other cases: a forum being installed by script,
 * a staging site that should match production, or an admin who would rather
 * keep their ladder in a file under version control than retype eight rungs.
 *
 * Writes nothing unless the ladder is empty, so running it on a forum that
 * already has ranks cannot quietly rearrange them.
 */
class SeedCommand extends AbstractCommand
{
    /**
     * A starter ladder, deliberately plain.
     *
     * The names suit any forum, which is the point: it exists so a new install
     * has something to look at within a minute of switching Ladder on. The
     * thresholds are a guess at an average forum and worth tuning — see the
     * note the command prints when it finishes.
     *
     * @var list<array{name: string, minPosts: int, icon: string, color: string, description: string}>
     */
    private const STARTER = [
        ['name' => 'Newcomer', 'minPosts' => 0, 'icon' => 'fas fa-seedling', 'color' => '#64748b', 'description' => 'Just joined.'],
        ['name' => 'Member', 'minPosts' => 10, 'icon' => 'fas fa-user', 'color' => '#0ea5e9', 'description' => 'Found their feet.'],
        ['name' => 'Regular', 'minPosts' => 50, 'icon' => 'fas fa-comments', 'color' => '#10b981', 'description' => 'Here most weeks.'],
        ['name' => 'Contributor', 'minPosts' => 150, 'icon' => 'fas fa-star', 'color' => '#8b5cf6', 'description' => 'Gives back more than they take.'],
        ['name' => 'Veteran', 'minPosts' => 500, 'icon' => 'fas fa-shield-halved', 'color' => '#f59e0b', 'description' => 'Knows where everything is.'],
        ['name' => 'Legend', 'minPosts' => 1500, 'icon' => 'fas fa-crown', 'color' => '#e11d48', 'description' => 'Part of the furniture.'],
    ];

    /**
     * The same six ranks at Renown levels: a member reaches Level 10 in about
     * two weeks and Level 100 in about two years of steady activity.
     */
    private const RENOWN_LEVELS = [1, 10, 25, 50, 75, 100];

    public function __construct(
        private Ladder $ladder,
        private ConnectionInterface $db,
        private Cache $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ladder:seed')
            ->setDescription('Create a starter ladder, or one from a JSON file, and rank every member on it.')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A JSON file describing the rungs, instead of the built-in starter ladder.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created and write nothing.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Add rungs even if the ladder already has some. Thresholds already taken are left alone.');
    }

    protected function fire(): int
    {
        $existing = $this->ladder->rungs();
        $force = (bool) $this->input->getOption('force');

        if ($existing->isNotEmpty() && ! $force) {
            $this->error(sprintf(
                'The ladder already has %d %s. Edit it in Admin → Ladder, or pass --force to add these rungs alongside the ones already there.',
                $existing->count(),
                $existing->count() === 1 ? 'rung' : 'rungs',
            ));

            return 1;
        }

        try {
            $rungs = $this->read();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        /*
         * 🚨 A threshold is a rung's place on the ladder, so two rungs can
         * never share one. Under --force the ones already taken are skipped
         * rather than refused: the command is then worth running twice, once
         * to lay the ladder down and again after adding a rung to the file.
         */
        // 🚨 Straight from the table, not from the loaded ladder: rungs()
        // drops any rung whose group has gone, and a threshold held by one of
        // those is still taken as far as the ladder's own rule is concerned.
        $taken = Rung::query()->pluck('min_posts')->map(fn ($n) => (int) $n)->all();
        $skipped = [];

        $rungs = array_values(array_filter($rungs, function (array $rung) use ($taken, &$skipped) {
            if (in_array($rung['minPosts'], $taken, true)) {
                $skipped[] = $rung;

                return false;
            }

            return true;
        }));

        foreach ($skipped as $rung) {
            $this->info(sprintf('Skipping %s: a rung at %d %s already exists.', $rung['name'], $rung['minPosts'], $this->unit()));
        }

        if (! $rungs) {
            $this->info('Nothing left to create.');

            return 0;
        }

        if ($this->input->getOption('dry-run')) {
            $this->info('Would create these rungs (--dry-run, nothing written):');

            // 🚨 Symfony's own table: AbstractCommand has no table() helper.
            (new Table($this->output))
                ->setHeaders(['Rank', ucfirst($this->unit()), 'Icon', 'Colour'])
                ->setRows(array_map(
                    fn (array $r) => [$r['name'], (string) $r['minPosts'], $r['icon'] ?? '—', $r['color'] ?? '—'],
                    $rungs,
                ))
                ->render();

            return 0;
        }

        $this->db->transaction(function () use ($rungs) {
            foreach ($rungs as $rung) {
                $group = new Group();
                $group->name_singular = $rung['name'];
                $group->name_plural = $rung['namePlural'] ?? $rung['name'];
                $group->icon = $rung['icon'];
                $group->color = $rung['color'];
                $group->is_hidden = false;
                $group->save();

                $row = new Rung();
                $row->group_id = $group->id;
                $row->min_posts = $rung['minPosts'];
                $row->description = $rung['description'];
                // Ladder made this group, so removing the rung removes it too.
                $row->owns_group = true;
                $row->save();
            }
        });

        // The ladder is cached per request, and InheritPermissions caches the
        // rung map until a write clears it. Both are now out of date.
        $this->ladder->forget();
        $this->cache->forget(InheritPermissions::CACHE_KEY);

        $this->info(sprintf('Created %d %s.', count($rungs), count($rungs) === 1 ? 'rung' : 'rungs'));

        /*
         * Quiet by design: syncAll() sends no notifications, so seeding a
         * ladder onto an established forum doesn't alert every member that
         * they have been promoted.
         */
        $this->ladder->syncAll();

        $this->info('Ranked every member on it. Nobody was notified.');
        $this->info('Check the thresholds against your forum in Admin → Ladder — a ladder nobody can climb is just a badge.');

        return 0;
    }

    /**
     * The rungs to create, from --file or the built-in starter ladder, with
     * every field validated the way the admin page validates it.
     *
     * @return list<array{name: string, namePlural?: string, minPosts: int, icon: string|null, color: string|null, description: string|null}>
     */
    private function read(): array
    {
        $file = $this->input->getOption('file');
        $raw = $file === null ? $this->starter() : $this->decode((string) $file);
        $rungs = [];
        $seen = [];

        foreach ($raw as $i => $rung) {
            $at = $file === null ? "rung {$i}" : "rung {$i} of {$file}";

            if (! is_array($rung)) {
                throw new \RuntimeException("Could not read {$at}: each rung must be an object.");
            }

            $name = trim((string) ($rung['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 100) {
                throw new \RuntimeException("Could not read {$at}: give the rank a name, up to 100 characters.");
            }

            $min = $rung['minPosts'] ?? null;

            // The rung editor's own rule (Ladder::validThreshold), so a file can't seed a rung the editor would refuse.
            if (! $this->ladder->validThreshold($min)) {
                throw new \RuntimeException($this->ladder->metric() === 'renown'
                    ? "Could not read {$at} ({$name}): \"minPosts\" is a Renown level here, a whole number from 0 to 100."
                    : "Could not read {$at} ({$name}): \"minPosts\" must be a whole number, 0 or more.");
            }

            $min = (int) $min;

            if (in_array($min, $seen, true)) {
                throw new \RuntimeException("Could not read {$at} ({$name}): two rungs both sit at {$min} {$this->unit()}, and a threshold is what puts a rung in its place.");
            }

            $seen[] = $min;

            $color = $this->clean($rung['color'] ?? null, 20);

            if ($color !== null && ! preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                throw new \RuntimeException("Could not read {$at} ({$name}): \"color\" must be a hex colour such as #7c3aed.");
            }

            $entry = [
                'name' => $name,
                'minPosts' => $min,
                'icon' => $this->clean($rung['icon'] ?? null, 100),
                'color' => $color,
                'description' => $this->clean($rung['description'] ?? null, 500),
            ];

            $plural = $this->clean($rung['namePlural'] ?? null, 100);

            if ($plural !== null) {
                $entry['namePlural'] = $plural;
            }

            $rungs[] = $entry;
        }

        if (! $rungs) {
            throw new \RuntimeException($file === null ? 'The starter ladder is empty.' : "{$file} describes no rungs.");
        }

        usort($rungs, fn (array $a, array $b) => $a['minPosts'] <=> $b['minPosts']);

        return $rungs;
    }

    /**
     * The starter ladder in the ladder's own unit. Money is left out: what a
     * rank should cost depends on each forum's economy, so it needs a file.
     *
     * @return list<array<string, mixed>>
     */
    private function starter(): array
    {
        return match ($this->ladder->metric()) {
            'renown' => array_map(fn (array $rung, int $level) => ['minPosts' => $level] + $rung, self::STARTER, self::RENOWN_LEVELS),
            'money' => throw new \RuntimeException('The starter ladder counts posts, and what a rank should cost depends on your forum\'s money. Describe your ladder in a JSON file and pass it with --file.'),
            default => self::STARTER,
        };
    }

    /**
     * A ladder read from JSON: either a bare list of rungs, or an object with
     * a "rungs" key, which is the shape the API already returns.
     *
     * @return array<int, mixed>
     */
    private function decode(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException("Could not read {$path}: no such file, or it isn't readable.");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new \RuntimeException("Could not read {$path}: ".(json_last_error_msg() ?: 'it is not valid JSON.'));
        }

        if (isset($data['rungs']) && is_array($data['rungs'])) {
            $data = $data['rungs'];
        }

        return array_values($data);
    }

    private function clean(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** The word the ladder's thresholds are counted in. */
    private function unit(): string
    {
        return match ($this->ladder->metric()) {
            'posts', 'money' => $this->ladder->metric(),
            'renown' => 'level',
            default => 'points',
        };
    }
}
