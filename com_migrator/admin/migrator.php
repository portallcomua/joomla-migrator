<?php
/**
 * @package     Migrator
 * @subpackage  com_migrator
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

try {
    // Отримуємо додаток
    $app = Factory::getApplication();
    
    $component = $app->bootComponent('com_migrator');
    
    $component->getDispatcher($app)->dispatch();
} catch (\Throwable $e) {
    echo '<div class="alert alert-danger">Помилка ініціалізації компонента: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</div>';
}
