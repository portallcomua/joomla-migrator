<?php
defined('_JEXEC') or die;
?>
<div class="container">
    <h2>Мігратор Joomla — Аналіз старої бази даних</h2>

    <form method="post" action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_migrator&task=analyze'); ?>">
        <div class="row">
            <div class="col-md-4"><label>Хост старої БД</label>
                <input type="text" name="old_host" value="localhost" class="form-control"></div>
            <div class="col-md-4"><label>Ім'я БД</label>
                <input type="text" name="old_db" class="form-control"></div>
            <div class="col-md-4"><label>Префікс</label>
                <input type="text" name="old_prefix" value="jos_" class="form-control"></div>
        </div>
        <div class="row mt-2">
            <div class="col-md-4"><label>Користувач</label>
                <input type="text" name="old_user" class="form-control"></div>
            <div class="col-md-4"><label>Пароль</label>
                <input type="password" name="old_pass" class="form-control"></div>
            <div class="col-md-4"><label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Аналізувати</button></div>
        </div>
    </form>

    <?php if ($this->report): ?>
        <hr>
        <?php if (!empty($this->report['error'])): ?>
            <div class="alert alert-danger">Помилка: <?php echo htmlspecialchars($this->report['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($this->report['summary'])): ?>
            <h3>Кількість основних даних</h3>
            <table class="table table-sm">
                <tr><th>Таблиця</th><th>Кількість</th></tr>
                <?php foreach ($this->report['summary'] as $k => $v): ?>
                    <tr><td><?php echo htmlspecialchars($k); ?></td><td><?php echo $v; ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <?php if (!empty($this->report['extensions'])): ?>
            <h3>Розширення</h3>
            <table class="table table-sm">
                <tr><th>Назва</th><th>Елемент</th><th>Тип</th><th>Статус</th></tr>
                <?php foreach ($this->report['extensions'] as $ext): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ext['name']); ?></td>
                        <td><?php echo htmlspecialchars($ext['element']); ?></td>
                        <td><?php echo htmlspecialchars($ext['type']); ?></td>
                        <td><?php echo $ext['status']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>