<?php
declare(strict_types=1);

final class BusinessIntelligence
{
    /** @var PDO */
    private $pdo;
    /** @var array */
    private $filters;
    /** @var bool */
    private $financial;

    public function __construct(PDO $pdo, array $filters, bool $financial)
    {
        $this->pdo = $pdo;
        $this->filters = $this->normaliseFilters($filters);
        $this->financial = $financial;
    }

    public function filters(): array { return $this->filters; }

    public function payload(): array
    {
        $productionWhere = $this->productionWhere('o');
        $timeWhere = $this->timeWhere();
        $settings = $this->settings();
        $summary = $this->row('SELECT COUNT(*) orders_total,
                SUM(CASE WHEN o.status NOT IN ("Concluída","Fechada","Cancelada") THEN 1 ELSE 0 END) orders_open,
                COALESCE(SUM(o.planned_quantity),0) planned,
                COALESCE(SUM(o.produced_quantity),0) produced,
                SUM(CASE WHEN o.due_date IS NOT NULL AND o.due_date < date("now") AND o.status NOT IN ("Concluída","Fechada","Cancelada") THEN 1 ELSE 0 END) overdue
            FROM erp_production_orders o WHERE '.$productionWhere['sql'], $productionWhere['params']);

        $deadline = $this->row('SELECT SUM(CASE WHEN o.status IN ("Concluída","Fechada") THEN 1 ELSE 0 END) completed,
                SUM(CASE WHEN o.status IN ("Concluída","Fechada") AND (o.updated_at IS NULL OR o.due_date IS NULL OR date(o.updated_at)<=date(o.due_date)) THEN 1 ELSE 0 END) on_time
            FROM erp_production_orders o WHERE '.$productionWhere['sql'], $productionWhere['params']);
        $deadlineRate = (int)$deadline['completed'] > 0 ? 100 * (float)$deadline['on_time'] / (int)$deadline['completed'] : null;

        $waste = $this->row('SELECT COALESCE(SUM(w.quantity),0) waste, COALESCE(SUM(t.quantity_good),0) good
            FROM erp_operation_time_entries t
            JOIN erp_production_order_operations opo ON opo.id=t.production_order_operation_id
            JOIN erp_production_orders o ON o.id=opo.production_order_id
            LEFT JOIN (SELECT time_entry_id,SUM(quantity) quantity,MAX(created_at) created_at FROM erp_operation_waste GROUP BY time_entry_id) w ON w.time_entry_id=t.id
            WHERE '.$timeWhere['sql'], $timeWhere['params']);
        $wasteRate = ((float)$waste['good'] + (float)$waste['waste']) > 0
            ? 100 * (float)$waste['waste'] / ((float)$waste['good'] + (float)$waste['waste']) : null;

        $cards = [
            $this->card('of_open', 'Ordens de fabrico em aberto', (float)$summary['orders_open'], 'nº', null, 'erp.php?page=production', 'bi-clipboard2-pulse'),
            $this->card('production', 'Produção realizada', (float)$summary['produced'], 'qtd.', $this->variation(), 'erp.php?page=production', 'bi-gear-wide-connected'),
            $this->card('deadline', 'Cumprimento dos prazos', $deadlineRate, '%', null, 'erp.php?page=production', 'bi-clock-history', $this->deadlineTone($deadlineRate, $settings)),
            $this->card('waste', 'Taxa de desperdício/refugo', $wasteRate, '%', null, 'shopfloor.php', 'bi-recycle', $this->wasteTone($wasteRate, $settings)),
        ];
        if ($this->financial) {
            $stock = (float)$this->value('SELECT COALESCE(SUM(b.physical_qty * CASE b.item_type WHEN "raw_material" THEN rm.standard_price ELSE fp.standard_cost END),0)
                FROM erp_stock_balances b LEFT JOIN erp_raw_materials rm ON b.item_type="raw_material" AND rm.id=b.item_id
                LEFT JOIN erp_finished_products fp ON b.item_type="finished_product" AND fp.id=b.item_id');
            $cards[] = $this->card('stock', 'Valor estimado do stock', $stock, '€', null, 'erp.php?page=warehouse', 'bi-box-seam');
        }

        return [
            'generated_at'=>gmdate('c'), 'filters'=>$this->filters, 'financial'=>$this->financial,
            'settings'=>$settings, 'cards'=>$cards,
            'charts'=>[
                'monthly_production'=>$this->monthlyProduction(),
                'production_status'=>$this->groupRows('SELECT o.status label,COUNT(*) value FROM erp_production_orders o WHERE '.$productionWhere['sql'].' GROUP BY o.status ORDER BY value DESC', $productionWhere['params']),
                'plan_actual'=>$this->groupRows('SELECT COALESCE(strftime("%Y-%m",o.created_at),"Sem data") label,ROUND(SUM(o.planned_quantity),2) planned,ROUND(SUM(o.produced_quantity),2) actual FROM erp_production_orders o WHERE '.$productionWhere['sql'].' GROUP BY label ORDER BY label', $productionWhere['params']),
                'waste'=>$this->groupRows('SELECT strftime("%Y-%m",COALESCE(w.created_at,t.started_at)) label,ROUND(SUM(w.quantity),2) value FROM erp_operation_waste w JOIN erp_operation_time_entries t ON t.id=w.time_entry_id JOIN erp_production_order_operations opo ON opo.id=t.production_order_operation_id JOIN erp_production_orders o ON o.id=opo.production_order_id WHERE '.$timeWhere['sql'].' GROUP BY label ORDER BY label', $timeWhere['params']),
                'customers'=>$this->groupRows('SELECT c.name label,COUNT(o.id) orders,ROUND(SUM(o.planned_quantity),2) value FROM erp_production_orders o JOIN erp_customers c ON c.id=o.customer_id WHERE '.$productionWhere['sql'].' GROUP BY c.id,c.name ORDER BY value DESC LIMIT 10', $productionWhere['params']),
                'stock'=>$this->groupRows('SELECT CASE b.item_type WHEN "raw_material" THEN "Matérias-primas" ELSE "Produtos acabados" END label,ROUND(SUM(b.physical_qty),2) value FROM erp_stock_balances b GROUP BY b.item_type ORDER BY value DESC', []),
            ],
            'alerts'=>$this->alerts($summary, $wasteRate, $settings),
            'details'=>$this->details($productionWhere),
            'unavailable'=>[
                'Valor e quantidade de encomendas de clientes, carteira comercial e vendas por artigo: não existem tabelas de encomendas de venda e linhas no modelo atual.',
                'Compras, fornecedores, materiais comprados e evolução de preços: não existem documentos nem linhas de compra no modelo atual.',
                'Reclamações e não conformidades: os controlos de qualidade existentes registam resultados de operação, não ocorrências formais.',
            ],
        ];
    }

    public function options(): array
    {
        return [
            'customers'=>$this->groupRows('SELECT id value,name label FROM erp_customers WHERE is_active=1 ORDER BY name', []),
            'suppliers'=>$this->groupRows('SELECT id value,name label FROM erp_suppliers WHERE is_active=1 ORDER BY name', []),
            'articles'=>$this->groupRows('SELECT id value,code||" — "||description label FROM erp_finished_products WHERE status="Ativo" ORDER BY code', []),
            'orders'=>$this->groupRows('SELECT id value,order_number label FROM erp_production_orders ORDER BY id DESC LIMIT 500', []),
            'machines'=>$this->tableExists('erp_machines') ? $this->groupRows('SELECT id value,code||" — "||name label FROM erp_machines WHERE is_active=1 ORDER BY code', []) : [],
            'operations'=>$this->groupRows('SELECT id value,code||" — "||name label FROM erp_operations WHERE is_active=1 ORDER BY code', []),
            'statuses'=>$this->groupRows('SELECT DISTINCT status value,status label FROM erp_production_orders WHERE status<>"" ORDER BY status', []),
        ];
    }

    private function normaliseFilters(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $from = $this->date((string)($input['from'] ?? '')) ?: $today->modify('first day of this month')->format('Y-m-d');
        $to = $this->date((string)($input['to'] ?? '')) ?: $today->format('Y-m-d');
        if ($from > $to) { $swap=$from; $from=$to; $to=$swap; }
        $out=['from'=>$from,'to'=>$to];
        foreach (['customer','supplier','article','order','machine','operation'] as $key) $out[$key]=max(0,(int)($input[$key]??0));
        $out['status']=substr(trim((string)($input['status']??'')),0,60);
        return $out;
    }

    private function date(string $date) { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date); return $d&&$d->format('Y-m-d')===$date?$date:null; }

    private function productionWhere(string $alias): array
    {
        $f=$this->filters; $clauses=['date('.$alias.'.created_at) BETWEEN ? AND ?']; $params=[$f['from'],$f['to']];
        foreach (['customer'=>'customer_id','order'=>'id','article'=>'finished_product_id'] as $key=>$column) if($f[$key]){$clauses[]="$alias.$column=?";$params[]=$f[$key];}
        if($f['status']!==''){$clauses[]="$alias.status=?";$params[]=$f['status'];}
        if($f['machine']){$clauses[]='EXISTS(SELECT 1 FROM erp_production_order_operations bx WHERE bx.production_order_id='.$alias.'.id AND COALESCE(bx.selected_machine_id,bx.primary_machine_id)=?)';$params[]=$f['machine'];}
        if($f['operation']){$clauses[]='EXISTS(SELECT 1 FROM erp_production_order_operations bo WHERE bo.production_order_id='.$alias.'.id AND bo.operation_id=?)';$params[]=$f['operation'];}
        return ['sql'=>implode(' AND ',$clauses),'params'=>$params];
    }

    private function timeWhere(): array { $w=$this->productionWhere('o'); $w['sql']='date(COALESCE(t.ended_at,t.started_at)) BETWEEN ? AND ? AND '.preg_replace('/^date\(o\.created_at\) BETWEEN \? AND \?/','1=1',$w['sql']); return $w; }
    private function monthlyProduction(): array { $w=$this->productionWhere('o'); return $this->groupRows('SELECT COALESCE(strftime("%Y-%m",o.created_at),"Sem data") label,ROUND(SUM(o.produced_quantity),2) value FROM erp_production_orders o WHERE '.$w['sql'].' GROUP BY label ORDER BY label',$w['params']); }

    private function details(array $where): array
    {
        return $this->groupRows('SELECT o.id,o.order_number,o.status,o.planned_quantity,o.produced_quantity,o.due_date,c.name customer,fp.code article FROM erp_production_orders o LEFT JOIN erp_customers c ON c.id=o.customer_id LEFT JOIN erp_finished_products fp ON fp.id=o.finished_product_id WHERE '.$where['sql'].' ORDER BY o.created_at DESC LIMIT 250',$where['params']);
    }

    private function variation()
    {
        $from=new DateTimeImmutable($this->filters['from']);$to=new DateTimeImmutable($this->filters['to']);$days=(int)$from->diff($to)->days+1;
        $current=$this->productionWhere('o');$previous=$this->filters;$this->filters['to']=$from->modify('-1 day')->format('Y-m-d');$this->filters['from']=$from->modify('-'.$days.' days')->format('Y-m-d');$prev=$this->productionWhere('o');$this->filters=$previous;
        $a=(float)$this->value('SELECT COALESCE(SUM(o.produced_quantity),0) FROM erp_production_orders o WHERE '.$current['sql'],$current['params']);
        $b=(float)$this->value('SELECT COALESCE(SUM(o.produced_quantity),0) FROM erp_production_orders o WHERE '.$prev['sql'],$prev['params']);
        return $b>0?100*($a-$b)/$b:null;
    }

    private function settings(): array { $out=[];foreach($this->groupRows('SELECT key,value FROM erp_settings WHERE key LIKE "bi_%"',[]) as $r)$out[$r['key']]=(float)$r['value'];return $out; }
    private function deadlineTone($rate,array $s): string { if($rate===null)return 'muted';return $rate>=($s['bi_target_deadline_percent']??95)?'success':($rate>=($s['bi_warning_deadline_percent']??85)?'warning':'danger'); }
    private function wasteTone($rate,array $s): string { if($rate===null)return 'muted';return $rate<=($s['bi_target_waste_percent']??3)?'success':($rate<=($s['bi_warning_waste_percent']??6)?'warning':'danger'); }
    private function card(string $id,string $label,$value,string $unit,$change,string $url,string $icon,string $tone='primary'): array { return compact('id','label','value','unit','change','url','icon','tone'); }
    private function alerts(array $summary,$waste,array $settings): array { $a=[];if((int)$summary['overdue']>0)$a[]=['tone'=>'danger','icon'=>'bi-calendar-x','title'=>$summary['overdue'].' OF atrasada(s)','text'=>'Prazo ultrapassado e ordem ainda aberta.'];if($waste!==null&&$waste>($settings['bi_warning_waste_percent']??6))$a[]=['tone'=>'warning','icon'=>'bi-recycle','title'=>'Desperdício acima do limite','text'=>number_format($waste,1,',','.').'% no período selecionado.'];$low=(int)$this->value('SELECT COUNT(*) FROM erp_raw_materials rm WHERE rm.status="Ativo" AND (SELECT COALESCE(SUM(physical_qty-reserved_qty-blocked_qty),0) FROM erp_stock_balances b WHERE b.item_type="raw_material" AND b.item_id=rm.id)<rm.min_stock');if($low>0)$a[]=['tone'=>'warning','icon'=>'bi-box-seam','title'=>$low.' materiais abaixo do mínimo','text'=>'Stock disponível inferior ao stock mínimo configurado.'];return array_slice($a,0,5); }
    private function row(string $sql,array $params=[]): array { $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch(PDO::FETCH_ASSOC)?:[]; }
    private function value(string $sql,array $params=[]){$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function groupRows(string $sql,array $params): array {$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function tableExists(string $name): bool {$s=$this->pdo->prepare('SELECT 1 FROM sqlite_master WHERE type="table" AND name=?');$s->execute([$name]);return(bool)$s->fetchColumn();}
}
