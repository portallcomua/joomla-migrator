<?php

require dirname(__DIR__) . '/com_migrator/admin/src/Service/CoreDataMigrationService.php';
require dirname(__DIR__) . '/com_migrator/admin/src/Service/ExtensionAssessmentService.php';
require dirname(__DIR__) . '/com_migrator/admin/src/Service/LanguageOverrideMigrationService.php';
require dirname(__DIR__) . '/com_migrator/admin/src/Service/SourceVersionResolver.php';
require dirname(__DIR__) . '/com_migrator/admin/src/Service/TargetTemplateService.php';

class TestJoomlaDatabase
{
    private $pdo;
    private $query = '';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function setQuery(string $query)
    {
        $this->query = str_replace('#__', 'j_', $query);
        $this->query = preg_replace('/\s+ENGINE=InnoDB.*$/i', '', $this->query);

        return $this;
    }

    public function execute()
    {
        return $this->pdo->exec($this->query);
    }

    public function loadObject()
    {
        $statement = $this->pdo->query($this->query);

        return $statement->fetch(PDO::FETCH_OBJ) ?: null;
    }

    public function loadAssocList(): array
    {
        return $this->pdo->query($this->query)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function loadResult()
    {
        $value = $this->pdo->query($this->query)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    public function replacePrefix(string $table): string
    {
        return str_replace('#__', 'j_', $table);
    }

    public function getTableColumns(string $table, bool $typeOnly = true): array
    {
        $columns = [];

        foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
            $columns[$row['name']] = $row['type'];
        }

        return $columns;
    }

    public function insertObject(string $table, object &$object, string $key = 'id'): void
    {
        $values = get_object_vars($object);
        $names = array_keys($values);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->replacePrefix($table)
            . ' (' . implode(',', $names) . ') VALUES (' . $placeholders . ')'
        );
        $statement->execute(array_values($values));
        $object->$key = (int) $this->pdo->lastInsertId();
    }

    public function transactionStart(): void
    {
        $this->pdo->beginTransaction();
    }

    public function transactionCommit(): void
    {
        $this->pdo->commit();
    }

    public function transactionRollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$assessmentService = new Migrator\Component\Migrator\Administrator\Service\ExtensionAssessmentService();
$assessedExtensions = $assessmentService->assess(
    [
        ['element' => 'com_content', 'type' => 'component', 'folder' => '', 'enabled' => 1],
        ['element' => 'com_categories', 'type' => 'component', 'folder' => '', 'enabled' => 1],
        ['element' => 'com_akeebabackup', 'type' => 'component', 'folder' => '', 'enabled' => 1],
        ['element' => 'com_k2', 'type' => 'component', 'folder' => '', 'enabled' => 1],
    ],
    [
        ['element' => 'com_content', 'type' => 'component', 'folder' => '', 'enabled' => 1],
    ]
);
assertSameValue('Встановлено й увімкнено', $assessedExtensions[0]['target_status'], 'Detect target core extension');
assertSameValue(false, $assessedExtensions[0]['can_install'], 'Do not offer installation for target extension');
assertSameValue(false, $assessedExtensions[1]['can_install'], 'Do not offer manual installation for missing core categories');
assertSameValue('Частково: переносяться матеріали, категорії, користувачі, меню та mod_custom', $assessedExtensions[1]['migration_status'], 'Classify core category migration');
assertSameValue(true, $assessedExtensions[2]['can_install'], 'Offer manual installation for missing Akeeba');
assertSameValue('Дані не переносяться: це розширення резервного копіювання', $assessedExtensions[2]['migration_status'], 'Classify Akeeba data migration');
assertSameValue(true, str_contains($assessedExtensions[2]['recommendation'], 'Не потрібен для перенесення контенту'), 'Explain Akeeba is optional');
assertSameValue('Не підтримується: адаптера K2 немає', $assessedExtensions[3]['migration_status'], 'Classify K2 migration support');
assertSameValue(false, $assessedExtensions[3]['can_install'], 'Do not offer installation for unsupported K2');
$missingCoreExtension = $assessmentService->assess(
    [['element' => 'com_users', 'type' => 'component', 'folder' => '', 'enabled' => 1]],
    []
);
assertSameValue(false, $missingCoreExtension[0]['can_install'], 'Do not offer manual installation for a Joomla core extension');
$assessedLanguages = $assessmentService->assess(
    [
        ['element' => 'uk-UA', 'type' => 'language', 'folder' => '', 'client_id' => 0, 'enabled' => 1],
        ['element' => 'uk-UA', 'type' => 'language', 'folder' => '', 'client_id' => 1, 'enabled' => 1],
    ],
    [['element' => 'uk-UA', 'type' => 'language', 'folder' => '', 'client_id' => 0, 'enabled' => 1]]
);
assertSameValue('Встановлено й увімкнено', $assessedLanguages[0]['target_status'], 'Detect installed site language pack');
assertSameValue('Не встановлено на цільовому сайті', $assessedLanguages[1]['target_status'], 'Detect missing administrator language pack');
assertSameValue(false, $assessedLanguages[1]['can_install'], 'Use Joomla language manager for language packs');

$source = new PDO('sqlite::memory:');
$source->exec('CREATE TABLE src_usergroups (id INTEGER, parent_id INTEGER, lft INTEGER, rgt INTEGER, title TEXT)');
$source->exec("INSERT INTO src_usergroups VALUES (1,0,0,5,'Public'),(2,1,1,2,'Registered')");
$source->exec('CREATE TABLE src_users (id INTEGER, name TEXT, username TEXT, email TEXT, password TEXT, block INTEGER, registerDate TEXT, params TEXT)');
$source->exec("INSERT INTO src_users VALUES (5,'Alice','alice','alice@example.test','legacy-hash',0,'2020-01-01','{}')");
$source->exec('CREATE TABLE src_user_usergroup_map (user_id INTEGER, group_id INTEGER)');
$source->exec('INSERT INTO src_user_usergroup_map VALUES (5,2)');
$source->exec('CREATE TABLE src_categories (id INTEGER, parent_id INTEGER, lft INTEGER, rgt INTEGER, extension TEXT, title TEXT, alias TEXT, description TEXT, published INTEGER, access INTEGER, params TEXT, metadesc TEXT, metakey TEXT, metadata TEXT, language TEXT, version INTEGER)');
$source->exec("INSERT INTO src_categories VALUES (2,1,1,4,'com_content','News','news','',1,1,'{}','category desc','cat key','{}','*',1),(3,2,2,3,'com_content','Local','local','',1,1,'{}','','','{}','*',1)");
$source->exec('CREATE TABLE src_languages (lang_id INTEGER, lang_code TEXT, title TEXT, title_native TEXT, sef TEXT, image TEXT, description TEXT, metakey TEXT, metadesc TEXT, sitename TEXT, published INTEGER, access INTEGER, ordering INTEGER, params TEXT)');
$source->exec("INSERT INTO src_languages VALUES (1,'en-GB','English','English','en','en_gb','English','','','',1,1,1,'{}'),(2,'uk-UA','Ukrainian','Українська','uk','uk_ua','Ukrainian','','','',1,1,2,'{}')");
$source->exec('CREATE TABLE src_content (id INTEGER, catid INTEGER, title TEXT, alias TEXT, introtext TEXT, fulltext TEXT, metadesc TEXT, metakey TEXT, created_by INTEGER, modified_by INTEGER, created TEXT, modified TEXT, state INTEGER, images TEXT, urls TEXT, attribs TEXT, metadata TEXT, language TEXT, access INTEGER)');
$source->exec("INSERT INTO src_content VALUES (17,3,'Story','story','intro','body','article desc','article key',5,5,'2020-01-01','2020-01-02',1,'{\"image_intro\":\"images/detail/image.png\"}','{}','{}','{}','*',1)");
$source->exec('CREATE TABLE src_content_frontpage (content_id INTEGER, ordering INTEGER)');
$source->exec('INSERT INTO src_content_frontpage VALUES (17,1)');
$source->exec('CREATE TABLE src_menu_types (id INTEGER, menutype TEXT, title TEXT, description TEXT)');
$source->exec("INSERT INTO src_menu_types VALUES (1,'mainmenu','Main Menu','')");
$source->exec('CREATE TABLE src_extensions (extension_id INTEGER, element TEXT)');
$source->exec("INSERT INTO src_extensions VALUES (10,'com_content')");
$source->exec('CREATE TABLE src_menu (id INTEGER, parent_id INTEGER, lft INTEGER, rgt INTEGER, level INTEGER, path TEXT, menutype TEXT, client_id INTEGER, component_id INTEGER, type TEXT, title TEXT, alias TEXT, link TEXT, published INTEGER, access INTEGER, home INTEGER, params TEXT, language TEXT, ordering INTEGER)');
$source->exec("INSERT INTO src_menu VALUES (2,1,1,2,1,'home','mainmenu',0,10,'component','Home','home','index.php?option=com_content&view=article&id=17',1,1,1,'{}','*',1)");
$source->exec('CREATE TABLE src_modules (id INTEGER, title TEXT, content TEXT, module TEXT, position TEXT, published INTEGER, access INTEGER, showtitle INTEGER, params TEXT, client_id INTEGER, language TEXT, ordering INTEGER, checked_out INTEGER, checked_out_time TEXT)');
$source->exec("INSERT INTO src_modules VALUES (8,'Footer','<img src=\"images/detail/image.png\">','mod_custom','footer',1,1,1,'{}',0,'*',1,0,NULL)");
$source->exec('CREATE TABLE src_modules_menu (moduleid INTEGER, menuid INTEGER)');
$source->exec('INSERT INTO src_modules_menu VALUES (8,2)');

$targetPdo = new PDO('sqlite::memory:');
$targetPdo->exec('CREATE TABLE j_usergroups (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, lft INTEGER, rgt INTEGER, title TEXT)');
$targetPdo->exec("INSERT INTO j_usergroups VALUES (1,0,0,3,'Public')");
$targetPdo->exec('CREATE TABLE j_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, username TEXT UNIQUE, email TEXT UNIQUE, password TEXT, block INTEGER, registerDate TEXT, params TEXT)');
$targetPdo->exec('CREATE TABLE j_user_usergroup_map (user_id INTEGER, group_id INTEGER, UNIQUE(user_id, group_id))');
$targetPdo->exec('CREATE TABLE j_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, lft INTEGER, rgt INTEGER, level INTEGER, path TEXT, extension TEXT, title TEXT, alias TEXT, description TEXT, published INTEGER, access INTEGER, params TEXT, metadesc TEXT, metakey TEXT, metadata TEXT, language TEXT, version INTEGER)');
$targetPdo->exec("INSERT INTO j_categories VALUES (1,0,0,5,0,'','system','ROOT','root','',1,1,'{}','','','{}','*',1),(2,1,1,2,1,'uncategorised','com_content','Uncategorised','uncategorised','',1,1,'{}','','','{}','*',1)");
$targetPdo->exec('CREATE TABLE j_languages (lang_id INTEGER PRIMARY KEY AUTOINCREMENT, lang_code TEXT UNIQUE, title TEXT, title_native TEXT, sef TEXT, image TEXT, description TEXT, metakey TEXT, metadesc TEXT, sitename TEXT, published INTEGER, access INTEGER, ordering INTEGER, params TEXT)');
$targetPdo->exec("INSERT INTO j_languages (lang_code,title,title_native,sef,image,description,published,access,ordering,params) VALUES ('en-GB','English','English','en','en_gb','English',1,1,1,'{}')");
$targetPdo->exec('CREATE TABLE j_content (id INTEGER PRIMARY KEY AUTOINCREMENT, catid INTEGER, title TEXT, alias TEXT, introtext TEXT, fulltext TEXT, metadesc TEXT, metakey TEXT, created_by INTEGER, modified_by INTEGER, created TEXT, modified TEXT, state INTEGER, images TEXT, urls TEXT, attribs TEXT, metadata TEXT, language TEXT, access INTEGER, featured INTEGER)');
$targetPdo->exec('CREATE TABLE j_content_frontpage (content_id INTEGER, ordering INTEGER, UNIQUE(content_id))');
$targetPdo->exec('CREATE TABLE j_menu_types (id INTEGER PRIMARY KEY AUTOINCREMENT, menutype TEXT UNIQUE, title TEXT, description TEXT)');
$targetPdo->exec("INSERT INTO j_menu_types VALUES (1,'mainmenu','Main Menu','')");
$targetPdo->exec('CREATE TABLE j_extensions (extension_id INTEGER PRIMARY KEY, element TEXT, type TEXT)');
$targetPdo->exec("INSERT INTO j_extensions VALUES (1,'com_content','component')");
$targetPdo->exec('CREATE TABLE j_menu (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, lft INTEGER, rgt INTEGER, level INTEGER, path TEXT, menutype TEXT, client_id INTEGER, component_id INTEGER, type TEXT, title TEXT, alias TEXT, link TEXT, published INTEGER, access INTEGER, home INTEGER, params TEXT, language TEXT, ordering INTEGER, checked_out INTEGER, checked_out_time TEXT)');
$targetPdo->exec("INSERT INTO j_menu (id,parent_id,lft,rgt,level,path,menutype,client_id,title,alias,type,link,published,home,language) VALUES (1,0,0,1,0,'','',0,'ROOT','root','root','',1,0,'*')");
$targetPdo->exec('CREATE TABLE j_modules (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, content TEXT, module TEXT, position TEXT, published INTEGER, access INTEGER, showtitle INTEGER, params TEXT, client_id INTEGER, language TEXT, ordering INTEGER, checked_out INTEGER, checked_out_time TEXT, template_style_id INTEGER)');
$targetPdo->exec('CREATE TABLE j_modules_menu (moduleid INTEGER, menuid INTEGER, UNIQUE(moduleid, menuid))');

$target = new TestJoomlaDatabase($targetPdo);
$service = new Migrator\Component\Migrator\Administrator\Service\CoreDataMigrationService();
$versionProfile = ['detected_version' => '2.5.28', 'effective_family' => '2.5'];
$migrationId = str_repeat('c', 64);
$first = $service->migrate($source, 'src_', $target, $migrationId, $versionProfile, ['footer' => 'sidebar'], 9);
assertSameValue(true, $first['success'], 'Initial import status');
assertSameValue(1, $first['usergroups'], 'Imported user groups');
assertSameValue(1, $first['users'], 'Imported users');
assertSameValue(1, $first['memberships'], 'Imported group memberships');
assertSameValue(2, $first['categories'], 'Imported categories');
assertSameValue(1, $first['languages'], 'Imported missing content language');
assertSameValue(1, $first['articles'], 'Imported articles');
assertSameValue(1, $first['featured_articles'], 'Imported featured articles');
assertSameValue(1, $first['menu_items'], 'Imported menu items');
assertSameValue(1, $first['home_items_reset'], 'Home menu item deferred');
assertSameValue(1, $first['custom_modules'], 'Imported custom modules');
assertSameValue(1, $first['module_assignments'], 'Imported module assignments');

$article = $targetPdo->query('SELECT * FROM j_content')->fetch(PDO::FETCH_ASSOC);
$localCategory = $targetPdo->query("SELECT * FROM j_categories WHERE alias = 'local'")->fetch(PDO::FETCH_ASSOC);
$newsCategory = $targetPdo->query("SELECT * FROM j_categories WHERE alias = 'news'")->fetch(PDO::FETCH_ASSOC);
$user = $targetPdo->query('SELECT * FROM j_users WHERE username = "alice"')->fetch(PDO::FETCH_ASSOC);
$group = $targetPdo->query('SELECT * FROM j_usergroups WHERE title = "Registered"')->fetch(PDO::FETCH_ASSOC);
$membership = $targetPdo->query('SELECT * FROM j_user_usergroup_map')->fetch(PDO::FETCH_ASSOC);
$menu = $targetPdo->query('SELECT * FROM j_menu WHERE id > 1')->fetch(PDO::FETCH_ASSOC);
$module = $targetPdo->query('SELECT * FROM j_modules')->fetch(PDO::FETCH_ASSOC);
$assignment = $targetPdo->query('SELECT * FROM j_modules_menu')->fetch(PDO::FETCH_ASSOC);
assertSameValue('story', $article['alias'], 'Article alias');
assertSameValue('article desc', $article['metadesc'], 'Article metadata');
assertSameValue((int) $newsCategory['id'], (int) $localCategory['parent_id'], 'Nested category parent mapping');
assertSameValue(2, (int) $targetPdo->query('SELECT COUNT(*) FROM j_languages')->fetchColumn(), 'Preserve existing and add donor content language');
assertSameValue('news/local', $localCategory['path'], 'Nested category path');
assertSameValue('{"image_intro":"images/detail/image.png"}', $article['images'], 'Article image path');
assertSameValue((int) $user['id'], (int) $article['created_by'], 'Article author mapping');
assertSameValue(1, (int) $article['featured'], 'Joomla 2.5 featured mapping');
assertSameValue((int) $group['id'], (int) $membership['group_id'], 'User group mapping');
assertSameValue('index.php?option=com_content&view=article&id=' . $article['id'], $menu['link'], 'Menu article link mapping');
assertSameValue(0, (int) $menu['home'], 'Home flag deferred for manual choice');
assertSameValue('<img src="images/detail/image.png">', $module['content'], 'Custom HTML content');
assertSameValue('sidebar', $module['position'], 'Custom module position mapping');
assertSameValue(9, (int) $module['template_style_id'], 'Custom module target template style');
assertSameValue((int) $menu['id'], (int) $assignment['menuid'], 'Module menu assignment');

$second = $service->migrate($source, 'src_', $target, $migrationId, $versionProfile);
assertSameValue(true, $second['success'], 'Repeat import status');
assertSameValue(0, $second['users'], 'Repeat import user idempotence');
assertSameValue(0, $second['articles'], 'Repeat import article idempotence');
assertSameValue(0, $second['menu_items'], 'Repeat import menu idempotence');
assertSameValue(0, $second['custom_modules'], 'Repeat import module idempotence');
assertSameValue(1, (int) $targetPdo->query('SELECT COUNT(*) FROM j_content')->fetchColumn(), 'No duplicate articles');

echo "Core data migration tests passed.\n";

$versionPdo = new PDO('sqlite::memory:');
$versionPdo->exec('CREATE TABLE source_extensions (type TEXT, element TEXT, manifest_cache TEXT)');
$resolver = new Migrator\Component\Migrator\Administrator\Service\SourceVersionResolver();

foreach (['2.5.28' => '2.5', '3.10.12' => '3', '4.4.3' => '4', '5.4.2' => '5', '6.1.0' => '6'] as $version => $family) {
    $versionPdo->exec('DELETE FROM source_extensions');
    $statement = $versionPdo->prepare('INSERT INTO source_extensions VALUES (?, ?, ?)');
    $statement->execute(['component', 'com_admin', json_encode(['version' => $version])]);
    $profile = $resolver->resolve($versionPdo, 'source_', '..', 'auto');
    assertSameValue($version, $profile['detected_version'], 'Detected Joomla version');
    assertSameValue($family, $profile['effective_family'], 'Selected Joomla family');
}

$versionPdo->exec('DELETE FROM source_extensions');
$versionPdo->prepare('INSERT INTO source_extensions VALUES (?, ?, ?)')
    ->execute(['component', 'com_admin', json_encode(['version' => '3.10.12'])]);
$override = $resolver->resolve($versionPdo, 'source_', '..', '6');
assertSameValue('6', $override['effective_family'], 'Manual Joomla version override');

echo "Source version detection tests passed.\n";

$filesystemBase = sys_get_temp_dir() . '/migrator-version-test-' . bin2hex(random_bytes(4));
$newRoot = $filesystemBase . '/old/new';
$oldRoot = dirname($newRoot);
mkdir($oldRoot . '/administrator/manifests/files', 0755, true);
mkdir($newRoot, 0755, true);
file_put_contents($oldRoot . '/configuration.php', '<?php');
file_put_contents($oldRoot . '/administrator/manifests/files/joomla.xml', '<extension><version>6.1.1</version></extension>');
define('JPATH_ROOT', $newRoot);
$versionPdo->exec('DELETE FROM source_extensions');
$versionPdo->prepare('INSERT INTO source_extensions VALUES (?, ?, ?)')
    ->execute(['component', 'com_admin', json_encode(['version' => '4.0.0'])]);
$filesystemProfile = $resolver->resolve($versionPdo, 'source_', '..', 'auto');
assertSameValue('6.1.1', $filesystemProfile['detected_version'], 'Filesystem version overrides stale database metadata');
assertSameValue('6', $filesystemProfile['effective_family'], 'Filesystem detected Joomla family');

mkdir($newRoot . '/templates/shaper_helixultimate', 0755, true);
file_put_contents(
    $newRoot . '/templates/shaper_helixultimate/templateDetails.xml',
    '<extension><positions><position>left</position><position>right</position><position>footer1</position></positions></extension>'
);
$targetPdo->exec(
    'CREATE TABLE j_template_styles (id INTEGER PRIMARY KEY, template TEXT, title TEXT, home INTEGER, client_id INTEGER)'
);
$targetPdo->exec("INSERT INTO j_template_styles VALUES (9,'shaper_helixultimate','Helix Ultimate',1,0)");
$templateService = new Migrator\Component\Migrator\Administrator\Service\TargetTemplateService();
$templateStyles = $templateService->getStyles($target);
assertSameValue('shaper_helixultimate', $templateStyles[0]['template'], 'Detected installed template style');
assertSameValue(['left', 'right', 'footer1'], $templateStyles[0]['positions'], 'Read target template positions');
$templateService->setDefaultStyle($target, 9);
assertSameValue(1, (int) $targetPdo->query('SELECT home FROM j_template_styles WHERE id = 9')->fetchColumn(), 'Set default template style');

mkdir($oldRoot . '/language/overrides', 0755, true);
mkdir($oldRoot . '/administrator/language/overrides', 0755, true);
mkdir($newRoot . '/language/overrides', 0755, true);
file_put_contents($oldRoot . '/language/overrides/uk-UA.override.ini', 'CUSTOM="value"');
file_put_contents($oldRoot . '/administrator/language/overrides/uk-UA.override.ini', 'ADMIN="value"');
file_put_contents($oldRoot . '/language/overrides/en-GB.override.ini', 'SOURCE="value"');
file_put_contents($newRoot . '/language/overrides/en-GB.override.ini', 'TARGET="value"');
@symlink('/etc/passwd', $oldRoot . '/language/overrides/xx-XX.override.ini');
$overrideService = new Migrator\Component\Migrator\Administrator\Service\LanguageOverrideMigrationService();
$overrideReport = $overrideService->copyOverrides($newRoot, $oldRoot);
assertSameValue(true, $overrideReport['success'], 'Language override copy status');
assertSameValue(2, $overrideReport['copied'], 'Copy site and administrator language overrides');
assertSameValue(1, count($overrideReport['conflicts']), 'Do not overwrite conflicting language override');
assertSameValue(1, $overrideReport['skipped_symlinks'], 'Skip symlinked language override');
assertSameValue('CUSTOM="value"', file_get_contents($newRoot . '/language/overrides/uk-UA.override.ini'), 'Copy custom language override');
$repeatOverrideReport = $overrideService->copyOverrides($newRoot, $oldRoot);
assertSameValue(0, $repeatOverrideReport['copied'], 'Language override copy is repeatable');
assertSameValue(2, $repeatOverrideReport['identical'], 'Skip identical language overrides');

$cleanup = function ($path) use (&$cleanup) {
    if (is_link($path) || !is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $cleanup($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
};
$cleanup($filesystemBase);

echo "Filesystem version fallback test passed.\n";