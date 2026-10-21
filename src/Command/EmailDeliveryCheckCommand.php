<?php

declare(strict_types=1);
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:email:check', description: 'Vérifie la configuration et les DNS publics sans envoyer de mail ni afficher les identifiants SMTP.')]
final class EmailDeliveryCheckCommand extends Command
{
    public function __construct(
        #[Autowire('%env(default:mail_delivery_from:MAIL_DELIVERY_FROM)%')] private readonly string $from,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $dsn,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('dkim-selector', null, InputOption::VALUE_REQUIRED, 'Sélecteur indiqué par le fournisseur SMTP', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domain = substr(strrchr($this->from, '@') ?: '', 1);
        $selector = (string) $input->getOption('dkim-selector');
        if (!filter_var($this->from, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $selector)) {
            $io->error('Adresse expéditeur ou sélecteur DKIM invalide.');
            return Command::FAILURE;
        }
        $rows = [['Expéditeur central', $this->from]];
        $warnings = [];
        // Never display the DSN: it contains credentials.
        $scheme = parse_url($this->dsn, PHP_URL_SCHEME);
        $rows[] = ['Transport', in_array($scheme, ['smtp', 'smtps'], true) ? $scheme : 'Transport spécifique : vérifier chez le fournisseur'];
        if ($scheme === 'smtp') $warnings[] = 'SMTP : vérifier STARTTLS et imposer require_tls=true si le serveur le permet.';
        if (preg_match('/(?:verify_peer|auto_tls|require_tls)=0(?:&|$)/', $this->dsn)) $warnings[] = 'Une protection TLS est désactivée dans la configuration.';
        foreach (['SPF' => $domain, 'DMARC' => '_dmarc.'.$domain, 'DKIM' => $selector.'._domainkey.'.$domain] as $label => $name) {
            $records = @dns_get_record($name, DNS_TXT);
            $prefix = ['SPF' => 'v=spf1', 'DMARC' => 'v=DMARC1', 'DKIM' => 'v=DKIM1'][$label];
            $matches = array_values(array_filter(array_column($records ?: [], 'txt'), static fn(string $txt): bool => str_starts_with($txt, $prefix)));
            $rows[] = [$label, count($matches) === 1 ? 'Enregistrement publié' : 'Absent, multiple ou résolution à vérifier'];
            if (count($matches) !== 1) $warnings[] = $label.' : contrôler '.$name.' (un CNAME DKIM peut nécessiter une vérification chez le fournisseur).';
            if ($label === 'DMARC' && isset($matches[0])) {
                if (preg_match('/(?:^|;)\s*p=none(?:;|$)/i', $matches[0])) $warnings[] = 'DMARC est en observation (p=none). Renforcer après analyse des sources légitimes.';
                if (!str_contains($matches[0], 'rua=')) $warnings[] = 'Aucune destination de rapports agrégés DMARC configurée.';
            }
        }
        $io->table(['Contrôle', 'Résultat'], $rows);
        $io->note('Ce contrôle ne prouve ni la signature DKIM des envois réels, ni le passage SPF, ni la réputation IP, ni la réception. Vérifier Authentication-Results sur un message reçu.');
        foreach ($warnings as $warning) $io->warning($warning);
        return $warnings ? Command::FAILURE : Command::SUCCESS;
    }
}
