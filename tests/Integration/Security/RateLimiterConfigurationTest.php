<?php

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Enforces the configured rate-limiter budgets used to protect the
 * registration and webhook endpoints. The limiter factories are the exact
 * wired services the controllers consume; the array-cache storage in the
 * test env accumulates within a single container lifetime, so this verifies
 * the real budgets (limits per interval) deterministically.
 */
class RateLimiterConfigurationTest extends KernelTestCase
{
    private function factory(string $id): RateLimiterFactory
    {
        self::bootKernel();
        $factory = static::getContainer()->get($id);
        $this->assertInstanceOf(RateLimiterFactory::class, $factory);

        return $factory;
    }

    public function testWebhookPerIpBudgetAllowsExactlyOneHundredPerMinute(): void
    {
        $factory = $this->factory('limiter.webhook_email_ip');

        $accepted = 0;
        $firstRejectedAt = null;
        for ($i = 0; $i < 102; $i++) {
            if ($factory->create('127.0.0.1')->consume(1)->isAccepted()) {
                $accepted++;
            } elseif ($firstRejectedAt === null) {
                $firstRejectedAt = $i + 1;
            }
        }

        $this->assertSame(100, $accepted);
        $this->assertSame(101, $firstRejectedAt, 'The 101st request within the minute must be rejected');
    }

    public function testWebhookGlobalBudgetAllowsExactlyThirtyPerMinute(): void
    {
        $factory = $this->factory('limiter.webhook_email');

        $accepted = 0;
        for ($i = 0; $i < 31; $i++) {
            if ($factory->create('global')->consume(1)->isAccepted()) {
                $accepted++;
            }
        }

        $this->assertSame(30, $accepted);
    }

    public function testRegistrationIpBudgetAllowsExactlyFivePerHour(): void
    {
        $factory = $this->factory('limiter.registration_ip');

        $accepted = 0;
        $firstRejectedAt = null;
        for ($i = 0; $i < 7; $i++) {
            if ($factory->create('203.0.113.7')->consume(1)->isAccepted()) {
                $accepted++;
            } elseif ($firstRejectedAt === null) {
                $firstRejectedAt = $i + 1;
            }
        }

        $this->assertSame(5, $accepted);
        $this->assertSame(6, $firstRejectedAt, 'The 6th registration attempt from one IP must be rejected');
    }

    public function testRegistrationEmailBudgetAllowsExactlyThreePerHour(): void
    {
        $factory = $this->factory('limiter.registration');

        $accepted = 0;
        for ($i = 0; $i < 4; $i++) {
            if ($factory->create('registration_' . md5('new@example.com'))->consume(1)->isAccepted()) {
                $accepted++;
            }
        }

        $this->assertSame(3, $accepted);
    }

    public function testPasswordResetBudgetsAreEnforced(): void
    {
        $perEmail = $this->factory('limiter.password_reset');
        $perIp = $this->factory('limiter.password_reset_ip');

        $emailAccepted = 0;
        for ($i = 0; $i < 4; $i++) {
            if ($perEmail->create('reset_' . md5('user@example.com'))->consume(1)->isAccepted()) {
                $emailAccepted++;
            }
        }
        $this->assertSame(3, $emailAccepted, 'password_reset limit is 3/hour');

        $ipAccepted = 0;
        for ($i = 0; $i < 6; $i++) {
            if ($perIp->create('198.51.100.10')->consume(1)->isAccepted()) {
                $ipAccepted++;
            }
        }
        $this->assertSame(5, $ipAccepted, 'password_reset_ip limit is 5/15min');
    }
}
