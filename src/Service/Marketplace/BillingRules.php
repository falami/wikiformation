<?php
declare(strict_types=1);
namespace App\Service\Marketplace;

use App\Entity\Marketplace\Seller;

/** The initial launch deliberately supports metropolitan France only. */
final class BillingRules
{
    public const VERSION = 'FR-2026-10-25-v1';
    public const PLATFORM = [
        'name'=>'Jeroen Popot EI — I.T. For You', 'registration'=>'80813612100023',
        'address'=>'88 Chemin de Russaud, 30100 Alès, France', 'country'=>'FR', 'postalCode'=>'30100',
        'email'=>'contact@itforyou.fr', 'phone'=>'06.27.08.76.99',
        'taxNumber'=>'FR75808136121', 'taxExemption'=>'',
    ];
    public static function metropolitan(string $country, string $postal): bool
    {
        return $country==='FR' && preg_match('/^(?:0[1-9]|[1-8][0-9]|9[0-5])[0-9]{3}$/D', $postal)===1;
    }
    public static function buyer(array $input): array
    {
        $data=[];
        foreach (['name','address','postalCode','city','country','registration','taxNumber','kind'] as $key) {
            $value=$input[$key]??'';
            if (!is_string($value) || mb_strlen($value)>255) throw new \DomainException('Coordonnées de facturation invalides.');
            $data[$key]=trim($value);
        }
        if (!in_array($data['kind'],['person','business'],true) || !$data['name'] || !$data['address'] || !$data['city'] || !self::metropolitan($data['country'],$data['postalCode'])) {
            throw new \DomainException('Renseignez votre nom et votre adresse de facturation en France métropolitaine. Les autres territoires ne sont pas encore ouverts à la vente.');
        }
        if ($data['kind']==='business' && !preg_match('/^\d{14}$/D',$data['registration'])) throw new \DomainException('Renseignez le SIRET de l’entreprise facturée.');
        if ($data['taxNumber'] && !preg_match('/^FR[A-Z0-9]{2}\d{9}$/D',$data['taxNumber'])) throw new \DomainException('Numéro de TVA français invalide.');
        if ($data['kind']==='person') $data['registration']=$data['taxNumber']='';
        $data['address'].=', '.$data['postalCode'].' '.$data['city'].', France';
        return $data;
    }
    public static function seller(Seller $seller): array
    {
        if (!self::metropolitan($seller->country,$seller->postalCode) || !preg_match('/^\d{14}$/D',$seller->registration) || !trim($seller->legalName) || !trim($seller->address) || !filter_var($seller->email,FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Le vendeur doit compléter son identité et son établissement en France métropolitaine.');
        }
        if (!$seller->taxExemption && !preg_match('/^FR[A-Z0-9]{2}\d{9}$/D',$seller->taxNumber)) throw new \DomainException('Le vendeur doit renseigner son numéro de TVA ou sa mention d’exonération.');
        return ['name'=>$seller->legalName,'registration'=>$seller->registration,'address'=>$seller->address,'email'=>$seller->email,'taxNumber'=>$seller->taxNumber,'taxExemption'=>$seller->taxExemption,'country'=>$seller->country,'postalCode'=>$seller->postalCode];
    }
    public static function mandateText(): string
    {
        return 'Mandat de facturation — '.self::VERSION."\n".
            'Le vendeur identifié dans ce profil mandate Jeroen Popot EI, nom commercial I.T. For You, SIRET 80813612100023, 88 Chemin de Russaud, 30100 Alès, France, pour établir en son nom et pour son compte les factures et avoirs des cours vendus sur WikiFormation. '.
            'Le vendeur reste responsable des informations légales, de son régime fiscal, de la TVA applicable et de ses déclarations. Il signale toute modification avant de nouvelles ventes. '.
            'Les documents utilisent une série distincte MK-S suivie de son identifiant vendeur, de l’année et d’un numéro continu. Le vendeur réserve cette série et ne l’utilise pas dans un autre logiciel. '.
            'Une copie est disponible dans son espace et lui est notifiée par e-mail. Le document est réputé accepté trente jours après cette notification en l’absence de contestation via son espace ou contact@itforyou.fr. Une contestation conserve le document original ; une rectification se fait par avoir et, si nécessaire, nouvelle facture. '.
            'Le mandat peut être révoqué dans le profil vendeur : les nouvelles ventes sont suspendues ; le traitement et la rectification des opérations déjà conclues restent couverts. '.
            'Les documents et la preuve du mandat sont conservés dix ans pour les obligations comptables. Ce mandat ne vaut ni représentation fiscale ni autorisation générale de transmission à une plateforme de facturation électronique. Les obligations de transmission applicables font l’objet d’un raccordement distinct.';
    }
    public static function activeMandate(Seller $seller): ?array
    {
        $last=$seller->mandates ? $seller->mandates[array_key_last($seller->mandates)] : null;
        if (!$last || ($last['version']??'')!==self::VERSION || ($last['revokedAt']??null)) return null;
        return $last;
    }
}
