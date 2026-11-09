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
    
    // Отримуємо екземпляр компонента (це створює Extension object)
    $component = $app->bootComponent('com_migrator');
    
    // Отримуємо диспетчер. У Joomla 6 це працює через контейнер.
    // Якщо метод getDispatcher() існує і вимагає аргументів, ми його не викликаємо напряму.
    $dispatcher = $component->getDispatcher();
    
    // Запускаємо компонент
    $dispatcher->dispatch();
    
} catch (\Exception $e) {
    // Якщо щось пішло не так на етапі ініціалізації, показуємо помилку
    echo '<div class="alert alert-danger">Помилка ініціалізації компонента: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
