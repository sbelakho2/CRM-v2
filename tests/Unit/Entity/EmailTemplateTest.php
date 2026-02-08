<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EmailTemplate;
use PHPUnit\Framework\TestCase;

class EmailTemplateTest extends TestCase
{
    private EmailTemplate $template;

    protected function setUp(): void
    {
        $this->template = new EmailTemplate();
    }

    public function testInitialState(): void
    {
        $this->assertNull($this->template->getId());
        $this->assertNull($this->template->getName());
        $this->assertNull($this->template->getSubjectLine());
        $this->assertNull($this->template->getPreviewText());
        $this->assertNull($this->template->getBodyHtml());
        $this->assertNull($this->template->getBodyText());
        $this->assertNull($this->template->getDescription());
        $this->assertNull($this->template->getCategory());
        $this->assertTrue($this->template->isActive());
        $this->assertIsArray($this->template->getPersonalizationTokens());
        $this->assertEmpty($this->template->getPersonalizationTokens());
        $this->assertInstanceOf(\DateTimeInterface::class, $this->template->getCreatedAt());
        $this->assertNull($this->template->getUpdatedAt());
        $this->assertNull($this->template->getCreatedBy());
    }

    public function testNameMutator(): void
    {
        $result = $this->template->setName('Welcome Email');
        $this->assertEquals('Welcome Email', $this->template->getName());
        $this->assertSame($this->template, $result);
    }

    public function testSubjectLineMutator(): void
    {
        $result = $this->template->setSubjectLine('Welcome to Our Platform');
        $this->assertEquals('Welcome to Our Platform', $this->template->getSubjectLine());
        $this->assertSame($this->template, $result);
    }

    public function testPreviewTextMutator(): void
    {
        $result = $this->template->setPreviewText('Check out our latest offers');
        $this->assertEquals('Check out our latest offers', $this->template->getPreviewText());
        $this->assertSame($this->template, $result);
    }

    public function testBodyHtmlMutator(): void
    {
        $html = '<h1>Hello</h1><p>Welcome!</p>';
        $result = $this->template->setBodyHtml($html);
        $this->assertEquals($html, $this->template->getBodyHtml());
        $this->assertSame($this->template, $result);
    }

    public function testBodyTextMutator(): void
    {
        $text = 'Hello, Welcome!';
        $result = $this->template->setBodyText($text);
        $this->assertEquals($text, $this->template->getBodyText());
        $this->assertSame($this->template, $result);
    }

    public function testDescriptionMutator(): void
    {
        $result = $this->template->setDescription('A welcome email template');
        $this->assertEquals('A welcome email template', $this->template->getDescription());
        $this->assertSame($this->template, $result);
    }

    public function testCategoryMutator(): void
    {
        $result = $this->template->setCategory('Newsletter');
        $this->assertEquals('Newsletter', $this->template->getCategory());
        $this->assertSame($this->template, $result);
    }

    public function testCategoryConstants(): void
    {
        $expected = ['Newsletter', 'Promotional', 'Transactional', 'ABM', 'Drip', 'Automated'];
        $this->assertEquals($expected, EmailTemplate::CATEGORIES);
    }

    public function testIsActiveMutator(): void
    {
        $this->assertTrue($this->template->isActive());
        
        $result = $this->template->setIsActive(false);
        $this->assertFalse($this->template->isActive());
        $this->assertSame($this->template, $result);
        
        $this->template->setIsActive(true);
        $this->assertTrue($this->template->isActive());
    }

    public function testPersonalizationTokensMutator(): void
    {
        $tokens = ['{{first_name}}', '{{company}}', '{{title}}'];
        $result = $this->template->setPersonalizationTokens($tokens);
        $this->assertEquals($tokens, $this->template->getPersonalizationTokens());
        $this->assertSame($this->template, $result);
    }

    public function testCreatedAtMutator(): void
    {
        $date = new \DateTime('2025-01-01');
        $result = $this->template->setCreatedAt($date);
        $this->assertEquals($date, $this->template->getCreatedAt());
        $this->assertSame($this->template, $result);
    }

    public function testUpdatedAtMutator(): void
    {
        $date = new \DateTime();
        $result = $this->template->setUpdatedAt($date);
        $this->assertEquals($date, $this->template->getUpdatedAt());
        $this->assertSame($this->template, $result);
    }

    public function testCreatedByMutator(): void
    {
        $result = $this->template->setCreatedBy('admin@example.com');
        $this->assertEquals('admin@example.com', $this->template->getCreatedBy());
        $this->assertSame($this->template, $result);
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->template->getUpdatedAt());
        $this->template->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->template->getUpdatedAt());
    }

    public function testFluentInterface(): void
    {
        $result = $this->template
            ->setName('Test Template')
            ->setSubjectLine('Subject')
            ->setBodyHtml('<p>Body</p>')
            ->setCategory('Promotional')
            ->setIsActive(true);

        $this->assertSame($this->template, $result);
        $this->assertEquals('Test Template', $result->getName());
        $this->assertEquals('Subject', $result->getSubjectLine());
        $this->assertEquals('<p>Body</p>', $result->getBodyHtml());
        $this->assertEquals('Promotional', $result->getCategory());
        $this->assertTrue($result->isActive());
    }
}
