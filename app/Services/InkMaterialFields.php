<?php
/** Shared ink contract for purchasing, master data and legacy spreadsheet imports. */
final class InkMaterialFields
{
    public static function boolean($value): int
    {
        $key=strtolower(trim((string)$value));
        if(in_array($key,['1','sim','yes','true','s','x'],true))return 1;
        if(in_array($key,['0','nao','não','no','false','n',''],true))return 0;
        throw new RuntimeException('Tinta de água / É tinta: utilize Sim ou Não.');
    }
    public static function normalize(PDO $pdo,array $input,array $existing=[]): array
    {
        $values=[];
        foreach(['is_ink','ink_color','ink_pantone','legacy_ink_id','is_water_based_ink','last_price'] as $field){
            if(array_key_exists($field,$input))$values[$field]=$input[$field];
            elseif(array_key_exists($field,$existing))$values[$field]=$existing[$field];
        }
        foreach(['ink_color','ink_pantone','legacy_ink_id'] as $field){$values[$field]=trim((string)($values[$field]??''));if(strlen($values[$field])>120)throw new RuntimeException('Cor, Pantone e ID antigo devem ter até 120 caracteres.');}
        $values['is_water_based_ink']=self::boolean($values['is_water_based_ink']??0);
        $values['is_ink']=self::boolean($values['is_ink']??0)||$values['is_water_based_ink']||$values['ink_color']!==''||$values['ink_pantone']!==''||$values['legacy_ink_id']!==''?1:0;
        if(array_key_exists('last_price',$values)){
            $number=preg_replace('/[\s\x{00A0}]/u','',trim((string)$values['last_price']));
            if(strpos($number,',')!==false&&strpos($number,'.')!==false)$number=strrpos($number,',')>strrpos($number,'.')?str_replace(',','.',str_replace('.','',$number)):str_replace(',','',$number);
            else $number=str_replace(',','.',$number);
            if(!is_numeric($number)||!is_finite((float)$number)||(float)$number<0)throw new RuntimeException('O último preço deve ser um número igual ou superior a zero.');
            $values['last_price']=(float)$number;
        }
        if($values['is_ink']){
            if($values['ink_color']===''||$values['ink_pantone']==='')throw new RuntimeException('Nas tintas, preencha a cor e o Pantone (por exemplo 000, se essa for a referência original).');
            if($values['legacy_ink_id']!==''){
                $q=$pdo->prepare('SELECT code FROM erp_raw_materials WHERE legacy_ink_id=? AND id<>? AND code<>? COLLATE NOCASE LIMIT 1');$q->execute([$values['legacy_ink_id'],(int)($existing['id']??0),(string)($input['code']??$existing['code']??'')]);
                if($other=$q->fetchColumn())throw new RuntimeException('O ID antigo da tinta já está associado ao código '.$other.'. Confirme a referência antes de importar.');
            }
        }
        if($values['is_water_based_ink']){
            $q=$pdo->query('SELECT id FROM erp_ink_types WHERE code="AGUA"');$water=$q->fetchColumn();if($water)$values['ink_type_id']=(int)$water;
            $values['is_solvent_based_ink']=0;
        }else{$q=$pdo->query('SELECT id FROM erp_ink_types WHERE code="AGUA"');$water=(int)$q->fetchColumn();$selected=(int)($input['ink_type_id']??$existing['ink_type_id']??0);if($water&&$selected===$water)$values['ink_type_id']=null;}
        return $values;
    }
}
