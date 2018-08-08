<?php

namespace app\modules\admin\controllers;

use Yii;
use yii\web\Controller;
use yii\data\ArrayDataProvider;
use yii\filters\AccessControl;
use yii\data\ActiveDataProvider;
use app\modules\admin\models\heartBeat\HbTerm;
use app\modules\admin\models\heartBeat\HbLog;
use app\modules\admin\models\heartBeat\HbTag;
use app\modules\user\models\User;
use yii\web\NotFoundHttpException;
use yii\db\mssql\PDO;

class HeartBeatController extends Controller
{

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['portalControl'],
                    ],
                ],
            ],
        ];
    }

    public function init()
    {
        parent::init();
    }

    public function actionIndex()
    {
        $active = ['Y'];
        if (in_array(Yii::$app->request->get('status'), array('error', ''))) {
            $condition = ' AND (t1.reportByValue ="FALSE" OR t1.reportByDate ="FALSE")';
        }

        if (in_array(Yii::$app->request->get('status'), array('notDetermined'))) {
            $condition = 'AND (t1.reportByValue IS NULL AND t1.reportByDate IS NULL)';
        }

        if (in_array(Yii::$app->request->get('status'), array('ok'))) {
            $condition = '
                AND (t1.reportByValue = "TRUE" AND (t1.reportByDate != "FALSE" OR t1.reportByDate IS NULL))
				 OR
			    (t1.reportByDate = "TRUE" AND (t1.reportByValue != "FALSE" OR t1.reportByValue IS NULL ))
            ';
        }
        if (Yii::$app->request->post('searchTag')) {
            $searchTag = Yii::$app->request->post('searchTag');
            $searchTag = trim($searchTag);
            //не учитываем фильтры
            $condition = 'AND (tag_code LIKE :tag_code OR tag_name LIKE :tag_code)';
            $active = ['Y', 'N'];
        }

        $sql = "
            SELECT * FROM (
                SELECT hb.*,
                 CASE hb.fieldLogicValue
                            WHEN '<' THEN
                                IF (hb.last_value < hb.fieldTypeValue,'TRUE','FALSE')
                            WHEN '>' THEN
                                IF (hb.last_value > hb.fieldTypeValue,'TRUE','FALSE')
                        END as reportByValue,

                CASE hb.fieldLogicDt
                            WHEN '<' THEN
                                IF ( ((UNIX_TIMESTAMP() - UNIX_TIMESTAMP(hb.dt))/60) < hb.fieldTypeDt ,'TRUE','FALSE')
                            WHEN '>' THEN
                                IF ( ((UNIX_TIMESTAMP() - UNIX_TIMESTAMP(hb.dt))/60) > hb.fieldTypeDt ,'TRUE','FALSE')
                            END as reportByDate,
                ((UNIX_TIMESTAMP() - UNIX_TIMESTAMP(hb.dt))/60) as minutes_ago
                FROM (
                SELECT t1.id, t1.tag_code, t1.tag_name, t1.last_value, t1.dt,
                min(case when t2.field = 'DT' then t2.compare end) fieldTypeDt,
                min(case when t2.field = 'DT' then t2.logic end) fieldLogicDt,
                min(case when t2.field = 'VALUE' then t2.compare end) fieldTypeValue,
                min(case when t2.field = 'VALUE' then t2.logic end) fieldLogicValue

                FROM `hb_tag` t1
                LEFT JOIN hb_term t2 ON t1.id = t2.tag_id
                WHERE t1.ACTIVE IN ('" . join("', '", $active) . "')
                GROUP BY t1.id
                ) as hb
            ) as t1
            WHERE 1=1
            " . $condition . "
            ORDER BY dt ASC
         ";
        $connection = Yii::$app->db;
        $command = $connection->createCommand($sql);
        if ($searchTag) {
            $searchTag = "%" . $searchTag . "%";
            $command->bindParam(":tag_code", $searchTag, PDO::PARAM_STR);
        }

        $rawData = $command->queryAll();

        $dataProvider = new ArrayDataProvider([
            'allModels' => $rawData,
            'sort' => array(
                'attributes' => array(
                    'tag_code',
                    'last_value',
                    'dt',
                    'minutes_ago',
                ),
            ),
            'pagination' => array(
                'pageSize' => 50,
            ),
        ]);

        return $this->render('index', array(
                'dataProvider' => $dataProvider
            )
        );
    }

    /**
     * Настройка пороговых значений
     */
    public function actionUpdate($id)
    {
        $hbTag = HbTag::findOne($id);
        $modelTermVal = HbTerm::find()->andWhere('tag_id=:tag_id AND field=:field',
            array(':tag_id' => $id, ':field' => 'VALUE'))->one();
        $modelTermDt = HbTerm::find()->andWhere('tag_id=:tag_id AND field=:field',
            array(':tag_id' => $id, ':field' => 'DT'))->one();

        if (!$modelTermVal) {
            $modelTermVal = new HbTerm;
        }
        if (!$modelTermDt) {
            $modelTermDt = new HbTerm;
        }

        if (Yii::$app->request->isPost) {

            //Тег
            $hbTag->attributes = Yii::$app->request->post()['HbTag'];
            $hbTag->save();

            //Для значений
            $termValue = Yii::$app->request->post()['HbTerm']['valueCompare'];
            $validateTermVal = $this->saveTerm($termValue, $id, $modelTermVal);

            //Для даты старта
            $termDt = Yii::$app->request->post()['HbTerm']['dtCompare'];
            $validateTermDt = $this->saveTerm($termDt, $id, $modelTermDt);

            if ($validateTermVal && $validateTermDt) {
                Yii::$app->session->setFlash('success',
                    User::findOne(Yii::$app->user->id)->first_name . ", все хорошо. данные успешно сохранены.");
                $this->redirect(array('/admin/heart-beat/update/', 'id' => $id));
            }
        }

        //Скрыть/отобразить метрику
        if (Yii::$app->request->get('ACTIVE')) {
            $hbTag->ACTIVE = Yii::$app->request->get('ACTIVE');
            $hbTag->save();

            //редирект на список метрик
            $this->redirect(array('/admin/heart-beat/'));
        }

        return $this->render('update',
            array(
                'hbTag' => $hbTag,
                'modelTermDt' => $modelTermDt,
                'modelTermVal' => $modelTermVal,
                'logicOption' => array('<' => '< (Меньше)', '>' => '> (Больше)')
            )
        );
    }

    /**
     * Просмотр детальной статистики
     */
    public function actionView($id)
    {
        $dataProvider = new ActiveDataProvider(array(
            'query' => HbTag::find()->where(['id' => $id]),
            'pagination' => array(
                'pageSize' => 10,
            ),
        ));

        $dataProviderLog = new ActiveDataProvider(array(
            'query' => HbLog::find()->where(['tag_id' => $id]),
            'sort' => array(
                'defaultOrder' => 'dt DESC',
            ),
            'pagination' => array(
                'pageSize' => 50,
            ),
        ));

        //Пороговые значения
        $modelTErm = HbTerm::find()->where(['tag_id' => $id])->all();

        //Данные для построения графика за последние 2 месяа или максимум 60 значений
        $dataForChars = HbLog::find()
            ->andWhere(['tag_id' => $id])
            ->andWhere('dt > DATE_ADD(NOW(), INTERVAL - 60 DAY)')
            ->orderBy(['dt' => 'DESC'])
            ->limit(60)
            ->all();
        $dataForChars = array_reverse($dataForChars);

        return $this->render('view', array(
            'dataProvider' => $dataProvider,
            'dataProviderLog' => $dataProviderLog,
            'dataForChars' => $dataForChars,
            'modelTErm' => $modelTErm,
        ));
    }

    /**
     * Сохраняет обновляет пороговые условия
     */
    protected function saveTerm($saveData, $id, $model)
    {
        $validate = true;
        if (!empty($saveData['compare'])) {
            if ($saveData['id']) {
                $model = $this->loadTermModel($saveData['id']);
            }

            $model->attributes = $saveData;
            $model->tag_id = $id;

            if (!$model->save()) {
                $validate = false;
            }
        } elseif ($saveData['id']) {
            //Удаляем пороговые значения
            $model = $this->loadTermModel($saveData['id']);
            $model->delete();
        }

        return $validate;
    }

    public function loadTermModel($id)
    {
        $model = HbTerm::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException(404, 'The requested page does not exist.');
        }
        return $model;
    }
}
