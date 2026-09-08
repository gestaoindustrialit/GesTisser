<?php
declare(strict_types=1);

function integration_editor_route_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$listSource = file_get_contents(dirname(__DIR__) . '/integrations.php');
$editorSource = file_get_contents(dirname(__DIR__) . '/integration_edit.php');

integration_editor_route_assert(
    strpos($listSource, 'href="integrations.php?action=edit"') !== false,
    'The new-integration link must use the integrations entry point.'
);
integration_editor_route_assert(
    strpos($listSource, "require __DIR__.'/integration_edit.php'") !== false,
    'The integrations entry point must dispatch editor requests.'
);
integration_editor_route_assert(
    strpos($editorSource, "'integrations.php?action=edit'") !== false,
    'The editor must preserve the routed URL after saving.'
);

echo "Integration editor route: OK\n";
