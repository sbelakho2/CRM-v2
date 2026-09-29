<?php

namespace App\Tests\Unit\Form;

use App\Entity\EmailCampaign;
use App\Form\EmailCampaignType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;

class EmailCampaignTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    protected function getTypes(): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // Template existence checks fail-closed in unit tests (find → null).
        $em->method('find')->willReturn(null);

        return [new EmailCampaignType($em)];
    }

    public function testSubmitValidDataCreatesEmailCampaign(): void
    {
        $formData = [
            'name' => 'Q4 Automotive Outreach',
            'language' => 'FR',
            'description' => 'Campaign objectives',
            'touchCount' => 3,
            'active' => true,
        ];

        $model = new EmailCampaign();
        $form = $this->factory->create(EmailCampaignType::class, $model);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('Q4 Automotive Outreach', $model->getName());
        $this->assertSame('FR', $model->getLanguage());
        $this->assertSame(3, $model->getTouchCount());
        // touchTemplates empty → decoded to an empty array by the transformer.
        $this->assertSame([], $model->getTouchTemplates());
    }

    public function testTouchTemplatesJsonTransformsToArray(): void
    {
        $formData = [
            'name' => 'Templates Campaign',
            'language' => 'EN',
            'touchCount' => 5,
            'touchTemplates' => '{"1": {"template_id": "12"}}',
        ];

        $model = new EmailCampaign();
        $form = $this->factory->create(EmailCampaignType::class, $model);
        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $templates = $model->getTouchTemplates();
        $this->assertIsArray($templates);
        $this->assertArrayHasKey('1', $templates);
        $this->assertSame('12', $templates['1']['template_id']);
    }

    public function testInactiveCampaignEditDoesNotSilentlyReactivate(): void
    {
        // The form must NOT override the bound model value with data=true.
        $model = new EmailCampaign();
        $model->setActive(false);

        $form = $this->factory->create(EmailCampaignType::class, $model);
        // Unchecked checkbox submits as absent → false stays false.
        $form->submit([
            'name' => 'Paused Campaign',
            'language' => 'EN',
            'touchCount' => 2,
        ]);

        $this->assertFalse($model->isActive(), 'An inactive campaign must stay inactive when the checkbox is left unchecked');
    }
}
