<?php

declare(strict_types=1);
namespace App\Tests\Unit;

use App\Entity\Utilisateur;
use App\Security\TwoFactor\EmailChallenge;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\{Request, RequestStack, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Mailer\{MailerInterface, Exception\TransportException};
use Symfony\Component\RateLimiter\{RateLimiterFactory, Storage\InMemoryStorage};
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class EmailChallengeTest extends TestCase
{
    private MockClock $clock;
    private Session $session;
    private RequestStack $requests;
    private EmailChallenge $challenge;
    private Utilisateur $user;
    private array $codes = [];
    private bool $deliveryFails = false;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-05 12:00:00');
        $this->session = new Session(new MockArraySessionStorage());
        $this->requests = new RequestStack(); $request = new Request(); $request->setSession($this->session); $this->requests->push($request);
        $this->user = (new Utilisateur())->setEmail('user@example.test')->setPassword('password-hash');
        (new \ReflectionProperty($this->user, 'id'))->setValue($this->user, 123);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email): void {
            if ($this->deliveryFails) throw new TransportException('Delivery unavailable');
            $this->codes[] = $email->getContext()['code'];
        });
        $factory = fn($id,$limit)=>new RateLimiterFactory(['id'=>$id,'policy'=>'sliding_window','limit'=>$limit,'interval'=>'15 minutes'],new InMemoryStorage());
        $this->challenge = new EmailChallenge($this->requests,$this->clock,$mailer,new NullLogger(),'test-secret',$factory('send',5),$factory('verify',10));
        $this->challenge->begin($this->user);
    }

    private function assertRejected(string $code, string $message): void
    {
        try { $this->challenge->verify($this->user,$code); self::fail('The code was unexpectedly accepted.'); }
        catch (CustomUserMessageAuthenticationException $e) { self::assertStringContainsString($message,$e->getMessageKey()); }
    }

    public function testCodeIsHashedSessionBoundAndSingleUse(): void
    {
        self::assertSame('', $this->challenge->send($this->user));
        self::assertNotSame($this->codes[0],$this->session->get(EmailChallenge::KEY)['hash']);
        $anotherRequest = new Request(); $anotherRequest->setSession(new Session(new MockArraySessionStorage()));
        $this->requests->push($anotherRequest);
        $this->assertRejected($this->codes[0],'expiré');
        $this->requests->pop();
        self::assertTrue($this->challenge->verify($this->user,$this->codes[0]));
        self::assertFalse($this->session->has(EmailChallenge::KEY));
        $this->assertRejected($this->codes[0],'expiré');
    }

    public function testExpiryAndResendCooldown(): void
    {
        $this->challenge->send($this->user);
        self::assertStringContainsString('patienter',$this->challenge->send($this->user));
        self::assertCount(1,$this->codes);
        $this->clock->sleep(600);
        $this->assertRejected($this->codes[0],'expiré');
        self::assertSame('',$this->challenge->send($this->user));
        self::assertTrue($this->challenge->verify($this->user,$this->codes[1]));
    }

    public function testFiveAttemptsLockCodeEvenIfNextAttemptIsCorrect(): void
    {
        $this->challenge->send($this->user);
        for($n=0;$n<5;++$n) $this->assertRejected('invalid','incorrect');
        $this->assertRejected($this->codes[0],'bloqué');
    }

    public function testGlobalLimitsSurviveStartingNewChallenges(): void
    {
        for($n=0;$n<5;++$n) {
            $this->challenge->begin($this->user);
            self::assertSame('',$this->challenge->send($this->user));
            $this->assertRejected('invalid','incorrect'); $this->assertRejected('invalid','incorrect');
        }
        $this->challenge->begin($this->user);
        self::assertStringContainsString('limite',$this->challenge->send($this->user));
        $this->assertRejected('123456','Trop de tentatives');
    }

    public function testResendReplacesHashAndDeliveryFailureNeverAllowsLogin(): void
    {
        $this->challenge->send($this->user);
        $hash=$this->session->get(EmailChallenge::KEY)['hash'];
        $this->clock->sleep(61);
        $this->challenge->send($this->user);
        self::assertNotSame($hash,$this->session->get(EmailChallenge::KEY)['hash']);
        if ($this->codes[0] !== $this->codes[1]) $this->assertRejected($this->codes[0],'incorrect');
        $this->clock->sleep(61); $this->deliveryFails=true;
        self::assertStringContainsString('pas pu être envoyé',$this->challenge->send($this->user));
        $this->assertRejected($this->codes[1],'expiré');
    }

    public function testChangedAccountIdentityAndExpiredPasswordStepInvalidateChallenge(): void
    {
        $this->challenge->send($this->user);
        $this->user->setEmail('changed@example.test');
        $this->assertRejected($this->codes[0],'expiré');
        $this->challenge->begin($this->user); $this->challenge->send($this->user);
        $this->user->setPassword('changed-password');
        $this->assertRejected($this->codes[1],'expiré');
        $this->challenge->begin($this->user);
        $this->clock->sleep(1800);
        self::assertStringContainsString('demande a expiré',$this->challenge->send($this->user));
    }
}
