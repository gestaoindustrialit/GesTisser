<?php
require_once __DIR__ . '/helpers.php';
require_login();
$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$isAdmin = is_admin($pdo, $userId);
$profile = (string) ($user['access_profile'] ?? 'Utilizador');
if (!$isAdmin && !in_array($profile, ['Produção','Chefias','RH'], true)) { http_response_code(403); exit('Acesso reservado a ERP/Produção.'); }
$flashSuccess = $flashError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create_product') {
            $stmt=$pdo->prepare('INSERT INTO erp_products(code, description, unit_id, unit_cost, unit_price, min_stock) VALUES (?,?,?,?,?,?)');
            $stmt->execute([trim($_POST['code']??''), trim($_POST['description']??''), (int)($_POST['unit_id']??0) ?: null, (float)($_POST['unit_cost']??0), (float)($_POST['unit_price']??0), (float)($_POST['min_stock']??0)]);
            $flashSuccess='Artigo criado.';
        } elseif ($action === 'create_order') {
            $stmt=$pdo->prepare('INSERT INTO erp_production_orders(order_number, product_id, planned_quantity, due_date, notes, estimated_material_cost, estimated_labor_cost, estimated_overhead_cost, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([trim($_POST['order_number']??''), (int)($_POST['product_id']??0), (float)($_POST['planned_quantity']??0), trim($_POST['due_date']??'') ?: null, trim($_POST['notes']??'') ?: null, (float)($_POST['estimated_material_cost']??0), (float)($_POST['estimated_labor_cost']??0), (float)($_POST['estimated_overhead_cost']??0), $userId]);
            $of=(int)$pdo->lastInsertId();
            $op=$pdo->query('SELECT id FROM erp_operations ORDER BY id LIMIT 1')->fetchColumn();
            if ($op) $pdo->prepare('INSERT INTO erp_production_order_operations(production_order_id, operation_id, sequence_no, planned_minutes) VALUES (?,?,10,0)')->execute([$of,(int)$op]);
            $flashSuccess='OF criada.';
        } elseif ($action === 'create_document') {
            $stmt=$pdo->prepare('INSERT INTO erp_production_order_documents(production_order_id, title, document_url, body, is_required, created_by) VALUES (?,?,?,?,?,?)');
            $stmt->execute([(int)($_POST['production_order_id']??0), trim($_POST['title']??''), trim($_POST['document_url']??'') ?: null, trim($_POST['body']??'') ?: null, (int)($_POST['is_required']??0), $userId]);
            $flashSuccess='Documento anexado à OF.';
        } elseif ($action === 'inventory_move') {
            $stmt=$pdo->prepare('INSERT INTO erp_inventory_movements(movement_type, product_id, warehouse_from_id, warehouse_to_id, quantity, unit_cost, notes, created_by) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([trim($_POST['movement_type']??''), (int)($_POST['product_id']??0), (int)($_POST['warehouse_from_id']??0) ?: null, (int)($_POST['warehouse_to_id']??0) ?: null, (float)($_POST['quantity']??0), (float)($_POST['unit_cost']??0), trim($_POST['notes']??'') ?: null, $userId]);
            $flashSuccess='Movimento de armazém registado.';
        }
    } catch (Throwable $e) { $flashError='Erro: '.$e->getMessage(); }
}
$products=$pdo->query('SELECT p.*, u.code unit_code FROM erp_products p LEFT JOIN erp_units u ON u.id=p.unit_id ORDER BY p.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$units=$pdo->query('SELECT * FROM erp_units ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$warehouses=$pdo->query('SELECT * FROM erp_warehouses ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$orders=$pdo->query('SELECT o.*, p.code product_code, p.description product_description FROM erp_production_orders o JOIN erp_products p ON p.id=o.product_id ORDER BY o.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$movements=$pdo->query('SELECT m.*, p.code product_code FROM erp_inventory_movements m JOIN erp_products p ON p.id=m.product_id ORDER BY m.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='ERP'; require __DIR__.'/partials/header.php';
?>
<div class="container-fluid py-4">
<h1 class="h3 mb-3">ERP - Produção e Armazém</h1>
<?php if($flashSuccess):?><div class="alert alert-success"><?=h($flashSuccess)?></div><?php endif;?><?php if($flashError):?><div class="alert alert-danger"><?=h($flashError)?></div><?php endif;?>
<div class="row g-3">
<div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5">Novo artigo</h2><form method="post" class="vstack gap-2"><input type="hidden" name="action" value="create_product"><input class="form-control" name="code" placeholder="Código" required><input class="form-control" name="description" placeholder="Descrição" required><select class="form-select" name="unit_id"><?php foreach($units as $u):?><option value="<?=$u['id']?>"><?=h($u['code'].' - '.$u['name'])?></option><?php endforeach;?></select><input class="form-control" type="number" step="0.001" name="min_stock" placeholder="Stock mínimo"><input class="form-control" type="number" step="0.001" name="unit_cost" placeholder="Custo unitário"><input class="form-control" type="number" step="0.001" name="unit_price" placeholder="Preço venda"><button class="btn btn-primary" <?=$isAdmin?'':'disabled'?>>Gravar</button></form></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5">Nova OF</h2><form method="post" class="vstack gap-2"><input type="hidden" name="action" value="create_order"><input class="form-control" name="order_number" placeholder="Nº OF" required><select class="form-select" name="product_id"><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['code'].' - '.$p['description'])?></option><?php endforeach;?></select><input class="form-control" type="number" step="0.001" name="planned_quantity" placeholder="Quantidade planeada" required><input class="form-control" type="date" name="due_date"><div class="row g-2"><div class="col"><input class="form-control" type="number" step="0.01" name="estimated_material_cost" placeholder="Mat."></div><div class="col"><input class="form-control" type="number" step="0.01" name="estimated_labor_cost" placeholder="M.O."></div><div class="col"><input class="form-control" type="number" step="0.01" name="estimated_overhead_cost" placeholder="Gastos"></div></div><textarea class="form-control" name="notes" placeholder="Observações"></textarea><button class="btn btn-primary" <?=$isAdmin?'':'disabled'?>>Criar OF</button></form></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5">Movimento de armazém</h2><form method="post" class="vstack gap-2"><input type="hidden" name="action" value="inventory_move"><select class="form-select" name="movement_type"><option>Entrada</option><option>Saída</option><option>Transferência</option><option>Consumo</option><option>Produção</option></select><select class="form-select" name="product_id"><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['code'])?></option><?php endforeach;?></select><select class="form-select" name="warehouse_from_id"><option value="">Armazém origem</option><?php foreach($warehouses as $w):?><option value="<?=$w['id']?>"><?=h($w['code'])?></option><?php endforeach;?></select><select class="form-select" name="warehouse_to_id"><option value="">Armazém destino</option><?php foreach($warehouses as $w):?><option value="<?=$w['id']?>"><?=h($w['code'])?></option><?php endforeach;?></select><input class="form-control" type="number" step="0.001" name="quantity" placeholder="Quantidade" required><input class="form-control" type="number" step="0.001" name="unit_cost" placeholder="Custo"><button class="btn btn-primary" <?=$isAdmin?'':'disabled'?>>Registar</button></form></div></div></div>
</div>
<div class="row g-3 mt-1"><div class="col-lg-6"><div class="card"><div class="card-body"><h2 class="h5">Ordens de fabrico</h2><div class="table-responsive"><table class="table table-sm"><tr><th>OF</th><th>Artigo</th><th>Qtd.</th><th>Custo estimado</th><th>Estado</th></tr><?php foreach($orders as $o): $cost=(float)$o['estimated_material_cost']+(float)$o['estimated_labor_cost']+(float)$o['estimated_overhead_cost'];?><tr><td><?=h($o['order_number'])?></td><td><?=h($o['product_code'])?></td><td><?=h((string)$o['planned_quantity'])?></td><td><?=number_format($cost,2,',','.')?> €</td><td><?=h($o['status'])?></td></tr><?php endforeach;?></table></div><hr><h3 class="h6">Anexar documento à OF</h3><form method="post" class="row g-2"><input type="hidden" name="action" value="create_document"><div class="col-md-4"><select class="form-select form-select-sm" name="production_order_id"><?php foreach($orders as $o):?><option value="<?=$o['id']?>"><?=h($o['order_number'])?></option><?php endforeach;?></select></div><div class="col-md-4"><input class="form-control form-control-sm" name="title" placeholder="Título" required></div><div class="col-md-4"><input class="form-control form-control-sm" name="document_url" placeholder="URL/documento"></div><div class="col-12"><textarea class="form-control form-control-sm" name="body" placeholder="Instruções ou conteúdo do documento"></textarea></div><div class="col-12 d-flex gap-2"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_required" value="1" checked> Obrigatório</label><button class="btn btn-sm btn-outline-primary" <?=$isAdmin?'':'disabled'?>>Anexar</button></div></form></div></div></div><div class="col-lg-6"><div class="card"><div class="card-body"><h2 class="h5">Movimentos recentes</h2><table class="table table-sm"><tr><th>Data</th><th>Tipo</th><th>Artigo</th><th>Qtd.</th></tr><?php foreach($movements as $m):?><tr><td><?=h($m['movement_date'])?></td><td><?=h($m['movement_type'])?></td><td><?=h($m['product_code'])?></td><td><?=h((string)$m['quantity'])?></td></tr><?php endforeach;?></table></div></div></div></div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>
