<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Service\Security\ContactCaptcha;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ContactCaptchaTest extends TestCase
{
    public function testExpirySingleUseAndSessionIsolation(): void
    {
        $stack = new RequestStack();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $stack->push($request);
        $captcha = new ContactCaptcha($stack);
        $first = $captcha->create();
        preg_match('/(\d+) \+ (\d+)/', $first['question'], $numbers);
        $answer = (string) ($numbers[1] + $numbers[2]);
        self::assertTrue($captcha->verify($first['id'], $answer));
        self::assertFalse($captcha->verify($first['id'], $answer));

        $expired = $captcha->create();
        $values = $session->get('public_contact_captcha');
        $values[$expired['id']]['expires'] = time() - 1;
        $values[$expired['id']]['answer'] = '10';
        $session->set('public_contact_captcha', $values);
        self::assertFalse($captcha->verify($expired['id'], '10'));

        $other = $captcha->create();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::assertFalse($captcha->verify($other['id'], '10'));
    }

    public function testMultipleFormsRemainIndependentAndStorageIsBounded(): void
    {
        $stack = new RequestStack();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $stack->push($request);
        $captcha = new ContactCaptcha($stack);
        for ($i = 0; $i < 60; ++$i) $captcha->create();
        self::assertCount(50, $session->get('public_contact_captcha'));
        foreach ($session->get('public_contact_captcha') as $id => $challenge) {
            self::assertTrue($captcha->verify($id, $challenge['answer']));
        }
    }
}
