<?php
$files=['GenericPdfOrderParser.php','OrderImportMatcher.php','OrderImportNormalizer.php','OrderPdfUpload.php','OrderSupplierDetector.php','PdfTextExtractor.php','PurchaseOrderImportService.php','SupplierSpecificParserInterface.php'];
foreach($files as $file){$source=(string)file_get_contents(__DIR__.'/../app/Services/'.$file);
    if(preg_match('/function\s+\w+\s*\([^)]*\?(?:array|int|string|float|bool)\b/',$source,$match))throw new RuntimeException($file.' contém parâmetro nullable incompatível com PHP 7.0: '.$match[0]);
    if(preg_match('/\)\s*:\s*\?(?:array|int|string|float|bool)\b/',$source,$match))throw new RuntimeException($file.' contém retorno nullable incompatível com PHP 7.0: '.$match[0]);
    if(preg_match('/\)\s*:\s*void\b/',$source,$match))throw new RuntimeException($file.' contém retorno void incompatível com PHP 7.0.');
    if(preg_match('/\b(?:private|protected|public)\s+const\b/',$source,$match))throw new RuntimeException($file.' contém visibilidade de constante incompatível com PHP 7.0.');
}
echo "order_pdf_php70_compatibility_test: OK\n";
