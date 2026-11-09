<?php
defined('_JEXEC') or die;

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$analysisData = $this->analysisData ?? [];
$report = $this->report ?? null;
$templateOptions = $this->templateOptions ?? [];
$selectedStyleId = (int) ($analysisData['target_template_style_id'] ?? ($report['target_template_style_id'] ?? 0));

if ($selectedStyleId < 1) {
    foreach ($templateOptions as $styleOption) {
        if ($styleOption['home'] === 1) {
            $selectedStyleId = $styleOption['id'];
            break;
        }
    }
}
?>
<div class="container mt-4">
    <h2>Мігратор Joomla — Аналіз старої бази даних</h2>
    <hr>
    <form method="post" action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_migrator&task=analyze'); ?>">
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Хост старої БД</label>
                <input type="text" name="old_host" value="<?php echo $escape($analysisData['host'] ?? 'localhost'); ?>" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Ім'я БД</label>
                <input type="text" name="old_db" value="<?php echo $escape($analysisData['db'] ?? ''); ?>" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Префікс</label>
                <input type="text" name="old_prefix" value="<?php echo $escape($analysisData['prefix'] ?? 'jos_'); ?>" class="form-control" required>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="migration-old-root">Корінь старого сайту відносно нового</label>
                <input id="migration-old-root" type="text" name="old_root" value="<?php echo $escape($analysisData['old_root'] ?? '..'); ?>" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="old-version">Версія старої Joomla (резервний вибір)</label>
                <select id="old-version" name="old_version" class="form-select">
                    <?php $versionOverride = (string) ($analysisData['version_override'] ?? 'auto'); ?>
                    <option value="auto" <?php echo $versionOverride === 'auto' ? 'selected' : ''; ?>>Визначити автоматично</option>
                    <option value="2.5" <?php echo $versionOverride === '2.5' ? 'selected' : ''; ?>>2.5.x</option>
                    <option value="3" <?php echo $versionOverride === '3' ? 'selected' : ''; ?>>3.x</option>
                    <option value="4" <?php echo $versionOverride === '4' ? 'selected' : ''; ?>>4.x</option>
                    <option value="5" <?php echo $versionOverride === '5' ? 'selected' : ''; ?>>5.x</option>
                    <option value="6" <?php echo $versionOverride === '6' ? 'selected' : ''; ?>>6.x</option>
                </select>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Користувач</label>
                <input type="text" name="old_user" value="<?php echo $escape($analysisData['user'] ?? ''); ?>" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Пароль<?php echo !empty($report['success']) ? ' (введіть знову для CSV або імпорту)' : ''; ?></label>
                <input type="password" name="old_pass" class="form-control">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="target-template-style">Шаблон і стиль для перенесених модулів</label>
                <select id="target-template-style" name="target_template_style_id" class="form-select">
                    <?php if (!$templateOptions): ?>
                        <option value="0" selected>Встановлених frontend-стилів не знайдено</option>
                    <?php endif; ?>
                    <?php foreach ($templateOptions as $styleOption): ?>
                        <option value="<?php echo (int) $styleOption['id']; ?>" <?php echo $selectedStyleId === $styleOption['id'] ? 'selected' : ''; ?>>
                            <?php echo $escape($styleOption['title'] . ' (' . $styleOption['template'] . ')'); ?><?php echo $styleOption['home'] ? ' — основний' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$templateOptions): ?>
                    <div class="form-text">Не вдалося прочитати встановлені frontend-шаблони.</div>
                <?php endif; ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="set_template_default" value="1" id="set-template-default">
                    <label class="form-check-label" for="set-template-default">Зробити вибраний стиль основним після перенесення</label>
                </div>
            </div>
        </div>

        <?php if (!empty($report['success']) && !empty($report['module_positions'])): ?>
            <h3 class="mt-3">Позиції модулів</h3>
            <?php if (empty($report['target_template_positions'])): ?>
                <div class="alert alert-warning">У вибраному шаблоні не знайдено позицій у templateDetails.xml. Модулі збережуть позиції старого сайту.</div>
            <?php else: ?>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-sm">
                        <thead><tr><th>Позиція джерела</th><th>Модулів</th><th>Позиція цільового шаблону</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['module_positions'] as $positionIndex => $modulePosition): ?>
                            <?php
                            $sourcePosition = (string) $modulePosition['position'];
                            $matchedPosition = in_array($sourcePosition, $report['target_template_positions'], true) ? $sourcePosition : '';
                            ?>
                            <tr>
                                <td><?php echo $escape($sourcePosition !== '' ? $sourcePosition : '(без позиції)'); ?></td>
                                <td><?php echo (int) $modulePosition['count']; ?></td>
                                <td>
                                    <input type="hidden" name="module_position_sources[<?php echo (int) $positionIndex; ?>]" value="<?php echo $escape($sourcePosition); ?>">
                                    <select name="target_module_positions[<?php echo (int) $positionIndex; ?>]" class="form-select form-select-sm">
                                        <option value="" <?php echo $matchedPosition === '' ? 'selected' : ''; ?>>Без позиції</option>
                                        <?php foreach ($report['target_template_positions'] as $targetPosition): ?>
                                            <option value="<?php echo $escape($targetPosition); ?>" <?php echo $matchedPosition === $targetPosition ? 'selected' : ''; ?>>
                                                <?php echo $escape($targetPosition); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="submit" class="btn btn-primary">Аналізувати</button>
            <button
                type="submit"
                class="btn btn-outline-secondary"
                formaction="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_migrator&task=exportCsv'); ?>"
            >
                Завантажити звіт CSV
            </button>
            <button
                type="submit"
                class="btn btn-danger"
                formaction="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_migrator&task=importCore'); ?>"
                <?php echo empty($report['success']) || empty($report['version_family']) ? 'disabled' : ''; ?>
                onclick="return confirm('Імпортувати дані ядра в поточний сайт? Перед запуском зробіть резервну копію.');"
            >
                Перенести дані ядра Joomla
            </button>
        </div>
        <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
    </form>

    <?php if (!empty($this->migrationReport)): ?>
        <p>
            <strong>Джерело:</strong>
            <?php echo $escape($this->migrationReport['source_version'] ?: 'версію не визначено'); ?>;
            <strong>профіль імпорту:</strong>
            <?php echo $escape($this->migrationReport['source_version_family'] !== '' ? 'Joomla ' . $this->migrationReport['source_version_family'] . '.x' : 'не визначений'); ?>
        </p>
        <div class="alert <?php echo $this->migrationReport['success'] ? 'alert-success' : 'alert-danger'; ?>">
            <?php echo $this->migrationReport['success'] ? 'Імпорт даних ядра завершено.' : 'Імпорт завершився з помилками.' ?>
            Додано груп: <?php echo (int) $this->migrationReport['usergroups']; ?>;
            користувачів: <?php echo (int) $this->migrationReport['users']; ?>;
            зв’язків із групами: <?php echo (int) $this->migrationReport['memberships']; ?>;
            категорій: <?php echo (int) $this->migrationReport['categories']; ?>;
            мов контенту: <?php echo (int) ($this->migrationReport['languages'] ?? 0); ?>;
            матеріалів: <?php echo (int) $this->migrationReport['articles']; ?>;
            featured: <?php echo (int) $this->migrationReport['featured_articles']; ?>;
            типів меню: <?php echo (int) $this->migrationReport['menu_types']; ?>;
            пунктів меню: <?php echo (int) $this->migrationReport['menu_items']; ?>;
            власних HTML-модулів: <?php echo (int) $this->migrationReport['custom_modules']; ?>;
            призначень модулів: <?php echo (int) $this->migrationReport['module_assignments']; ?>.
        </div>
        <?php if (!empty($this->migrationReport['home_items_reset'])): ?>
            <div class="alert alert-warning">
                Перенесених пунктів головної сторінки: <?php echo (int) $this->migrationReport['home_items_reset']; ?>. Виберіть головний пункт меню вручну на цільовому сайті.
            </div>
        <?php endif; ?>
        <?php if (!empty($this->migrationReport['user_conflicts'])): ?>
            <div class="alert alert-warning">
                Пропущено користувачів через конфлікт email: <?php echo (int) $this->migrationReport['user_conflicts']; ?>.
            </div>
        <?php endif; ?>
        <?php if (!empty($this->migrationReport['unmapped_authors'])): ?>
            <div class="alert alert-warning">
                Для матеріалів не знайдено відповідність автора: <?php echo (int) $this->migrationReport['unmapped_authors']; ?>.
            </div>
        <?php endif; ?>
        <div class="alert alert-warning">
            Парольні хеші копіюються без зміни; підтримку застарілих хешів цільовою Joomla не перевірено.
        </div>
        <?php foreach ($this->migrationReport['errors'] as $error): ?>
            <div class="alert alert-danger"><?php echo $escape($error); ?></div>
        <?php endforeach; ?>
        <?php if (!empty($this->languageOverrideReport)): ?>
            <div class="alert <?php echo $this->languageOverrideReport['success'] ? 'alert-success' : 'alert-warning'; ?>">
                Власні переклади: скопійовано <?php echo (int) $this->languageOverrideReport['copied']; ?>;
                уже однакових <?php echo (int) $this->languageOverrideReport['identical']; ?>;
                конфліктів без перезапису <?php echo count($this->languageOverrideReport['conflicts']); ?>;
                пропущено symlink <?php echo (int) $this->languageOverrideReport['skipped_symlinks']; ?>.
            </div>
            <?php if (!empty($this->languageOverrideReport['conflicts'])): ?>
                <div class="alert alert-warning">
                    Ці override-файли вже існують і не перезаписані:
                    <ul class="mb-0">
                        <?php foreach ($this->languageOverrideReport['conflicts'] as $path): ?>
                            <li><?php echo $escape($path); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php foreach ($this->languageOverrideReport['errors'] as $error): ?>
                <div class="alert alert-danger"><?php echo $escape($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <hr>
    <h3>Перенесення медіафайлів</h3>
    <form method="post" action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_migrator&task=copyMedia'); ?>">
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="old-root">Шлях до старого сайту від кореня нового</label>
                <input id="old-root" type="text" name="old_root" value=".." class="form-control" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-warning">Копіювати папку images</button>
            </div>
        </div>
        <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
    </form>

    <?php if (!empty($this->mediaReport)): ?>
        <div class="alert <?php echo $this->mediaReport['success'] ? 'alert-success' : 'alert-danger'; ?>">
            <?php echo $this->mediaReport['success'] ? 'Копіювання завершено.' : 'Копіювання завершилося з помилками.'; ?>
            Скопійовано: <?php echo (int) $this->mediaReport['copied']; ?>;
            уже однакових: <?php echo (int) $this->mediaReport['identical']; ?>;
            пропущено symlink: <?php echo (int) $this->mediaReport['skipped_symlinks']; ?>.
        </div>
        <?php foreach ($this->mediaReport['errors'] as $error): ?>
            <div class="alert alert-danger"><?php echo $escape($error); ?></div>
        <?php endforeach; ?>
        <?php if (!empty($this->mediaReport['conflicts'])): ?>
            <div class="alert alert-warning">
                Файли з таким шляхом уже існують, але відрізняються; вони не перезаписані:
                <ul>
                    <?php foreach ($this->mediaReport['conflicts'] as $path): ?>
                        <li><?php echo $escape($path); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($this->report): ?>
        <hr>
        <?php if (!empty($this->report['error'])): ?>
            <div class="alert alert-danger"><?php echo $escape($this->report['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($this->report['version'])): ?>
            <h3 class="mt-4">Визначення джерела</h3>
            <p><strong>Цільова Joomla:</strong> <?php echo $escape($this->report['target_version'] ?? 'Не визначено'); ?></p>
            <p><strong>Версія Joomla:</strong> <?php echo $escape($this->report['version']); ?>
                <?php if (!empty($this->report['version_source'])): ?>
                    <span class="text-muted">(<?php echo $escape($this->report['version_source']); ?>)</span>
                <?php endif; ?>
            </p>
            <p><strong>Профіль імпорту:</strong>
                <?php echo $escape($this->report['version_family'] !== '' ? 'Joomla ' . $this->report['version_family'] . '.x' : 'не визначений'); ?>
                <?php if (!empty($this->report['version_override'])): ?>
                    <span class="text-muted">(резервний ручний вибір)</span>
                <?php endif; ?>
            </p>
            <div class="alert alert-warning">
                Це попередній аналіз бази даних. Власні поля, розширення та перенаправлення URL ще не переносяться.
            </div>
        <?php endif; ?>

        <?php if (!empty($this->report['summary'])): ?>
            <h3 class="mt-4">Кількість основних даних</h3>
            <table class="table table-bordered table-sm w-50">
                <thead><tr><th>Дані</th><th>Кількість записів</th></tr></thead>
                <tbody>
                <?php foreach ($this->report['summary'] as $k => $v): ?>
                    <tr>
                        <td><?php echo $escape([
                            'content' => 'Матеріали',
                            'categories' => 'Категорії',
                            'users' => 'Користувачі',
                            'user_usergroup_map' => 'Зв’язки користувачів із групами',
                            'usergroups' => 'Групи користувачів',
                            'menu' => 'Пункти меню',
                            'modules' => 'Модулі',
                            'custom_html_modules' => 'Власні HTML-модулі',
                            'extensions' => 'Розширення',
                            'assets' => 'Правила доступу',
                            'fields' => 'Власні поля',
                            'fields_values' => 'Значення власних полів',
                            'redirect_links' => 'Перенаправлення',
                            'schemas' => 'Схеми оновлення',
                            'banners' => 'Банери',
                            'contacts' => 'Контакти',
                            'weblinks' => 'Посилання',
                            'tags' => 'Теги',
                            'contentitem_tag_map' => 'Зв’язки тегів із матеріалами',
                            'content_frontpage' => 'Матеріали головної сторінки (Joomla 2.5)',
                            'k2_items' => 'Матеріали K2',
                            'k2_categories' => 'Категорії K2',
                            'virtuemart_products' => 'Товари VirtueMart',
                            'virtuemart_categories' => 'Категорії VirtueMart',
                            'virtuemart_orders' => 'Замовлення VirtueMart',
                        ][$k] ?? $k); ?></td>
                        <td><?php echo $v === null ? 'таблицю не знайдено' : (int) $v; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($this->report['extensions'])): ?>
            <h3 class="mt-4">Розширення</h3>
            <?php if (empty($this->report['target_extensions_checked'])): ?>
                <div class="alert alert-warning">Не вдалося перевірити, які розширення вже встановлені на цільовому сайті.</div>
            <?php endif; ?>
            <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead><tr><th>Назва</th><th>Елемент</th><th>Тип</th><th>Клієнт</th><th>Версія джерела</th><th>Стан на цілі</th><th>Перенесення даних</th><th>Що робити</th><th>Дія</th></tr></thead>
                <tbody>
                <?php foreach ($this->report['extensions'] as $ext): ?>
                    <tr>
                        <td>
                            <?php if ($ext['type'] === 'component' && str_starts_with($ext['element'], 'com_') && $ext['target_status'] !== 'Не встановлено на цільовому сайті' && $ext['target_status'] !== 'Не вдалося перевірити'): ?>
                                <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=' . rawurlencode($ext['element'])); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo $escape($ext['name']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo $escape($ext['name']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $escape($ext['element']); ?></td>
                        <td><?php echo $escape($ext['type']); ?></td>
                        <td><?php echo $ext['type'] === 'language' ? ((int) $ext['client_id'] === 1 ? 'Адміністратор' : 'Сайт') : '—'; ?></td>
                        <td><?php echo $escape($ext['version'] ?: 'не визначена'); ?></td>
                        <td><?php echo $escape($ext['target_status']); ?></td>
                        <td><?php echo $escape($ext['migration_status']); ?></td>
                        <td><?php echo $escape($ext['recommendation']); ?></td>
                        <td>
                            <?php if ($ext['type'] === 'language' && $ext['target_status'] === 'Не встановлено на цільовому сайті'): ?>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_installer&view=languages'); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Встановити мову Joomla
                                </a>
                            <?php elseif (!empty($ext['can_install']) && !empty($this->report['target_extensions_checked'])): ?>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_installer&view=install'); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Відкрити інсталятор Joomla
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
