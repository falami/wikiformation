<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite,DocumentTemplate,DocumentTemplateBinding};
use App\Service\DocumentStudio\{Catalog,Renderer,Content};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DocumentStudioTest extends WebTestCase
{
    public function testDraftPublicationIsolationPreviewAndReset(): void {
        $database=[$_ENV['DATABASE_URL'] ?? null,$_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        try {
            $client=self::createClient();$client->disableReboot();$client->catchExceptions(false);
            $em=self::getContainer()->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $user=(new Utilisateur())->setEmail('studio@test.local')->setPassword('unused')->setPrenom('Camille')->setNom('Test');
            $tenant=(new Entite())->setNom('Atelier Test')->setPublic(false)->setCreateur($user);
            $other=(new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($user);
            foreach([$user,$tenant,$other] as $e)$em->persist($e);$em->flush();$user->setEntite($tenant);
            $em->persist((new UtilisateurEntite())->setUtilisateur($user)->setEntite($tenant)->setCreateur($user)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
            $em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($tenant)->setStatus('active'));
            $em->flush();$client->loginUser($user);
            $base='/fr/administrateur/'.$tenant->getId().'/documents/modeles';
            $crawler=$client->request('GET',$base);self::assertResponseIsSuccessful();
            self::assertSelectorCount(13,'.studio-model-card');
            self::assertSelectorCount(count(Catalog::TYPES),'input[name="base"][value="blank"]');
            $token=$crawler->filter('input[name="_token"]')->first()->attr('value');
            $client->request('POST',$base.'/create',['kind'=>'convention_contrat','_token'=>$token]);self::assertResponseRedirects();
            $client->followRedirect();self::assertResponseIsSuccessful();
            $model=$em->getRepository(DocumentTemplate::class)->findOneBy(['entite'=>$tenant]);$id=$model->getId();
            $config=Catalog::defaults();$config['blocks']=[['title'=>'Votre contact','html'=>'<p><strong>[[organisme.nom]]</strong> : [[custom.contact]]</p><script>alert(1)</script><img src="file:///etc/passwd">','position'=>'end']];
            $config['fields']=[['key'=>'contact','label'=>'Contact','value'=>'Camille & équipe']];
            $send=function($action,$data)use($client,$base,$id,$token){$client->request('POST',$base.'/'.$id.'/'.$action,[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],json_encode($data));};
            $send('save',['name'=>'Modèle test','version'=>1,'config'=>$config]);self::assertResponseIsSuccessful();
            $saved=json_decode($client->getResponse()->getContent(),true);
            $model=$em->find(DocumentTemplate::class,$id);self::assertStringNotContainsString('<script',$model->getConfig()['blocks'][0]['html']);
            $renderer=self::getContainer()->get(Renderer::class);
            $original='<html><head></head><body><p>Original</p></body></html>';
            self::assertSame($original,$renderer->filter($original,'convention_contrat',['entite'=>$tenant]));
            $send('preview',['config'=>$config,'source'=>0]);self::assertResponseIsSuccessful();
            self::assertStringStartsWith('%PDF',$client->getResponse()->getContent());
            $parser=new \Smalot\PdfParser\Parser();$text=$parser->parseContent($client->getResponse()->getContent())->getText();
            self::assertStringContainsString('Camille & équipe',$text);self::assertStringNotContainsString('alert(1)',$text);
            $send('publish',['version'=>$saved['version']]);self::assertResponseIsSuccessful();
            $published=json_decode($client->getResponse()->getContent(),true);
            $html=$renderer->filter($original,'convention_contrat',['entite'=>$tenant]);
            self::assertStringContainsString('Camille & équipe',html_entity_decode($html));
            self::assertSame($original,$renderer->filter($original,'convention_contrat',['entite'=>$other]));
            $config['fields'][0]['value']='Brouillon non publié';
            $send('save',['name'=>'Nouveau brouillon','version'=>$published['version'],'config'=>$config]);self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Brouillon non publié',$renderer->filter($original,'convention_contrat',['entite'=>$tenant]));
            $send('save',['name'=>'Écrasement','version'=>1,'config'=>$config]);self::assertResponseStatusCodeSame(422);
            $other=$em->find(Entite::class,$other->getId());$foreign=new DocumentTemplate($other,'convention_contrat','Secret',Catalog::defaults());$em->persist($foreign);$em->flush();
            $client->catchExceptions(true);$client->request('GET',$base.'/'.$foreign->getId().'/edit');self::assertResponseStatusCodeSame(404);
            $client->request('POST',$base.'/default/convention_contrat',['_token'=>'invalid']);self::assertResponseStatusCodeSame(403);
            $client->request('POST',$base.'/default/convention_contrat',['_token'=>$token]);self::assertResponseRedirects();
            self::assertSame($original,$renderer->filter($original,'convention_contrat',['entite'=>$tenant]));
            self::assertCount(1,$em->find(DocumentTemplate::class,$id)->getHistory());
            $client->request('POST',$base.'/'.$id.'/delete',['_token'=>$token]);self::assertResponseStatusCodeSame(409);
            $client->request('POST',$base.'/create',['kind'=>'contrat_formateur','base'=>'blank','_token'=>$token]);self::assertResponseRedirects();
            $client->followRedirect();self::assertResponseIsSuccessful();
            $blank=$em->getRepository(DocumentTemplate::class)->findOneBy(['kind'=>'contrat_formateur']);
            self::assertSame('blank',$blank->getConfig()['base']);
            $html=$renderer->render($original,'contrat_formateur',$blank->getConfig(),['entite'=>$tenant]);
            self::assertStringNotContainsString('Original',$html);
            $blankConfig=$blank->getConfig();$blankConfig['blocks']=[['title'=>'Mon contrat','html'=>'<p>[[organisme.nom]]</p>','position'=>'end']];
            $html=$renderer->render($original,'contrat_formateur',$blankConfig,['entite'=>$tenant]);
            self::assertStringContainsString('Mon contrat',$html);self::assertStringContainsString('Atelier Test',$html);
            $blankId=$blank->getId();
            $client->request('POST',$base.'/'.$blankId.'/delete',['_token'=>'invalid']);self::assertResponseStatusCodeSame(403);
            $client->request('POST',$base.'/'.$blankId.'/delete',['_token'=>$token]);self::assertResponseRedirects();
            self::assertNull($em->find(DocumentTemplate::class,$blankId));
            foreach (Catalog::TYPES as $kind=>$type) {
                $client->request('POST',$base.'/create',['kind'=>$kind,'base'=>'blank','_token'=>$token]);self::assertResponseRedirects();
                $location=$client->getResponse()->headers->get('Location');
                preg_match('~/([0-9]+)/edit$~',$location,$matches);$createdId=(int)$matches[1];
                $created=$em->find(DocumentTemplate::class,$createdId);
                self::assertSame($type['label'].' · Modèle vierge',$created->getName());
                $custom=$created->getConfig();$custom['blocks']=[['title'=>'Chapitre personnalisé','html'=>'<p>[[organisme.nom]]</p>','position'=>'end']];
                $result=$renderer->render($original,$kind,$custom,['entite'=>$tenant]);
                self::assertStringNotContainsString('Original',$result);
                self::assertStringContainsString('Chapitre personnalisé',html_entity_decode($result));
                self::assertStringContainsString('Atelier Test',$result);
                $client->request('POST',$base.'/'.$createdId.'/delete',['_token'=>$token]);self::assertResponseRedirects();
                self::assertNull($em->find(DocumentTemplate::class,$createdId));
            }
        } finally {self::ensureKernelShutdown();[$_ENV['DATABASE_URL'],$_SERVER['DATABASE_URL']]=$database;}
    }
    public function testUnknownFieldsAndUnsafeMarkupCannotBecomeExecutable(): void {
        $content=new Content();
        $html=$content->sanitize('<p onclick="evil()" style="background:url(https://evil.test);color:#123456"><strong>OK</strong><iframe src="https://evil.test"></iframe><svg onload="evil()"></svg></p>');
        self::assertStringContainsString('color:#123456',$html);
        foreach(['onclick','url(','iframe','svg','evil'] as $bad)self::assertStringNotContainsString($bad,$html);
        $this->expectException(\DomainException::class);
        $content->normalize(['blocks'=>[['html'=>'[[user.password]]']]],'convention_contrat');
    }
}
