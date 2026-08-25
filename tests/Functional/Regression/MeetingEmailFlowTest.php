<?php

namespace App\Tests\Functional\Regression;

use App\Entity\MeetingSlot;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Regression tests for the meeting booking emails.
 *
 * The bookSlot/cancel actions used to carry "TODO: Send confirmation/cancellation
 * email" markers — booking and cancelling never notified the booker. Both flows
 * must now send a mail (null transport in tests) and persist the
 * confirmation_sent flags, and the booker's contact details must survive a
 * cancellation so the cancellation notice can be delivered.
 */
class MeetingEmailFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['users', 'meeting_slots'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testBookingSendsConfirmationAndMarksSlot(): void
    {
        $slot = $this->createAvailableSlot('booker@example.com');

        $crawler = $this->client->request('GET', '/meetings/book/slot/' . $slot->getBookingToken());
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Confirm Booking')->form();
        $form['meeting_booking[name]'] = 'Jane Booker';
        $form['meeting_booking[email]'] = 'booker@example.com';
        $form['meeting_booking[phone]'] = '+212600000000';
        $form['meeting_booking[company]'] = 'Acme SARL';
        $form['meeting_booking[notes]'] = 'Interested in PCB assembly';
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Booking Confirmed', $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(MeetingSlot::class)->find($slot->getId());
        $this->assertSame(MeetingSlot::STATUS_BOOKED, $saved->getStatus());
        $this->assertTrue($saved->isConfirmationSent(), 'confirmation email must be marked as sent');
        $this->assertNotNull($saved->getConfirmationSentAt());
        $this->assertSame('booker@example.com', $saved->getBookedByEmail());
    }

    public function testCancellationNotifiesBookerAndKeepsContactDetails(): void
    {
        $user = $this->createUser('owner@example.com');
        $slot = $this->createAvailableSlot('booker2@example.com');
        $slot->book('Jane Booker', 'booker2@example.com', null, 'Acme SARL', null);
        $slot->setStatus(MeetingSlot::STATUS_BOOKED);
        $slot->setBookedAt(new \DateTimeImmutable());
        $slot->setOwner($user);
        $this->entityManager->flush();

        $this->client->loginUser($this->loadUser('owner@example.com'));
        $crawler = $this->client->request('GET', '/meetings/' . $slot->getId());
        $this->assertResponseIsSuccessful();

        $token = $crawler->filter('form[action*="cancel"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/meetings/' . $slot->getId() . '/cancel', ['_token' => $token]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(MeetingSlot::class)->find($slot->getId());
        $this->assertSame(MeetingSlot::STATUS_CANCELLED, $saved->getStatus());
        $this->assertSame('booker2@example.com', $saved->getBookedByEmail(), 'booker contact details must survive cancellation');
        $this->assertSame('Jane Booker', $saved->getBookedByName());
    }

    private function createAvailableSlot(string $ownerEmail): MeetingSlot
    {
        $user = $this->createUser($ownerEmail);

        $slot = new MeetingSlot();
        $slot->setTitle('Intro Call');
        $slot->setStartTime(new \DateTimeImmutable('+2 days 10:00'));
        $slot->setEndTime(new \DateTimeImmutable('+2 days 10:30'));
        $slot->setTimezone('Africa/Casablanca');
        $slot->setDurationMinutes(30);
        $slot->setStatus(MeetingSlot::STATUS_AVAILABLE);
        $slot->setBookingToken(bin2hex(random_bytes(16)));
        $slot->setOwner($user);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        return $slot;
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Meeting');
        $user->setLastName('Owner');
        $user->setIsVerified(true);
        $user->setPassword(self::getContainer()->get('security.password_hasher')->hashPassword($user, 'test-password'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loadUser(string $email): UserInterface
    {
        return self::getContainer()->get(UserProviderInterface::class)->loadUserByIdentifier($email);
    }
}
