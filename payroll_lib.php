<?php
/** Payroll calculation and XLSX export services. Kept dependency-free for legacy PHP installations. */

function payroll_user_can(PDO $pdo, $userId, $permission)
{
    $stmt = $pdo->prepare('SELECT is_admin, access_profile FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;
    if ((int) $user['is_admin'] === 1) return true;
    $override = $pdo->prepare('SELECT is_allowed FROM payroll_user_permissions WHERE user_id = ? AND permission_code = ? LIMIT 1');
    $override->execute([(int) $userId, $permission]);
    $allowed = $override->fetchColumn();
    if ($allowed !== false) return (int) $allowed === 1;
    return (string) $user['access_profile'] === 'RH';
}

function payroll_minutes($time)
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim((string) $time), $m) || (int)$m[1] > 23 || (int)$m[2] > 59) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}

/** Returns night overlap in seconds. Both work and night windows may cross midnight. */
function payroll_night_seconds(DateTimeImmutable $start, DateTimeImmutable $end, $nightStart, $nightEnd)
{
    if ($end <= $start) return 0;
    $ns = payroll_minutes($nightStart); $ne = payroll_minutes($nightEnd);
    if ($ns === null || $ne === null) return 0;
    if ($ns === $ne) return $end->getTimestamp() - $start->getTimestamp();
    $total = 0;
    $cursor = $start->modify('-1 day')->setTime(0, 0);
    $last = $end->modify('+1 day')->setTime(0, 0);
    while ($cursor <= $last) {
        $a = $cursor->modify('+' . $ns . ' minutes');
        $b = $ne > $ns ? $cursor->modify('+' . $ne . ' minutes') : $cursor->modify('+1 day +' . $ne . ' minutes');
        $left = max($start->getTimestamp(), $a->getTimestamp());
        $right = min($end->getTimestamp(), $b->getTimestamp());
        if ($right > $left) $total += $right - $left;
        $cursor = $cursor->modify('+1 day');
    }
    return $total;
}

function payroll_schedule_seconds($schedule)
{
    $total = 0;
    foreach ([['start_time','end_time'], ['second_start_time','second_end_time']] as $pair) {
        $a = payroll_minutes(isset($schedule[$pair[0]]) ? $schedule[$pair[0]] : '');
        $b = payroll_minutes(isset($schedule[$pair[1]]) ? $schedule[$pair[1]] : '');
        if ($a !== null && $b !== null) $total += (($b - $a + 1440) % 1440) * 60;
    }
    return max(0, $total - max(0, (int)(isset($schedule['break_minutes']) ? $schedule['break_minutes'] : 0)) * 60);
}

function payroll_absence_code($reason)
{
    $value = trim((string)$reason);
    foreach (['Fr','FJ','FI','BX','AD','DM','OF','Lt','LP','LM','DD','LC'] as $code) {
        if (preg_match('/(^|[^A-Za-z])' . preg_quote($code, '/') . '([^A-Za-z]|$)/i', $value)) return $code;
    }
    $lower=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    foreach (['injust'=>'FI','baixa'=>'BX','luto'=>'Lt','parental'=>'LP','matern'=>'LM','casamento'=>'LC','oferta'=>'OF','desconto'=>'DD','férias'=>'Fr','ferias'=>'Fr'] as $label=>$code) if(strpos($lower,$label)!==false)return $code;
    // Internal MOT-* reasons represent approved/justified absences unless the
    // configured label explicitly identifies another payroll code above.
    if(preg_match('/^MOT-\d+/i',$value))return 'FJ';
    if (preg_match('/\b([A-Za-z]{1,5})\b/', $value, $m)) return $m[1];
    return 'FJ';
}

function payroll_build(PDO $pdo, $year, $month, array $userIds = [], $departmentId = 0, $activeOnly = true)
{
    $year=(int)$year; $month=(int)$month;
    $start=sprintf('%04d-%02d-01',$year,$month); $end=date('Y-m-t',strtotime($start));
    $where=['COALESCE(u.pin_only_login,0)=0']; $params=[];
    if ($activeOnly) { $where[]='(u.hire_date IS NULL OR u.hire_date="" OR date(u.hire_date)<=date(?)) AND (u.termination_date IS NULL OR u.termination_date="" OR date(u.termination_date)>=date(?))'; $params[]=$end; $params[]=$start; }
    if ($departmentId) { $where[]='u.department_id=?'; $params[]=(int)$departmentId; }
    if ($userIds) { $ids=array_values(array_filter(array_map('intval',$userIds))); if ($ids) { $where[]='u.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=array_merge($params,$ids); } }
    $sql='SELECT u.id,u.name,u.user_number,u.tax_number,u.social_security_number,u.hire_date,u.termination_date,u.department_id,u.schedule_id,d.name department_name,s.name schedule_name,s.start_time,s.end_time,s.second_start_time,s.second_end_time,s.break_minutes,s.weekdays_mask FROM users u LEFT JOIN hr_departments d ON d.id=u.department_id LEFT JOIN hr_schedules s ON s.id=u.schedule_id WHERE '.implode(' AND ',$where).' ORDER BY u.name COLLATE NOCASE';
    $st=$pdo->prepare($sql); $st->execute($params); $users=$st->fetchAll(PDO::FETCH_ASSOC);
    $nightStart=app_setting($pdo,'payroll_night_start','22:00'); $nightEnd=app_setting($pdo,'payroll_night_end','07:00');
    $result=['year'=>$year,'month'=>$month,'start'=>$start,'end'=>$end,'days'=>(int)date('t',strtotime($start)),'rows'=>[],'details'=>[],'attendance'=>[],'holidays'=>[],'warnings'=>[]];
    $holidayStmt=$pdo->prepare('SELECT start_date,end_date FROM hr_calendar_events WHERE event_type="Feriado" AND date(start_date)<=date(?) AND date(end_date)>=date(?)');$holidayStmt->execute([$end,$start]);foreach($holidayStmt as $holiday){$a=max($start,$holiday['start_date']);$b=min($end,$holiday['end_date']);for($d=$a;$d<=$b;$d=date('Y-m-d',strtotime($d.' +1 day')))$result['holidays'][]=$d;}
    foreach ($users as $u) {
        $uid=(int)$u['id']; $summary=['user'=>$u,'worked_days'=>0,'day_hours'=>0,'night_hours'=>0,'extra_first'=>0,'extra_following'=>0,'extra_night'=>0,'rest_hours'=>0,'meal_days'=>0,'codes'=>[],'manual'=>[],'observations'=>''];
        if (empty($u['schedule_id'])) $result['warnings'][]=['user'=>$u['name'],'date'=>'','problem'=>'Colaborador sem horário definido.'];
        $q=$pdo->prepare('SELECT entry_type,occurred_at,note FROM shopfloor_time_entries WHERE user_id=? AND datetime(occurred_at)>=datetime(?) AND datetime(occurred_at)<datetime(?,"+1 day") ORDER BY datetime(occurred_at),id'); $q->execute([$uid,$start,$end]);
        $entries=$q->fetchAll(PDO::FETCH_ASSOC); $byDay=[];
        foreach($entries as $entry) { $day=substr($entry['occurred_at'],0,10); $byDay[$day][]=$entry; }
        $breakQ=$pdo->prepare('SELECT started_at,ended_at FROM shopfloor_break_entries WHERE user_id=? AND datetime(started_at)<datetime(?,"+1 day") AND (ended_at IS NULL OR datetime(ended_at)>=datetime(?))'); $breakQ->execute([$uid,$end,$start]); $breaks=$breakQ->fetchAll(PDO::FETCH_ASSOC);
        foreach($byDay as $day=>$dayEntries) {
            $segments=[]; $open=null;
            foreach($dayEntries as $entry) {
                $dt=new DateTimeImmutable($entry['occurred_at']);
                if ($entry['entry_type']==='entrada') { if ($open!==null) $result['warnings'][]=['user'=>$u['name'],'date'=>$day,'problem'=>'Registos sobrepostos ou duas entradas consecutivas.']; else $open=$dt; }
                elseif ($entry['entry_type']==='saida') { if ($open===null) $result['warnings'][]=['user'=>$u['name'],'date'=>$day,'problem'=>'Saída sem entrada correspondente.']; elseif ($dt<=$open) $result['warnings'][]=['user'=>$u['name'],'date'=>$day,'problem'=>'Intervalo de ponto inválido.']; else { $segments[]=[$open,$dt]; $open=null; } }
            }
            if ($open!==null) $result['warnings'][]=['user'=>$u['name'],'date'=>$day,'problem'=>'Ponto sem saída.'];
            $worked=0; $night=0; $pause=0; $firstEntry=''; $lastExit='';
            foreach($segments as $seg) {
                if ($firstEntry==='') $firstEntry=$seg[0]->format('H:i'); $lastExit=$seg[1]->format('H:i');
                $seconds=$seg[1]->getTimestamp()-$seg[0]->getTimestamp(); $segNight=payroll_night_seconds($seg[0],$seg[1],$nightStart,$nightEnd);
                foreach($breaks as $br) if (!empty($br['ended_at'])) { $ba=new DateTimeImmutable($br['started_at']);$bb=new DateTimeImmutable($br['ended_at']);$l=max($seg[0]->getTimestamp(),$ba->getTimestamp());$r=min($seg[1]->getTimestamp(),$bb->getTimestamp());if($r>$l){$cut=$r-$l;$seconds-=$cut;$segNight-=payroll_night_seconds((new DateTimeImmutable())->setTimestamp($l),(new DateTimeImmutable())->setTimestamp($r),$nightStart,$nightEnd);$pause+=$cut;} }
                $worked+=max(0,$seconds);$night+=max(0,$segNight);
            }
            if ($worked<=0) continue;
            $planned=payroll_schedule_seconds($u); $overtime=max(0,$worked-$planned); $normal=$worked-$overtime; $nightNormal=min($night,$normal); $nightExtra=max(0,$night-$nightNormal);
            $week=(int)date('N',strtotime($day)); $mask=array_map('intval',explode(',',(string)$u['weekdays_mask'])); $rest=!in_array($week,$mask,true);
            $summary['worked_days']++;$summary['meal_days']++;$summary['day_hours']+=($normal-$nightNormal)/3600;$summary['night_hours']+=$nightNormal/3600;$summary['extra_first']+=min(3600,$overtime)/3600;$summary['extra_following']+=max(0,$overtime-3600)/3600;$summary['extra_night']+=$nightExtra/3600;if($rest)$summary['rest_hours']+=$worked/3600;
            $result['details'][]=['date'=>$day,'code'=>$u['user_number'],'name'=>$u['name'],'entry'=>$firstEntry,'exit'=>$lastExit,'pause'=>$pause/3600,'worked'=>$worked/3600,'day'=>($worked-$night)/3600,'night'=>$night/3600,'extra'=>$overtime/3600,'extra_night'=>$nightExtra/3600,'type'=>$rest?'Folga':($week>=6?'Fim de semana':'Normal'),'note'=>''];
        }
        $vac=$pdo->prepare('SELECT start_date,end_date FROM hr_vacation_events WHERE user_id=? AND status="Aprovado" AND date(start_date)<=date(?) AND date(end_date)>=date(?)');$vac->execute([$uid,$end,$start]);foreach($vac as $v){$a=max($start,$v['start_date']);$b=min($end,$v['end_date']);for($d=$a;$d<=$b;$d=date('Y-m-d',strtotime($d.' +1 day')))$summary['codes'][$d]='Fr';}
        $abs=$pdo->prepare('SELECT start_date,end_date,reason,details FROM shopfloor_absence_requests WHERE user_id=? AND status LIKE "Aprovado%" AND date(start_date)<=date(?) AND date(end_date)>=date(?)');$abs->execute([$uid,$end,$start]);foreach($abs as $a){$code=payroll_absence_code($a['reason']);$x=max($start,$a['start_date']);$b=min($end,$a['end_date']);for($d=$x;$d<=$b;$d=date('Y-m-d',strtotime($d.' +1 day'))){if(isset($byDay[$d]))$result['warnings'][]=['user'=>$u['name'],'date'=>$d,'problem'=>'Ausência e presença registadas no mesmo dia.'];$summary['codes'][$d]=$code;}}
        if (!empty($u['hire_date'])&&substr($u['hire_date'],0,7)===substr($start,0,7)) $summary['codes'][substr($u['hire_date'],0,10)]='AD';
        if (!empty($u['termination_date'])&&substr($u['termination_date'],0,7)===substr($start,0,7)) $summary['codes'][substr($u['termination_date'],0,10)]='DM';
        $m=$pdo->prepare('SELECT variable_type,value,observation FROM payroll_variables WHERE user_id=? AND payroll_year=? AND payroll_month=?');$m->execute([$uid,$year,$month]);foreach($m as $v){$summary['manual'][$v['variable_type']]=(float)$v['value'];if(trim((string)$v['observation'])!=='')$summary['observations'].=($summary['observations']?' | ':'').$v['observation'];}
        foreach($summary['codes'] as $date=>$code)$result['attendance'][$uid][$date]=$code;
        $result['rows'][]=$summary;
    }
    return $result;
}

function payroll_xml($value) { return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function payroll_col($n){$s='';while($n>0){$n--;$s=chr(65+$n%26).$s;$n=intdiv($n,26);}return $s;}
function payroll_sheet_xml(array $rows, array $moneyColumns=[], array $weekendColumns=[], $headerRow=0)
{
    $split=$headerRow+1;$xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$split.'" topLeftCell="A'.($split+1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
    foreach($rows as $ri=>$row){$r=$ri+1;$xml.='<row r="'.$r.'">';foreach(array_values($row) as $ci=>$v){$c=payroll_col($ci+1).$r;$style=$ri===$headerRow?1:(in_array($ci,$moneyColumns,true)?2:(in_array($ci,$weekendColumns,true)?3:0));if(is_int($v)||is_float($v))$xml.='<c r="'.$c.'" s="'.$style.'"><v>'.$v.'</v></c>';else$xml.='<c r="'.$c.'" s="'.$style.'" t="inlineStr"><is><t>'.payroll_xml($v).'</t></is></c>';}$xml.='</row>';}$lastCol=payroll_col(count($rows[$headerRow]));$xml.='</sheetData><autoFilter ref="A'.($headerRow+1).':'.$lastCol.count($rows).'"/><pageSetup orientation="landscape" fitToWidth="1"/></worksheet>';return $xml;
}
function payroll_export_xlsx(PDO $pdo,array $data,$path)
{
    if(!class_exists('ZipArchive')) throw new RuntimeException('A extensão ZipArchive é necessária para gerar XLSX.');
    $headers=['Cód.','Colaborador','NIF','NISS','Data Admissão','Data Saída','Dias Trabalhados','Horas Diurnas','Horas Noturnas','Extra 1ª Hora','Extra 2ª Hora+','Extra Noturna','Sábado/Folga','Dias Subsídio Alimentação','Férias','Falta Justificada','Falta Injustificada','Baixa','Luto','Licença Parental','Licença Casamento','Comissões €','Ajudas Custo €','KM','Prémio €','Subsídios Extra €','Gratificação €','Outros €','Observações'];$blank=array_fill(0,count($headers),'');$title=$blank;$title[0]='TISSER · Export Payroll';$company=$blank;$company[0]='Empresa: '.app_setting($pdo,'company_name','TISSER');$company[1]='NIF: '.app_setting($pdo,'company_tax_number','');$period=$blank;$period[0]=sprintf('Período: %02d/%04d',$data['month'],$data['year']);$period[1]='Gerado em: '.date('Y-m-d H:i:s');$rows=[$title,$company,$period,$blank,$headers];
    foreach($data['rows'] as $s){$counts=array_count_values(array_values($s['codes']));$m=$s['manual'];$u=$s['user'];$rows[]=[(string)$u['user_number'],$u['name'],(string)$u['tax_number'],(string)$u['social_security_number'],(string)$u['hire_date'],(string)$u['termination_date'],$s['worked_days'],round($s['day_hours'],2),round($s['night_hours'],2),round($s['extra_first'],2),round($s['extra_following'],2),round($s['extra_night'],2),round($s['rest_hours'],2),$s['meal_days'],isset($counts['Fr'])?$counts['Fr']:0,isset($counts['FJ'])?$counts['FJ']:0,isset($counts['FI'])?$counts['FI']:0,isset($counts['BX'])?$counts['BX']:0,isset($counts['Lt'])?$counts['Lt']:0,isset($counts['LP'])?$counts['LP']:0,isset($counts['LC'])?$counts['LC']:0,isset($m['commission'])?$m['commission']:0,isset($m['expenses'])?$m['expenses']:0,isset($m['km'])?$m['km']:0,isset($m['bonus'])?$m['bonus']:0,isset($m['extra_subsidy'])?$m['extra_subsidy']:0,isset($m['balance_bonus'])?$m['balance_bonus']:0,isset($m['other'])?$m['other']:0,$s['observations']];}
    $att=[array_merge(['Cód.','Funcionário'],range(1,$data['days']),['Observações'])];$weekCols=[];for($d=1;$d<=$data['days'];$d++){$date=sprintf('%04d-%02d-%02d',$data['year'],$data['month'],$d);if((int)date('N',strtotime($date))>=6||in_array($date,isset($data['holidays'])?$data['holidays']:[],true))$weekCols[]=$d+1;}foreach($data['rows'] as $s){$r=[(string)$s['user']['user_number'],$s['user']['name']];for($d=1;$d<=$data['days'];$d++){$date=sprintf('%04d-%02d-%02d',$data['year'],$data['month'],$d);$r[]=isset($s['codes'][$date])?$s['codes'][$date]:'';}$r[]=$s['observations'];$att[]=$r;}
    $detail=[['Data','Código colaborador','Nome','Entrada','Saída','Pausa','Horas trabalhadas','Horas diurnas','Horas noturnas','Horas extra','Horas extra noturnas','Tipo de dia','Observação']];foreach($data['details'] as $d)$detail[]=array_values($d);
    $sheets=[payroll_sheet_xml($rows,[21,22,24,25,26,27],[],4),payroll_sheet_xml($att,[],$weekCols),payroll_sheet_xml($detail)];
    $logoSetting=(string)app_setting($pdo,'logo_report_dark','');$logoPath=$logoSetting!==''?dirname(__FILE__).'/'.ltrim($logoSetting,'/'):'';$logoExt=strtolower(pathinfo($logoPath,PATHINFO_EXTENSION));$hasLogo=is_file($logoPath)&&in_array($logoExt,['png','jpg','jpeg'],true);if($hasLogo){$sheets[0]=str_replace('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',$sheets[0]);$sheets[0]=str_replace('</worksheet>','<drawing r:id="rId1"/></worksheet>',$sheets[0]);}
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível criar o XLSX.');
    $imageTypes=$hasLogo?'<Default Extension="'.($logoExt==='jpeg'?'jpg':$logoExt).'" ContentType="image/'.($logoExt==='jpg'?'jpeg':$logoExt).'"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>':'';$zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.$imageTypes.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.implode('',array_map(function($i){return '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';},[1,2,3])).'</Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Payroll" sheetId="1" r:id="rId1"/><sheet name="Assiduidade" sheetId="2" r:id="rId2"/><sheet name="Detalhe Horas" sheetId="3" r:id="rId3"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml','<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="[$€-pt-PT] #,##0.00"/></numFmts><fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF222222"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill></fills><borders count="1"><border/></borders><cellXfs count="4"><xf/><xf fontId="1" fillId="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf fillId="2" applyFill="1"/></cellXfs></styleSheet>');
    if($hasLogo){$mediaExt=$logoExt==='jpeg'?'jpg':$logoExt;$zip->addFile($logoPath,'xl/media/logo.'.$mediaExt);$zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');$zip->addFromString('xl/drawings/_rels/drawing1.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.'.$mediaExt.'"/></Relationships>');$zip->addFromString('xl/drawings/drawing1.xml','<?xml version="1.0"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><xdr:twoCellAnchor><xdr:from><xdr:col>24</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:to><xdr:col>28</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>3</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="Logo TISSER"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>');}
    foreach($sheets as $i=>$xml)$zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml',$xml);$zip->close();
}
