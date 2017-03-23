<?php
/**
 * Created by PhpStorm.
 * User: ilv.semenov
 * Date: 10.09.15
 * Time: 11:46
 * Синхронизация пользоватетелей из тбл AUser в тбл ASubordinate
 * c расстановкой по правилам подчиненности (Nested Set)
 */
class RewriteASubordinate extends CComponent{
    private $users = array();
    private $successCount = 0;

    /**
     * Полное перестроение дерева подчиненности
     * Внимание! Всвязи с вливанием пользаков ПИК, медот отрабатывает медленно(около 2 часов).
     * Рекомендуется использовать метод частичного апдейта RewriteASubordinate::Update()
     */
    public function start()
    {
        set_time_limit(14400);
        $timeStart = time();
        $db = Yii::app()->db;
        $this->users = User::model()
            ->active()
            ->with(
                array('bUser' =>
                    array(
                        /* Получаем только пользаков Мортон, остальные нас не интересуют для субординации */
                        'condition' => "bUser.EXTERNAL_AUTH_ID = 'LDAP#3'"
                    )
                )
            )
            ->findAll();
        $transaction = $db->beginTransaction();
        try{
            ASubordinate::model()->deleteAll();
            foreach($this->users as $user){
                $ID_Chief_1 = User::model()->active()->findByPk($user->ID_Chief_1);
                if(!isset($ID_Chief_1->ID) && empty($ID_Chief_1->ID)){
                    $root = new ASubordinate;
                    $root->id = $user->ID;
                    if(!$root->saveNode()) throw new CDbException('Error: Can`t create a Node for root user');
                    $this->successCount++;
                    $this->insertChildren($user);
                }
            }
            $transaction->commit();
        }
        catch(CDbException $e)
        {
            var_dump($e);
            $transaction->rollback();
        }
        mail('ilv.semenov@morton.ru', '', 'RewriteASubordinate->start() is finished. Count of items: '.$this->successCount.', time: '.(time() - $timeStart));
        return $this->successCount;
    }

    private function insertChildren($user)
    {
        $children = User::model()->active()->findAll('ID_Chief_1 = ' . $user->ID);
        if(!count($children)) return false;
        $parentNode = ASubordinate::model()->findByPk($user->ID);
        foreach($children as $child){
            $node = new ASubordinate;
            $node->id = $child->ID;
            $node->parent_id = $user->ID;
            if($node->appendTo($parentNode))
                $this->insertChildren($child);
            $this->successCount++;
        }
    }

    /**
     * Перестраиваем субординацию только для пользаков у которых руководитель сменился
     */
    public static function Update() {
        /**
         * Получение
         */
        $query = '
          SELECT
            au.ID, au.ID_Chief_1 as new_Chief, s.parent_id as old_Chief
            FROM a_user au
            LEFT JOIN a_subordinate s ON s.id = au.ID
            WHERE au.ID_Chief_1 IS NOT NULL
            AND au.ID_Chief_1 != s.parent_id /* Только с измененным руководителем */
        ';
        $onlyChangedChiefUsers = Yii::app()->db->createCommand($query)->queryAll();

        /**
         * Обработка
         */
        $transaction = Yii::app()->db->beginTransaction();
        try{
            foreach($onlyChangedChiefUsers as $userIds){
                $user = ASubordinate::model()->findByPk($userIds['ID']);
                $newChief = ASubordinate::model()->findByPk($userIds['new_Chief']);
                if($newChief != NULL && $user != NULL && $user->moveAsLast($newChief)) {
                    $user->parent_id = $userIds['new_Chief'];
                    $user->saveNode();
                }
            }
            $transaction->commit();
        }
        catch(CDbException $e)
        {
            var_dump($e);
            $transaction->rollback();
        }
        return count($onlyChangedChiefUsers);
    }
} 
