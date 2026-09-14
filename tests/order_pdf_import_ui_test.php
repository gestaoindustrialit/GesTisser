<?php
$source=(string)file_get_contents(__DIR__.'/../erp.php');
function ui_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
ui_check(strpos($source,'data-pdf-dropzone')!==false,'drop zone missing');
ui_check(strpos($source,'name="order_pdf"')!==false,'PDF input missing');
ui_check(strpos($source,"prepare_order_pdf")!==false,'prepare action missing');
ui_check(strpos($source,"confirm_order_import")!==false,'confirmation action missing');
ui_check(strpos($source,'name="line_item_key[]"')!==false,'article mapping missing');
ui_check(strpos($source,'name="line_ignored[')!==false,'ignore control missing');
ui_check(strpos($source,'data-pdf-progress')!==false,'progress feedback missing');
echo "order_pdf_import_ui_test: OK\n";
