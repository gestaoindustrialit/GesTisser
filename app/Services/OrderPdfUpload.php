<?php
declare(strict_types=1);

final class OrderPdfUpload
{
    public static function store(array $file,string $directory,int $maxBytes=10485760):array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Não foi possível receber o PDF.');
        $name=basename((string)($file['name']??''));$tmp=(string)($file['tmp_name']??'');$size=(int)($file['size']??0);
        if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf')throw new RuntimeException('Apenas ficheiros PDF são permitidos.');
        if($size<5||$size>$maxBytes)throw new RuntimeException('O PDF excede o tamanho máximo permitido ou está vazio.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$head=(string)file_get_contents($tmp,false,null,0,5);
        if($mime!=='application/pdf'||$head!=='%PDF-')throw new RuntimeException('O conteúdo do ficheiro não é um PDF válido.');
        if(!is_dir($directory)&&!mkdir($directory,0750,true))throw new RuntimeException('Não foi possível preparar o arquivo de importações.');
        // Defence in depth for deployments whose storage directory is below the
        // web root. Imported originals must only be reached through an audited endpoint.
        if(!is_file($directory.'/.htaccess'))@file_put_contents($directory.'/.htaccess',"Require all denied\nDeny from all\n");
        if(!is_file($directory.'/web.config'))@file_put_contents($directory.'/web.config','<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        $stored=bin2hex(random_bytes(24)).'.pdf';$path=rtrim($directory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$stored;
        if(!is_uploaded_file($tmp)||!move_uploaded_file($tmp,$path))throw new RuntimeException('Não foi possível arquivar o PDF.');
        @chmod($path,0640);return ['original_filename'=>$name,'stored_filename'=>$stored,'mime_type'=>$mime,'file_size'=>filesize($path),'file_hash'=>hash_file('sha256',$path),'path'=>$path];
    }
}
