<?php
// Compatibility entry point for deployments that use /installer.php.
header('Location: install.php', true, 302);
exit;
