<?php
/**
 * Created by PhpStorm.
 * User: ilv.semenov
 * Date: 16.09.15
 * Time: 10:51
 * Сбор показателей для Кабинета руководителя
 * При пилотном запуске и 10519 пользователях отрабатывал за 23 секунды
 */
class ChiefRoomGrep extends CComponent{
    const IS_OPEN = true;
    private $count = 0;
    public function start()
    {
        $db = Yii::app()->db;
        $allUsers = User::model()->findAll();
        $userIds = CHtml::listData($allUsers, 'ID', 'ID');
        $transaction = $db->beginTransaction();
        try{
            // Получаем сводные данные по всем пользоваетлям
            $stat = array(
                'unconfirmedDays'   => $this->_getUnconfirmDays($userIds),
                'kipCreate'         => $this->_getKipCreateCount($userIds),
                'kipExecuteAll'     => $this->_getKipExecuteCount($userIds),
                'kipExecuteOpen'    => $this->_getKipExecuteCount($userIds, self::IS_OPEN),
                'appCreate'         => $this->_getAppCreateCount($userIds),
                'appExecuteAll'     => $this->_getAppExecuteCount($userIds),
                'appExecuteOpen'    => $this->_getAppExecuteCount($userIds, self::IS_OPEN),
            );
            // Очистка устаревших данных
            AUserCabinet::model()->deleteAll();
            // сохранение новых данных в БД
            foreach($userIds as $userId){
                $user = new AUserCabinet;
                $user->user_id = $userId;
                $user->unconfirmed_days = $stat['unconfirmedDays'][$userId];
                $user->kip_create       = $stat['kipCreate'][$userId];
                $user->kip_execute_all  = $stat['kipExecuteAll'][$userId];
                $user->kip_execute_open = $stat['kipExecuteOpen'][$userId];
                $user->app_create       = $stat['appCreate'][$userId];
                $user->app_execute_all  = $stat['appExecuteAll'][$userId];
                $user->app_execute_open = $stat['appExecuteOpen'][$userId];
                if($user->save()){
                    $this->count++;
                }
            }
            $transaction->commit();
        }
        catch(CDbException $e)
        {
            var_dump($e);
            $transaction->rollback();
        }
        return $this->count;
    }







    // Непроставленные дни в ежедневнике
    private function _getUnconfirmDays($userIds)
    {
        $array = array();
        $sql = '
            SELECT u.ID, count(t.id) as daysCount
            FROM a_user u
            LEFT JOIN b_diary_tasks t ON t.user_id = u.ID
            WHERE u.ID IN ('.join(',', $userIds).')
            AND t.`day` != CURDATE()
            AND t.confirm = 0
            AND t.element_id IS NULL
            GROUP BY u.ID';
        $unconfirmedDays = Yii::app()->db->createCommand($sql)->queryAll();
        foreach($unconfirmedDays as $user){
            $array[$user['ID']] = $user['daysCount'];
        }
        return $array;
    }

    /*
     * КИП-заявки kip
     */
    // Количество созданных инициатором
    private function _getKipCreateCount($userIds)
    {
        $array = array();
        $sql = '
            SELECT
                    bkt.created_user_id AS ID
                    , COUNT(bkt.id) AS `kipCreateCount`
            FROM
                b_kip_tasks bkt
            WHERE
                bkt.created_user_id IN('.join(', ', $userIds).')
            AND
                bkt.date_closed IS NULL
            GROUP BY bkt.created_user_id
        ';
        $kipCreateCount = Yii::app()->db->createCommand($sql)->queryAll();
        foreach($kipCreateCount as $user){
            $array[$user['ID']] = $user['kipCreateCount'];
        }
        return $array;
    }
    // Количество на исполнеении
    private function _getKipExecuteCount($userIds, $onlyOpened = 0){
        $array = array();
        if($onlyOpened)
            $onlyOpened = 'AND k.status_id IN (3, 5)';
        else
            $onlyOpened = 'AND k.status_id IN (1, 2, 3, 5)';
        $sql = "
            SELECT
                e.user_id as ID,
                COUNT(k.id) as kipExecuteCount
            FROM b_kip_tasks k
            LEFT JOIN b_kip_executors e ON e.task_id = k.id
            WHERE
                e.user_id IN(".join(', ', $userIds).")
            AND k.date_closed IS NULL
            AND e.executor_type_id = 1
                ".$onlyOpened."
            GROUP BY e.user_id
        ";
        $kipExecuteCount = Yii::app()->db->createCommand($sql)->queryAll();
        foreach($kipExecuteCount as $user){
            $array[$user['ID']] = $user['kipExecuteCount'];
        }
        return $array;
    }

    /*
     * Простые заявки app
     */
    // Количество созданных инициатором
    private function _getAppCreateCount($userIds){
        $sql = '
            SELECT a.USER_ID as ID, COUNT(a.ID) as appCreateCount
            FROM app a
            left join app_status s ON s.ID = a.STATUS_ID
            where a.USER_ID in ('.join(', ', $userIds).')
            AND s.GROUP_NAME = \'OPEN\'
            AND a.ACTIVE = \'Y\'
            GROUP BY USER_ID
        ';
        $appCreateCount = Yii::app()->db->createCommand($sql)->queryAll();
        $array = array();
        foreach($appCreateCount as $user){
            $array[$user['ID']] = $user['appCreateCount'];
        }
        return $array;
    }
    // Количество на исполнении
    private function _getAppExecuteCount($userIds, $hasStatus = 0){
        $auStatus = 'AND au.UF_SD_STATUS_INT IN (2)';
        if($hasStatus){
            $auStatus = 'AND au.UF_SD_STATUS_INT IN (1)';
        }
        $sql = "
            SELECT
                au.UF_SD_ISP_ID as ID
                , COUNT(a1.ID) as appExecuteCount
            FROM app a1
            LEFT JOIN app_uf au ON au.APP_ID = a1.ID
            WHERE
                au.UF_SD_ISP_ID IN(".join(', ', $userIds).")
            AND a1.STATUS_ID = 4
            AND a1.ACTIVE = 'Y'
              ".$auStatus."
            GROUP BY au.UF_SD_ISP_ID
        ";
        $appExecuteCount = Yii::app()->db->createCommand($sql)->queryAll();
        $array = array();
        foreach($appExecuteCount as $user){
            $array[$user['ID']] = $user['appExecuteCount'];
        }
        return $array;
    }

} 
