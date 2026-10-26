<?php
require_once __DIR__.'/../app/Services/CentralPrintingSetup.php';
header('Cache-Control: no-store');
$centralPrinting=new CentralPrinting($pdo);$agentCredential=null;
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['print_action'])){
    if(!validate_csrf_or_abort(false)){$flashError='Pedido inválido.';}else try{
        $pa=(string)$_POST['print_action'];
        if($pa==='prepare'){
            $preparation=(new CentralPrintingSetup($pdo))->prepare(__DIR__.'/../storage/backups/printing',$userId);
            $flashSuccess=$preparation['erp_ready']?'ERP preparado para configurar os agentes.':'Preparação concluída com modelos por rever; as configurações existentes foram preservadas.';
            $flashSuccess.=$preparation['enabled']?' A ativação existente foi preservada.':' Envio centralizado desativado.';
            $flashSuccess.=' Modelos criados: '.$preparation['models_created'].'.';
        }
        elseif(!$centralPrinting->installed())throw new RuntimeException('Prepare o sistema primeiro.');
        elseif($pa==='enabled')$centralPrinting->setEnabled(!empty($_POST['enabled']),$userId);
        elseif($pa==='agent')$agentCredential=$centralPrinting->createAgent(trim((string)$_POST['name']),$userId);
        elseif($pa==='revoke'){$pdo->prepare('UPDATE erp_print_agents SET active=0 WHERE id=?')->execute([(string)$_POST['agent_id']]);$centralPrinting->audit($userId,'agent_revoked',null,(string)$_POST['agent_id']);}
        elseif($pa==='rotate'){$token=bin2hex(random_bytes(32));$pdo->prepare('UPDATE erp_print_agents SET token_hash=?,active=1 WHERE id=?')->execute([hash('sha256',$token),(string)$_POST['agent_id']]);$agentCredential=['id'=>(string)$_POST['agent_id'],'token'=>$token];$centralPrinting->audit($userId,'agent_rotated',null,(string)$_POST['agent_id']);}
        elseif($pa==='printer'){
            $id=(int)$_POST['printer_id'];$language=(string)$_POST['language'];$queue=trim((string)$_POST['windows_queue']);$agent=(string)$_POST['agent_id'];$dpi=(int)$_POST['dpi'];$code=trim((string)$_POST['print_code']);$connection=(string)($_POST['connection_type']??'windows_usb');
            if(!in_array($connection,['windows_usb','ipp','raw_tcp'],true)||!in_array($language,['zpl','driver'],true)||$queue===''||strlen($queue)>240||!in_array($dpi,[203,300,600],true)||!preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$code))throw new InvalidArgumentException('Configuração inválida.');
            $s=$pdo->prepare('SELECT inventory FROM erp_print_agents WHERE id=? AND active=1');$s->execute([$agent]);$inventory=json_decode((string)$s->fetchColumn(),true);$names=[];foreach((array)$inventory as $p)$names[]=$p['name'];if(!in_array($queue,$names,true))throw new InvalidArgumentException('Escolha uma fila detetada pelo agente.');
            $s=$pdo->prepare('SELECT id FROM erp_printers WHERE print_code=? AND id<>?');$s->execute([$code,$id]);if($s->fetchColumn())throw new InvalidArgumentException('Código já utilizado.');
            $values=[$code,$language==='zpl'?'labels':'documents',(string)$_POST['brand'],(string)$_POST['model'],$agent,$queue,$language,$dpi,(string)$_POST['notes'],$connection];
            $pdo->prepare("UPDATE erp_printers SET print_code=?,printer_type=?,brand=?,model=?,agent_id=?,windows_queue=?,language=?,dpi=?,notes=?,connection_type=?,online=0,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute(array_merge($values,[$id]));$centralPrinting->audit($userId,'printer_configured',null,(string)$id);
        }elseif($pa==='association'){
            $v=[(int)$_POST['work_center_id'],(int)$_POST['printer_id'],(int)$_POST['type_id'],(int)$_POST['format_id'],(int)$_POST['priority'],!empty($_POST['is_default'])?1:0,!empty($_POST['manual_allowed'])?1:0,!empty($_POST['is_alternative'])?1:0,!empty($_POST['active'])?1:0];
            foreach(['erp_work_centers','erp_printers','erp_print_types','erp_print_formats'] as $i=>$table){$s=$pdo->prepare('SELECT id FROM '.$table.' WHERE id=?');$s->execute([$v[$i]]);if(!$s->fetchColumn())throw new InvalidArgumentException('Associação inválida.');}
            $pdo->beginTransaction();try{
                if($v[5]&&$v[8])$pdo->prepare('UPDATE erp_work_center_printers SET is_default=0 WHERE work_center_id=? AND type_id=? AND format_id=?')->execute([$v[0],$v[2],$v[3]]);
                $pdo->prepare('INSERT OR REPLACE INTO erp_work_center_printers(work_center_id,printer_id,type_id,format_id,priority,is_default,manual_allowed,is_alternative,active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($v);$centralPrinting->audit($userId,'association_saved',null,json_encode($v));$pdo->commit();
            }catch(Throwable $e){$pdo->rollBack();throw $e;}
        }elseif($pa==='type'){
            if(!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',(string)$_POST['code'])||trim((string)$_POST['name'])==='')throw new InvalidArgumentException('Tipo inválido.');
            $pdo->prepare('INSERT INTO erp_print_types(code,name) VALUES (?,?)')->execute([$_POST['code'],$_POST['name']]);$centralPrinting->audit($userId,'type_created');
        }elseif($pa==='format'){
            if(!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',(string)$_POST['code'])||trim((string)$_POST['name'])===''||(float)$_POST['width_mm']<=0||(float)$_POST['height_mm']<=0||!in_array($_POST['orientation'],['portrait','landscape'],true))throw new InvalidArgumentException('Formato inválido.');
            $pdo->prepare('INSERT INTO erp_print_formats(code,name,width_mm,height_mm,orientation) VALUES (?,?,?,?,?)')->execute([$_POST['code'],$_POST['name'],$_POST['width_mm'],$_POST['height_mm'],$_POST['orientation']]);$centralPrinting->audit($userId,'format_created');
        }elseif($pa==='template'){
            if(!in_array($_POST['renderer'],['product_label','raw_material_roll_label','raw_material_ink_label','existing_pdf','existing_zpl'],true)||!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',(string)$_POST['code'])||trim((string)$_POST['name'])==='')throw new InvalidArgumentException('Modelo inválido.');
            $pdo->prepare('INSERT INTO erp_print_templates(code,name,type_id,format_id,renderer) VALUES (?,?,?,?,?)')->execute([$_POST['code'],$_POST['name'],(int)$_POST['type_id'],(int)$_POST['format_id'],$_POST['renderer']]);$centralPrinting->audit($userId,'template_created');
        }elseif($pa==='reroute')$centralPrinting->reroute((string)$_POST['job_id'],(int)$_POST['printer_id'],$userId);
        elseif($pa==='reprint')$centralPrinting->reprint((string)$_POST['job_id'],$userId,!empty($_POST['acknowledge']),(string)$_POST['request_key']);
        elseif($pa==='cancel')$centralPrinting->cancel((string)$_POST['job_id'],$userId);
        elseif($pa==='purge')$centralPrinting->cleanup();
        else throw new InvalidArgumentException('Ação inválida.');
        if(!$flashSuccess)$flashSuccess='Configuração de impressão guardada.';
    }catch(Throwable $e){$flashError=$e->getMessage();}
}
