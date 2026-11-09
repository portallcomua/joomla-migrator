<?php
namespace Migrator\Component\Migrator\Administrator\Service;

class ExtensionAssessmentService
{
    public function assess(array $sourceExtensions, array $targetExtensions): array
    {
        $targetIndex = [];

        foreach ($targetExtensions as $extension) {
            $targetIndex[$this->key($extension)] = $extension;
        }

        foreach ($sourceExtensions as &$extension) {
            $target = $targetIndex[$this->key($extension)] ?? null;
            $extension['target_status'] = $target === null
                ? 'Не встановлено на цільовому сайті'
                : ((int) ($target['enabled'] ?? 0) === 1 ? 'Встановлено й увімкнено' : 'Встановлено, вимкнено');
            $extension['migration_status'] = $this->migrationStatus((string) $extension['element']);
            $extension['recommendation'] = $this->recommendation((string) $extension['element'], $target !== null);
            $extension['can_install'] = $target === null && !in_array(
                (string) $extension['element'],
                ['com_content', 'com_users', 'com_menus', 'com_modules', 'mod_custom', 'com_k2', 'com_virtuemart'],
                true
            );
        }
        unset($extension);

        return $sourceExtensions;
    }

    private function key(array $extension): string
    {
        return strtolower((string) ($extension['type'] ?? '')) . "\0"
            . strtolower((string) ($extension['folder'] ?? '')) . "\0"
            . strtolower((string) ($extension['element'] ?? ''));
    }

    private function migrationStatus(string $element): string
    {
        if (in_array($element, ['com_content', 'com_users', 'com_menus', 'com_modules', 'mod_custom'], true)) {
            return 'Частково: переносяться лише дані ядра та mod_custom';
        }

        if ($element === 'com_k2') {
            return 'Не підтримується: адаптера K2 немає';
        }

        if ($element === 'com_virtuemart') {
            return 'Не підтримується: адаптера VirtueMart немає';
        }

        if ($element === 'com_akeebabackup') {
            return 'Дані не переносяться: це розширення резервного копіювання';
        }

        return 'Не перевірено: міграція даних цього розширення не реалізована';
    }

    private function recommendation(string $element, bool $installedOnTarget): string
    {
        if ($element === 'com_akeebabackup') {
            return $installedOnTarget
                ? 'Не потрібен для перенесення контенту. Перевірте, чи встановлена версія сумісна з Joomla 6.2.'
                : 'Не потрібен для перенесення контенту. Якщо потрібні резервні копії, встановіть офіційний пакет Akeeba Backup із підтримкою Joomla 6.2.';
        }

        if ($element === 'com_k2') {
            return 'Дані K2 автоматично не переносяться. Залиште старий сайт доступним або використайте окремий перевірений мігратор у копії сайту.';
        }

        if ($element === 'com_virtuemart') {
            return 'Товари, замовлення та інші дані VirtueMart не переносяться. Потрібен окремий мігратор, сумісний із цільовою Joomla.';
        }

        if (in_array($element, ['com_content', 'com_users', 'com_menus', 'com_modules', 'mod_custom'], true)) {
            return 'Не встановлюйте окремо: це розширення ядра Joomla. Перевірте примітки про часткову підтримку.';
        }

        if ($installedOnTarget) {
            return 'Встановлення не потрібне. Сумісність і перенесення власних даних ще не перевірені.';
        }

        return 'Не є блокером перенесення даних ядра. Якщо розширення потрібне, перевірте підтримку Joomla 6.2 у виробника та встановіть його офіційний пакет.';
    }
}