<?php
/**
 * Created by PhpStorm.
 * User: ilv.semenov
 * Date: 16.08.16
 * Time: 09:23
 */
Yii::import('application.modules.document.models.protocol.BProtocol');

/**
 * Class ProtocolController
 */
class ProtocolController extends Controller
{
    /**
     * @var ProtocolAccess
     */
    public $access = NULL;
    public function init() {
        parent::init();
        Yii::import('document.models.protocol.*');
    }
    protected function beforeAction($action)
    {
        /**
         * Импорт моделей
         */
        Yii::import('kip.models.*');
        Yii::import('kip.components.*');

        /**
         * Подключаем визуальные штуки
         */
        Yii::app()->theme = 'info_new';
        Yii::app()->kendoui->register();
        Yii::app()->clientScript->RegisterCssFile(Yii::app()->baseUrl . '/css/document/style.css', false);
        return true;
    }

    public function filters()
    {
        return array('accessControl');
    }

    public function accessRules()
    {
        return array(
            array(
                'allow',//Админский доступ
                'actions' => array('admin'),
                'users' => array('@'),
                'expression' => array('ProtocolAccess', 'isAdmin'),
            ),
            array(
                'deny',//Редирект на страницу админов
                'actions' => array('index'),
                'expression' => array('ProtocolAccess', 'isAdmin'),
                'deniedCallback' => function () {
                    Yii::app()->controller->redirect(array('admin'));
                }
            ),
            array(
                'allow',//Доступ к протоколу
                'actions' => array('view'),
                'users' => array('@'),
                'expression' => array('ProtocolController', 'viewAccess'),
            ),
            array(
                'allow', // Авторизованные пользователи могут просматривать список протоколов
                'actions' => array('index'),
                'users' => array('@')
            ),
            array(
                'deny', // Остальные нет
                'users' => array('*'),
            ),
        );
    }

    public function actionIndex()
    {
        Yii::app()->session->close();

        $access = ProtocolAccess::init();
        if ($access->isPartTaker()) {
            $data = $this->getUserProtocols();
        } elseif ($access->isPikPrivilegedUser()) {
            /**
             * Некоторые Пики могут видеть все протоколы, но только видеть.
             * Заявки не поступало на открытие им административного доступа
             * SD 630373
             */
            $data = $this->getAllProtocols();
        } else {
            return $this->render('accessDenied');
        }

        $this->render(
            'list',
            array(
                'data' => CJSON::encode($data),
                'filters' => $this->getFilters()
            )
        );
    }

    public function actionAdmin($my = false)
    {
        Yii::app()->session->close();

        $data = (!$my) ? $this->getAllProtocols() : $this->getUserProtocols();

        $this->render(
            'list',
            array(
                'data' => CJSON::encode($data),
                'filters' => $this->getFilters()
            )
        );
    }

    public function actionView($id)
    {
        $p = BProtocol::model()->findByAttributes(
            array('document_row_id' => $id),
            array(
                'with' => array(
                    'absent',
                    'invited',
                    'attendees'
                )
            )
        );
        $access = ProtocolAccess::init($p->id);
        $category_id = Yii::app()->request->getParam('category_id');
        $executor_id = Yii::app()->request->getParam('executor_id');
        $date_from_filter = Yii::app()->request->getParam('date_from');
        $date_to_filter = Yii::app()->request->getParam('date_to');
        $hasPdf = Yii::app()->request->getParam('pdf', 0);
        $categories = array();
        $rootCategory = $p->rootCategory;
        $allCategories = $rootCategory->descendants()->findAll();

        $executors = $tempArray = array();
        foreach ($allCategories as $item) {
            # Получаем все категории задач
            $categories[] = array(
                'id' => $item['id'],
                'name' => $item['title'],
                'level' => $item['level'],
            );
            # Получаем всех исполнителей задач
            foreach ($item->tasks as $task) {
                if(count($task->executors)) {
                    foreach ($task->executors as $executor) {
                        # устраняем повторы при заполнении массива
                        if(!in_array($executor['user_id'], $tempArray)) {
                            $executors[] = array(
                                'id' => $executor['user_id'],
                                'name' => $executor->user->FioShort,
                            );
                            $tempArray[] = $executor['user_id'];
                        }
                    }

                }

            }

        }
        unset($tempArray);

        $allCategories = $rootCategory->descendants()->findAll();
        $data = array();
        # Если выбрана конкретная категория...
        if($category_id)
        {
            // ...Начинаем поиск подкатегорий от нее
            $rootCategory = BProtocolCategory::model()->findByPk($category_id);
            $allCategories = array_merge(
                array($rootCategory),
                $rootCategory->descendants()->findAll()
            );
        }
        $prev_level = 2;
        $number = array(0);
        foreach($allCategories as $key => $category){
            if(!$key) {
                $rootLevel = $category['level'];
            }
            $item = $category->attributes;
            $number = $this->getNumber($number, $item['level'], $prev_level);
            $item['number'] = $number;
            $prev_level = $item['level'];
            $parentCategory = $category->parent()->find();
            $item['parentId'] = $parentCategory['id'];
            # Всем корневым элементам проставляем parent_id равное NULL
            if($rootLevel == $category['level']) {
                $item['parentId'] = NULL;
            }
            $item['isTask'] = false;
            $item['categoryMenu'] .= $this->widget('application.modules.document.widgets.categoryMenu.CategoryMenu', array(
                'access' => ProtocolAccess::init($p['id']),
                'protocol_id' => $p['id'],
                'document_row_id' => $p['document_row_id'],
                'id' => $category['id'],
                'parent_id' => $parentCategory['id']
            ), true);
            $data[] = $item; # Кладем категорию в массив для kendo
            $c = new CDbCriteria();
            $c->compare('protocolCategories.id', $category['id']);

            # Сортировка по параметру из b_protocol_properties
            $c->order = 'protocolProperties.ordering';

            # Если выбран ответственный, то ограничиваем выгрузку им
            if($executor_id) {
                $c->compare('executor.user_id', $executor_id);
            }
            $categoryTasks =
                KipTask::model()
                    ->with('protocolCategories')
                    ->with('executor')
                    ->with('protocolProperties') # Только для сортировки
                    ->together(true)
                    ->findAll($c);
            foreach ($categoryTasks as $task) {
                $taskActionAccess = TaskActionAccess::init($task);
                $item = $task->attributes;
                $item['canChangeDeadline'] = $taskActionAccess->canChangeDeadline();
                $item['isTask'] = true;
                $item['statusName'] = $task->status->title_s;
                $item['executorName'] = $task->executor->user['FioShort'];
                $item['executorId'] = $task->executor->user['ID'];
                $item['comments'] = array();
                $item['desc'] = strip_tags($task->desc);
                $item['parentId'] = $category->id;
                $item['level'] = $category->level+1;
                $number = $this->getNumber($number, $item['level'], $prev_level);
                $item['number'] = $number;
                $prev_level = $item['level'];
                $item['date_end'] = date('d.m.Y', strtotime($task['date_end']));

                /**
                 * Выделяем задачи визуально по фильтру сроков
                 */
                $item['selected'] = false;
                if(strtotime($date_from_filter) <= strtotime($item['date_end']) && strtotime($item['date_end']) <= strtotime($date_to_filter)) {
                    $item['selected'] = true;
                }

                $item['canComment'] = ($taskActionAccess->canComment() || $access->canAddComment())?true:false;
                $item['canChangeExecutor'] = $access->canChangeExecutor();

                /**
                 * IsOverdue
                 */
                $item['isOverdue'] = (
                    (date('H:i:s',strtotime($task['date_end'])) == '00:00:00'
                        &&
                        strtotime("-1 day") >= strtotime($task['date_end']))
                    ||
                    (date('H:i:s',strtotime($task['date_end'])) != '00:00:00'
                        &&
                        strtotime("now") >= strtotime($task['date_end']))
                );

                $item['date_end_history'] = array();
                # Дата выполнения с переносами дат
                if(!count($task->periods)) {
                    $item['date_end_history'][] = date('d.m.Y', strtotime($task['date_end']));
                } else {
                    foreach($task->periods as $iter => $period) {
                        /**
                         * Конвертим дату
                         */
                        $period['data'] = iconv('utf-8', 'windows-1251', $period['data']);
                        $serialize = $period['data'];
                        $periodArray = unserialize($serialize);
                        foreach ($periodArray as $alias => &$periodItem) {
                            if(is_string($periodItem) && $alias == 'reason')
                                $periodItem = iconv('windows-1251', 'utf-8', $periodItem);
                        }
                        # Добавляем первую дату
                        if($iter == 0) {
                            /**
                             * Маска
                             */
                            $mask = HDate::maskDateOrDateTime($periodArray['old_date_end'], 'd.m.Y');
                            $item['date_end_history'][] = date('d.m.Y', strtotime($periodArray['old_date_end']));
                        }
                        $mask = HDate::maskDateOrDateTime($periodArray['new_date_end'], 'd.m.Y');
                        $item['date_end_history'][] = date('d.m.Y', strtotime($periodArray['new_date_end']));
                    }
                }
                $item['contextMenu'] = $this->widget('application.modules.kip.widgets.TaskMenu', array(
                    'allowMenuItems' => array(
                        'view', 'subTask', 'copy', 'start', 'queue', 'take', 'hold', 'reopen', 'delete', 'finish'
                    ),
                    'access' => $taskActionAccess, # Доступы к действиям над задачей (Меню с возможными действиями)
                    'task' => $task,
                    'protocol_id' => $p['id'],
                    'document_row_id' => $p['document_row_id'],
                ))->run();
                $item['priority'] = $this->widget('application.modules.kip.widgets.PriorityMenu', array(
                    'task' => $task,
                    'access' => $taskActionAccess
                ), true);

                if($access->canEditTask()) {
                    $item['taskEdit'] = $this->renderPartial('_taskEdit', array('task' => $task, 'protocol' => $p), true);
                } else {
                    $item['taskEdit'] = '';
                }
                # Компонент для конвертирования bbcode в html
                $decoda = Yii::app()->decoda;
                # Наполняем комментарии
                foreach($task->comments as $comment) {
                    $user = User::model()->findByPk($comment['user_id']);
                    $item['comments'][] = array(
                        'id' => $comment['id'],
                        'date' => date('d/m/Y H:i', strtotime($comment['date'])),
                        'text' => strip_tags($decoda->parse($comment['text'])),
                        'user_id' => $user['ID'],
                        'userName' => $user['FioShort'],
                    );
                }
                $data[] = $item; # Кладем задачу в массив для kendo
            }
        }

        $result = DvDocument::model()->findByPk($p['document_row_id']);
        $document = $result->attributes;
        $document['properties'] = DvDocumentProperty::model()->getPropertiesAsArray($result);
        $document['initiator'] = $result->author->user;
        $document['pkr'] = IpmSprPkr::model()->findByAttributes(array('GuidDV' => $document['properties']['ProjectPR']['value']));
        $document['files'] = DvFile::model()->findAllByAttributes(array('CardID' => $document['row_id']));

        $params = array(
            'data' => $data,
            'p' => $p,//protocol
            'versions' => $this->prepareVersions($p), # Версии протокола
            'document' => $document,
            'access' => $access,
            'withHeader' => Yii::app()->request->getParam('withHeader', 0),

            # Для Фильтров-Селектов
            'categories' => $categories,
            'executors' => $executors,
        );
        if (!$hasPdf) {
            $this->render('view', $params);
        } else {
            $pdf = Yii::app()->wkPdf;
            $pdf->setOptions(array(
                'tmp' => Yii::app()->basePath.'/runtime',
                'encoding' => 'UTF-8',
                'orientation' => 'Portrait',
                'title' => 'Protocol '.$p['id'],
                'footer-html' => Yii::getPathOfAlias('ext.WkHtmlToPdf.views.').'/footer.html',
                'margin-top' => 7,
                'margin-right' => 7,
                'margin-bottom' => 7,
                'margin-left' => 7,
            ));
            $content = $this->renderPartial('pdf', $params, true);
            # Обернуто в <html></html>
            $pdf->addPage($this->renderFile(Yii::getPathOfAlias('ext.WkHtmlToPdf.views.').'/layout.php', array('content' => $content), true));
            $pdf->send('Protocol-'.$p['id'].'.pdf');
        }
    }

    /**
     * Проверка доступа на просмотр конкретного протокола
     *
     * @return bool
     */
    public static function viewAccess()
    {
        $protocol_id = Yii::app()->request->getParam('id', 0);
        if($protocol_id) {
            $canView = ProtocolAccess::init($protocol_id)->canView();
        } else {
            $canView = ProtocolAccess::init()->canView();
        }
        return $canView;
    }

    private function getFilters()
    {
        $directions = Yii::app()->msdb1->createCommand('
        SELECT
            CONVERT(char(36), t1.[DirectionRowId]) value
            ,ISNULL(t2.Name,"") group_name
            ,t1.[Name]
            ,t2.[Order] parent
            ,t1.[Order]
        FROM [DV5].[TransitITService].[spr_dv5isp_Direction] t1
        LEFT JOIN [DV5].[TransitITService].[spr_dv5isp_Direction] t2 ON t1.[DirectionParentRowId] = t2.[DirectionRowId]
        WHERE t1.IsDeleted = 0')->queryAll();

        usort($directions, array('ProtocolController', 'SortDirections'));

        $projects = Yii::app()->getDb()->createCommand("
        SELECT
            project_code AS `value`,
            `Name`
        FROM
            b_protocols
        LEFT JOIN ipm_spr_project ON project_code = Code1C
        WHERE
            project_code IS NOT NULL
        AND project_code <> ''
        AND ACTIVE = 'Y'
        AND isDelete = 0
        AND Del = 0
        AND isFolder = 0
        ORDER BY
            `Name`
        ")->queryAll();

        $authors = Yii::app()->getDb()->createCommand("
		SELECT
            a.login `value`,
            FioShort `Name`
        FROM
                dv_document d
        LEFT JOIN dv_document_access a ON d.row_id = a.document_row_id AND a.ACTIVE = 'Y'
        INNER JOIN a_user u ON u.Login = a.login
        WHERE
          d.card_kind_row_id = 'EBEA4140-86D6-4495-A422-83E95ABAB4EC'
        AND a.alias = 'Author'
        AND d.ACTIVE = 'Y'
        GROUP BY a.login
        ORDER BY FioShort
        ")->queryAll();

        return array(
            array(
                'alias' => 'direction',
                'name' => 'Направление',
                'items' => $directions
            ),
            array(
                'alias' => 'project',
                'name' => 'Проект',
                'items' => $projects
            ),
            array(
                'alias' => 'author',
                'name' => 'Инициатор',
                'items' => $authors
            ),
        );
    }

    private function getAllProtocols()
    {
        return Yii::app()->getDb()->createCommand(' /* Получение всех протоколов */
            SELECT * FROM
            (
            SELECT
                        p.id AS protocol_id,
                        DATE_FORMAT(p.date, "%d.%m.%Y") date,
                        p.version,
                        d.reg_number,
                        p.project_code project,
                        d.row_id AS document_row_id,
                        d.doc_name,
                        1 AS createProtocol,
                        IF (d.card_kind_state_name = \'Активен\',0,1) AS archive,
                    author.login AS author,
                    d.card_kind_state_name,
                    direction
                FROM
                    (
                        SELECT
                            row_id,
                            reg_number,
                            card_kind_state_name,
                            doc_name,
                            GROUP_CONCAT(`value`) direction
                        FROM
                            dv_document d
                        LEFT JOIN dv_document_tabular_section dts ON row_id = document_row_id
                        AND alias = "Direction"
                        AND dts.ACTIVE = "Y"
                        WHERE
                                d.ACTIVE = "Y"
                        AND card_kind_state_row_id IN (\'074874E7-0F8C-4F92-9C46-F9B80B2486CB\',\'4C9B0317-266F-4BE5-81DE-A74487DB2E86\')
                        AND row_id != "1FF07374-A28A-E411-940E-40F2E94E7026"
                        GROUP BY
                            d.row_id
                    ) d
                LEFT JOIN b_protocols p ON d.row_id = p.document_row_id
                LEFT JOIN dv_document_access author ON (
                    author.document_row_id = d.row_id
                    AND author.alias = "Author"
                    AND author.ACTIVE = "Y"
                )
            ) AS t
        ORDER BY
            t.reg_number
            ')->queryAll();
    }

    private function getUserProtocols()
    {
        $user_id = Yii::app()->user->getState('id');
        $login = Yii::app()->user->getState('login');

        return Yii::app()->getDb()->createCommand('/* Получение списка протоколов по User_id */
        SELECT
                p.id AS protocol_id,
                DATE_FORMAT(p.date, "%d.%m.%Y") as date,
                p.version,
                d.reg_number,
                p.project_code project,
                d.row_id AS document_row_id,
                d.description,
                d.doc_name,
                if(a.id > 0 ,1,0) as createProtocol,
                if(d.card_kind_state_name = "Активен", 0, 1) as archive,
                author.login as author,
                direction
        FROM `dv_document` d
        LEFT JOIN b_protocols p ON p.document_row_id = d.row_id
        LEFT JOIN dv_document_access author ON author.document_row_id = d.row_id AND author.alias = "Author" AND author.ACTIVE = "Y"
        LEFT JOIN dv_document_access a ON a.document_row_id = d.row_id AND a.login = "' . $login . '" AND a.ACTIVE = "Y"
        LEFT JOIN (
        SELECT
                    d.row_id document_row_id,
                    GROUP_CONCAT(`value`) direction
            FROM
            dv_document d
            LEFT JOIN dv_document_tabular_section dts ON (
                    d.row_id = document_row_id
                    AND dts.card_kind_property_row_id = "915E53AA-43B8-4D3D-8D8E-6839AEE0B735"
                    AND dts.ACTIVE="Y"
            )
            WHERE card_kind_state_row_id IN ("074874E7-0F8C-4F92-9C46-F9B80B2486CB","4C9B0317-266F-4BE5-81DE-A74487DB2E86")
            GROUP BY d.row_id) tabular on tabular.document_row_id = d.row_id
        WHERE (
        p.members LIKE "%i:' . $user_id . ';%" OR
        a.id > 0 )
            AND d.ACTIVE = "Y"
        AND d.card_kind_state_row_id IN ("074874E7-0F8C-4F92-9C46-F9B80B2486CB","4C9B0317-266F-4BE5-81DE-A74487DB2E86")
        GROUP BY d.row_id
        ORDER BY d.reg_number
        ')->queryAll();


    }

    private static function SortDirections($a, $b)
    {
        $result = null;
        if ($a['parent'] && $b['parent']) {
            $result = $a['parent'] - $b['parent'];
        } elseif ($a['parent']) {
            $result = $a['parent'] - $b['Order'];
        } elseif ($b['parent']) {
            $result = $a['Order'] - $b['parent'];
        }

        if ($result == 0) {
            $result = $a['Order'] - $b['Order'];
        }

        return $result;
    }

    /**
     * Возвращает массив версий протокола в форматированном для kendo.grid виде
     * @param BProtocol $p
     * @return array
     */
    private function prepareVersions($p) {
        $result = BProtocolArchive::model()->findAllByAttributes(array('protocol_id' => $p['id']));
        $versions = array();
        foreach($result as $version) {
            $item = array(
                'version' => 0,
                'dateCreated' => NULL,
                'createrId' => 0,
                'createrName' => NULL,
                'date' => '&mdash;',
                'place' => NULL,
                'version_id' => 0,
                'doc_name' => NULL,
                'downloadLink' => NULL,
            );
            $data = unserialize(base64_decode($version['data']));
            $protocol = $data['result']['protocol'];
            $item['version'] = $protocol['prefix_version'].$protocol['version'].$protocol['suffix_version'];
            $item['dateCreated'] = date('d.m.Y H:i', strtotime($version['date_created']));
            $item['createrId'] = $version['user_id'];
            $item['createrName'] = $version->creater['FioShort'];
            if($protocol["date"] && $protocol["date"] != '0000-00-00') {
                $item['date'] = date('d.m.Y', strtotime($protocol['date']));
            }
            $item['place'] = iconv('windows-1251', 'utf-8', $protocol['place']);
            $item['version_id'] = $version['id'];
            $item['doc_name'] = iconv('windows-1251', 'utf-8', $protocol['doc_name']);
            $item['downloadLink'] = '/doc/protocol.php?action=download-version&protocol_id='.$p['id'].'&version_id='.$item['version_id'];
            $versions[] = $item;
        }
        return $versions;
    }

    /**
     * @brief Получение порядкового номера строки
     * @param array $number - Массив вложенности содержащий порядковый номер предыдущего элемента
     * @param int $level - Текущий уровень
     * @param int $prev_level - Предыдущий уровень
     * @return array возвращает массив, после приведения которого в строку получится порядковый номер
     */
    private function getNumber($number, $level, $prev_level) {
        if($level > $prev_level) {			//если текущий уровень выше - увеличиваем вложенность
            //увеличиваем вложенность
            array_push($number, 1);
        } else if($level < $prev_level) {	//если меньше - необходимо вернуться на уровень $level и увеличить вложенность
            //делта, разница между уровнями
            $delta = $prev_level - $level;

            for($i = 0; $i < $delta; $i++) {
                //удаляем нижние элемента массива
                array_pop($number);
            }

            //увеличиваем вложенность
            array_push($number, array_pop($number)+1);
        } else {							//если уровни равны - удаляем последний элемент и добавляем на 1 единицу выше
            //увеличиваем вложенность
            array_push($number, array_pop($number)+1);
        }

        return $number;
    }

}
