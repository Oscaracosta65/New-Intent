<?php
/**
 * mod_le_instantintent
 * Joomla 5.x module
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;

require_once __DIR__ . '/helper.php';

// Load assets (scoped + versioned)
$assetsBase = 'mod_le_instantintent';
$wa = $app->getDocument()->getWebAssetManager();

$wa->registerAndUseStyle(
    $assetsBase . '.css',
    'media/mod_le_instantintent/css/instantintent.css',
    [],
    ['version' => '1.1.0']
);

$wa->registerAndUseScript(
    $assetsBase . '.js',
    'media/mod_le_instantintent/js/instantintent.js',
    [],
    ['defer' => true, 'version' => '1.1.0']
);

// Initial server render: provide one initial payload so the page shows instantly
$initial = ModLeInstantintentHelper::buildPicksPayload($params, (string) $params->get('default_method', 'random'), (int) $params->get('default_lines', 5));

require ModuleHelper::getLayoutPath('mod_le_instantintent', $params->get('layout', 'default'));
