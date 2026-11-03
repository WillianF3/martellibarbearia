<?php
declare(strict_types=1);
/**
 * Testes isolados da regra de um corte VIP por dia, sem conexão com o MySQL.
 * A integração completa deve ser validada no banco de homologação da Hostinger.
 */
class FakeStatementV55 extends PDOStatement {
    private array $args=[];
    public function __construct(private FakePDOv55 $owner,private string $sql) {}
    public function execute(?array $params = null): bool {$this->args=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args): array {
        if(str_contains($this->sql,'mt_vip_appointments')){
            [$client,$ignore,$start,$last]=$this->args;
            return array_values(array_map(static fn($e)=>$e['date'],array_filter($this->owner->appointments,
                static fn($e)=>$e['client']===$client && $e['id']!==$ignore && in_array($e['status'],['pending','confirmed','completed'],true)
                 && $e['date']>=substr($start,0,10) && $e['date']<=$last)));
        }
        [$client,$first,$last]=$this->args;
        return array_values(array_map(static fn($e)=>$e['date'],array_filter($this->owner->uses,
            static fn($e)=>$e['client']===$client && $e['date']>=$first && $e['date']<=$last)));
    }
}
class FakePDOv55 extends PDO {
    public array $appointments=[];
    public array $uses=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new FakeStatementV55($this,$query);}
}
$db=new FakePDOv55();
function db(): PDO { global $db; return $db; }
function fail(string $message,int $status=400): never {throw new DomainException($message,$status);}
require __DIR__.'/../../app/vip_schedule.php';
function verifyV55(bool $check,string $why): void {if(!$check)throw new RuntimeException($why);}
$db->appointments=[
    ['id'=>1,'client'=>10,'status'=>'confirmed','date'=>'2026-11-28'],
    ['id'=>2,'client'=>10,'status'=>'cancelled','date'=>'2026-11-29'],
    ['id'=>3,'client'=>10,'status'=>'pending','date'=>'2026-11-30'],
    ['id'=>4,'client'=>20,'status'=>'confirmed','date'=>'2026-12-01'],
    ['id'=>5,'client'=>10,'status'=>'completed','date'=>'2026-12-02'],
];
$db->uses=[['client'=>10,'date'=>'2026-12-03']];
$days=vip_schedule_blocked_days(10,'2026-11-28','2026-12-04');
verifyV55($days===['2026-11-28','2026-11-30','2026-12-02','2026-12-03'],'Dias bloqueados incorretos');
verifyV55(vip_schedule_blocked_days(10,'2026-11-28','2026-12-04',1)===['2026-11-30','2026-12-02','2026-12-03'],'Remarcação não ignora o próprio corte');
vip_schedule_assert_day_available(10,'2026-11-29');
vip_schedule_assert_day_available(10,'2026-11-28',1);
vip_schedule_assert_day_available(20,'2026-11-28');
foreach(['2026-11-28','2026-11-30','2026-12-02','2026-12-03'] as $day){
    try{vip_schedule_assert_day_available(10,$day);throw new RuntimeException('Não bloqueou '.$day);}
    catch(DomainException $e){verifyV55($e->getCode()===409,'Código HTTP incorreto');}
}
echo "OK: 8 verificações de dias VIP reservados, cancelados e remarcação.\n";
