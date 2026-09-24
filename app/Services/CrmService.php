<?php
declare(strict_types=1);

class CrmService
{
    private $pdo;
    private $user;
    public function __construct(PDO $pdo, array $user) { $this->pdo=$pdo; $this->user=$user; }
    public function canView(): bool { return (int)($this->user['is_admin']??0)===1 || gt_erp_user_can($this->pdo,$this->user,'crm.view'); }
    public function isAdmin(): bool { return (int)($this->user['is_admin']??0)===1 || gt_erp_user_can($this->pdo,$this->user,'crm.admin'); }
    public function visibilitySql(string $alias='l'): array {
        if ($this->isAdmin()) return ['1=1',[]];
        return ['('.$alias.'.visibility="shared" OR '.$alias.'.created_by=? OR '.$alias.'.assigned_to=?)',[(int)$this->user['id'],(int)$this->user['id']]];
    }
    public function leads(): array { list($w,$p)=$this->visibilitySql(); $s=$this->pdo->prepare('SELECT l.*,u.name assigned_name FROM crm_leads l LEFT JOIN users u ON u.id=l.assigned_to WHERE '.$w.' ORDER BY l.updated_at DESC');$s->execute($p);return $s->fetchAll(); }
    public function dashboard(): array {
        list($w,$p)=$this->visibilitySql('l'); $stale=(int)$this->pdo->query("SELECT value FROM crm_settings WHERE key='crm_stale_lead_days'")->fetchColumn();
        $q=$this->pdo->prepare('SELECT COUNT(*) total, SUM(CASE WHEN status NOT IN ("WON","LOST","CONVERTED") THEN 1 ELSE 0 END) active, SUM(CASE WHEN date(created_at)>=date("now","start of month") THEN 1 ELSE 0 END) new_month, SUM(CASE WHEN status NOT IN ("WON","LOST","CONVERTED") AND julianday("now")-julianday(COALESCE(last_activity_at,created_at))>=? THEN 1 ELSE 0 END) stale FROM crm_leads l WHERE '.$w);$q->execute(array_merge([$stale],$p));$leads=$q->fetch();
        $opp=$this->pdo->query('SELECT COUNT(CASE WHEN stage NOT IN ("WON","LOST") THEN 1 END) open_count, COALESCE(SUM(CASE WHEN stage NOT IN ("WON","LOST") THEN estimated_value ELSE 0 END),0) pipeline, COALESCE(SUM(CASE WHEN stage NOT IN ("WON","LOST") THEN estimated_value*probability/100.0 ELSE 0 END),0) weighted, COUNT(CASE WHEN stage="WON" THEN 1 END) won, COUNT(CASE WHEN stage="LOST" THEN 1 END) lost FROM crm_opportunities')->fetch();
        $uid=(int)$this->user['id'];$a=$this->pdo->prepare('SELECT COUNT(CASE WHEN status="PENDING" THEN 1 END) pending, COUNT(CASE WHEN status="PENDING" AND due_datetime<datetime("now") THEN 1 END) overdue FROM crm_activities WHERE assigned_to=?');$a->execute([$uid]);
        return ['leads'=>$leads,'opportunities'=>$opp,'activities'=>$a->fetch(),'customers'=>(int)$this->pdo->query('SELECT COUNT(*) FROM erp_customers WHERE is_active=1')->fetchColumn()];
    }
    public function activities(): array {$s=$this->pdo->prepare('SELECT a.*,u.name creator_name FROM crm_activities a LEFT JOIN users u ON u.id=a.created_by WHERE a.assigned_to=? ORDER BY CASE WHEN a.completed_at IS NULL THEN 0 ELSE 1 END,due_datetime');$s->execute([(int)$this->user['id']]);return $s->fetchAll();}
    public function saveLead(array $d): int {
        $uid=(int)$this->user['id'];$assigned=(int)($d['assigned_to']??$uid);if(!$this->isAdmin())$assigned=$uid;
        $setting=$this->pdo->prepare('SELECT leads_visibility FROM crm_user_settings WHERE user_id=?');$setting->execute([$uid]);$visibility=(string)($setting->fetchColumn()?:'private');
        $s=$this->pdo->prepare('INSERT INTO crm_leads(company,contact_name,email,phone,mobile,website,tax_number,country,district,locality,source,status,priority,potential,estimated_value,probability,segment,notes,expected_decision_date,created_by,assigned_to,visibility) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([trim((string)$d['company']),trim((string)($d['contact_name']??'')),trim((string)($d['email']??'')),trim((string)($d['phone']??'')),trim((string)($d['mobile']??'')),trim((string)($d['website']??'')),trim((string)($d['tax_number']??'')),trim((string)($d['country']??'')),trim((string)($d['district']??'')),trim((string)($d['locality']??'')),trim((string)($d['source']??'')),'NEW',(string)($d['priority']??'MEDIUM'),trim((string)($d['potential']??'')),(float)($d['estimated_value']??0),max(0,min(100,(int)($d['probability']??10))),trim((string)($d['segment']??'')),trim((string)($d['notes']??'')),trim((string)($d['expected_decision_date']??''))?:null,$uid,$assigned,$visibility]);
        $id=(int)$this->pdo->lastInsertId();$this->timeline('lead',$id,'CREATED','Lead criado');return $id;
    }
    public function saveActivity(array $d): int {$uid=(int)$this->user['id'];$s=$this->pdo->prepare('INSERT INTO crm_activities(type,title,description,assigned_to,created_by,related_type,related_id,start_datetime,due_datetime,status,priority,reminder_datetime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([(string)$d['type'],trim((string)$d['title']),trim((string)($d['description']??'')),(int)($d['assigned_to']??$uid),$uid,(string)($d['related_type']??''),(int)($d['related_id']??0)?:null,trim((string)($d['start_datetime']??''))?:null,trim((string)($d['due_datetime']??''))?:null,'PENDING',(string)($d['priority']??'MEDIUM'),trim((string)($d['reminder_datetime']??''))?:null]);$id=(int)$this->pdo->lastInsertId();if(!empty($d['related_type'])&&!empty($d['related_id']))$this->timeline((string)$d['related_type'],(int)$d['related_id'],(string)$d['type'],trim((string)$d['title']));return $id;}
    public function moveOpportunity(int $id,string $stage): void {$allowed=['NEW'=>10,'CONTACTED'=>20,'QUALIFIED'=>40,'PROPOSAL'=>60,'NEGOTIATION'=>80,'WON'=>100,'LOST'=>0];if(!isset($allowed[$stage]))throw new RuntimeException('Fase inválida.');$s=$this->pdo->prepare('SELECT stage,probability_manual FROM crm_opportunities WHERE id=?');$s->execute([$id]);$old=$s->fetch();if(!$old)throw new RuntimeException('Oportunidade não encontrada.');$this->pdo->prepare('UPDATE crm_opportunities SET stage=?,probability=CASE WHEN probability_manual=0 THEN ? ELSE probability END,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$stage,$allowed[$stage],$id]);$this->pdo->prepare('INSERT INTO crm_stage_history(opportunity_id,user_id,previous_stage,new_stage) VALUES (?,?,?,?)')->execute([$id,(int)$this->user['id'],$old['stage'],$stage]);}
    private function timeline(string $type,int $id,string $event,string $title): void {$this->pdo->prepare('INSERT INTO crm_timeline(related_type,related_id,event_type,title,actor_id) VALUES (?,?,?,?,?)')->execute([$type,$id,$event,$title,(int)$this->user['id']]);}
}
