<?php
declare(strict_types=1);


if (!class_exists('ProductionDossierService', false)) {
final class ProductionDossierService
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function createSnapshot(int $orderId, int $articleId, int $userId, array $orderData = []): int
    {
        $article = $this->row('SELECT fp.*,c.code customer_code,c.name customer_name FROM erp_finished_products fp LEFT JOIN erp_customers c ON c.id=fp.customer_id WHERE fp.id=?',[$articleId]);
        if (!$article) throw new RuntimeException('Artigo não encontrado para gerar o dossier.');
        $version = $this->row('SELECT * FROM erp_article_technical_sheet_versions WHERE finished_product_id=? AND status="approved" AND (effective_from IS NULL OR effective_from<=date("now")) ORDER BY version_no DESC LIMIT 1',[$articleId]);
        if (!$version) {
            $next=(int)$this->scalar('SELECT COALESCE(MAX(version_no),0)+1 FROM erp_article_technical_sheet_versions WHERE finished_product_id=?',[$articleId]);
            $master=$this->articleSnapshot($articleId,$article);
            $this->pdo->prepare('INSERT INTO erp_article_technical_sheet_versions(finished_product_id,version_no,status,effective_from,snapshot_json,created_by,approved_by,approved_at) VALUES (?,?,"approved",date("now"),?,?,?,CURRENT_TIMESTAMP)')->execute([$articleId,$next,json_encode($master,JSON_UNESCAPED_UNICODE),$userId,$userId]);
            $version=['id'=>(int)$this->pdo->lastInsertId(),'version_no'=>$next,'snapshot_json'=>json_encode($master,JSON_UNESCAPED_UNICODE)];
        }
        $snapshot=json_decode((string)$version['snapshot_json'],true) ?: [];
        $snapshot['_order']=$orderData;
        $snapshot['_order']['id']=$orderId;
        $snapshot['_technical_sheet_version']=(int)$version['version_no'];
        $this->pdo->prepare('INSERT OR IGNORE INTO erp_production_order_snapshots(production_order_id,technical_sheet_version_id,snapshot_json,created_by) VALUES (?,?,?,?)')->execute([$orderId,(int)$version['id'],json_encode($snapshot,JSON_UNESCAPED_UNICODE),$userId]);
        $snapshotId=(int)$this->scalar('SELECT id FROM erp_production_order_snapshots WHERE production_order_id=?',[$orderId]);
        $token=bin2hex(random_bytes(24));
        $this->pdo->prepare('UPDATE erp_production_orders SET technical_sheet_version_id=?,snapshot_id=?,public_token=COALESCE(public_token,?) WHERE id=?')->execute([(int)$version['id'],$snapshotId,$token,$orderId]);
        return $snapshotId;
    }

    public function articleSnapshot(int $articleId, array $article = []): array
    {
        if (!$article) $article=$this->row('SELECT fp.*,c.code customer_code,c.name customer_name FROM erp_finished_products fp LEFT JOIN erp_customers c ON c.id=fp.customer_id WHERE fp.id=?',[$articleId]);
        $article['_materials']=$this->all('SELECT am.raw_material_id,rm.code,rm.description,rm.product_category type,u.code unit_code,am.quantity_per_unit,am.waste_percent,rm.standard_price FROM erp_article_materials am JOIN erp_raw_materials rm ON rm.id=am.raw_material_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE am.finished_product_id=? ORDER BY rm.code',[$articleId]);
        $article['_colors']=$this->all('SELECT pc.color_order,pc.face,COALESCE(c.name,pc.ink_type) color,pc.pantone,pc.planned_quantity,i.code ink_code,i.description ink_description FROM erp_product_colors pc LEFT JOIN erp_colors c ON c.id=pc.color_id LEFT JOIN erp_inks i ON i.pantone=pc.pantone WHERE pc.finished_product_id=? ORDER BY pc.face,pc.color_order',[$articleId]);
        $article['_documents']=$this->all('SELECT id,title,file_url,document_type,version FROM erp_product_documents WHERE entity_type="finished_product" AND entity_id=? AND status="Ativo" ORDER BY CASE WHEN document_type="production_main" THEN 0 ELSE 1 END,id',[$articleId]);
        return $article;
    }

    public function dossier(int $orderId): array
    {
        $order=$this->row('SELECT o.*,c.code customer_code,c.name customer_name,fp.code article_code,fp.description article_description,ts.version_no technical_version FROM erp_production_orders o LEFT JOIN erp_customers c ON c.id=o.customer_id LEFT JOIN erp_finished_products fp ON fp.id=o.finished_product_id LEFT JOIN erp_article_technical_sheet_versions ts ON ts.id=o.technical_sheet_version_id WHERE o.id=?',[$orderId]);
        if (!$order) throw new RuntimeException('Ordem de Fabrico não encontrada.');
        $snap=$this->row('SELECT snapshot_json FROM erp_production_order_snapshots WHERE production_order_id=?',[$orderId]);
        if (!$snap) $snap=$this->row('SELECT snapshot_json FROM erp_technical_sheets WHERE production_order_id=?',[$orderId]);
        $snapshot=$snap ? (json_decode((string)$snap['snapshot_json'],true) ?: []) : [];
        $operations=$this->all('SELECT opo.*,COALESCE(opo.operation_code,op.code) code,COALESCE(opo.operation_name,op.name) name,COALESCE(m.name,pm.name) machine_name,COALESCE(SUM(te.quantity_good),0) quantity_good,COALESCE(SUM(te.quantity_rejected),0) quantity_rejected,MIN(te.started_at) started_at,MAX(te.ended_at) ended_at,COALESCE(SUM((julianday(COALESCE(te.ended_at,CURRENT_TIMESTAMP))-julianday(te.started_at))*1440-te.pause_seconds/60.0),0) actual_minutes FROM erp_production_order_operations opo JOIN erp_operations op ON op.id=opo.operation_id LEFT JOIN erp_operation_time_entries te ON te.production_order_operation_id=opo.id LEFT JOIN erp_machines m ON m.id=te.selected_machine_id LEFT JOIN erp_machines pm ON pm.id=opo.primary_machine_id WHERE opo.production_order_id=? GROUP BY opo.id ORDER BY opo.sequence_no,opo.id',[$orderId]);
        $consumptions=$this->all('SELECT pc.*,COALESCE(rm.code,p.code) code,COALESCE(rm.description,p.description) description,u.code unit_code FROM erp_production_consumptions pc LEFT JOIN erp_raw_materials rm ON rm.id=pc.raw_material_id LEFT JOIN erp_products p ON p.id=pc.product_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE pc.production_order_id=? ORDER BY pc.id',[$orderId]);
        $costs=$this->calculateCosts($order,$snapshot,$operations,$consumptions);
        $good=0.0;$rejected=0.0;$minutes=0.0;foreach($operations as $op){$good=max($good,(float)$op['quantity_good']);$rejected+=(float)$op['quantity_rejected'];$minutes+=(float)$op['actual_minutes'];}
        $planned=(float)$order['planned_quantity'];$metrics=['good'=>$good,'rejected'=>$rejected,'missing'=>max(0,$planned-$good),'excess'=>max(0,$good-$planned),'efficiency'=>$planned>0?100*$good/$planned:0,'waste_percent'=>($good+$rejected)>0?100*$rejected/($good+$rejected):0,'actual_minutes'=>$minutes,'planned_minutes'=>array_sum(array_map(function($x){return(float)$x['planned_minutes'];},$operations)),'planned_cost'=>$costs['planned_total'],'actual_cost'=>$costs['actual_total'],'unit_cost'=>$good>0?$costs['actual_total']/$good:0,'thousand_cost'=>$good>0?$costs['actual_total']/$good*1000:0];
        $closeReport=$this->closeReport($orderId,$metrics,$costs);
        return compact('order','snapshot','operations','consumptions','costs','metrics','closeReport');
    }

    public function closeReport(int $orderId,array $metrics=[],array $costs=[]): array
    {
        $defaults=['produced_quantity'=>$metrics['good']??0,'waste_kg'=>$metrics['rejected']??0,'waste_percent'=>$metrics['waste_percent']??0,'sale_unit_price'=>0,'pallet_count'=>0,'pallet_details'=>'','notes'=>''];
        foreach(['materia_prima','tintas','diluente','acelerador','retardador','outro','impressora','corte_e_cose','cliche','energia','embalagem','caixas','transporte'] as $key)$defaults['cost_'.$key]=0;
        foreach((array)($costs['rows']??[]) as $row){$category=(string)($row['category']??'');if($category==='Matérias-primas')$defaults['cost_materia_prima']=(float)$row['actual'];elseif($category==='Máquina')$defaults['cost_impressora']=(float)$row['actual'];elseif($category==='Mão de obra')$defaults['cost_corte_e_cose']=(float)$row['actual'];}
        $saved=$this->row('SELECT report_json FROM erp_production_order_close_reports WHERE production_order_id=?',[$orderId]);
        return array_merge($defaults,$saved?(json_decode((string)$saved['report_json'],true)?:[]):[]);
    }

    public function saveCloseReport(int $orderId,int $userId,array $input,string $reason=''): array
    {
        $d=$this->dossier($orderId);$old=$d['closeReport'];$clean=[];$numeric=['produced_quantity','waste_kg','waste_percent','sale_unit_price','pallet_count'];
        foreach($old as $key=>$value){if(strpos($key,'cost_')===0||in_array($key,$numeric,true))$clean[$key]=max(0,(float)str_replace(',','.',(string)($input[$key]??$value)));else$clean[$key]=trim((string)($input[$key]??$value));}
        if($clean==$old)return $old;
        $json=json_encode($clean,JSON_UNESCAPED_UNICODE);$this->pdo->beginTransaction();
        try{$exists=$this->scalar('SELECT id FROM erp_production_order_close_reports WHERE production_order_id=?',[$orderId]);if($exists)$this->pdo->prepare('UPDATE erp_production_order_close_reports SET report_json=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE production_order_id=?')->execute([$json,$userId,$orderId]);else$this->pdo->prepare('INSERT INTO erp_production_order_close_reports(production_order_id,report_json,updated_by) VALUES (?,?,?)')->execute([$orderId,$json,$userId]);$this->pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,old_value_json,new_value_json,reason) VALUES (?,? ,"update_close_report",?,?,?)')->execute([$orderId,$userId,json_encode($old,JSON_UNESCAPED_UNICODE),$json,$reason?:null]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $clean;
    }

    public function closureIssues(array $d): array
    {
        $issues=[];foreach($d['operations'] as $op){if((string)$op['status']!=='Concluída')$issues[]='Operação '.$op['code'].' — '.$op['name'].' ainda não está concluída.';if((float)$op['actual_minutes']<=0)$issues[]='Falta registo de tempo na operação '.$op['code'].'.';}
        if((float)$d['metrics']['good']<=0)$issues[]='Falta registar quantidade boa produzida.';
        if(!$d['consumptions'])$issues[]='Faltam consumos reais ou movimentos de stock associados à OF.';
        return array_values(array_unique($issues));
    }

    public function close(int $orderId,int $userId,bool $override,string $reason): array
    {
        $d=$this->dossier($orderId);$issues=$this->closureIssues($d);
        if($issues&&!$override)throw new RuntimeException("Não é possível fechar a OF:\n• ".implode("\n• ",$issues));
        if($issues&&trim($reason)==='')throw new RuntimeException('O motivo do override é obrigatório.');
        $this->pdo->prepare('INSERT INTO erp_production_order_closures(production_order_id,metrics_json,total_planned_cost,total_actual_cost,override_reason,closed_by) VALUES (?,?,?,?,?,?)')->execute([$orderId,json_encode($d['metrics'],JSON_UNESCAPED_UNICODE),(float)$d['metrics']['planned_cost'],(float)$d['metrics']['actual_cost'],$issues?$reason:null,$userId]);
        $this->pdo->prepare('UPDATE erp_production_orders SET status="Fechada",produced_quantity=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(float)$d['metrics']['good'],$orderId]);
        $this->pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,new_value_json,reason) VALUES (?,? ,"close",?,?)')->execute([$orderId,$userId,json_encode($d['metrics'],JSON_UNESCAPED_UNICODE),$issues?$reason:null]);
        return $d;
    }

    private function calculateCosts(array $order,array $snapshot,array $ops,array $cons): array
    {
        $planned=[];$actual=[];
        foreach((array)($snapshot['_materials']??[]) as $m){$q=(float)($m['quantity_per_unit']??0)*(float)$order['planned_quantity']*(1+(float)($m['waste_percent']??0)/100);$planned['Matérias-primas'] = ($planned['Matérias-primas']??0)+$q*(float)($m['standard_price']??0);}
        foreach($cons as $c){$actual['Matérias-primas']=($actual['Matérias-primas']??0)+(float)$c['quantity']*(float)$c['unit_cost'];}
        foreach($ops as $op){$machineId=(int)($op['selected_machine_id']?:$op['primary_machine_id']);if($machineId>0){$rate=$this->activeRate('machine',$machineId);$planned['Máquina']=($planned['Máquina']??0)+(float)$op['planned_minutes']/60*$rate;$actual['Máquina']=($actual['Máquina']??0)+(float)$op['actual_minutes']/60*$rate;}$labour=$this->activeRate('operator',0);$planned['Mão de obra']=($planned['Mão de obra']??0)+(float)$op['planned_minutes']/60*$labour*(int)$op['operators_count'];$actual['Mão de obra']=($actual['Mão de obra']??0)+(float)$op['actual_minutes']/60*$labour*(int)$op['operators_count'];}
        $categories=array_values(array_unique(array_merge(array_keys($planned),array_keys($actual))));$rows=[];foreach($categories as $cat){$p=$planned[$cat]??0;$a=$actual[$cat]??0;$rows[]=['category'=>$cat,'planned'=>$p,'actual'=>$a,'difference'=>$a-$p];}
        return ['rows'=>$rows,'planned_total'=>array_sum($planned),'actual_total'=>array_sum($actual)];
    }
    private function activeRate(string $type,int $id): float {$sql='SELECT hourly_rate FROM erp_production_cost_rates WHERE cost_type=? AND is_active=1 AND (reference_id=? OR reference_id IS NULL) AND valid_from<=date("now") AND (valid_until IS NULL OR valid_until>=date("now")) ORDER BY CASE WHEN reference_id=? THEN 0 ELSE 1 END,valid_from DESC LIMIT 1';return(float)$this->scalar($sql,[$type,$id,$id]);}
    private function row(string $sql,array $p=[]){$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetch(PDO::FETCH_ASSOC);}
    private function all(string $sql,array $p=[]):array{$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function scalar(string $sql,array $p=[]){$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetchColumn();}
}
}

// Article helpers live here as a deployment fallback because some production
// jobs publish root entry points without publishing application service files.
if (!class_exists('ArticleDocument', false)) {
final class ArticleDocument
{
    /** Maximum size accepted for each document attached to an article (20 MiB). */
    // Class-constant visibility is only supported from PHP 7.1 onwards. Keep
    // this declaration compatible with the PHP 7.0 runtime used in production.
    const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    /**
     * Validate the size-related upload metadata before the file is persisted.
     *
     * PHP may discard an oversized temporary file, so checking only the size in
     * UploadService would turn that case into an ambiguous format error.
     */
    public static function validateUploadSize(array $file)
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);

        if ($error === UPLOAD_ERR_INI_SIZE) {
            $serverLimit = trim((string) ini_get('upload_max_filesize'));
            throw new RuntimeException(
                'O servidor rejeitou o documento por causa do limite de upload configurado'
                . ($serverLimit !== '' ? ' (' . $serverLimit . ')' : '')
                . '. O nome do ficheiro não causa este erro; contacte o administrador se o documento tiver menos de 20 MB.'
            );
        }

        if ($error === UPLOAD_ERR_FORM_SIZE || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Cada documento do artigo deve ter no máximo 20 MB.');
        }
    }

    public static function url(int $documentId): string
    {
        return 'erp.php?page=article_document&id=' . $documentId;
    }

    public static function thumbnailUrl(int $documentId): string
    {
        return 'erp.php?page=article_document_thumbnail&id=' . $documentId;
    }

    /** Use the explicitly selected artwork, with a legacy fallback for old data. */
    public static function mainArtwork(array $documents)
    {
        foreach ($documents as $document) {
            if (is_array($document) && (string) ($document['document_type'] ?? '') === 'production_main') {
                $kind = self::presentation((string) ($document['file_url'] ?? ''))['kind'];
                if (in_array($kind, ['image', 'pdf'], true)) return $document;
            }
        }

        $ranked = [];
        foreach ($documents as $position => $document) {
            if (!is_array($document)) continue;
            $kind = self::presentation((string) ($document['file_url'] ?? ''))['kind'];
            if (!in_array($kind, ['image', 'pdf'], true)) continue;
            $rank = $kind === 'image' ? 0 : 1;
            $ranked[] = [$rank, (int) $position, $document];
        }
        usort($ranked, function (array $left, array $right): int {
            return $left[0] === $right[0] ? $left[1] <=> $right[1] : $left[0] <=> $right[0];
        });
        return $ranked ? $ranked[0][2] : null;
    }

    /**
     * Create a printable first-page preview. Images are normalised with GD and
     * PDFs use Imagick when the server has the PDF delegate enabled.
     */
    public static function thumbnail(string $absolutePath, int $maxWidth = 1200, int $maxHeight = 900): string
    {
        if ($absolutePath === '' || !is_file($absolutePath)) return '';
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        $image = null;

        if ($extension === 'pdf' && class_exists('Imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(144, 144);
                $imagick->readImage($absolutePath . '[0]');
                $imagick->setImageBackgroundColor('white');
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $imagick->thumbnailImage($maxWidth, $maxHeight, true, true);
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(86);
                $blob = $imagick->getImageBlob();
                $imagick->clear();
                if (is_string($blob) && $blob !== '') return $blob;
            } catch (Throwable $exception) {
                // Try the command-line PDF renderers below. Some Imagick builds
                // deliberately disable PDF while Poppler remains available.
            }
        }

        if ($extension === 'pdf') {
            $blob = self::rasterisePdf($absolutePath, $maxWidth, $maxHeight);
            if ($blob !== '') return $blob;
        } elseif (function_exists('imagecreatefromstring')) {
            $source = @file_get_contents($absolutePath);
            $image = is_string($source) ? @imagecreatefromstring($source) : false;
            if ($image) {
                $width = imagesx($image); $height = imagesy($image);
                $scale = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));
                $thumb = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
                $white = imagecolorallocate($thumb, 255, 255, 255); imagefill($thumb, 0, 0, $white);
                imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), $width, $height);
                ob_start(); imagejpeg($thumb, null, 86); $blob = (string) ob_get_clean();
                imagedestroy($thumb); imagedestroy($image);
                if ($blob !== '') return $blob;
            }
        }

        return self::placeholderThumbnail($extension === 'pdf' ? 'PDF' : 'DOCUMENTO');
    }

    /** Rasterise page one with Poppler or Ghostscript (compatible with PHP 7). */
    private static function rasterisePdf(string $absolutePath, int $maxWidth, int $maxHeight): string
    {
        if (!function_exists('proc_open')) return '';
        $temporaryBase = tempnam(sys_get_temp_dir(), 'gt-artwork-');
        if ($temporaryBase === false) return '';
        @unlink($temporaryBase);
        $commands = [
            ['pdftoppm', '-f', '1', '-singlefile', '-jpeg', '-jpegopt', 'quality=90', '-scale-to-x', (string) $maxWidth, '-scale-to-y', (string) $maxHeight, $absolutePath, $temporaryBase],
            ['gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dFirstPage=1', '-dLastPage=1', '-sDEVICE=jpeg', '-dJPEGQ=90', '-r144', '-dPDFFitPage', '-g' . $maxWidth . 'x' . $maxHeight, '-sOutputFile=' . $temporaryBase . '.jpg', $absolutePath],
        ];
        foreach ($commands as $command) {
            $pipes = [];
            $escapedCommand = implode(' ', array_map('escapeshellarg', $command));
            $process = @proc_open($escapedCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (!is_resource($process)) continue;
            fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
            $status = proc_close($process);
            $output = $temporaryBase . '.jpg';
            if ($status === 0 && is_file($output)) {
                $blob = (string) @file_get_contents($output); @unlink($output);
                if (substr($blob, 0, 2) === "\xFF\xD8") return $blob;
            }
            @unlink($output);
        }
        return '';
    }

    private static function placeholderThumbnail(string $label): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true);
        }
        $image = imagecreatetruecolor(800, 520);
        $background = imagecolorallocate($image, 245, 247, 246);
        $border = imagecolorallocate($image, 8, 119, 93);
        $text = imagecolorallocate($image, 23, 37, 31);
        imagefill($image, 0, 0, $background);
        imagerectangle($image, 16, 16, 783, 503, $border);
        imagestring($image, 5, 345, 235, $label, $text);
        imagestring($image, 3, 252, 270, 'PRE-VISUALIZACAO INDISPONIVEL', $text);
        ob_start(); imagejpeg($image, null, 85); $blob = (string) ob_get_clean(); imagedestroy($image);
        return $blob;
    }

    /** Resolve legacy upload URLs while preventing reads outside storage/uploads. */
    public static function absolutePath(string $root, string $fileUrl): string
    {
        $urlPath = rawurldecode((string) (parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl));
        $relativePath = ltrim(str_replace('\\', '/', $urlPath), '/');
        if (strpos($relativePath, 'storage/uploads/') !== 0 || strpos($relativePath, "\0") !== false) {
            return '';
        }

        $uploadRoot = realpath(rtrim($root, '/\\') . '/storage/uploads');
        $candidate = realpath(rtrim($root, '/\\') . '/' . $relativePath);
        if ($uploadRoot === false || $candidate === false || !is_file($candidate)) return '';

        $prefix = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/';
        $normalisedCandidate = str_replace('\\', '/', $candidate);
        return strpos($normalisedCandidate, $prefix) === 0 ? $candidate : '';
    }

    /** Return the presentation data used for an article attachment. */
    public static function presentation(string $fileUrl): array
    {
        $path = (string) (parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return ['kind' => 'pdf', 'label' => 'PDF', 'icon' => 'bi-file-earmark-pdf', 'class' => 'text-danger'];
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [
                'kind' => 'image',
                'label' => $extension === 'jpeg' ? 'JPG' : strtoupper($extension),
                'icon' => 'bi-file-earmark-image',
                'class' => 'text-primary',
            ];
        }

        return ['kind' => 'document', 'label' => 'DOC', 'icon' => 'bi-file-earmark-text', 'class' => 'text-secondary'];
    }
}
}
