<?php
/**
 * ajax Контроллер
 * Created by PhpStorm.
 * User: sys.admin154
 * Date: 02.08.16
 * Time: 12:02
 */

class KipController extends Controller {
    /**
     * @var TaskActionAccess
     */
    private $access = NULL;
    private $result = array(
        'success' => false,
        'data' => array(),
    );
    public function beforeAction() {
        Yii::import('application.modules.kip.models.*');
        Yii::import('application.modules.kip.components.*');
        Yii::import('document.models.protocol.*');
        return true;
    }

    /**
     * Сменить приоритет для задачи
     */
    public function actionChangePriority ()
    {

        Yii::import('document.components.ProtocolAccess');
        $task_id = Yii::app()->request->getQuery('task_id', 0);
        $priority_id = Yii::app()->request->getQuery('priority_id', 0);

        # Если параметры не пришли
        if(!$task_id || !$priority_id) {
            echo CJSON::encode($this->result);
            return;
        }

        # Если проиритет пришел не верный
        $allowPriorities = CHtml::listData(KipPriority::model()->findAll(), 'id', 'id');
        if(!in_array($priority_id, $allowPriorities)) {
            $this->result['data'] = array('error' => 'Идентификатор приоритета неверный');
            echo CJSON::encode($this->result);
            return;
        }

        $task = KipTask::model()->findByPk($task_id);
        # объект с дотсупами
        $this->access = TaskActionAccess::init($task);

        # Если нету прав на изменение приоритета
        if(
            !$this->access->canChangePriority() && # КИПОМ запрещено менять приоритет
            (
                !ProtocolAccess::init()->canChangePriority() || # ПРОТОКОЛАМИ запрещено менять приоритет
                in_array($task['status_id'], array(5, 6)) # Статус задачи не позволяет менять приоритет
            )
        ) {
            $this->result['data'] = 'Запрещено менять приоритет';
            echo CJSON::encode($this->result);
            return;
        }

        $old_priority_id = $task->priority_id;
        $task->priority_id = $priority_id;
        $doValidation = false;
        if(!$task->save($doValidation)) {
            $this->result['data'] = $task->getErrors();
            echo CJSON::encode($this->result);
            return;
        }
        $this->result['success'] = true;
        $this->result['data'] = array(
            'old_priority_id' => $old_priority_id,
            'new_priority_id' => $priority_id,
        );
        echo CJSON::encode($this->result);
    }

    /**
     * Возобновить
     */
    public function actionReopen() {
        $task_id = Yii::app()->request->getParam('task_id', 0);
        $protocol_id = Yii::app()->request->getParam('protocol_id', 0);
        // Если найден идентификатор задачи
        if($task_id) {
            $task = KipTask::model()->findByPk($task_id);
            // Если объект задачи найден
            if($task != NULL) {
                $this->access = TaskActionAccess::init($task);
                # Если пользак может возобновлять
                if($this->access->canReopen()) {
                    $task['status_id'] = 3;
                    /**
                     * Т.к. работаем с одним конкретным полем,
                     * приходится писать параметр false
                     * потому что иначе модель ругается
                     * что обазательные поля не заполнены
                     */
                    $saved = $task->save(false);
                    if($saved) {
                        $this->result['success'] = true;
                        $this->result['data'] = 'Задача возобновлена';
                        $protocol = BProtocol::model()->findByPk($protocol_id);
                        $this->redirect($this->createUrl('/document/protocol/view/id/'.$protocol->document_row_id));
                    }
                }
            }
        }
    }

    /**
     * Приостановить задачу (Отложить выполнение)
     */
    public function actionHold() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        // Если найден идентификатор задачи
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Не удалось найти задачу в справочнике';
            } else {
                $this->access = TaskActionAccess::init($task);
                if(!$this->access->canHold()) {
                    $this->result['data'] = 'Приостановка задачи запрещена';
                } else {
                    # Если пользак может приостанавливать
                    $task['status_id'] = 6;
                    /**
                     * Т.к. работаем с одним конкретным полем,
                     * приходится писать параметр false
                     * потому что иначе модель ругается
                     * что обазательные поля не заполнены
                     */
                    $saved = $task->save(false);
                    if(!$saved) {
                        $this->result['data'] = 'Не удалось произвести сохранение';
                    } else {
                        $this->result['success'] = true;
                        $this->result['data'] = 'Задача приостановлена';
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }

    /**
     * Удаление задачи КИП
     * @throws CDbException
     */
    public function actionDelete() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Указанная задача не найдена';
            } else {
                $taskAccess = TaskActionAccess::init($task);
                if(!$taskAccess->canDelete()) {
                    $this->result['data'] = 'Удаление задачи запрещено';
                } else {
                    if(!$task->delete()) {
                        $this->result['data'] = 'Не удалось удалить задачу';
                    } else {
                        $query = 'DELETE FROM b_protocol_properties WHERE task_id = ' . $task_id;
                        Yii::app()->db->createCommand($query)->execute();
                        $this->result['data'] = 'Задача удалена';
                        $this->result['success'] = true;
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }

    /**
     * Завершить задачу
     *
     * Если инициатор задачи, то перевод в статус ЗАВЕРШЕНА 5
     * Если не инициатор задачи, то перевод в статус ОЖИДАЕТ КОНТРОЛЯ 4
     */
    public function actionFinish() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Задача не найдена';
            } else {
                if(!TaskActionAccess::init($task)->canFinish()) {
                    $this->result['data'] = 'Доступ на завершение задачи запрещен';
                } else {
                    if(!Yii::app()->request->isPostRequest) {
                        $this->result['data'] = 'Тип запроса указан не верно';
                    } else {
                        $visitor_id = Yii::app()->user->ID;
                        # Если посетитель = Инициатор
                        if($task->initiator->user_id == $visitor_id) {
                            $task['status_id'] = 5; # Завершена
                        } else {
                            $task['status_id'] = 4; # Ожидает контроля
                        }
                        if(!$task->save(false)) {
                            $this->result['data'] = 'Не удалось завершить задачу';
                        } else {
                            $this->result['data'] = 'Задача завершена';
                            $this->result['success'] = true;
                        }
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }

    /**
     * Начать выполнение
     */
    public function actionStart() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Задача не найдена';
            } else {
                if(!TaskActionAccess::init($task)->canStart()) {
                    $this->result['data'] = 'Доступ запрещен';
                } else {
                    $task['status_id'] = 3; # Меняем статус на "ВЫПОЛНЯЕТСЯ"
                    if(!$task->save()) {
                        $this->result['data'] = 'Не удалось сохранить изменения';
                    } else {
                        $this->result['data'] = 'Начато выполнение задачи';
                        $this->result['success'] = true;
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }

    /**
     * Отправить в очередь
     */
    public function actionQueue() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Задача не найдена';
            } else {
                if(!TaskActionAccess::init($task)->canQueue()) {
                    $this->result['data'] = 'Отправка в очередь запрещена';
                } else {
                    $task['status_id'] = 2; # Меняем статус на "В ОЧЕРЕДИ"
                    if(!$task->save()) {
                        $this->result['data'] = 'Не удалось сохранить изменения';
                    } else {
                        $this->result['data'] = 'Задача отправлена в очередь';
                        $this->result['success'] = true;
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }

    /**
     * Принять работу
     */
    public function actionTake() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Задача не найдена';
            } else {
                if(!TaskActionAccess::init($task)->canTake()) {
                    $this->result['data'] = 'Доступ запрещен';
                } else {
                    $task['status_id'] = 5; # Меняем статус на "ЗАВЕРШЕНА"
                    if(!$task->save()) {
                        $this->result['data'] = 'Не удалось сохранить изменения';
                    } else {
                        $this->result['data'] = 'Работа принята';
                        $this->result['success'] = true;
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }

    /**
     * Изменить крайний срок задачи
     */
    public function actionChangeDeadline() {
        $task_id = Yii::app()->request->getParam('taskId', 0);
        $tr_uid = Yii::app()->request->getParam('trUid', '');
        $new_date = Yii::app()->request->getParam('newDate', '');
        $old_date = Yii::app()->request->getParam('oldDate', '');
        $reason = Yii::app()->request->getParam('reason', '');
        if(!$task_id) {
            $this->result['data'] = 'Идентификатор задачи не найден';
        } else {
            $task = KipTask::model()->findByPk($task_id);
            if($task == NULL) {
                $this->result['data'] = 'Задача не найдена';
            } else {
                if(!TaskActionAccess::init($task)->canChangeDeadline()) {
                    $this->result['data'] = 'Доступ запрещен';
                } else {
                    if(strtotime($new_date) < strtotime($old_date)) {
                        $this->result['data'] = 'Срок не может быть уменьшен';
                    } else {
                        if(!Yii::app()->request->isPostRequest) {
                            /**
                             * Если ГЕТ
                             */
                            $this->result['data'] = $this->renderPartial('descriptionArea', array(
                                'task_id' => $task_id,
                                'tr_uid' => $tr_uid,
                                'new_date' => $new_date,
                                'old_date' => $old_date
                            ), true);
                            $this->result['success'] = true;
                        } else {
                            /**
                             * Если ПОСТ
                             */
                            $task->date_end = date('Y-m-d H:i:s', strtotime($new_date));
                            $user_id = Yii::app()->user->ID;
                            /**
                             * Причина reason
                             */

                            $data = array(
                                'real_user_id' => $user_id,
                                'my_user_id' => $user_id,
                                'reason' => $reason,
                                'old_date_end' => date('Y-m-d H:i:s', strtotime($old_date)),
                                'new_date_end' => date('Y-m-d H:i:s', strtotime($new_date)),
                                'protocol' => array(
                                    'action' => 'change-deadline',
                                    'protocol_id' => ''
                                ),
                            );
                            $kl = new KipLog();
                            $kl->date = date('Y-m-d H:i:s');
                            $kl->task_id = $task_id;
                            $kl->event_id = 4; # Изменение крайнего срока
                            $kl->data = serialize($data);

                            /**
                             * Сохранение в два справочника через транзакцию
                             */
                            $transaction = Yii::app()->db->beginTransaction();
                            try {
                                # Чтобы валидация не сработала
                                $task->save(false); $kl->save();
                                $transaction->commit();
                                $this->result['data'] = 'Срок изменен';
                                $this->result['success'] = true;
                            } catch(Exception $e) {
                                $transaction->rollback();
                                $this->result['data'] = 'Не удалось изменить дату задачи';
                            }
                        }
                    }
                }
            }
        }
        echo CJSON::encode($this->result);
    }
}
