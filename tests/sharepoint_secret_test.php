<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
function secret_check($ok, $label) { if (!$ok) throw new RuntimeException($label); }
$previous = getenv('GESTISSER_SHAREPOINT_CLIENT_SECRET');
$dir = sys_get_temp_dir().'/gestisser-secret-'.bin2hex(random_bytes(8));
mkdir($dir, 0700);
$path = $dir.'/secret';
set_error_handler(function ($severity) {
    if (error_reporting() & $severity) throw new RuntimeException('Unexpected warning');
    return true;
});
try {
    putenv('GESTISSER_SHAREPOINT_CLIENT_SECRET');
    secret_check(SharePointClientSecret::read($path) === '', 'missing file');
    file_put_contents($path, "dummy-file-secret\n"); chmod($path, 0600);
    secret_check(SharePointClientSecret::read($path) === 'dummy-file-secret', 'private file and newline');
    putenv('GESTISSER_SHAREPOINT_CLIENT_SECRET=dummy-environment-secret');
    secret_check(SharePointClientSecret::read($path) === 'dummy-environment-secret', 'environment precedence');
    secret_check(SharePointClientSecret::available(), 'availability boolean');
    putenv('GESTISSER_SHAREPOINT_CLIENT_SECRET=');
    secret_check(SharePointClientSecret::read($path) === 'dummy-file-secret', 'empty environment fallback');
    putenv('GESTISSER_SHAREPOINT_CLIENT_SECRET=   ');
    secret_check(SharePointClientSecret::read($path) === 'dummy-file-secret', 'blank environment fallback');
    chmod($path, 0640);
    secret_check(SharePointClientSecret::read($path) === '', 'reject group-readable file');
    chmod($path, 0600); chmod($dir, 0750);
    secret_check(SharePointClientSecret::read($path) === '', 'reject shared directory');
    chmod($dir, 0700);
    symlink($path, $dir.'/link');
    secret_check(SharePointClientSecret::read($dir.'/link') === '', 'reject symlink');
    unlink($dir.'/link');
    foreach (['', "   \n", "one\ntwo", str_repeat('a', 8193), "one\0two"] as $invalid) {
        file_put_contents($path, $invalid);
        secret_check(SharePointClientSecret::read($path) === '', 'reject invalid content');
    }
    file_put_contents($path, '<?php echo "must-not-execute";');
    ob_start(); $value = SharePointClientSecret::read($path); $output = ob_get_clean();
    secret_check($output === '' && strpos($value, '<?php') === 0, 'file read as data only');
    echo "PASS: secret precedence, private permissions, missing/invalid file, symlink rejection, no execution or output.\n";
} finally {
    restore_error_handler();
    if ($previous === false) putenv('GESTISSER_SHAREPOINT_CLIENT_SECRET');
    else putenv('GESTISSER_SHAREPOINT_CLIENT_SECRET='.$previous);
    if (is_file($path)) unlink($path);
    rmdir($dir);
}
