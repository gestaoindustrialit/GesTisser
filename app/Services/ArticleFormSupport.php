<?php
function erp_article_fields(): array { return ['material','bag_color','width_tolerance','length_tolerance','front_colors','back_colors','of_front_colors','of_back_colors','of_colors_match_technical','printer_roll_measure','pallet_dimensions','pallet_lid','pallet_straps','pallet_film','pallet_weight','pallet_quantity','microperforation','has_handle','has_holes','has_gusset','centered_gusset','gusset_length','composition','theoretical_weight','thread_color','perforation_type','seam_type','lot_identification_rule','analysis_grammage','analysis_total_weight','analysis_apparent_width','analysis_gusset_width','analysis_bag_height','analysis_break_height','analysis_break_length','analysis_seam_strength','analysis_static_friction','analysis_dynamic_friction','analysis_air_permeability']; }
function erp_colors_per_face($value) {
    $value=preg_replace('/\s+/', '', trim((string)$value));
    if($value==='')return null;
    if(!preg_match('/^[0-8](?:\+[0-8])?$/',$value))throw new RuntimeException('Indique as cores por face entre 0 e 8, por exemplo 2+0.');
    return $value;
}
function erp_article_grammage($value) {
    $value=str_replace(',', '.', preg_replace('/\s+/', '', trim((string)$value)));
    if($value==='')return null;
    if(!preg_match('/^\d+(?:\.\d+)?(?:\+\d+(?:\.\d+)?)*$/',$value))throw new RuntimeException('Indique uma gramagem válida, por exemplo 60 ou 60+20.');
    return $value;
}
function erp_article_color_list($value): array {
    if (is_array($value)) return array_values(array_filter(array_map('trim', $value), 'strlen'));
    return array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $value)), 'strlen'));
}
function erp_validate_article_inks(PDO $pdo, $value, string $label): string {
    $colors=array_values(array_unique(erp_article_color_list($value)));
    if(count($colors)>8)throw new RuntimeException($label.' permite selecionar no máximo 8 tintas.');
    if(!$colors)return '';
    $placeholders=implode(',',array_fill(0,count($colors),'?'));
    $stmt=$pdo->prepare('SELECT code,description FROM erp_raw_materials WHERE product_category="subsidiary" AND status="Ativo" AND code IN ('.$placeholders.')');
    $stmt->execute($colors);$available=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$ink)$available[(string)$ink['code']]=(string)$ink['code'].' — '.(string)$ink['description'];
    foreach($colors as$code)if(!isset($available[$code]))throw new RuntimeException($label.' contém uma tinta inválida ou inativa. Selecione uma matéria-prima subsidiária.');
    return implode("\n",array_map(function($code)use($available){return$available[$code];},$colors));
}
function erp_selected_ink_codes($value, array $inks): array {
    $selected=[];$values=erp_article_color_list($value);
    foreach($inks as$ink){$code=(string)$ink['code'];$label=$code.' — '.(string)$ink['description'];if(in_array($code,$values,true)||in_array($label,$values,true))$selected[]=$code;}
    return array_slice($selected,0,8);
}
