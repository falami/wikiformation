<?php
namespace App\Tests\Unit\Habilitation;
use App\Service\Habilitation\HabilitationSchema;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('neighbourhoodReductions')]
    public function testEmployerCanOnlyRemoveNeighbourhoodWithinTheSameRow(string $row, string $from, string $to): void
    {
        $schema = new HabilitationSchema();
        $reference = ['rows' => [$row => ['verdict' => 'favorable', 'symbols' => [$from], 'voltage' => ['BT']]]];
        $allowed = $schema->employerChoices($schema->example(), $reference);
        self::assertContains($from, $allowed[$row]['symbols']);
        self::assertContains($to, $allowed[$row]['symbols']);
        $answer = $schema->answers($schema->example(), ['rows' => [$row => ['symbols' => [$to]]]], false, $reference);
        self::assertSame([$to], $answer['rows'][$row]['symbols']);
        self::assertSame([$from], $reference['rows'][$row]['symbols'], 'The source must remain untouched.');
    }

    public static function neighbourhoodReductions(): iterable
    {
        yield 'H0V to H0' => ['row_0', 'H0V', 'H0'];
        yield 'B1V to B1' => ['row_5', 'B1V', 'B1'];
        yield 'H1V to H1' => ['row_5', 'H1V', 'H1'];
        yield 'B2V to B2' => ['row_6', 'B2V', 'B2'];
        yield 'H2V to H2' => ['row_6', 'H2V', 'H2'];
    }

    public function testReductionDoesNotAddAnOptionToAModelOrInferCustomHierarchy(): void
    {
        $schema = new HabilitationSchema();
        $model = $schema->example();
        $model['sections'][0]['rows'][0]['fields'][0]['options'] = ['H0V', 'Niveau 1', 'Niveau 2'];
        $reference = ['rows' => ['row_0' => ['verdict' => 'favorable', 'symbols' => ['H0V', 'Niveau 2']]]];
        self::assertSame(['H0V', 'Niveau 2'], $schema->employerChoices($model, $reference)['row_0']['symbols']);
    }

    #[DataProvider('forbiddenEmployerChoices')]
    public function testEmployerCannotExpandOrChangeScope(array $referenceRow, string $targetRow, array $answers): void
    {
        $schema = new HabilitationSchema();
        $this->expectException(\InvalidArgumentException::class);
        $schema->answers($schema->example(), ['rows' => [$targetRow => $answers]], false, ['rows' => ['row_0' => $referenceRow]]);
    }

    public static function forbiddenEmployerChoices(): iterable
    {
        yield 'restore neighbourhood' => [['verdict' => 'favorable', 'symbols' => ['H0']], 'row_0', ['symbols' => ['H0V']]];
        yield 'cross voltage domain' => [['verdict' => 'favorable', 'symbols' => ['H0V']], 'row_0', ['symbols' => ['B0']]];
        yield 'expand voltage' => [['verdict' => 'favorable', 'voltage' => ['BT']], 'row_0', ['voltage' => ['HTA']]];
        yield 'cross function row' => [['verdict' => 'favorable', 'symbols' => ['H0V']], 'row_1', ['symbols' => ['H0']]];
        yield 'unfavorable opinion' => [['verdict' => 'unfavorable', 'symbols' => ['H0V']], 'row_0', ['verdict' => 'favorable', 'symbols' => ['H0']]];
        yield 'unevaluated opinion' => [['verdict' => 'not_evaluated', 'symbols' => ['H0V']], 'row_0', ['verdict' => 'favorable', 'symbols' => ['H0V']]];
    }

    public function testEmployerCannotRewriteEvaluatedInstallations(): void
    {
        $schema = new HabilitationSchema();
        $reference = ['rows' => ['row_0' => ['verdict' => 'favorable', 'works' => 'Atelier principal']]];
        $answer = $schema->answers($schema->example(), [], false, $reference);
        self::assertSame('Atelier principal', $answer['rows']['row_0']['works']);
        $this->expectException(\InvalidArgumentException::class);
        $schema->answers($schema->example(), ['rows' => ['row_0' => ['works' => 'Tous les sites']]], false, $reference);
    }

    public function testMalformedRowsRejected(): void
    {
        $schema = new HabilitationSchema();
        $this->expectException(\InvalidArgumentException::class);
        $schema->answers($schema->example(), ['rows' => 'invalid'], false);
    }
}
