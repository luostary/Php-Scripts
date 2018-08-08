<?php

use yii\helpers\Html;
use yii\grid\GridView;
use yii\grid\ActionColumn;
use yii\bootstrap\Nav;

$this->params['breadcrumbs'] = array(
    ['label' => 'Администрирование', 'url' => ['/admin']],
    'Логирование синхронизаций'
);
?>
    <h3>Логирование синхронизаций (HeartBeat)</h3>

<?= Nav::widget([
    'options' => [
        'class' => 'nav-tabs',
        'style' => 'margin: 35px 0 20px 0',
    ],
    'encodeLabels' => false,
    'items' => [
        [
            'label' => 'С ошибками',
            'url' => ['/admin/heart-beat/index', 'status' => 'error'],
        ],
        [
            'label' => 'Успешные',
            'url' => ['/admin/heart-beat/index', 'status' => 'ok'],
        ],
        [
            'label' => 'Не назначены',
            'url' => ['/admin/heart-beat/index', 'status' => 'notDetermined'],
        ],
    ],
]) ?>
<?php
echo Html::beginForm('', 'post');
echo Html::label('Поиск по тегу', 'searchTag');
?>
    <div class="form-group">
        <?= Html::textInput('searchTag', Yii::$app->request->post('searchTag'), ['class' => 'form-control']); ?>
    </div>
    <div class="form-group">
        <?= Html::submitButton('Найти', ['class' => 'btn btn-info']); ?>
    </div>

<?= Html::endForm(); ?>

<?php $columns = array(
    array(
        'header' => 'Код',
        'label' => 'tag_code',
        'value' => function ($data) {
            return Yii::$app->controller->renderPartial("common/_row_kod_column", array("data" => $data));
        },
        'format' => 'raw',
    ),
    'last_value',
    array(
        'header' => 'LastRun',
        'label' => 'dt',
        'format' => 'raw',
        'value' => function ($data) {
            return (!empty($data["dt"])) ? date("d.m.Y H:i", strtotime($data["dt"])) : "-";
        },
    ),
    array(
        'header' => 'Time ago',
        'label' => 'minutes_ago',
        'format' => 'raw',
        'value' => function ($data) {
            $data["minutes_ago"] = (time() - strtotime($data['dt'])) / 60;
            return ($data["minutes_ago"] > 120) ? round(($data["minutes_ago"] / 60)) . " час." : round($data["minutes_ago"]) . " мин";
        },
    ),
    array(
        'header' => 'Error',
        'label' => 'reportByValue',
        'format' => 'raw',
        'value' => function ($data) {
            return Yii::$app->controller->renderPartial("common/_row_error_column", array("data" => $data));
        },
    ),
    array(
        'header' => 'Ok',
        'label' => 'reportByValue',
        'format' => 'raw',
        'value' => function ($data) {
            return Yii::$app->controller->renderPartial("common/_row_ok_column", array("data" => $data));
        },
    ),
    array(
        'class' => ActionColumn::class,
        'template' => '{update}',
        'buttons' => array
        (
            'update' => function ($url, $data) {
                return Html::a('<i class="glyphicon glyphicon-pencil"></i>',
                    Yii::$app->urlManager->createUrl(["/admin/heart-beat/update", "id" => $data["id"]]));
            }
        ),
    ),
);

echo GridView::widget([
    'dataProvider' => $dataProvider,
    'layout' => "{pager}{items}{pager}",
    'options' => array('class' => 'docs-table'),
    'columns' => $columns,
]);

?>
