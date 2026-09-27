<?php
namespace App\Tests\Service\Filter;

use App\Service\Filter\RecipientFilter;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class RecipientFilterTest extends TestCase
{
    public function testAllNoneSelectedAndCompanyContactAreUnambiguous(): void
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $db->executeStatement('CREATE TABLE recipients(id INTEGER, user_id INTEGER, company_id INTEGER, tenant INTEGER)');
        foreach ([[1,10,null,1],[2,null,20,1],[3,10,21,1],[4,null,20,2],[5,null,null,1]] as $row) $db->insert('recipients',array_combine(['id','user_id','company_id','tenant'],$row));
        $cases = [
            [[],[1,2,3,5]],
            [['payeurUserMode'=>'all','payeurEntrepriseMode'=>'none'],[1,5]],
            [['payeurUserMode'=>'none','payeurEntrepriseMode'=>'all'],[2,3]],
            [['payeurUserMode'=>'none','payeurEntrepriseMode'=>'none'],[]],
            [['payeurUserMode'=>'selected','payeurUserIds'=>[10],'payeurEntrepriseMode'=>'none'],[1]],
            [['payeurUserMode'=>'none','payeurEntrepriseMode'=>'selected','payeurEntrepriseIds'=>[20]], [2]],
            [['payeurUserMode'=>'selected','payeurUserIds'=>[],'payeurEntrepriseMode'=>'none'],[]],
            [['payeurEntrepriseIds'=>[20]],[2]],
        ];
        foreach ($cases as [$input,$expected]) {
            $filter = RecipientFilter::condition(new InputBag($input),'user_id','company_id');
            $types = array_fill_keys(array_keys($filter['parameters']),ArrayParameterType::INTEGER);
            $actual = $db->fetchFirstColumn('SELECT id FROM recipients WHERE tenant=1 AND '.$filter['sql'].' ORDER BY id',$filter['parameters'],$types);
            self::assertSame($expected,$actual,json_encode($input));
        }
    }
}
