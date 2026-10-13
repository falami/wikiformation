<?php
declare(strict_types=1);
namespace App\Service\TrainerProfile;
use App\Entity\TrainerProfile;
use Sabre\VObject\Reader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
final class CalendarSync
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret) {}
    public function encrypt(string $url): string {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || mb_strlen($url)>3000 || !in_array($host,['calendar.google.com','outlook.live.com','outlook.office365.com','outlook.office.com'],true)) throw new \InvalidArgumentException('Utilisez un lien ICS HTTPS Google Agenda ou Outlook.');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($url,$nonce,hash('sha256',$this->secret,true)));
    }
    private function decrypt(string $value): string {
        $raw=base64_decode($value,true);
        if (!$raw || strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new \RuntimeException();
        $url=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),hash('sha256',$this->secret,true));
        if ($url===false) throw new \RuntimeException();
        return $url;
    }
    public function sync(TrainerProfile $profile): void {
        if (!$profile->calendarSecret) return;
        $profile->attemptedAt=new \DateTimeImmutable();
        try {
            // Private addresses and redirects are denied, even for an approved provider.
            $client=new NoPrivateNetworkHttpClient(HttpClient::create());
            $response=$client->request('GET',$this->decrypt($profile->calendarSecret),['timeout'=>8,'max_duration'=>12,'max_redirects'=>0,'headers'=>['Accept'=>'text/calendar']]);
            if ($response->getStatusCode()!==200) throw new \RuntimeException();
            $ics='';
            foreach ($client->stream($response) as $chunk) {
                $ics.=$chunk->getContent();
                if (strlen($ics)>3000000) { $response->cancel(); throw new \RuntimeException(); }
            }
            $start=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Paris'));
            $end=$start->modify('+6 months');
            $profile->busy=$this->parse($ics,$start,$end);
            $profile->syncedAt=new \DateTimeImmutable(); $profile->coveredUntil=$end; $profile->syncError=null;
        } catch (\Throwable) {
            // Never expose secret URLs or event contents in an error message.
            $profile->syncError='Synchronisation impossible. Vérifiez le lien ICS et réessayez.';
        }
    }
    public function parse(string $ics, \DateTimeImmutable $start, \DateTimeImmutable $end): array {
        if (!str_contains($ics,'BEGIN:VCALENDAR') || strlen($ics)>3000000) throw new \InvalidArgumentException('Calendrier invalide.');
        $calendar=Reader::read($ics);
        if (!$calendar instanceof \Sabre\VObject\Component\VCalendar) throw new \InvalidArgumentException();
        $expanded=$calendar->expand($start,$end,new \DateTimeZone('Europe/Paris'));
        $busy=[];
        foreach ($expanded->select('VEVENT') as $event) {
            if ((string)$event->STATUS==='CANCELLED' || (string)$event->TRANSP==='TRANSPARENT' || (string)$event->{'X-MICROSOFT-CDO-BUSYSTATUS'}==='FREE') continue;
            $from=$event->DTSTART?->getDateTime(new \DateTimeZone('Europe/Paris'));
            $to=$event->DTEND?->getDateTime(new \DateTimeZone('Europe/Paris'));
            if (!$to && $from && isset($event->DURATION)) $to=$from->add($event->DURATION->getDateInterval());
            if (!$to && $from && !$event->DTSTART->hasTime()) $to=$from->modify('+1 day');
            // A timed event without an end has zero duration in iCalendar.
            if ($from && !$to) continue;
            if (!$from || !$to || $to<=$from) throw new \InvalidArgumentException('Période invalide.');
            $busy[]=['start'=>$from->format(DATE_ATOM),'end'=>$to->format(DATE_ATOM)];
            if (count($busy)>10000) throw new \RuntimeException();
        }
        return $busy;
    }
}
