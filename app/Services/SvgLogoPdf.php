<?php
/** Native outlined SVG logos: no rasterizer, fonts, Composer or external resources. */
final class SvgLogoPdf
{
    public static function fromDataUri(string $uri): array
    {
        if(!preg_match('#^data:image/svg\+xml;base64,#i',$uri,$match)||!class_exists('DOMDocument'))return [];
        $svg=base64_decode(substr($uri,strlen($match[0])),true);
        if(!is_string($svg)||preg_match('/<!DOCTYPE|<!ENTITY/i',$svg))return [];
        $previous=libxml_use_internal_errors(true);
        try{
            $dom=new DOMDocument();$dom->resolveExternals=false;$dom->substituteEntities=false;
            if(!$dom->loadXML($svg,LIBXML_NONET))return [];
            $root=$dom->documentElement;if(!$root||($root->localName!=='svg'||$root->namespaceURI!=='http://www.w3.org/2000/svg'))return [];
            $view=self::numbers($root->getAttribute('viewBox'));
            $width=(float)$root->getAttribute('width');$height=(float)$root->getAttribute('height');
            if(count($view)!==4){if($width<=0||$height<=0)return [];$view=[0,0,$width,$height];}
            list($x,$y,$vw,$vh)=$view;if($vw<=0||$vh<=0)return [];
            if($width<=0)$width=$vw;if($height<=0)$height=$vh;
            $scale=min($width/$vw,$height/$vh);
            $stream=self::matrix([$scale/$width,0,0,-$scale/$height,(($width-$vw*$scale)/2-$x*$scale)/$width,(($height-$vh*$scale)/2+($y+$vh)*$scale)/$height]);
            $stream.=self::node($root,['fill'=>'#000000','fill-rule'=>'nonzero','fill-opacity'=>'1','stroke'=>'none']);
            if(strpos($stream," rg\n")===false)return [];
            return ['type'=>'form','width'=>$width,'height'=>$height,'data'=>"q\n".$stream."Q\n"];
        }catch(RuntimeException $e){return [];}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    private static function numbers(string $value): array
    {
        preg_match_all('/[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?/',$value,$matches);
        return array_map('floatval',$matches[0]);
    }
    private static function n(float $value): string
    {
        if(!is_finite($value))throw new RuntimeException('Invalid SVG number.');
        return rtrim(rtrim(sprintf('%.8F',abs($value)<0.000000005?0:$value),'0'),'.');
    }
    private static function matrix(array $m): string {return implode(' ',array_map([__CLASS__,'n'],$m))." cm\n";}
    private static function transform(string $value): string
    {
        if(trim($value)==='')return '';
        preg_match_all('/([A-Za-z]+)\s*\(([^)]*)\)/',$value,$matches,PREG_SET_ORDER);
        if(preg_replace('/([A-Za-z]+)\s*\(([^)]*)\)|[\s,]+/','',$value)!=='')throw new RuntimeException('Invalid SVG transform.');
        $out='';foreach($matches as $match){$a=self::numbers($match[2]);$name=$match[1];
            if($name==='matrix'&&count($a)===6)$out.=self::matrix($a);
            elseif($name==='translate'&&(count($a)===1||count($a)===2))$out.=self::matrix([1,0,0,1,$a[0],$a[1]??0]);
            elseif($name==='scale'&&(count($a)===1||count($a)===2))$out.=self::matrix([$a[0],0,0,$a[1]??$a[0],0,0]);
            elseif($name==='rotate'&&(count($a)===1||count($a)===3)){
                $r=deg2rad($a[0]);$cx=$a[1]??0;$cy=$a[2]??0;
                $out.=self::matrix([1,0,0,1,$cx,$cy]).self::matrix([cos($r),sin($r),-sin($r),cos($r),0,0]).self::matrix([1,0,0,1,-$cx,-$cy]);
            }elseif(($name==='skewX'||$name==='skewY')&&count($a)===1){$t=tan(deg2rad($a[0]));$out.=self::matrix([1,$name==='skewY'?$t:0,$name==='skewX'?$t:0,1,0,0]);}
            else throw new RuntimeException('Unsupported SVG transform.');
        }return $out;
    }
    private static function node(DOMElement $node,array $style): string
    {
        $tag=$node->localName;
        if(in_array($tag,['title','desc','metadata'],true))return '';
        if(!in_array($tag,['svg','g','path'],true))throw new RuntimeException('Unsupported SVG element.');
        foreach(['filter','mask','clip-path'] as $attribute)if($node->hasAttribute($attribute))throw new RuntimeException('Unsupported SVG effect.');
        foreach(['fill','fill-rule','fill-opacity','stroke','opacity','display','visibility'] as $key)if($node->hasAttribute($key))$style[$key]=$node->getAttribute($key);
        foreach(explode(';',$node->getAttribute('style')) as $declaration){$pair=explode(':',$declaration,2);if(count($pair)===2){$key=trim($pair[0]);$style[$key]=trim($pair[1]);}}
        if(($style['display']??'')==='none'||($style['visibility']??'')==='hidden'||(isset($style['opacity'])&&(float)$style['opacity']===0.0))return '';
        if((float)($style['opacity']??1)!==1.0||(float)$style['fill-opacity']!==1.0)throw new RuntimeException('Unsupported SVG alpha.');
        if(isset($style['filter'])||isset($style['mask'])||isset($style['clip-path']))throw new RuntimeException('Unsupported SVG style effect.');
        $out="q\n".self::transform($node->getAttribute('transform'));
        if($tag==='path'&&$style['stroke']!=='none')throw new RuntimeException('Unsupported SVG stroke.');
        if($tag==='path'&&$style['fill']!=='none'){
            $color=$style['fill'];
            if(preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i',$color,$match)){
                $hex=$match[1];if(strlen($hex)===3)$hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                $out.=self::n(hexdec(substr($hex,0,2))/255).' '.self::n(hexdec(substr($hex,2,2))/255).' '.self::n(hexdec(substr($hex,4,2))/255)." rg\n";
            }elseif(in_array($color,['black','white'],true))$out.=($color==='black'?'0 0 0':'1 1 1')." rg\n";
            else throw new RuntimeException('Unsupported SVG fill.');
            $out.=self::path($node->getAttribute('d')).($style['fill-rule']==='evenodd'?"f*\n":"f\n");
        }
        foreach($node->childNodes as $child)if($child instanceof DOMElement)$out.=self::node($child,$style);
        return $out."Q\n";
    }
    private static function path(string $d): string
    {
        preg_match_all('/[A-Za-z]|[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?/',$d,$matches);$tokens=$matches[0];
        if(preg_replace('/[A-Za-z]|[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?|[\s,]+/','',$d)!=='')throw new RuntimeException('Invalid SVG path.');
        $i=0;$cmd='';$x=0;$y=0;$sx=0;$sy=0;$last='';$control=null;$quadratic=null;$out='';
        $counts=['M'=>2,'L'=>2,'H'=>1,'V'=>1,'C'=>6,'S'=>4,'Q'=>4,'T'=>2];
        while($i<count($tokens)){
            if(ctype_alpha($tokens[$i]))$cmd=$tokens[$i++];
            $op=strtoupper($cmd);$relative=$cmd!==$op;
            if($op==='Z'){$out.="h\n";$x=$sx;$y=$sy;$last='Z';$control=null;$quadratic=null;$cmd='';continue;}
            if(!isset($counts[$op]))throw new RuntimeException('Unsupported SVG path command.');
            $n=$counts[$op];$v=[];for($j=0;$j<$n;$j++){if(!isset($tokens[$i])||!is_numeric($tokens[$i]))throw new RuntimeException('Incomplete SVG path.');$v[]=(float)$tokens[$i++];}
            $bx=$relative?$x:0;$by=$relative?$y:0;
            if($op==='M'||$op==='L'){$x=$v[0]+$bx;$y=$v[1]+$by;$out.=self::n($x).' '.self::n($y).($op==='M'?" m\n":" l\n");if($op==='M'){$sx=$x;$sy=$y;$cmd=$relative?'l':'L';}}
            elseif($op==='H'||$op==='V'){if($op==='H')$x=$v[0]+$bx;else $y=$v[0]+$by;$out.=self::n($x).' '.self::n($y)." l\n";}
            elseif($op==='C'||$op==='S'){
                if($op==='C'){$c1=[$v[0]+$bx,$v[1]+$by];$c2=[$v[2]+$bx,$v[3]+$by];$end=[$v[4]+$bx,$v[5]+$by];}
                else{$c1=in_array($last,['C','S'],true)&&$control?[2*$x-$control[0],2*$y-$control[1]]:[$x,$y];$c2=[$v[0]+$bx,$v[1]+$by];$end=[$v[2]+$bx,$v[3]+$by];}
                $out.=implode(' ',array_map([__CLASS__,'n'],array_merge($c1,$c2,$end)))." c\n";$control=$c2;$x=$end[0];$y=$end[1];
            }elseif($op==='Q'||$op==='T'){
                if($op==='Q'){$q=[$v[0]+$bx,$v[1]+$by];$end=[$v[2]+$bx,$v[3]+$by];}
                else{$q=in_array($last,['Q','T'],true)&&$quadratic?[2*$x-$quadratic[0],2*$y-$quadratic[1]]:[$x,$y];$end=[$v[0]+$bx,$v[1]+$by];}
                $curve=[$x+2*($q[0]-$x)/3,$y+2*($q[1]-$y)/3,$end[0]+2*($q[0]-$end[0])/3,$end[1]+2*($q[1]-$end[1])/3,$end[0],$end[1]];
                $out.=implode(' ',array_map([__CLASS__,'n'],$curve))." c\n";$quadratic=$q;$x=$end[0];$y=$end[1];
            }
            if($op!=='C'&&$op!=='S')$control=null;if($op!=='Q'&&$op!=='T')$quadratic=null;$last=$op;
        }return $out;
    }
}
