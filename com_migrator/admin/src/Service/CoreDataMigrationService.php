<?php
namespace Migrator\Component\Migrator\Administrator\Service;

class CoreDataMigrationService
{
    public function migrate(
        \PDO $source,
        string $sourcePrefix,
        $target,
        string $migrationId,
        array $versionProfile = [],
        array $modulePositionMap = [],
        int $targetTemplateStyleId = 0
    ): array
    {
        $result = [
            'success' => false,
            'source_version' => (string) ($versionProfile['detected_version'] ?? ''),
            'source_version_family' => (string) ($versionProfile['effective_family'] ?? ''),
            'usergroups' => 0,
            'users' => 0,
            'memberships' => 0,
            'categories' => 0,
            'articles' => 0,
            'featured_articles' => 0,
            'menu_types' => 0,
            'menu_items' => 0,
            'home_items_reset' => 0,
            'custom_modules' => 0,
            'module_assignments' => 0,
            'user_conflicts' => 0,
            'unmapped_authors' => 0,
            'errors' => [],
        ];

        try {
            $this->createMapTable($target);
            $this->migrateUsergroups($source, $sourcePrefix, $target, $migrationId, $result);
            $this->migrateUsers($source, $sourcePrefix, $target, $migrationId, $result);
            $this->migrateMemberships($source, $sourcePrefix, $target, $migrationId, $result);
            $this->migrateCategories($source, $sourcePrefix, $target, $migrationId, $result);
            $this->migrateArticles($source, $sourcePrefix, $target, $migrationId, $result);
            if (($versionProfile['effective_family'] ?? '') === '2.5') {
                $this->migrateFeaturedArticles($source, $sourcePrefix, $target, $migrationId, $result);
            }
            $this->migrateMenuTypes($source, $sourcePrefix, $target, $migrationId, $result);
            $this->migrateMenuItems($source, $sourcePrefix, $target, $migrationId, $result);
            $this->migrateCustomModules(
                $source,
                $sourcePrefix,
                $target,
                $migrationId,
                $modulePositionMap,
                $targetTemplateStyleId,
                $result
            );
            $this->migrateModuleAssignments($source, $sourcePrefix, $target, $migrationId, $result);
            $result['success'] = $result['errors'] === [];
        } catch (\Throwable $e) {
            $result['errors'][] = 'Імпорт зупинено: ' . $e->getMessage();
        }

        return $result;
    }

    private function createMapTable($target): void
    {
        $target->setQuery(
            'CREATE TABLE IF NOT EXISTS #__migrator_map ('
            . 'migration_id CHAR(64) NOT NULL, '
            . 'entity VARCHAR(32) NOT NULL, '
            . 'source_id BIGINT UNSIGNED NOT NULL, '
            . 'target_id BIGINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (migration_id, entity, source_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        )->execute();
    }

    private function migrateCategories(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query(
            "SELECT * FROM `{$prefix}categories` WHERE extension = 'com_content' AND id > 1 ORDER BY lft, id"
        );
        $targetColumns = $this->getColumns($target, '#__categories');
        $initialCount = $result['categories'];

        $target->transactionStart();

        try {
            while ($category = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $category['id'];

                if ($this->getMappedId($target, $migrationId, 'category', $sourceId) !== null) {
                    continue;
                }

                $sourceParentId = (int) ($category['parent_id'] ?? 1);
                $parentId = $sourceParentId > 1
                    ? $this->getMappedId($target, $migrationId, 'category', $sourceParentId)
                    : 1;

                if ($parentId === null) {
                    $parentId = 1;
                }

                $target->setQuery(
                    'SELECT id, lft, rgt, level, path FROM #__categories WHERE id = ' . (int) $parentId
                );
                $parent = $target->loadObject();

                if (!$parent) {
                    throw new \RuntimeException('A target parent category is missing.');
                }

                $right = (int) $parent->rgt;
                $target->setQuery('UPDATE #__categories SET rgt = rgt + 2 WHERE rgt >= ' . $right)->execute();
                $target->setQuery('UPDATE #__categories SET lft = lft + 2 WHERE lft > ' . $right)->execute();

                $alias = (string) ($category['alias'] ?? '');
                $parentPath = trim((string) $parent->path, '/');
                $path = $parentPath === '' ? $alias : $parentPath . '/' . $alias;
                $row = [
                    'parent_id' => (int) $parent->id,
                    'lft' => $right,
                    'rgt' => $right + 1,
                    'level' => (int) $parent->level + 1,
                    'path' => $path,
                    'extension' => 'com_content',
                    'title' => (string) ($category['title'] ?? ''),
                    'alias' => $alias,
                    'description' => (string) ($category['description'] ?? ''),
                    'published' => (int) ($category['published'] ?? 1),
                    'access' => (int) ($category['access'] ?? 1),
                    'params' => (string) ($category['params'] ?? '{}'),
                    'metadesc' => (string) ($category['metadesc'] ?? ''),
                    'metakey' => (string) ($category['metakey'] ?? ''),
                    'metadata' => (string) ($category['metadata'] ?? '{}'),
                    'language' => (string) ($category['language'] ?? '*'),
                    'version' => (int) ($category['version'] ?? 1),
                ];

                $this->insertRow($target, '#__categories', $row, $targetColumns);
                $this->saveMapping($target, $migrationId, 'category', $sourceId, (int) $row['id']);
                $result['categories']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['categories'] = $initialCount;
            throw $e;
        }
    }

    private function migrateUsergroups(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query("SELECT * FROM `{$prefix}usergroups` ORDER BY lft, id");
        $targetColumns = $this->getColumns($target, '#__usergroups');
        $initialCount = $result['usergroups'];
        $target->transactionStart();

        try {
            while ($group = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $group['id'];

                if ($this->getMappedId($target, $migrationId, 'usergroup', $sourceId) !== null) {
                    continue;
                }

                $target->setQuery('SELECT id FROM #__usergroups WHERE title = ' . $target->quote((string) $group['title']) . ' LIMIT 1');
                $existingId = $target->loadResult();

                if ($existingId !== null) {
                    $this->saveMapping($target, $migrationId, 'usergroup', $sourceId, (int) $existingId);
                    continue;
                }

                $sourceParentId = (int) ($group['parent_id'] ?? 1);
                $parentId = $sourceParentId > 1
                    ? $this->getMappedId($target, $migrationId, 'usergroup', $sourceParentId)
                    : 1;

                if ($parentId === null) {
                    $parentId = 1;
                }

                $target->setQuery('SELECT id, lft, rgt FROM #__usergroups WHERE id = ' . (int) $parentId);
                $parent = $target->loadObject();

                if (!$parent) {
                    throw new \RuntimeException('A target parent user group is missing.');
                }

                $right = (int) $parent->rgt;
                $target->setQuery('UPDATE #__usergroups SET rgt = rgt + 2 WHERE rgt >= ' . $right)->execute();
                $target->setQuery('UPDATE #__usergroups SET lft = lft + 2 WHERE lft > ' . $right)->execute();
                $group['parent_id'] = (int) $parent->id;
                $group['lft'] = $right;
                $group['rgt'] = $right + 1;
                unset($group['id']);
                $this->insertRow($target, '#__usergroups', $group, $targetColumns);
                $this->saveMapping($target, $migrationId, 'usergroup', $sourceId, (int) $group['id']);
                $result['usergroups']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['usergroups'] = $initialCount;
            throw $e;
        }
    }

    private function migrateUsers(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query("SELECT * FROM `{$prefix}users` ORDER BY id");
        $targetColumns = $this->getColumns($target, '#__users');
        $initialCount = $result['users'];
        $initialConflicts = $result['user_conflicts'];
        $target->transactionStart();

        try {
            while ($user = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $user['id'];

                if ($this->getMappedId($target, $migrationId, 'user', $sourceId) !== null) {
                    continue;
                }

                $username = (string) ($user['username'] ?? '');
                $target->setQuery('SELECT id FROM #__users WHERE username = ' . $target->quote($username) . ' LIMIT 1');
                $existingId = $target->loadResult();

                if ($existingId !== null) {
                    $this->saveMapping($target, $migrationId, 'user', $sourceId, (int) $existingId);
                    continue;
                }

                $email = trim((string) ($user['email'] ?? ''));

                if ($email !== '') {
                    $target->setQuery('SELECT id FROM #__users WHERE email = ' . $target->quote($email) . ' LIMIT 1');
                    if ($target->loadResult() !== null) {
                        $result['user_conflicts']++;

                        continue;
                    }
                }

                unset($user['id']);
                $this->insertRow($target, '#__users', $user, $targetColumns);
                $this->saveMapping($target, $migrationId, 'user', $sourceId, (int) $user['id']);
                $result['users']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['users'] = $initialCount;
            $result['user_conflicts'] = $initialConflicts;
            throw $e;
        }
    }

    private function migrateMemberships(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query("SELECT user_id, group_id FROM `{$prefix}user_usergroup_map` ORDER BY user_id, group_id");
        $initialCount = $result['memberships'];
        $target->transactionStart();

        try {
            while ($membership = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $userId = $this->getMappedId($target, $migrationId, 'user', (int) $membership['user_id']);
                $groupId = $this->getMappedId($target, $migrationId, 'usergroup', (int) $membership['group_id']);

                if ($userId === null || $groupId === null) {
                    continue;
                }

                $target->setQuery(
                    'SELECT user_id FROM #__user_usergroup_map WHERE user_id = ' . $userId
                    . ' AND group_id = ' . $groupId . ' LIMIT 1'
                );

                if ($target->loadResult() !== null) {
                    continue;
                }

                $target->setQuery(
                    'INSERT INTO #__user_usergroup_map (user_id, group_id) VALUES (' . $userId . ', ' . $groupId . ')'
                )->execute();
                $result['memberships']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['memberships'] = $initialCount;
            throw $e;
        }
    }

    private function migrateArticles(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query("SELECT * FROM `{$prefix}content` ORDER BY id");
        $targetColumns = $this->getColumns($target, '#__content');
        $initialCount = $result['articles'];
        $target->transactionStart();

        try {
            while ($article = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $article['id'];

                if ($this->getMappedId($target, $migrationId, 'article', $sourceId) !== null) {
                    continue;
                }

                $sourceCategoryId = (int) ($article['catid'] ?? 0);
                $targetCategoryId = $this->getMappedId($target, $migrationId, 'category', $sourceCategoryId);

                if ($targetCategoryId === null) {
                    $targetCategoryId = 2;
                }

                $article['catid'] = $targetCategoryId;
                foreach (['created_by', 'modified_by'] as $authorField) {
                    if (!empty($article[$authorField])) {
                        $targetAuthorId = $this->getMappedId($target, $migrationId, 'user', (int) $article[$authorField]);

                        if ($targetAuthorId === null) {
                            $article[$authorField] = 0;
                            $result['unmapped_authors']++;
                        } else {
                            $article[$authorField] = $targetAuthorId;
                        }
                    }
                }

                unset($article['id'], $article['asset_id']);
                $this->insertRow($target, '#__content', $article, $targetColumns);
                $this->saveMapping($target, $migrationId, 'article', $sourceId, (int) $article['id']);
                $result['articles']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['articles'] = $initialCount;
            throw $e;
        }
    }

    private function migrateFeaturedArticles(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        try {
            $rows = $source->query("SELECT content_id, ordering FROM `{$prefix}content_frontpage` ORDER BY ordering, content_id");
        } catch (\PDOException $e) {
            return;
        }

        $contentColumns = $this->getColumns($target, '#__content');

        try {
            $frontpageColumns = $this->getColumns($target, '#__content_frontpage');
        } catch (\Throwable $e) {
            $frontpageColumns = [];
        }

        $target->transactionStart();
        $initialCount = $result['featured_articles'];

        try {
            while ($featured = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $articleId = $this->getMappedId($target, $migrationId, 'article', (int) $featured['content_id']);

                if ($articleId === null) {
                    continue;
                }

                if (isset($frontpageColumns['content_id'])) {
                    $target->setQuery(
                        'SELECT content_id FROM #__content_frontpage WHERE content_id = ' . $articleId . ' LIMIT 1'
                    );

                    if ($target->loadResult() === null) {
                        $target->setQuery(
                            'INSERT INTO #__content_frontpage (content_id, ordering) VALUES ('
                            . $articleId . ', ' . (int) $featured['ordering'] . ')'
                        )->execute();
                        $result['featured_articles']++;
                    }
                }

                if (isset($contentColumns['featured'])) {
                    $target->setQuery('UPDATE #__content SET featured = 1 WHERE id = ' . $articleId)->execute();
                }
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['featured_articles'] = $initialCount;
            throw $e;
        }
    }

    private function migrateMenuTypes(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query("SELECT * FROM `{$prefix}menu_types` ORDER BY id");
        $targetColumns = $this->getColumns($target, '#__menu_types');
        $initialCount = $result['menu_types'];
        $target->transactionStart();

        try {
            while ($menuType = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $menuType['id'];

                if ($this->getMappedId($target, $migrationId, 'menu_type', $sourceId) !== null) {
                    continue;
                }

                $name = (string) $menuType['menutype'];
                $target->setQuery('SELECT id FROM #__menu_types WHERE menutype = ' . $target->quote($name) . ' LIMIT 1');
                $existingId = $target->loadResult();

                if ($existingId !== null) {
                    $this->saveMapping($target, $migrationId, 'menu_type', $sourceId, (int) $existingId);
                    continue;
                }

                unset($menuType['id']);
                $this->insertRow($target, '#__menu_types', $menuType, $targetColumns);
                $this->saveMapping($target, $migrationId, 'menu_type', $sourceId, (int) $menuType['id']);
                $result['menu_types']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['menu_types'] = $initialCount;
            throw $e;
        }
    }

    private function migrateMenuItems(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        if ($this->getMappedId($target, $migrationId, 'menu_item', 1) === null) {
            $this->saveMapping($target, $migrationId, 'menu_item', 1, 1);
        }

        $rows = $source->query("SELECT * FROM `{$prefix}menu` WHERE client_id = 0 AND id > 1 ORDER BY lft, id");
        $targetColumns = $this->getColumns($target, '#__menu');
        $initialCount = $result['menu_items'];
        $initialHomeResets = $result['home_items_reset'];
        $target->transactionStart();

        try {
            while ($item = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $item['id'];
                $isHome = !empty($item['home']);

                if ($this->getMappedId($target, $migrationId, 'menu_item', $sourceId) !== null) {
                    continue;
                }

                $menuType = (string) ($item['menutype'] ?? '');
                $target->setQuery('SELECT id FROM #__menu_types WHERE menutype = ' . $target->quote($menuType) . ' LIMIT 1');
                if ($target->loadResult() === null) {
                    continue;
                }

                $sourceParentId = (int) ($item['parent_id'] ?? 1);
                $parentId = $sourceParentId > 1
                    ? $this->getMappedId($target, $migrationId, 'menu_item', $sourceParentId)
                    : 1;

                if ($parentId === null) {
                    $parentId = 1;
                }

                $this->remapMenuLink($item, $target, $migrationId);
                $item['component_id'] = $this->mapComponentId($source, $prefix, $target, (int) ($item['component_id'] ?? 0));
                foreach (['created_by', 'modified_by'] as $authorField) {
                    if (!empty($item[$authorField])) {
                        $item[$authorField] = $this->getMappedId($target, $migrationId, 'user', (int) $item[$authorField]) ?? 0;
                    }
                }

                $alias = (string) ($item['alias'] ?? '');
                $target->setQuery(
                    'SELECT id FROM #__menu WHERE menutype = ' . $target->quote($menuType)
                    . ' AND path = ' . $target->quote((string) ($item['path'] ?? $alias)) . ' LIMIT 1'
                );
                $existingId = $target->loadResult();

                if ($existingId !== null) {
                    $this->saveMapping($target, $migrationId, 'menu_item', $sourceId, (int) $existingId);
                    continue;
                }

                $target->setQuery('SELECT id, lft, rgt, level, path FROM #__menu WHERE id = ' . (int) $parentId);
                $parent = $target->loadObject();

                if (!$parent) {
                    throw new \RuntimeException('A target parent menu item is missing.');
                }

                $right = (int) $parent->rgt;
                $target->setQuery('UPDATE #__menu SET rgt = rgt + 2 WHERE rgt >= ' . $right)->execute();
                $target->setQuery('UPDATE #__menu SET lft = lft + 2 WHERE lft > ' . $right)->execute();
                $parentPath = trim((string) $parent->path, '/');
                $item['parent_id'] = (int) $parent->id;
                $item['lft'] = $right;
                $item['rgt'] = $right + 1;
                $item['level'] = (int) $parent->level + 1;
                $item['path'] = $parentPath === '' ? $alias : $parentPath . '/' . $alias;
                $item['client_id'] = 0;
                $item['home'] = 0;
                $item['checked_out'] = 0;
                $item['checked_out_time'] = null;
                unset($item['id'], $item['asset_id']);
                $this->insertRow($target, '#__menu', $item, $targetColumns);
                $this->saveMapping($target, $migrationId, 'menu_item', $sourceId, (int) $item['id']);
                $result['menu_items']++;

                if ($isHome) {
                    $result['home_items_reset']++;
                }
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['menu_items'] = $initialCount;
            $result['home_items_reset'] = $initialHomeResets;
            throw $e;
        }
    }

    private function remapMenuLink(array &$item, $target, string $migrationId): void
    {
        $link = (string) ($item['link'] ?? '');
        $parts = parse_url($link);

        if (empty($parts['query'])) {
            return;
        }

        parse_str($parts['query'], $query);

        if (($query['option'] ?? '') !== 'com_content' || empty($query['id'])) {
            return;
        }

        $entity = ($query['view'] ?? '') === 'article' ? 'article'
            : (($query['view'] ?? '') === 'category' ? 'category' : '');
        $mappedId = $entity !== '' ? $this->getMappedId($target, $migrationId, $entity, (int) $query['id']) : null;

        if ($mappedId === null) {
            return;
        }

        $query['id'] = $mappedId;
        $item['link'] = ($parts['path'] ?? '') . '?' . http_build_query($query);
    }

    private function mapComponentId(\PDO $source, string $prefix, $target, int $sourceComponentId): int
    {
        if ($sourceComponentId < 1) {
            return 0;
        }

        try {
            $statement = $source->query(
                "SELECT element FROM `{$prefix}extensions` WHERE extension_id = " . $sourceComponentId . " LIMIT 1"
            );
            $element = $statement->fetchColumn();

            if (!$element) {
                return 0;
            }

            $target->setQuery(
                'SELECT extension_id FROM #__extensions WHERE type = ' . $target->quote('component')
                . ' AND element = ' . $target->quote((string) $element) . ' LIMIT 1'
            );

            return (int) ($target->loadResult() ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function migrateCustomModules(
        \PDO $source,
        string $prefix,
        $target,
        string $migrationId,
        array $modulePositionMap,
        int $targetTemplateStyleId,
        array &$result
    ): void
    {
        $rows = $source->query(
            "SELECT * FROM `{$prefix}modules` WHERE client_id = 0 AND module = 'mod_custom' ORDER BY ordering, id"
        );
        $targetColumns = $this->getColumns($target, '#__modules');
        $initialCount = $result['custom_modules'];
        $target->transactionStart();

        try {
            while ($module = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $sourceId = (int) $module['id'];

                if ($this->getMappedId($target, $migrationId, 'custom_module', $sourceId) !== null) {
                    continue;
                }

                foreach (['created_by', 'modified_by'] as $authorField) {
                    if (!empty($module[$authorField])) {
                        $module[$authorField] = $this->getMappedId($target, $migrationId, 'user', (int) $module[$authorField]) ?? 0;
                    }
                }

                $sourcePosition = (string) ($module['position'] ?? '');

                if (array_key_exists($sourcePosition, $modulePositionMap)) {
                    $module['position'] = $modulePositionMap[$sourcePosition];
                }

                if ($targetTemplateStyleId > 0 && isset($targetColumns['template_style_id'])) {
                    $module['template_style_id'] = $targetTemplateStyleId;
                }

                $module['checked_out'] = 0;
                $module['checked_out_time'] = null;
                unset($module['id'], $module['asset_id']);
                $this->insertRow($target, '#__modules', $module, $targetColumns);
                $this->saveMapping($target, $migrationId, 'custom_module', $sourceId, (int) $module['id']);
                $result['custom_modules']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['custom_modules'] = $initialCount;
            throw $e;
        }
    }

    private function migrateModuleAssignments(\PDO $source, string $prefix, $target, string $migrationId, array &$result): void
    {
        $rows = $source->query("SELECT moduleid, menuid FROM `{$prefix}modules_menu` ORDER BY moduleid, menuid");
        $initialCount = $result['module_assignments'];
        $target->transactionStart();

        try {
            while ($assignment = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $moduleId = $this->getMappedId($target, $migrationId, 'custom_module', (int) $assignment['moduleid']);

                if ($moduleId === null) {
                    continue;
                }

                $sourceMenuId = (int) $assignment['menuid'];
                $menuId = $sourceMenuId <= 0
                    ? $sourceMenuId
                    : $this->getMappedId($target, $migrationId, 'menu_item', $sourceMenuId);

                if ($menuId === null) {
                    continue;
                }

                $target->setQuery(
                    'SELECT moduleid FROM #__modules_menu WHERE moduleid = ' . $moduleId
                    . ' AND menuid = ' . $menuId . ' LIMIT 1'
                );

                if ($target->loadResult() !== null) {
                    continue;
                }

                $target->setQuery(
                    'INSERT INTO #__modules_menu (moduleid, menuid) VALUES (' . $moduleId . ', ' . $menuId . ')'
                )->execute();
                $result['module_assignments']++;
            }

            $target->transactionCommit();
        } catch (\Throwable $e) {
            $target->transactionRollback();
            $result['module_assignments'] = $initialCount;
            throw $e;
        }
    }

    private function getColumns($target, string $table): array
    {
        $columns = $target->getTableColumns($target->replacePrefix($table), false);

        return array_fill_keys(array_keys($columns), true);
    }

    private function insertRow($target, string $table, array &$row, array $columns): void
    {
        $row = array_intersect_key($row, $columns);
        $object = (object) $row;
        $target->insertObject($table, $object, 'id');
        $row['id'] = (int) $object->id;
    }

    private function getMappedId($target, string $migrationId, string $entity, int $sourceId): ?int
    {
        $target->setQuery(
            'SELECT target_id FROM #__migrator_map WHERE migration_id = ' . $target->quote($migrationId)
            . ' AND entity = ' . $target->quote($entity)
            . ' AND source_id = ' . $sourceId
        );
        $mappedId = $target->loadResult();

        return $mappedId === null ? null : (int) $mappedId;
    }

    private function saveMapping($target, string $migrationId, string $entity, int $sourceId, int $targetId): void
    {
        $target->setQuery(
            'INSERT INTO #__migrator_map (migration_id, entity, source_id, target_id) VALUES ('
            . $target->quote($migrationId) . ', '
            . $target->quote($entity) . ', '
            . $sourceId . ', '
            . $targetId . ')'
        )->execute();
    }
}