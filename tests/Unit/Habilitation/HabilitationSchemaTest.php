<?php
namespace App\Tests\Unit\Habilitation;
use App\Service\Habilitation\HabilitationSchema;
use PHPUnit\Framework\TestCase;
final class HabilitationSchemaTest extends TestCase
{
    public function testReferenceModelAndFreeColumns(): void {
        $s = new HabilitationSchema(); $model = $s->validate($s->example());
        self::assertCount(3, $model['sections']); self::assertCount(4, $model['sections'][0]['rows'][0]['fields']);
        $model['sections'][0]['rows'][0]['fields'][] = ['id'=>'score','label'=>'Résultat pratique','type'=>'text','options'=>[]];
        self::assertCount(5, $s->validate($model)['sections'][0]['rows'][0]['fields']);
    }
    public function testDuplicateIdsRejected(): void {
        $s = new HabilitationSchema(); $m=$s->example();$m['sections'][1]['rows'][0]['id']='row_0';
        $this->expectException(\InvalidArgumentException::class);$s->validate($m);
    }
    public function testUnknownSymbolRejected(): void {
        $s=new HabilitationSchema();$this->expectException(\InvalidArgumentException::class);
        $s->answers($s->example(),['rows'=>['row_0'=>['symbols'=>['ADMIN']]]],false);
    }
    public function testEmployerCannotExpandFavorableScope(): void {
        $s=new HabilitationSchema();$this->expectException(\InvalidArgumentException::class);
        $s->answers($s->example(),['rows'=>['row_0'=>['symbols'=>['H0']]]],false,['rows'=>['row_0'=>['verdict'=>'favorable','symbols'=>['B0']]]]);
    }
    public function testInvalidCalendarDateRejected(): void {
        $s=new HabilitationSchema();$this->expectException(\InvalidArgumentException::class);$s->answers($s->example(),['issuedAt'=>'2026-02-30'],false);
    }
    public function testIncompleteEmployerDecisionRejected(): void {
        $s=new HabilitationSchema();$this->expectException(\InvalidArgumentException::class);
        $s->answers($s->example(),['issuedAt'=>'2026-10-06','validUntil'=>'2027-10-06','function'=>'Technicien','assignment'=>'Atelier','signerFunction'=>'Directeur','rows'=>['row_0'=>['voltage'=>['BT']]]],true,['rows'=>['row_0'=>['verdict'=>'favorable','voltage'=>['BT'],'symbols'=>['B0']]]]);
    }
}
