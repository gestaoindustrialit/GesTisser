<?php
declare(strict_types=1);

/** Deterministic retrieval. No network, model, document execution or ERP writes. */
final class AssistService
{
    private $pdo;
    private $user;
    private $inAtomic = false;
    const FIELDS = ['description','symptoms','causes','conditions','procedure','safety','tools','expected','verification','keywords','synonyms','equipment','material','operation'];

    public function __construct(PDO $pdo, array $user) { $this->pdo=$pdo; $this->user=$user; }
    private function query(string $sql, array $args = []) { $s=$this->pdo->prepare($sql); $s->execute($args); return $s; }
    public function setting(string $key, string $default = ''): string { $v=$this->query('SELECT value FROM assist_settings WHERE key=?',[$key])->fetchColumn(); return $v===false?$default:(string)$v; }
    public function can(string $permission): bool
    {
        return !empty($this->user['id']) && gt_erp_user_can($this->pdo,$this->user,$permission);
    }
    public function enabled(): bool
    {
        if (empty($this->user['id'])) return false;
        return !empty($this->user['is_admin']) || $this->setting('enabled')==='1' || in_array((string)$this->user['id'],explode(',',$this->setting('pilot_users')),true);
    }
    public function demand(string $permission)
    {
        if (!$this->enabled() || !$this->can($permission)) throw new RuntimeException('Acesso não autorizado.',403);
    }
    private function audit(string $action, array $details)
    {
        // Existing audit writer, with the same transaction as the knowledge change.
        $this->query('INSERT INTO audit_logs(user_id,action,details_json) VALUES (?,?,?)',[(int)$this->user['id'],'assist.'.$action,json_encode($details,JSON_UNESCAPED_UNICODE)]);
    }
    private function atomic(callable $callback)
    {
        if ($this->inAtomic) return $callback();
        // Reserve SQLite writer before reading approval/version state.
        $this->pdo->exec('BEGIN IMMEDIATE');$this->inAtomic=true;
        try { $result=$callback(); $this->pdo->exec('COMMIT');$this->inAtomic=false; return $result; }
        catch (Throwable $e) { $this->pdo->exec('ROLLBACK');$this->inAtomic=false; throw $e; }
    }
    public function categories(): array
    {
        return array_values(array_filter($this->query('SELECT * FROM assist_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),function($c){return $this->categoryAllowed($c);}));
    }
    private function categoryAllowed(array $category): bool
    {
        // Recheck ancestors too: a public child must not bypass a restricted parent.
        if ($category['required_permission']!=='' && !$this->can($category['required_permission'])) return false;
        $id=isset($category['category_id'])?(int)$category['category_id']:(int)$category['id'];$seen=[];
        while($id) {
            if(isset($seen[$id]))return false;$seen[$id]=true;
            $row=$this->query('SELECT parent_id,required_permission FROM assist_categories WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$row || ($row['required_permission']!=='' && !$this->can($row['required_permission'])))return false;
            $id=(int)$row['parent_id'];
        }
        return true;
    }
    private function category(int $id): array
    {
        $c=$this->query('SELECT * FROM assist_categories WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$c || !$this->categoryAllowed($c)) throw new RuntimeException('Conteúdo indisponível.',403);
        return $c;
    }
    public function saveCategory(array $data): int
    {
        $this->demand('assist.admin');
        return $this->atomic(function() use($data){
            $name=trim((string)($data['name']??'')); $id=(int)($data['id']??0); $parent=(int)($data['parent_id']??0);
            if ($name==='' || strlen($name)>150 || ($id>0 && $parent===$id)) throw new InvalidArgumentException('Categoria inválida.');
            if ($parent) { $this->category($parent); $seen=[$id]; $cursor=$parent; while ($cursor) { if(in_array($cursor,$seen,true)) throw new InvalidArgumentException('Hierarquia circular.'); $seen[]=$cursor; $row=$this->category($cursor);$cursor=(int)$row['parent_id']; } }
            $restriction=!empty($data['restricted'])?'assist.restricted':'';
            if ($id) { $this->category($id);$this->query('UPDATE assist_categories SET name=?,parent_id=?,required_permission=? WHERE id=?',[$name,$parent?:null,$restriction,$id]); }
            else { $this->query('INSERT INTO assist_categories(name,parent_id,required_permission,created_by) VALUES (?,?,?,?)',[$name,$parent?:null,$restriction,$this->user['id']]);$id=(int)$this->pdo->lastInsertId(); }
            $this->query('INSERT OR IGNORE INTO erp_permissions(code,label,description) VALUES (?,?,?)',['assist.validate.category.'.$id,'Validar categoria '.$name,'GesTISSER Assist']);
            $this->audit('category',['id'=>$id]);return $id;
        });
    }
    public function article(int $id, bool $management = false): array
    {
        $this->demand($management?'assist.edit':'assist.use');
        $a=$this->query('SELECT a.*,c.required_permission,c.name category FROM assist_articles a JOIN assist_categories c ON c.id=a.category_id WHERE a.id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$a || !$this->categoryAllowed($a) || (!$management && !$this->usable($a))) throw new RuntimeException('Conteúdo indisponível.',404);
        $a['content']=json_decode($a['content_json'],true);return $a;
    }
    private function usable(array $a): bool
    {
        if ($a['status']!=='published' || !$a['approved_by'] || !$a['approved_at'] || ($a['expires_on'] && $a['expires_on']<date('Y-m-d'))) return false;
        return (bool)$this->query('SELECT 1 FROM assist_approvals WHERE article_id=? AND version=? AND decision="approved" AND actor_id=?',[$a['id'],$a['version'],$a['approved_by']])->fetchColumn();
    }
    public static function normalize(string $value): string
    {
        $value=strtr($value,['Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','á'=>'a','à'=>'a','ã'=>'a','â'=>'a','É'=>'e','Ê'=>'e','é'=>'e','ê'=>'e','Í'=>'i','í'=>'i','Ó'=>'o','Õ'=>'o','Ô'=>'o','ó'=>'o','õ'=>'o','ô'=>'o','Ú'=>'u','ú'=>'u','Ç'=>'c','ç'=>'c']);
        return trim(preg_replace('/[^\p{L}\p{M}\p{N}]+/u',' ',function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value)));
    }
    public function saveArticle(array $data): int
    {
        $this->demand('assist.edit');
        return $this->atomic(function() use($data){
            $id=(int)($data['id']??0); $old=$id?$this->article($id,true):null;
            if ($old && (int)($data['version']??0)!==(int)$old['version']) throw new RuntimeException('O artigo mudou. Recarregue antes de guardar.',409);
            $category=$this->category((int)($data['category_id']??0));$title=trim((string)($data['title']??''));
            if ($title==='' || strlen($title)>250) throw new InvalidArgumentException('Indique um título até 250 caracteres.');
            $content=[];foreach (self::FIELDS as $field) { $content[$field]=trim((string)($data[$field]??'')); if(strlen($content[$field])>30000) throw new InvalidArgumentException('Campo demasiado extenso.'); }
            $language=(string)($data['language']??'pt');if (!in_array($language,['pt','en','hi'],true)) throw new InvalidArgumentException('Idioma inválido.');
            $expires=trim((string)($data['expires_on']??''));
            if ($expires!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$expires) || !checkdate((int)substr($expires,5,2),(int)substr($expires,8,2),(int)substr($expires,0,4)))) throw new InvalidArgumentException('Data de revisão inválida.');
            $validator=(int)($data['validator_id']??0);
            if ($validator && !$this->query('SELECT 1 FROM users WHERE id=?',[$validator])->fetchColumn()) throw new InvalidArgumentException('Validador inválido.');
            $version=$old?(int)$old['version']+1:1;
            $json=json_encode($content,JSON_UNESCAPED_UNICODE);if($json===false)throw new InvalidArgumentException('Texto inválido.');
            $search=self::normalize($title.' '.implode(' ',$content).' '.$category['name']);
            $args=[$category['id'],$version,$title,$json,$search,$language,$this->user['id'],$validator?:null,$expires?:null];
            if ($id) { $args[]=$id; $this->query('UPDATE assist_articles SET category_id=?,version=?,title=?,content_json=?,search_text=?,language=?,author_id=?,validator_id=?,expires_on=?,status="draft",approved_by=NULL,approved_at=NULL,reviewed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?',$args); }
            else { $this->query('INSERT INTO assist_articles(category_id,version,title,content_json,search_text,language,author_id,validator_id,expires_on) VALUES (?,?,?,?,?,?,?,?,?)',$args); $id=(int)$this->pdo->lastInsertId(); }
            $snapshot=$this->article($id,true);
            unset($snapshot['content']);
            $this->query('INSERT INTO assist_versions(article_id,version,snapshot_json,author_id) VALUES (?,?,?,?)',[$id,$version,json_encode($snapshot,JSON_UNESCAPED_UNICODE),$this->user['id']]);
            $this->index($id,false);$this->audit('save',['article'=>$id,'version'=>$version]);return $id;
        });
    }
    /** Add-only batch: the whole transaction rolls back if any row fails. */
    public function importDrafts(array $records): array
    {
        $this->demand('assist.edit');
        if(!$records||count($records)>200)throw new InvalidArgumentException('Importe entre 1 e 200 artigos.');
        return $this->atomic(function()use($records){
            $ids=[];$seen=[];
            foreach($records as $index=>$record){
                $data=array_intersect_key($record,array_fill_keys(array_merge(self::FIELDS,['title','category_id','language','validator_id','expires_on']),true));
                $category=$this->category((int)($data['category_id']??0));$title=trim((string)($data['title']??''));$language=(string)($data['language']??'pt');
                if(trim((string)($data['procedure']??''))==='')throw new InvalidArgumentException('Registo '.($index+1).': preencha o procedimento.');
                $key=$category['id'].'|'.$language.'|'.self::normalize($title);
                if(isset($seen[$key]))throw new InvalidArgumentException('Registo '.($index+1).': título repetido no ficheiro para a mesma categoria e idioma.');
                $seen[$key]=true;
                $existing=$this->query('SELECT title FROM assist_articles WHERE category_id=? AND language=?',[$category['id'],$language])->fetchAll(PDO::FETCH_COLUMN);
                foreach($existing as $existingTitle)if(self::normalize($existingTitle)===self::normalize($title))throw new InvalidArgumentException('Registo '.($index+1).': já existe um artigo com este título na categoria e idioma. Nenhum artigo foi substituído.');
                $ids[]=$this->saveArticle($data);
            }
            $this->audit('import',['article_ids'=>$ids,'count'=>count($ids)]);return $ids;
        });
    }
    public function transition(int $id, int $version, string $action, string $reason = '')
    {
        $this->demand('assist.edit');
        $this->atomic(function() use($id,$version,$action,$reason){
            $a=$this->article($id,true);
            if ((int)$a['version']!==$version) throw new RuntimeException('Versão alterada. Recarregue.',409);
            $next=['submit'=>'review','approve'=>'approved','reject'=>'draft','publish'=>'published','archive'=>'archived'];
            $from=['submit'=>['draft'],'approve'=>['review'],'reject'=>['review','approved'],'publish'=>['approved'],'archive'=>['draft','review','approved','published']];
            if (!isset($next[$action]) || !in_array($a['status'],$from[$action],true)) throw new InvalidArgumentException('Transição inválida.');
            if (in_array($action,['approve','reject'],true)) {
                if (!$this->can('assist.validate') && !$this->can('assist.validate.category.'.$a['category_id'])) throw new RuntimeException('Sem permissão de validação.',403);
                if ($a['validator_id'] && (int)$a['validator_id']!==(int)$this->user['id'] && empty($this->user['is_admin'])) throw new RuntimeException('Artigo atribuído a outro validador.',403);
                if ($action==='approve' && trim($a['content']['procedure'])==='') throw new InvalidArgumentException('Indique o procedimento aprovado.');
                $this->query('INSERT INTO assist_approvals(article_id,version,decision,actor_id,reason) VALUES (?,?,?,?,?)',[$id,$version,$action==='approve'?'approved':'rejected',$this->user['id'],substr($reason,0,2000)]);
                if ($action==='approve') $this->query('UPDATE assist_articles SET approved_by=?,approved_at=CURRENT_TIMESTAMP,reviewed_at=CURRENT_TIMESTAMP WHERE id=?',[$this->user['id'],$id]);
            }
            if (in_array($action,['publish','archive'],true)) $this->demand('assist.publish');
            if ($action==='publish' && (!$a['approved_by'] || ($a['expires_on'] && $a['expires_on']<date('Y-m-d')))) throw new InvalidArgumentException('Aprovação inexistente ou revisão expirada.');
            if (in_array($action,['reject','archive'],true)) $this->query('UPDATE assist_articles SET approved_by=NULL,approved_at=NULL WHERE id=?',[$id]);
            $this->query('UPDATE assist_articles SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$next[$action],$id]);
            $this->index($id,$action==='publish');$this->audit($action,['article'=>$id,'version'=>$version,'reason'=>$reason]);
        });
    }
    private function index(int $id, bool $publish)
    {
        if (!$this->query("SELECT 1 FROM sqlite_master WHERE name='assist_search'")->fetchColumn()) return;
        try {
            $this->query('DELETE FROM assist_search WHERE rowid=?',[$id]);
            if ($publish) $this->query('INSERT INTO assist_search(rowid,title,search_text) SELECT id,title,search_text FROM assist_articles WHERE id=?',[$id]);
        } catch (PDOException $e) { error_log('[Assist] Optional FTS index unavailable.'); }
    }
    public function articles(): array
    {
        $this->demand('assist.edit');
        return array_values(array_filter($this->query('SELECT a.*,c.name category,c.required_permission FROM assist_articles a JOIN assist_categories c ON c.id=a.category_id ORDER BY a.updated_at DESC,a.id DESC')->fetchAll(PDO::FETCH_ASSOC),function($a){return $this->categoryAllowed($a);}));
    }
    public function versions(int $id): array { $this->article($id,true);return $this->query('SELECT * FROM assist_versions WHERE article_id=? ORDER BY version DESC',[$id])->fetchAll(PDO::FETCH_ASSOC); }
    public function approvals(int $id): array
    {
        $this->article($id,true);return $this->query('SELECT * FROM assist_approvals WHERE article_id=? ORDER BY id DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    private function words(string $question): array
    {
        $stop=explode(' ','a o os as um uma uns umas de da do das dos e em na no nas nos por para com ao que qual quais como quanto quanta quantos qual é esta está estou tenho muito muita demasiado demasiada resolver corrigir fazer preciso pode podes favor se não nao the a an is are how what can i to of for with my please está tinta');
        return array_values(array_unique(array_filter(explode(' ',self::normalize($question)),function($w)use($stop){return strlen($w)>1 && !in_array($w,$stop,true);} )));
    }
    public function retrieve(string $question, string $language = 'pt', array $context = []): array
    {
        $this->demand('assist.use');
        $none=['outcome'=>'none','text'=>self::noAnswer($language),'sources'=>[]];
        $normalized=self::normalize($question);$words=$this->words($question);
        // Parameter requests require a dedicated validated answer field in a future version.
        // Never infer which numerical value in a procedure answers a free-form question.
        if (!$words || count($words)>40 || preg_match('/[0-9]/u',$question) || preg_match('/\b(quanto|quanta|quantos|quantas|valor|valores|percentagem|temperatura|tempo|tempos|quantidade|quantidades|dosagem|dose|medida|medidas|parametro|parametros|ph|how much|how many|temperature|time|duration|quantity|dosage|value|percentage)\b/u',$normalized)) return $none;
        $matches=[];
        $where=[];$args=[$language];
        foreach ($words as $word) { $where[]='a.search_text LIKE ?';$args[]='%'.$word.'%'; }
        // FTS5 helps candidate discovery; the same literal LIKE candidates preserve fallback parity.
        $ftsIds=[];
        if ($this->query("SELECT 1 FROM sqlite_master WHERE name='assist_search'")->fetchColumn()) {
            $expression=implode(' OR ',array_map(function($w){return '"'.str_replace('"','""',$w).'"';},$words));
            try { $ftsIds=$this->query('SELECT rowid FROM assist_search WHERE assist_search MATCH ? LIMIT 100',[$expression])->fetchAll(PDO::FETCH_COLUMN); }
            catch (PDOException $e) { error_log('[Assist] FTS unavailable; using literal search.'); }
        }
        if ($ftsIds) { $where[]='a.id IN ('.implode(',',array_fill(0,count($ftsIds),'?')).')';$args=array_merge($args,$ftsIds); }
        $rows=$this->query('SELECT a.*,c.required_permission,c.name category FROM assist_articles a JOIN assist_categories c ON c.id=a.category_id WHERE a.status="published" AND a.language=? AND ('.implode(' OR ',$where).')',$args)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $a) {
            if (!$this->categoryAllowed($a) || !$this->usable($a)) continue;
            $content=json_decode($a['content_json'],true);
            if (trim($content['procedure'])==='') continue;
            $hits=0;foreach ($words as $w) if (preg_match('/(?:^| )'.preg_quote($w,'/').'(?: |$)/u',$a['search_text'])) $hits++;
            $ratio=$hits/count($words);
            if ($ratio<0.6) continue;
            foreach (['equipment','operation'] as $field) if (!empty($context[$field]) && $content[$field]!=='' && self::normalize($content[$field])!==self::normalize($context[$field])) continue 2;
            $a['content']=$content;$a['score']=$ratio;$matches[]=$a;
        }
        if (!$matches) return $none;
        usort($matches,function($a,$b){return $a['score']===$b['score']?($a['id']<=>$b['id']):($a['score']>$b['score']?-1:1);});
        // Never merge different instructions. Require the operator to choose the scope first.
        $top=$matches[0];$material=trim($top['content']['material']);
        if (count($matches)>1 || ($material!=='' && strpos(' '.$normalized.' ',' '.self::normalize($material).' ')===false)) {
            return ['outcome'=>'clarify','text'=>['pt'=>'Escolha o procedimento e confirme o material/equipamento antes de continuar.','en'=>'Choose the procedure and confirm the material/equipment before continuing.','hi'=>'आगे बढ़ने से पहले प्रक्रिया चुनें और सामग्री/उपकरण की पुष्टि करें।'][$language]??'Escolha o procedimento.','sources'=>array_map(function($a){return ['id'=>(int)$a['id'],'version'=>(int)$a['version'],'title'=>$a['title'],'material'=>$a['content']['material'],'equipment'=>$a['content']['equipment']];},array_slice($matches,0,8))];
        }
        return ['outcome'=>'answer','text'=>$top['content']['procedure'],'safety'=>$top['content']['safety'],'conditions'=>$top['content']['conditions'],'verification'=>$top['content']['verification'],'sources'=>[['id'=>(int)$top['id'],'version'=>(int)$top['version'],'title'=>$top['title'],'material'=>$material,'equipment'=>$top['content']['equipment']]]];
    }
    public static function noAnswer(string $language): string
    {
        return ['pt'=>'Não encontrei um procedimento técnico validado pela Tisser que permita indicar como corrigir esta situação. Podes consultar os documentos disponíveis ou solicitar apoio ao responsável técnico.','en'=>'I could not find a technical procedure validated by Tisser for this situation. Consult the available documents or contact the technical supervisor.','hi'=>'इस स्थिति के लिए Tisser द्वारा मान्य तकनीकी प्रक्रिया नहीं मिली। उपलब्ध दस्तावेज़ देखें या तकनीकी प्रभारी से संपर्क करें।'][$language]??self::noAnswer('pt');
    }
    public function ask(string $question, string $language, int $conversation = 0, array $context = []): array
    {
        $this->demand('assist.use');$question=trim($question);
        if ($question==='' || strlen($question)>2000 || !in_array($language,['pt','en','hi'],true)) throw new InvalidArgumentException('Pergunta ou idioma inválido.');
        return $this->atomic(function()use($question,$language,$conversation,$context){
            $count=$this->query('SELECT COUNT(*) FROM assist_messages WHERE user_id=? AND created_at>=datetime("now","-1 minute")',[$this->user['id']])->fetchColumn();
            if ((int)$count>=20) throw new RuntimeException('Limite de pedidos. Aguarde um minuto.',429);
            if ($conversation) { if (!$this->query('SELECT 1 FROM assist_conversations WHERE id=? AND user_id=?',[$conversation,$this->user['id']])->fetchColumn()) throw new RuntimeException('Conversa indisponível.',404); }
            else { $this->query('INSERT INTO assist_conversations(user_id) VALUES (?)',[$this->user['id']]);$conversation=(int)$this->pdo->lastInsertId(); }
            $safeContext=[];foreach (['equipment','operation','order'] as $key) if(!empty($context[$key]))$safeContext[$key]=substr((string)$context[$key],0,150);
            $answer=$this->retrieve($question,$language,$safeContext);
            $this->query('INSERT INTO assist_messages(conversation_id,user_id,question,answer_json,context_json,outcome) VALUES (?,?,?,?,?,?)',[$conversation,$this->user['id'],$question,json_encode($answer,JSON_UNESCAPED_UNICODE),json_encode($safeContext,JSON_UNESCAPED_UNICODE),$answer['outcome']]);
            $message=(int)$this->pdo->lastInsertId();foreach($answer['sources'] as $source)$this->query('INSERT INTO assist_sources(message_id,article_id,version) VALUES (?,?,?)',[$message,$source['id'],$source['version']]);
            return ['id'=>$message,'conversation_id'=>$conversation,'question'=>$question,'answer'=>$answer];
        });
    }
    public function suggestions(string $language = 'pt'): array
    {
        $this->demand('assist.use');$suggestions=[];
        $rows=$this->query('SELECT a.*,c.required_permission FROM assist_articles a JOIN assist_categories c ON c.id=a.category_id WHERE a.status="published" AND a.language=? ORDER BY a.updated_at DESC,a.id DESC',[$language])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $a){if($this->categoryAllowed($a)&&$this->usable($a))$suggestions[]=['title'=>$a['title']];if(count($suggestions)>=5)break;}
        return $suggestions;
    }
    public function history(): array
    {
        $this->demand('assist.use');$rows=$this->query('SELECT * FROM assist_messages WHERE user_id=? ORDER BY id DESC LIMIT 50',[$this->user['id']])->fetchAll(PDO::FETCH_ASSOC);$result=[];
        foreach(array_reverse($rows) as $row) {
            $answer=json_decode($row['answer_json'],true);$safe=true;
            foreach($answer['sources'] as $source) {try { $a=$this->article((int)$source['id']);if((int)$a['version']!==(int)$source['version'])$safe=false; }catch(Throwable $e){$safe=false;} }
            if(!$safe)$answer=['outcome'=>'withdrawn','text'=>'Fonte alterada ou indisponível. Faça uma nova pesquisa.','sources'=>[]];
            $result[]=['id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],'question'=>$row['question'],'answer'=>$answer];
        }return $result;
    }
    private function ownMessage(int $id)
    {
        if (!$this->query('SELECT 1 FROM assist_messages WHERE id=? AND user_id=?',[$id,$this->user['id']])->fetchColumn()) throw new RuntimeException('Mensagem indisponível.',404);
    }
    public function feedback(int $id, int $useful)
    {
        $this->demand('assist.use');$this->ownMessage($id);if(!in_array($useful,[0,1],true))throw new InvalidArgumentException('Feedback inválido.');
        $this->query('INSERT OR REPLACE INTO assist_feedback(message_id,user_id,useful) VALUES (?,?,?)',[$id,$this->user['id'],$useful]);
    }
    public function report(array $data): int
    {
        $this->demand('assist.use');$description=trim((string)($data['description']??''));$priority=(string)($data['priority']??'normal');$message=(int)($data['message_id']??0);
        if($message)$this->ownMessage($message);
        if($description===''||strlen($description)>5000||!in_array($priority,['low','normal','high'],true))throw new InvalidArgumentException('Descrição ou prioridade inválida.');
        if((int)$this->query('SELECT COUNT(*) FROM assist_problems WHERE user_id=? AND created_at>=datetime("now","-1 minute")',[$this->user['id']])->fetchColumn()>=10)throw new RuntimeException('Limite de reportes. Aguarde.',429);
        $this->query('INSERT INTO assist_problems(user_id,message_id,description,equipment,operation_ref,priority) VALUES (?,?,?,?,?,?)',[$this->user['id'],$message?:null,$description,substr((string)($data['equipment']??''),0,150),substr((string)($data['operation_ref']??''),0,150),$priority]);return (int)$this->pdo->lastInsertId();
    }
    public function problems(): array { $this->demand('assist.admin');return $this->query('SELECT * FROM assist_problems ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC); }
    public function resolve(int $id, string $solution, bool $effective)
    {
        $this->demand('assist.admin');if(strlen($solution)>30000)throw new InvalidArgumentException('Solução demasiado extensa.');
        $this->atomic(function()use($id,$solution,$effective){$this->query('UPDATE assist_problems SET solution=?,effective=? WHERE id=?',[$solution,$effective?1:0,$id]);$this->audit('resolve',['problem'=>$id,'effective'=>$effective]);});
    }
    public function convert(int $id, int $category): int
    {
        $this->demand('assist.admin');
        return $this->atomic(function()use($id,$category){
            $p=$this->query('SELECT * FROM assist_problems WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$p || !$p['effective'] || trim($p['solution'])==='' || $p['article_id'])throw new InvalidArgumentException('Registe uma solução eficaz antes de converter.');
            $article=$this->saveArticle(['title'=>'Problema #'.$id,'category_id'=>$category,'description'=>$p['description'],'procedure'=>$p['solution'],'equipment'=>$p['equipment']]);
            $this->query('UPDATE assist_problems SET article_id=? WHERE id=?',[$article,$id]);
            $this->audit('convert',['problem'=>$id,'article'=>$article]);return $article;
        });
    }
    public function setPermission(int $userId, string $permission, string $mode)
    {
        $this->demand('assist.admin');
        if(strpos($permission,'assist.')!==0 || !in_array($mode,['allow','deny','inherit'],true) || !$this->query('SELECT 1 FROM erp_permissions WHERE code=?',[$permission])->fetchColumn() || !$this->query('SELECT 1 FROM users WHERE id=?',[$userId])->fetchColumn()) throw new InvalidArgumentException('Utilizador ou capacidade inválida.');
        $this->atomic(function()use($userId,$permission,$mode){
            $this->query('DELETE FROM erp_user_permissions WHERE user_id=? AND permission_code=?',[$userId,$permission]);
            if($mode!=='inherit')$this->query('INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES (?,?,?)',[$userId,$permission,$mode==='allow'?1:0]);
            $this->audit('permission',['user'=>$userId,'permission'=>$permission,'mode'=>$mode]);
        });
    }
    public function permissions(): array
    {
        $this->demand('assist.admin');return $this->query('SELECT code,label FROM erp_permissions WHERE code LIKE "assist.%" ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function configure(array $data)
    {
        $this->demand('assist.admin');$days=(int)($data['retention_days']??180);$pilot=trim((string)($data['pilot_users']??''));
        if($days<1||$days>3650||($pilot!==''&&!preg_match('/^\d+(,\d+)*$/D',$pilot)))throw new InvalidArgumentException('Retenção ou lista de utilizadores inválida.');
        $this->atomic(function()use($data,$days,$pilot){foreach(['enabled'=>!empty($data['enabled'])?'1':'0','pilot_users'=>$pilot,'retention_days'=>(string)$days] as $k=>$v)$this->query('UPDATE assist_settings SET value=? WHERE key=?',[$v,$k]);$this->audit('configure',['retention_days'=>$days,'pilot_users'=>$pilot,'enabled'=>!empty($data['enabled'])]);});
    }
    public function prune(): int
    {
        $this->demand('assist.admin');$days=max(1,(int)$this->setting('retention_days','180'));
        return $this->atomic(function()use($days){$s=$this->query('DELETE FROM assist_messages WHERE created_at<datetime("now",?)',['-'.$days.' days']);$n=$s->rowCount();$this->query('DELETE FROM assist_conversations WHERE NOT EXISTS (SELECT 1 FROM assist_messages m WHERE m.conversation_id=assist_conversations.id)');$this->audit('prune',['messages'=>$n]);return $n;});
    }
    public function statistics(): array
    {
        $this->demand('assist.admin');return [
            'outcomes'=>$this->query('SELECT outcome,COUNT(*) total FROM assist_messages GROUP BY outcome')->fetchAll(PDO::FETCH_ASSOC),
            'unanswered'=>$this->query('SELECT question,COUNT(*) total FROM assist_messages WHERE outcome="none" GROUP BY question ORDER BY total DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC),
            'negative'=>$this->query('SELECT s.article_id,s.version,COUNT(*) total FROM assist_feedback f JOIN assist_sources s ON s.message_id=f.message_id WHERE f.useful=0 GROUP BY s.article_id,s.version ORDER BY total DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
}
