<?php

namespace App\Tests\Unit\Message;

use App\Message\EmailCampaignMessage;
use PHPUnit\Framework\TestCase;

class EmailCampaignMessageTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $message = new EmailCampaignMessage(42, [1, 2, 3]);

        $this->assertSame(42, $message->getCampaignId());
        $this->assertSame([1,2,3], $message->getRecipientIds());
    }
}
