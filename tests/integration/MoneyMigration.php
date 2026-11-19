<?php

namespace Ernestdefoe\Ladder\Tests\integration;

/**
 * antoinefr/flarum-ext-money's own migration adds a NOT NULL `money` column
 * with no default, which PostgreSQL and SQLite refuse on a `users` table that
 * already has rows. Money can't be installed there, so its tests are skipped
 * on those drivers instead of failing CI for a bug that isn't Ladder's.
 */
trait MoneyMigration
{
    protected function skipUnlessMoneyInstalls(): void
    {
        // The driver the test forum was installed with (composer test:setup).
        $config = @include __DIR__.'/../../vendor/flarum/testing/src/integration/tmp/config.php';
        $driver = is_array($config) ? ($config['database']['driver'] ?? '') : (getenv('DB_DRIVER') ?: 'sqlite');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("antoinefr/flarum-ext-money's migration adds a NOT NULL column with no default, which $driver refuses.");
        }
    }
}
