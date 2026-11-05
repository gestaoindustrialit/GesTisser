<?php
/** Ink types come from the existing settings catalogue; legacy flags are compatibility inputs. */
final class InkMaterialFields
{
    public static function boolean($value): int
    {
        $key=strtolower(trim((string)$value));
        if(in_array($key,['1','sim','yes','true','s','x'],true))return 1;
        if(in_array($key,['0','nao','não','no','false','n',''],true))return 0;
        throw new RuntimeException('Tinta de água / É tinta: utilize Sim ou Não.');
    }
    public static function featureValues(string $description): array
    {
        $result=[];
        if(preg_match('/(?:^|\|)\s*COR\s*:\s*([^|]+)/iu',$description,$match))$result['ink_color']=trim($match[1]);
        if(preg_match('/(?:^|\|)\s*PANTONE\s*:\s*([^|]+)/iu',$description,$match))$result['ink_pantone']=trim($match[1]);
        return $result;
    }
    public static function normalize(PDO $pdo,array $input,array $existing=[]): array
    {
        $values=[];
        foreach(['is_ink','ink_color','ink_pantone','legacy_ink_id','last_price'] as $field){
            if(array_key_exists($field,$input))$values[$field]=$input[$field];elseif(array_key_exists($field,$existing))$values[$field]=$existing[$field];
        }
        $featureId=(int)($input['material_feature_id']??$existing['material_feature_id']??0);
        if($featureId){
            $q=$pdo->prepare('SELECT description FROM erp_material_features WHERE id=?');$q->execute([$featureId]);
            foreach(self::featureValues((string)$q->fetchColumn()) as $field=>$source){
                if(trim((string)($input[$field]??$existing[$field]??''))!==''&&strcasecmp(trim((string)($input[$field]??$existing[$field]??'')),$source)!==0)throw new RuntimeException('A cor ou o Pantone divergem da característica selecionada. Confirme a característica do material antes de guardar.');
                $values[$field]=$source;
            }
        }
        foreach(['ink_color','ink_pantone','legacy_ink_id'] as $field){$values[$field]=trim((string)($values[$field]??''));if(strlen($values[$field])>120)throw new RuntimeException('Cor, Pantone e ID antigo devem ter até 120 caracteres.');}
        $explicitType=array_key_exists('ink_type_id',$input)||array_key_exists('ink_type_code',$input);
        $typeId=(int)($input['ink_type_id']??$existing['ink_type_id']??0);$type=null;
        if(array_key_exists('ink_type_code',$input)&&trim((string)$input['ink_type_code'])!==''){
            $q=$pdo->prepare('SELECT * FROM erp_ink_types WHERE code=? COLLATE NOCASE OR name=? COLLATE NOCASE');$q->execute([trim((string)$input['ink_type_code']),trim((string)$input['ink_type_code'])]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($matches)!==1)throw new RuntimeException('Tipo de tinta não encontrado ou ambíguo nas configurações.');$type=$matches[0];$typeId=(int)$type['id'];
        }elseif($typeId){$q=$pdo->prepare('SELECT * FROM erp_ink_types WHERE id=?');$q->execute([$typeId]);$type=$q->fetch(PDO::FETCH_ASSOC);if(!$type)throw new RuntimeException('Selecione um tipo de tinta existente.');}
        $waterProvided=array_key_exists('is_water_based_ink',$input);$legacyWater=$waterProvided?self::boolean($input['is_water_based_ink']):null;
        if($waterProvided&&$type&&$legacyWater!==(strtoupper((string)$type['code'])==='AGUA'?1:0))throw new RuntimeException('Tinta de água contradiz o tipo de tinta já configurado. Confirme a classificação; não foi alterada automaticamente.');
        if(!$type&&!$explicitType&&$legacyWater===1){$q=$pdo->query('SELECT * FROM erp_ink_types WHERE code="AGUA" COLLATE NOCASE');$type=$q->fetch(PDO::FETCH_ASSOC);if(!$type)throw new RuntimeException('Configure o tipo de tinta de água antes de importar.');$typeId=(int)$type['id'];}
        if($type&&isset($type['is_active'])&&!(int)$type['is_active']&&$typeId!==(int)($existing['ink_type_id']??0))throw new RuntimeException('O tipo de tinta selecionado está inativo.');
        if($explicitType&&!$type&&$legacyWater===1)throw new RuntimeException('Selecione um tipo de tinta coerente com a indicação de água.');
        $values['ink_type_id']=$typeId?:null;
        $values['is_water_based_ink']=$type&&strtoupper((string)$type['code'])==='AGUA'?1:0;
        $values['is_solvent_based_ink']=$type&&strtoupper((string)$type['code'])==='SOLVENTE'?1:0;
        $materialTypeId=(int)($input['material_type_id']??$existing['material_type_id']??0);$inkFamily=false;if($materialTypeId){$q=$pdo->prepare('SELECT code FROM erp_material_types WHERE id=?');$q->execute([$materialTypeId]);$inkFamily=in_array(strtoupper(trim((string)$q->fetchColumn())),['TINTA','TINTAS','INK'],true);}
        $values['is_ink']=self::boolean($values['is_ink']??0)||$typeId||$inkFamily||$values['ink_color']!==''||$values['ink_pantone']!==''||$values['legacy_ink_id']!==''?1:0;
        if(array_key_exists('last_price',$values)){
            $number=preg_replace('/[\s\x{00A0}]/u','',trim((string)$values['last_price']));
            if(strpos($number,',')!==false&&strpos($number,'.')!==false)$number=strrpos($number,',')>strrpos($number,'.')?str_replace(',','.',str_replace('.','',$number)):str_replace(',','',$number);
            else $number=str_replace(',','.',$number);
            if(!is_numeric($number)||!is_finite((float)$number)||(float)$number<0)throw new RuntimeException('O último preço deve ser um número igual ou superior a zero.');$values['last_price']=(float)$number;
        }
        if($values['is_ink']){
            if($values['ink_color']===''||$values['ink_pantone']==='')throw new RuntimeException('Nas tintas, preencha a cor e o Pantone (por exemplo 000, se essa for a referência original).');
            if($values['legacy_ink_id']!==''){
                $q=$pdo->prepare('SELECT code FROM erp_raw_materials WHERE legacy_ink_id=? AND id<>? AND code<>? COLLATE NOCASE LIMIT 1');$q->execute([$values['legacy_ink_id'],(int)($existing['id']??0),(string)($input['code']??$existing['code']??'')]);
                if($other=$q->fetchColumn())throw new RuntimeException('O ID antigo da tinta já está associado ao código '.$other.'. Confirme a referência antes de importar.');
            }
        }
        return $values;
    }
}
