<?php

namespace app\modules\admin\controllers;

use app\models\UploadJpeg;
use app\modules\admin\models\ManagerException;
use app\modules\admin\models\UserSearch;
use app\modules\organizational\components\Managers;
use app\modules\user\models\Employee;
use app\modules\user\models\Subordination;
use Yii;
use yii\helpers\Json;
use yii\web\Controller;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use app\models\UploadForm;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;
use app\modules\user\models\User;

/**
 * DefaultController implements the CRUD actions for User model.
 */
class UserController extends Controller
{

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['userAdmin'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['post'],
                ],
            ],
        ];
    }


    public function actionIndex()
    {
        $searchModel = new UserSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionCreate()
    {
        $model = new User();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->cache->flush();
            return $this->redirect(['index']);
        } else {
            return $this->render('create', [
                'model' => $model,
                'employeeModel' => false
            ]);
        }
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $employeeModel = $model->mainEmployee;
        if (!$employeeModel) {
            $employeeModel = new Employee();
        }

        if (($model->load(Yii::$app->request->post()) && $model->save()) && ($employeeModel->load(Yii::$app->request->post()) && $employeeModel->save())) {
            \Yii::$app->session->setFlash('success', "Данные сохранены.");
            Yii::$app->cache->flush();
            return $this->redirect(['update', 'id' => $id]);
        } else {
            return $this->render('update', [
                'model' => $model,
                'employeeModel' => $employeeModel,
            ]);
        }
    }

    /**
     * @return null|string
     */
    public function actionCreateManagerException()
    {
        $model = new ManagerException();
        $model->type = Yii::$app->request->get('type');
        $model->user_id = Yii::$app->request->get('user_id');

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->cache->flush();
            return '#reload';
        }
        return $this->renderAjax('_form_manager_exception', [
            'model' => $model,
        ]);
    }

    /**
     * @param $id
     *
     * @return string
     * @throws \Exception
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function actionDeleteManagerException($id)
    {
        ManagerException::findOne($id)->delete();
        Yii::$app->cache->flush();
        return '#reload';
    }

    /**
     * @param $userId
     * @param $type
     *
     * @return \yii\web\Response
     * @throws \Exception
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function actionDeleteManager($userId, $type)
    {
        $model = Subordination::findOne(['user_id' => $userId, 'type' => $type]);
        if ($model) {
            $model->delete();
            Yii::$app->cache->flush();
        }
        return $this->redirect(['update', 'id' => $userId, '#' => 'manager']);
    }

    /**
     * @param integer $id
     *
     * @return User
     * @throws NotFoundHttpException
     */
    protected function findModel($id)
    {
        if (($model = User::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('Запрошенная страница не существует.');
        }
    }


    /**
     * @deprecated - ручная загрузка не используется. Метод загрузки не актуален.
     * @return string
     * @throws \yii\db\Exception
     */
    public function actionUploadcsv()
    {
        $runfakeInsert = false;
        $result = [
            'upload' => 'Не удалось загрузить файл',
            'insertUser' => [
                'success' => false,
                'data' => ['Не удалось обновить таблицу User']
            ],
            'insertEmployee' => [
                'success' => false,
                'data' => ['Не удалось обновить таблицу Employee']
            ],
            'insertSubordination' => 'Не удалось обновить таблицу Subordination',
        ];
        $model = new UploadForm();
        $userModel = new User;
        $employeeModel = new Employee;
        $subordination = new Subordination;
        if (Yii::$app->request->isPost) {
            $model->file = UploadedFile::getInstance($model, 'file');
            if ($model->upload()) {
                $result['upload'] = 'Загрузка файла на сервер выполнена';
                $result['insertUser'] = $userModel->insertFromCsv($model->file->name);
                # Если выполнено то только в этом слуаче можно заполнять Employee
                if ($result['insertUser']['success']) {
                    $result['insertEmployee'] = $employeeModel->insertFromCsv($model->file->name);

                    if ($runfakeInsert) {
                        /**
                         * @todo - вот это когда будем убирать???
                         * Фейковый INSERT пользователя с Дополнительным руководителем
                         */
                        $whoUserLogin = 'i.Semenov';
                        $whoManagerLogin = 'v.Pupkin';
                        $user = User::find()->where(['user_login' => $whoUserLogin])->one();
                        $manager = Employee::find()->where(['user_ad_login' => $whoManagerLogin])->one();
                        if ($user != null) {
                            $employeeModel = new Employee();
                            $employeeModel->user_id = $user->id;
                            $employeeModel->main = 0;
                            $employeeModel->manager_outer_id = $manager->outer_id;
                            $employeeModel->save(false);
                        }
                    }
                    # Если выполнено то только в этом слуаче можно заполнять Subordination
                    if ($result['insertEmployee']['success']) {
                        $result['insertSubordination'] = $subordination->rebuild();
                    }
                }
            }
        }
        return $this->render('uploadcsv', ['model' => $model, 'result' => $result]);
    }

    public function actionPhotos()
    {
        $result = [
            'success' => false,
            'data' => ['Не удалось загрузить файлы']
        ];
        $modelJpeg = new UploadJpeg();
        if (Yii::$app->request->isPost) {
            $modelJpeg->files = UploadedFile::getInstances($modelJpeg, 'files');
            if ($suffix = $modelJpeg->upload()) {
                $result['success'] = true;
                $result['data'] = ['Загрузка файлов на сервер выполнена'];
                if ($result['success']) {
                    $modelJpeg->createResizedImage();
                    $result['data'][] = 'Аватары пользователей созданы';
                }
            }
        }
        return $this->render('photos', ['model' => $modelJpeg, 'result' => $result]);
    }

    public function actionSubordinationRecount()
    {
        $response = ['error' => 0];
        Managers::Run();
        echo Json::encode($response);
        Yii::$app->end();
    }

    public function actionGetuserscsv()
    {
        $users = User::find()->all();
        $separator = ';';
        $firstRow = [
            'EmployeeOuterID',
            'UserLogin',
            'UserADLogin',
            'LastName',
            'FirstName',
            'MiddleName',
            'GenderID',
            'DateBirth',
            'ManagerEmployeeOuterID',
            'ShowFlag',
            'Email',
            'PhoneWork',
            'PhoneMobile',
            'PhoneMAC',
            'Territory',
            'DepartmentOuterID',
        ];
        $list[] = join($separator, $firstRow);
        foreach ($users as $user) {
            if (!empty($user->date_birth)) {
                $user->date_birth = date('d.m.Y', strtotime($user->date_birth));
            }
            $row = [
                $user->mainEmployee->outer_id,
                $user->user_login,
                $user->user_ad_login,
                $user->last_name,
                $user->first_name,
                $user->middle_name,
                $user->gender_id,
                $user->date_birth,
                $user->mainEmployee->manager_outer_id,
                $user->show_flag,
                $user->email,
                $user->phone_work,
                $user->phone_mobile,
                $user->phone_mac,
                $user->territory,
                $user->mainEmployee->department->outer_id,
            ];
            foreach ($row as $key => $item) {
                $row[$key] = iconv('utf-8', 'cp1251', $item);
            }
            $list[] = join($separator, $row);
        }
        return Yii::$app->response->sendContentAsFile(join("\r\n", $list), 'users.csv');
    }

}
