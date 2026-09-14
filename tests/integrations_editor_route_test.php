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
    strpos($listSource, 'integrations_migrate($pdo);') < strpos($listSource, "require __DIR__.'/integration_edit.php'"),
    'The integration schema must be initialized before dispatching the editor.'
);
integration_editor_route_assert(
    strpos($listSource, '$integrationManager = new IntegrationManager($pdo);') !== false,
    'The routed editor must receive the initialized integration manager.'
);
integration_editor_route_assert(
    strpos($editorSource, '$isRoutedEditor=isset($integrationManager)') !== false
        && strpos($editorSource, 'if(!$isRoutedEditor){require_admin();}') !== false,
    'The editor must identify routed requests without relying on rewritten server paths.'
);
integration_editor_route_assert(
    strpos($editorSource, "'integrations.php?action=edit'") !== false,
    'The editor must preserve the routed URL after saving.'
);

echo "Integration editor route: OK\n";
