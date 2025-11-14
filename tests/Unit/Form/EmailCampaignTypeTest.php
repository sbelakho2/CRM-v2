<?php

namespace App\Tests\Unit\Form;

use App\Entity\EmailCampaign;
use App\Form\EmailCampaignType;
use Symfony\Component\Form\Test\TypeTestCase;

class EmailCampaignTypeTest extends TypeTestCase
{
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
        $this->assertEquals('Q4 Automotive Outreach', $model->getName());
        $this->assertEquals('FR', $model->getLanguage());
        $this->assertEquals('Campaign objectives', $model->getDescription());
        $this->assertEquals(3, $model->getTouchCount());
        $this->assertTrue($model->isActive());
    }

    public function testDefaultValues(): void
    {
        $model = new EmailCampaign();
        $form = $this->factory->create(EmailCampaignType::class, $model);

        // Defaults defined on the form/type
        $this->assertEquals(5, $form->get('touchCount')->getData());
        $this->assertTrue($form->get('active')->getData());
    }
}
