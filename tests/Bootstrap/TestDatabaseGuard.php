<?php

namespace App\Tests\Bootstrap;

/**
 * @deprecated moved to App\Infrastructure\TestDatabaseGuard (production
 * commands reference it; classes in src/ must not depend on tests/).
 * This alias keeps existing test references working.
 */
class_alias(\App\Infrastructure\TestDatabaseGuard::class, TestDatabaseGuard::class);
