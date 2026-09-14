<?php
declare(strict_types=1);

final class PdfTextExtractor
{
    public function extract(string $path): string
    {
        if (!is_file($path)) throw new RuntimeException('PDF arquivado não encontrado.');
        $binary=$this->findBinary('pdftotext');
        if($binary!==''){
            $output=tempnam(sys_get_temp_dir(),'gt_pdf_');
            $command=escapeshellarg($binary).' -layout -enc UTF-8 '.escapeshellarg($path).' '.escapeshellarg($output).' 2>&1';
            exec($command,$messages,$status);$text=$status===0&&is_file($output)?(string)file_get_contents($output):'';@unlink($output);
            if(trim($text)!=='')return $text;
        }
        throw new RuntimeException('Não foi possível extrair texto deste PDF. Se for uma digitalização, envie um PDF com OCR pesquisável. O original ficou guardado para diagnóstico.');
    }
    private function findBinary(string $name):string
    {
        foreach(['/usr/bin/','/usr/local/bin/'] as $directory){$path=$directory.$name;if(is_executable($path))return $path;}
        return '';
    }
}
