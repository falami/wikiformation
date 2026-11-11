<?php
declare(strict_types=1);
namespace App\Tests\Service\Caces;
use App\Service\Caces\{CacesRules,CacesWorkflow};
use App\Entity\{CacesAttempt,CacesExam};
use PHPUnit\Framework\TestCase;
final class CacesRulesTest extends TestCase
{
    private function schema(): array { $themes=[];foreach([15,25,10,50] as $i=>$count){$qs=[];for($j=1;$j<=$count;$j++)$qs[]=['id'=>'t'.$i.'q'.$j,'label'=>'Question '.$j,'points'=>1,'correct'=>'A'];$themes[]=['title'=>'Thème '.$i,'questions'=>$qs];}return ['durationMinutes'=>60,'themes'=>$themes]; }
    private function answers(array $scores): array { $a=[];foreach($scores as $i=>$score)for($j=1;$j<=$score;$j++)$a['t'.$i.'q'.$j]='A';return $a; }
    public function testThresholdsAndRounding(): void { $r=new CacesRules();$s=$this->schema();self::assertTrue($r->grade($s,$this->answers([8,13,5,44]))['passed']);self::assertFalse($r->grade($s,$this->answers([7,25,10,50]))['passed']);self::assertFalse($r->grade($s,$this->answers([8,13,5,43]))['passed']);self::assertSame(13,$r->grade($s,[])['themes'][1]['threshold']); }
    public function testLateAnswersAreRejectedEvenWithoutBrowserTimer(): void { $s=$this->schema();$a=(new CacesAttempt())->setExam((new CacesExam())->setTheorySnapshot(['schema'=>$s]))->setStartedAt(new \DateTimeImmutable('2026-01-01 09:00:00'))->setAnswers($this->answers([8,13,5,44]));$w=new CacesWorkflow(new CacesRules());$w->saveAnswers($a,$this->answers([15,25,10,50]),new \DateTimeImmutable('2026-01-01 10:00:00'));self::assertSame(70,$a->getTheoryResult()['score']);self::assertSame('10:00:00',$a->getSubmittedAt()->format('H:i:s')); }
    public function testPracticalZeroAndTimeAreEliminatory(): void { $s=['durationMinutes'=>60,'themes'=>[['title'=>'Pratique','questions'=>array_map(fn($n)=>['id'=>'q'.$n,'label'=>'Critère '.$n,'points'=>5],range(1,20))]]];$a=array_fill_keys(array_map(fn($n)=>'q'.$n,range(1,20)),5);$r=new CacesRules();self::assertTrue($r->grade($s,$a,true,3600)['passed']);self::assertFalse($r->grade($s,$a,true,3601)['passed']);$a['q1']=0;self::assertSame(95,$r->grade($s,$a,true,3600)['score']);self::assertFalse($r->grade($s,$a,true,3600)['passed']); }
    public function testMissingPracticalScoreIsNotSilentlyZero(): void { $this->expectException(\InvalidArgumentException::class);(new CacesRules())->grade($this->schema(),[],true,1); }
    public function testNoMultipleTheoryAnswers(): void { $a=(new CacesAttempt())->setExam((new CacesExam())->setTheorySnapshot(['schema'=>$this->schema()]))->setStartedAt(new \DateTimeImmutable());$this->expectException(\InvalidArgumentException::class);(new CacesWorkflow(new CacesRules()))->saveAnswers($a,['t0q1'=>['A','B']],new \DateTimeImmutable()); }
    public function testCertificateDatesAndSharedAnchor(): void { $r=new CacesRules();$a=new \DateTimeImmutable('2026-01-02');$d=$r->dates('489',new \DateTimeImmutable('2026-01-01'),new \DateTimeImmutable('2026-12-03'),$a);self::assertSame('2026-12-03',$d['obtainedAt']);self::assertSame('2031-01-01',$d['expiresAt']);self::assertSame('2036-01-01',$r->dates('482',$a,$a)['expiresAt']); }
    public function testAllBundledGridsHaveCompleteUniqueAnswers(): void { $catalog=json_decode(file_get_contents(__DIR__.'/../../../resources/caces/catalog.json'),true,512,JSON_THROW_ON_ERROR);$r=new CacesRules();$count=0;foreach($catalog as $t){$s=$r->validate($t['schema'],$t['kind']);if($t['kind']!=='theory')continue;$count++;$answers=[];foreach($s['themes'] as $theme)foreach($theme['questions'] as $q){$answers[$q['id']]=$q['correct'];self::assertDoesNotMatchRegularExpression('/^Affirmation \d+ — thème \d+ \(page \d+\)$/u', $q['label']);self::assertGreaterThan(12, strlen($q['label']));self::assertFileExists(__DIR__.'/../../../resources/caces/'.$s['source']['key'].'/page-'.$q['sourcePage'].'.png');}self::assertCount(100,$answers);self::assertSame(100,$r->grade($s,$answers)['score']);}self::assertSame(40,$count); }

    private function practicalCatalog(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../resources/caces/practice-2026.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public function testEveryCategoryHasCompletePracticalRubrics(): void
    {
        $rules=new CacesRules();$coverage=[];$keys=[];
        foreach($this->practicalCatalog() as $template) {
            self::assertArrayNotHasKey($template['key'],$keys);$keys[$template['key']]=true;
            $coverage[$template['recommendation']][$template['category']]=true;
            $schema=$rules->validate($template['schema'],'practice');$answers=[];
            foreach($schema['themes'] as $theme) foreach($theme['questions'] as $q) {
                $answers[$q['id']]=$q['points'];
                self::assertNotEmpty($q['label']);self::assertNotEmpty($q['instructions']);
                self::assertContains($q['referencePage'],$schema['reference']['pages']);
                self::assertFileExists(__DIR__.'/../../../resources/caces/reference-'.pathinfo($schema['reference']['file'],PATHINFO_FILENAME).'/page-'.$q['referencePage'].'.png');
            }
            $result=$rules->grade($schema,$answers,true,3600);
            self::assertEquals(100,$result['score'],$template['key']);self::assertTrue($result['passed'],$template['key']);
            foreach($result['assessments'] as $a) self::assertSame(100,$a['max']);
        }
        self::assertCount(37,$keys);
        foreach(CacesRules::CATEGORIES as $rec=>$categories) foreach($categories as $category) self::assertTrue($coverage[$rec][$category]??false,'Missing '.$rec.' '.$category);
    }
    public function testR489Category1AIncludesTenPointDrivingAndCorrectWeights(): void
    {
        $t=array_values(array_filter($this->practicalCatalog(),fn($t)=>$t['recommendation']==='489' && $t['category']==='1A'))[0];
        $schema=(new CacesRules())->validate($t['schema'],'practice');
        self::assertSame([10,20,55,15],array_map(fn($t)=>array_sum(array_column($t['questions'],'points')),$schema['themes']));
        self::assertSame([10,10],array_column($schema['themes'][1]['questions'],'points'));
        $answers=[];foreach($schema['themes'] as $theme) foreach($theme['questions'] as $q)$answers[$q['id']]=$q['points'];
        $driving=$schema['themes'][1]['questions'][0]['id'];$answers[$driving]=9;
        self::assertSame(99,(new CacesRules())->grade($schema,$answers,true,3600)['score']);
        $answers[$driving]=11;$this->expectException(\InvalidArgumentException::class);
        (new CacesRules())->grade($schema,$answers,true,3600);
    }
    public function testAllRemediationThemesAreSelectableBelowSeventy(): void
    {
        $schema=['durationMinutes'=>60,'themes'=>[
            ['title'=>'Premier','questions'=>[['id'=>'a','label'=>'A','points'=>50]]],
            ['title'=>'Second','questions'=>[['id'=>'b','label'=>'B','points'=>50]]],
        ]];
        $rules=new CacesRules();
        self::assertSame(['Premier','Second'],$rules->remediationThemes($schema,['a'=>30,'b'=>39],['0','1']));
        $this->expectException(\InvalidArgumentException::class);
        $rules->remediationThemes($schema,['a'=>30,'b'=>40],['0','1']);
    }

    public function testBothRequiredEnginesMustPassIndependently(): void
    {
        $t=array_values(array_filter($this->practicalCatalog(),fn($t)=>$t['recommendation']==='489' && $t['category']==='7'))[0];
        $schema=(new CacesRules())->validate($t['schema'],'practice');$answers=[];
        foreach($schema['themes'] as $theme) foreach($theme['questions'] as $q) $answers[$q['id']]=$theme['assessment']==='chariot-1'?$q['points']:max(1,(int)ceil($q['points']/2));
        $result=(new CacesRules())->grade($schema,$answers,true,3600);
        self::assertGreaterThanOrEqual(70,$result['score']);self::assertFalse($result['passed']);
        self::assertCount(2,$result['assessments']);
    }
}
